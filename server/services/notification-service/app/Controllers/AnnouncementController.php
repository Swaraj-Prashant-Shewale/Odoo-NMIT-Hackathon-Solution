<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AnnouncementReads;
use App\Models\Announcements;
use App\Policies\AnnouncementPolicy;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\Validator;

/**
 * The company notice board.
 *
 * Reading is open to everybody, narrowed to what each person is addressed by.
 * Publishing, editing and removing all require announcement.publish.
 */
final class AnnouncementController
{
    private Announcements $announcements;

    private AnnouncementReads $reads;

    public function __construct()
    {
        $this->announcements = new Announcements();
        $this->reads = new AnnouncementReads();
    }

    public function index(Request $request): Response
    {
        $principal = $request->principal();

        $filters = Validator::make($request->all(), ['scope' => 'nullable|in:visible,all'])->validated();

        // The whole board, including retired and expired notices, is only for
        // the people who maintain it.
        if (($filters['scope'] ?? 'visible') === 'all' && $principal->can(Permissions::ANNOUNCEMENT_PUBLISH)) {
            return Response::page($this->announcements->allFor(
                $principal->employeeId,
                $request->page(),
                $request->perPage()
            ));
        }

        return Response::page($this->announcements->feedFor(
            $principal->employeeId,
            $principal->departmentId,
            $principal->roles,
            $request->page(),
            $request->perPage()
        ));
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'required|safe_text|max:200',
            'body' => 'required|safe_text|max:20000',
            'category' => 'nullable|slug|max:40',
            'severity' => 'nullable|in:info,success,warning,critical',
            'expires_on' => 'nullable|date|after_or_equal:today',
            'pinned' => 'nullable|bool',
            'target_department_id' => 'nullable|uuid',
            'target_role' => 'nullable|role',
            'is_active' => 'nullable|bool',
        ])->validated();

        // published_by and published_at are the service's to set. An author
        // supplied in the request would let anybody publish under a colleague.
        $record = $this->announcements->create([
            'id' => Str::uuid(),
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'] ?? 'general',
            'severity' => $data['severity'] ?? 'info',
            'published_by' => $request->principal()->userId,
            'published_at' => Clock::iso(),
            'expires_on' => $data['expires_on'] ?? null,
            'pinned' => $data['pinned'] ?? false,
            'target_department_id' => $data['target_department_id'] ?? null,
            'target_role' => $data['target_role'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        AuditLog::record($request, 'announcement.created', 'announcement', $record['id'], [], $record);

        return Response::created($record);
    }

    public function update(Request $request): Response
    {
        $existing = $this->announcements->find(self::identifier($request));

        if ($existing === null) {
            throw HttpException::notFound('This announcement does not exist.');
        }

        // Columns the table declares NOT NULL are left without "nullable", so a
        // request that omits one leaves it alone instead of clearing it.
        $data = Validator::make($request->all(), [
            'title' => 'safe_text|max:200',
            'body' => 'safe_text|max:20000',
            'category' => 'slug|max:40',
            'severity' => 'in:info,success,warning,critical',
            'expires_on' => 'nullable|date',
            'pinned' => 'bool',
            'target_department_id' => 'nullable|uuid',
            'target_role' => 'nullable|role',
            'is_active' => 'bool',
        ])->validated();

        if ($data === []) {
            throw HttpException::badRequest('There is nothing in this request to change.');
        }

        $updated = $this->announcements->update((string) $existing['id'], $data);

        AuditLog::record(
            $request,
            'announcement.updated',
            'announcement',
            (string) $existing['id'],
            $existing,
            $updated ?? []
        );

        return Response::ok($updated);
    }

    public function destroy(Request $request): Response
    {
        $existing = $this->announcements->find(self::identifier($request));

        if ($existing === null) {
            throw HttpException::notFound('This announcement does not exist.');
        }

        $id = (string) $existing['id'];
        $this->announcements->delete($id);

        AuditLog::record($request, 'announcement.deleted', 'announcement', $id, $existing, []);

        return Response::noContent();
    }

    public function read(Request $request): Response
    {
        $principal = $request->principal();

        if ($principal->employeeId === null) {
            throw HttpException::forbidden('A read receipt belongs to an employee record, and this account has none.');
        }

        $announcement = $this->announcements->find(self::identifier($request));

        // Refused as missing rather than forbidden: a receipt on a notice
        // addressed to another department would otherwise confirm it exists.
        if ($announcement === null || !AnnouncementPolicy::isVisibleTo($principal, $announcement)) {
            throw HttpException::notFound('This announcement does not exist.');
        }

        return Response::ok($this->reads->record((string) $announcement['id'], $principal->employeeId));
    }

    private static function identifier(Request $request): string
    {
        $data = Validator::make(['id' => $request->route('id')], ['id' => 'required|uuid'])->validated();

        return (string) $data['id'];
    }
}
