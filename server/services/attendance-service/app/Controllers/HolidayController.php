<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Holidays;
use App\Policies\AttendanceScope;
use App\Services\PeopleDirectory;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * The holiday calendar.
 *
 * Reading it is open to any authenticated user, because everybody needs to
 * know when the office is shut. Writing it is an HR administration task.
 */
final class HolidayController
{
    /** PostgreSQL's unique-violation class, raised by the one-per-day-and-office index. */
    private const UNIQUE_VIOLATION = '23505';

    private Holidays $holidays;
    private PeopleDirectory $people;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->holidays = new Holidays();
        $this->people = new PeopleDirectory();
        $this->scope = new AttendanceScope();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'year' => 'nullable|int|between:2000,2100',
            'type' => 'nullable|in:public,restricted,company',
            'location_id' => 'nullable|uuid',
            'include_inactive' => 'nullable|boolean',
        ])->validated();

        $year = $filters['year'] ?? (int) Clock::now()->format('Y');

        $builder = $this->holidays->query()
            ->where('year', '=', $year)
            ->orderBy('holiday_date', 'asc');

        if (($filters['include_inactive'] ?? false) !== true) {
            $builder->where('is_active', '=', true);
        }

        if (isset($filters['type'])) {
            $builder->where('holiday_type', '=', $filters['type']);
        }

        // A location filter still returns the company-wide entries, which is
        // the whole point of a null location on a holiday row.
        $rows = array_map([$this->holidays, 'present'], $builder->get());

        if (($filters['location_id'] ?? null) !== null) {
            $locationId = (string) $filters['location_id'];
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['location_id'] === null || $row['location_id'] === $locationId
            ));
        }

        return Response::ok($rows, ['year' => $year, 'total' => count($rows)]);
    }

    /** The next holidays for the office the caller sits in. */
    public function upcoming(Request $request): Response
    {
        $filters = Validator::make($request->all(), ['limit' => 'nullable|int|between:1,50'])->validated();

        $locationId = $this->people->locationOf($request, $this->scope->selfId($request));

        return Response::ok(
            $this->holidays->upcoming(Clock::today(), (int) ($filters['limit'] ?? 5), $locationId)
        );
    }

    public function show(Request $request): Response
    {
        $holiday = $this->holidays->find(RouteId::of($request));

        if ($holiday === null) {
            throw HttpException::notFound();
        }

        return Response::ok($holiday);
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|safe_text|max:120',
            'holiday_date' => 'required|date',
            'holiday_type' => 'nullable|in:public,restricted,company',
            'location_id' => 'nullable|uuid',
            'description' => 'nullable|safe_text|max:500',
            'is_active' => 'nullable|boolean',
        ])->validated();

        $date = (string) $data['holiday_date'];
        $locationId = $data['location_id'] ?? null;

        if ($this->holidays->existsOn($date, (string) $data['name'], $locationId)) {
            throw HttpException::conflict('That holiday is already on the calendar for this date.');
        }

        // An explicit null from a client would otherwise be written over the
        // column default and violate the NOT NULL constraint behind it.
        $data['holiday_type'] = $data['holiday_type'] ?? 'public';
        $data['is_active'] = $data['is_active'] ?? true;
        // Derived rather than accepted: a year that disagreed with the date
        // would quietly drop the day out of every calendar query.
        $data['year'] = (int) Clock::parse($date)->format('Y');

        try {
            $holiday = $this->holidays->create($data);
        } catch (\PDOException $exception) {
            throw $this->clash($exception);
        }

        AuditLog::record($request, 'attendance.holiday.created', 'holiday', (string) $holiday['id'], [], $holiday);

        return Response::created($holiday);
    }

    public function update(Request $request): Response
    {
        $holiday = $this->holidays->find(RouteId::of($request));

        if ($holiday === null) {
            throw HttpException::notFound();
        }

        $data = Validator::make($request->all(), [
            'name' => 'nullable|safe_text|max:120',
            'holiday_date' => 'nullable|date',
            'holiday_type' => 'nullable|in:public,restricted,company',
            'location_id' => 'nullable|uuid',
            'description' => 'nullable|safe_text|max:500',
            'is_active' => 'nullable|boolean',
        ])->validated();

        if (isset($data['holiday_date'])) {
            $data['year'] = (int) Clock::parse((string) $data['holiday_date'])->format('Y');
        }

        // Only location_id and description may legitimately be cleared.
        foreach (['name', 'holiday_date', 'holiday_type', 'is_active'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
            }
        }

        if ($data === []) {
            throw HttpException::unprocessable('No changes were supplied.');
        }

        try {
            $updated = $this->holidays->update((string) $holiday['id'], $data) ?? $holiday;
        } catch (\PDOException $exception) {
            // Moving a holiday onto a day that already has one with the same
            // name is an ordinary editing mistake, not a server fault.
            throw $this->clash($exception);
        }

        AuditLog::record($request, 'attendance.holiday.updated', 'holiday', (string) $holiday['id'], $holiday, $updated);

        return Response::ok($updated);
    }

    public function destroy(Request $request): Response
    {
        $holiday = $this->holidays->find(RouteId::of($request));

        if ($holiday === null) {
            throw HttpException::notFound();
        }

        $this->holidays->delete((string) $holiday['id']);

        AuditLog::record($request, 'attendance.holiday.deleted', 'holiday', (string) $holiday['id'], $holiday, []);

        return Response::noContent();
    }

    /**
     * Turns the calendar's uniqueness rule into an answer a client can act on.
     *
     * Anything else is a genuine database fault and is handed back untouched
     * so the kernel logs it rather than dressing it up as a conflict.
     */
    private function clash(\PDOException $exception): \Throwable
    {
        if ($exception->getCode() === self::UNIQUE_VIOLATION) {
            return HttpException::conflict('That holiday is already on the calendar for this date.');
        }

        return $exception;
    }
}
