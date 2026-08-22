<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmployeeDocuments;
use App\Models\Employees;
use App\Policies\DocumentAccessPolicy;
use App\Services\DocumentStorage;
use App\Services\RecordId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Employee paperwork.
 *
 * Nothing here ever returns a filesystem path. A file is reachable only
 * through the download endpoint, which re-checks the caller against the row
 * that owns it every time.
 */
final class DocumentController
{
    /** Photographs are managed through the profile endpoint, not uploaded here. */
    private const UPLOADABLE_CATEGORIES = [
        'identity', 'address', 'education', 'employment', 'contract',
        'payroll', 'medical', 'other',
    ];

    private EmployeeDocuments $documents;
    private Employees $employees;
    private DocumentStorage $storage;
    private DocumentAccessPolicy $access;

    public function __construct()
    {
        $this->documents = new EmployeeDocuments();
        $this->employees = new Employees();
        $this->storage = new DocumentStorage();
        $this->access = new DocumentAccessPolicy();
    }

    public function index(Request $request): Response
    {
        $principal = $request->principal();

        $filters = Validator::make($request->query, [
            'employee_id' => 'nullable|uuid',
            'category' => 'nullable|in:' . implode(',', EmployeeDocuments::CATEGORIES),
            'status' => 'nullable|in:pending,verified,rejected,expired',
        ])->validated();

        $query = $this->documents->listQuery();

        if ($principal->canAny(Permissions::DOCUMENT_VIEW_ALL, Permissions::DOCUMENT_MANAGE)) {
            if (!empty($filters['employee_id'])) {
                $query->where('employee_documents.employee_id', '=', $filters['employee_id']);
            }
        } else {
            // A requested employee_id is ignored rather than honoured: without
            // an "all" permission the only readable set is the caller's own.
            // An account with no person record attached owns nothing, and an
            // empty list says so without putting a blank value up against a
            // uuid column.
            $self = RecordId::orNull($principal->employeeId);

            if ($self === null) {
                $query->whereIn('employee_documents.employee_id', []);
            } else {
                $query->where('employee_documents.employee_id', '=', $self);
            }
        }

        if (!empty($filters['category'])) {
            $query->where('employee_documents.category', '=', $filters['category']);
        }

        if (!empty($filters['status'])) {
            $query->where('employee_documents.status', '=', $filters['status']);
        }

        $query->orderBy('employee_documents.created_at', 'desc');

        return Response::page($this->documents->paginate($query, $request->page(), $request->perPage()));
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'category' => 'required|in:' . implode(',', self::UPLOADABLE_CATEGORIES),
            'title' => 'required|safe_text|max:150',
            'issued_on' => 'nullable|date|before_or_equal:today',
            'expires_on' => 'nullable|date',
        ])->validated();

        $principal = $request->principal();
        $employeeId = $this->access->resolveUploadTarget(
            $principal,
            isset($data['employee_id']) ? (string) $data['employee_id'] : null
        );

        if ($this->employees->find($employeeId) === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        if (!empty($data['issued_on']) && !empty($data['expires_on'])
            && strtotime((string) $data['expires_on']) < strtotime((string) $data['issued_on'])) {
            throw HttpException::unprocessable('The expiry date cannot fall before the issue date.', [
                'expires_on' => ['The expiry date cannot fall before the issue date.'],
            ]);
        }

        $file = $request->files['file'] ?? $request->files['document'] ?? null;
        $stored = $this->storage->store(is_array($file) ? $file : null, DocumentStorage::DOCUMENT_EXTENSIONS);

        $document = $this->documents->create($stored + [
            'employee_id' => $employeeId,
            'category' => $data['category'],
            'title' => $data['title'],
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'status' => 'pending',
            'uploaded_by' => RecordId::orNull($principal->employeeId),
        ]);

        AuditLog::record($request, 'employee.document.uploaded', 'employee_document', $document['id'], [], [
            'employee_id' => $employeeId,
            'category' => $document['category'],
            'title' => $document['title'],
            'size_bytes' => $document['size_bytes'],
            'checksum' => $document['checksum'],
        ]);

        return Response::created($document);
    }

    public function download(Request $request): Response
    {
        $document = $this->requireDocument($request->route('id'));

        $this->access->assertCanRead($request->principal(), $document);

        $contents = $this->storage->read((string) $document['stored_filename']);

        AuditLog::record($request, 'employee.document.downloaded', 'employee_document', (string) $document['id'], [], [
            'employee_id' => $document['employee_id'],
            'title' => $document['title'],
        ]);

        // Response::download already forces an attachment disposition and
        // nosniff, so a stored HTML file can never be rendered in place.
        return Response::download(
            $contents,
            (string) $document['original_filename'],
            (string) $document['mime_type']
        );
    }

    /**
     * Serves a profile photograph.
     *
     * Addressed by employee rather than by document id so the canonical record
     * can carry a stable photo_url that survives the picture being replaced.
     */
    public function photo(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = RecordId::orNull($request->route('employee_id'));
        $employee = $employeeId === null ? null : $this->employees->find($employeeId);

        if ($employee === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        if (!$principal->can(Permissions::DIRECTORY_VIEW) && !$principal->owns((string) $employee['id'])) {
            throw HttpException::forbidden('You may not view this photograph.');
        }

        if ($employee['photo_document_id'] === null) {
            throw HttpException::notFound('That person has no photograph on file.');
        }

        $document = $this->documents->findInternal((string) $employee['photo_document_id']);

        if ($document === null) {
            throw HttpException::notFound('That person has no photograph on file.');
        }

        $contents = $this->storage->read((string) $document['stored_filename']);

        return Response::binary(200, $contents, [
            'Content-Type' => (string) $document['mime_type'],
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(Request $request): Response
    {
        $before = $this->requireDocument($request->route('id'));
        $principal = $request->principal();

        // Verification is the control that makes a document trustworthy, so it
        // cannot be the holder's own signature. Somebody who both uploads a
        // qualification and marks it checked has verified nothing.
        if ($principal->owns(isset($before['employee_id']) ? (string) $before['employee_id'] : null)) {
            throw HttpException::forbidden('Your own paperwork has to be verified by somebody else.');
        }

        $data = Validator::make($request->all(), [
            'status' => 'required|in:verified,rejected',
            'note' => 'nullable|safe_text|max:500',
        ])->validated();

        $after = $this->documents->update((string) $before['id'], [
            'status' => $data['status'],
            'verified_by' => RecordId::orNull($principal->employeeId),
            'verified_at' => Clock::iso(),
        ]);

        if ($after === null) {
            throw HttpException::notFound('That document does not exist.');
        }

        AuditLog::record(
            $request,
            'employee.document.' . $data['status'],
            'employee_document',
            (string) $after['id'],
            ['status' => $before['status']],
            ['status' => $after['status']],
            ['note' => $data['note'] ?? null]
        );

        return Response::ok($after);
    }

    public function destroy(Request $request): Response
    {
        $document = $this->requireDocument($request->route('id'));
        $storedFilename = (string) $document['stored_filename'];

        $this->documents->delete((string) $document['id']);

        // The row is the record of truth; the file is removed afterwards so a
        // storage problem cannot leave an undeletable database row behind.
        $this->storage->remove($storedFilename);

        AuditLog::record($request, 'employee.document.deleted', 'employee_document', (string) $document['id'], [
            'employee_id' => $document['employee_id'],
            'title' => $document['title'],
            'category' => $document['category'],
        ], []);

        return Response::noContent();
    }

    public function expiring(Request $request): Response
    {
        $filters = Validator::make($request->query, [
            'days' => 'nullable|integer|between:1,365',
        ])->validated();

        $days = (int) ($filters['days'] ?? 30);
        $today = Clock::today();
        $until = Clock::parse($today)->modify(sprintf('+%d days', $days))->format('Y-m-d');

        // Anything already past its date is moved into the expired state, so a
        // status filter alone is enough for every other screen in the platform.
        $this->documents->markLapsed($today);

        $documents = $this->documents->expiringWithin($today, $until);

        // Reading a list must not mean reminding everybody on it again. Each
        // document is claimed for the day before its event is queued, so an
        // afternoon of refreshing this screen still sends one reminder each.
        foreach ($this->documents->claimExpiryReminders($today, $until) as $due) {
            EventPublisher::publish('employee.document.expiring', [
                'employee_id' => $due['employee_id'],
                'document_id' => $due['id'],
                'expires_on' => $due['expires_on'],
            ]);
        }

        return Response::ok($documents, [
            'days' => $days,
            'from' => $today,
            'until' => $until,
            'total' => count($documents),
        ]);
    }

    /**
     * Loads a document including the columns hidden from API responses.
     *
     * @return array<string, mixed>
     */
    private function requireDocument(string $id): array
    {
        $id = RecordId::orNull($id);
        $document = $id === null ? null : $this->documents->findInternal($id);

        if ($document === null) {
            throw HttpException::notFound('That document does not exist.');
        }

        return $document;
    }
}
