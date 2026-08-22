<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Employees;
use App\Models\Locations;
use App\Services\RecordId;
use App\Services\RequiredColumns;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\ValidationException;
use Dayflow\Kernel\Validation\Validator;

/**
 * Offices and remote working bases.
 *
 * Each location carries its own timezone because attendance is judged against
 * the working day where the person actually is, not where the company is
 * registered.
 */
final class LocationController
{
    private Locations $locations;
    private Employees $employees;

    public function __construct()
    {
        $this->locations = new Locations();
        $this->employees = new Employees();
    }

    public function index(Request $request): Response
    {
        return Response::ok($this->locations->all('name', 'asc'));
    }

    public function show(Request $request): Response
    {
        $location = $this->requireLocation($request->route('id'));

        return Response::ok($location + [
            'employee_count' => $this->employees->countAtLocation((string) $location['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request);

        if ($this->locations->findByName((string) $data['name']) !== null) {
            throw HttpException::conflict('A location already uses that name.');
        }

        $location = $this->locations->create($data);

        AuditLog::record($request, 'location.created', 'location', $location['id'], [], $location);

        return Response::created($location);
    }

    public function update(Request $request): Response
    {
        $before = $this->requireLocation($request->route('id'));
        $data = $this->validate($request, false);

        if ($data === []) {
            throw HttpException::badRequest('No changes were supplied.');
        }

        if (isset($data['name'])) {
            $clash = $this->locations->findByName((string) $data['name']);

            if ($clash !== null && (string) $clash['id'] !== (string) $before['id']) {
                throw HttpException::conflict('A location already uses that name.');
            }
        }

        $after = $this->locations->update((string) $before['id'], $data);

        if ($after === null) {
            throw HttpException::notFound('That location does not exist.');
        }

        AuditLog::record($request, 'location.updated', 'location', $after['id'], $before, $after);

        return Response::ok($after);
    }

    public function destroy(Request $request): Response
    {
        $location = $this->requireLocation($request->route('id'));
        $id = (string) $location['id'];

        $headcount = $this->employees->countAtLocation($id);

        if ($headcount > 0) {
            throw HttpException::conflict(
                'People are still based at that location. Move them before deleting it.',
                ['employee_count' => $headcount]
            );
        }

        $this->locations->delete($id);

        AuditLog::record($request, 'location.deleted', 'location', $id, $location, []);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'name' => $required . '|safe_text|max:100',
            'address_line1' => 'nullable|safe_text|max:150',
            'address_line2' => 'nullable|safe_text|max:150',
            'city' => 'nullable|safe_text|max:80',
            'state' => 'nullable|safe_text|max:80',
            'country' => 'nullable|safe_text|max:80',
            'postal_code' => 'nullable|safe_text|max:20',
            'timezone' => 'nullable|safe_text|max:64',
            'is_remote' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ])->validated();

        if (!empty($data['timezone']) && !in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            // A bad zone here would silently shift every attendance record for
            // everyone based at this location, so it is checked on the way in.
            throw new ValidationException('Some of the information supplied is not valid.', [
                'timezone' => ['That is not a recognised timezone.'],
            ]);
        }

        return RequiredColumns::stripNulls($data, ['name', 'country', 'timezone', 'is_remote', 'is_active']);
    }

    /** @return array<string, mixed> */
    private function requireLocation(string $id): array
    {
        $id = RecordId::orNull($id);
        $location = $id === null ? null : $this->locations->find($id);

        if ($location === null) {
            throw HttpException::notFound('That location does not exist.');
        }

        return $location;
    }
}
