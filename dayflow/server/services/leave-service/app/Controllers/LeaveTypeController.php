<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LeaveRequests;
use App\Models\LeaveTypes;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

final class LeaveTypeController
{
    private LeaveTypes $types;
    private LeaveRequests $requests;

    public function __construct()
    {
        $this->types = new LeaveTypes();
        $this->requests = new LeaveRequests();
    }

    /** GET /leave-types */
    public function index(Request $request): Response
    {
        $includeRetired = $request->bool('include_inactive', false);

        $rows = $includeRetired ? $this->types->all('name', 'asc') : $this->types->active();

        return Response::ok($rows, ['total' => count($rows)]);
    }

    /** GET /leave-types/{id} */
    public function show(Request $request): Response
    {
        $type = $this->types->find(RouteId::of($request));

        if ($type === null) {
            throw HttpException::notFound();
        }

        return Response::ok($type);
    }

    /** POST /leave-types */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, null);

        if ($this->types->findBy('code', $data['code']) !== null) {
            throw HttpException::conflict('A leave type with that code already exists.');
        }

        if ($this->types->findBy('name', $data['name']) !== null) {
            throw HttpException::conflict('A leave type with that name already exists.');
        }

        $record = $this->types->create($data);

        AuditLog::record($request, 'leave.type.created', 'leave_type', (string) $record['id'], [], $record);

        return Response::created($record);
    }

    /** PUT /leave-types/{id} */
    public function update(Request $request): Response
    {
        $existing = $this->types->find(RouteId::of($request));

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $data = $this->validate($request, $existing);

        if (isset($data['code'])) {
            $clash = $this->types->findBy('code', $data['code']);

            if ($clash !== null && $clash['id'] !== $existing['id']) {
                throw HttpException::conflict('Another leave type already uses that code.');
            }
        }

        if (isset($data['name'])) {
            $clash = $this->types->findBy('name', $data['name']);

            if ($clash !== null && $clash['id'] !== $existing['id']) {
                throw HttpException::conflict('Another leave type already uses that name.');
            }
        }

        $record = $this->types->update((string) $existing['id'], $data);

        AuditLog::record($request, 'leave.type.updated', 'leave_type', (string) $existing['id'], $existing, $record ?? []);

        return Response::ok($record ?? $existing);
    }

    /**
     * DELETE /leave-types/{id}
     *
     * Retires the type rather than removing it. Historical requests and
     * balances reference it, and a leave record that cannot say what kind of
     * leave it was is worthless for payroll or for an audit years later.
     */
    public function destroy(Request $request): Response
    {
        $existing = $this->types->find(RouteId::of($request));

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $open = $this->requests->query()
            ->where('leave_type_id', '=', $existing['id'])
            ->where('status', '=', 'pending')
            ->count();

        if ($open > 0) {
            throw HttpException::conflict(
                'This leave type still has requests waiting for a decision.',
                ['pending_requests' => $open]
            );
        }

        $record = $this->types->update((string) $existing['id'], ['is_active' => false]);

        AuditLog::record($request, 'leave.type.retired', 'leave_type', (string) $existing['id'], $existing, $record ?? []);

        return Response::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request, ?array $existing): array
    {
        $required = $existing === null ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'name' => $required . '|safe_text|max:80',
            'code' => $required . '|alpha_num|min:2|max:20',
            'category' => $required . '|in:' . implode(',', LeaveTypes::CATEGORIES),
            'colour' => 'nullable|safe_text|max:7',
            'annual_quota_days' => 'nullable|numeric|between:0,366',
            'accrual_frequency' => 'nullable|in:' . implode(',', LeaveTypes::ACCRUAL_FREQUENCIES),
            'accrual_days' => 'nullable|numeric|between:0,31',
            'max_carry_forward_days' => 'nullable|numeric|between:0,366',
            'allows_half_day' => 'nullable|boolean',
            'requires_document_after_days' => 'nullable|int|between:0,366',
            'min_notice_days' => 'nullable|int|between:0,365',
            'max_consecutive_days' => 'nullable|int|between:1,366',
            'is_paid' => 'nullable|boolean',
            'applies_to_gender' => 'nullable|in:' . implode(',', LeaveTypes::GENDERS),
            'is_active' => 'nullable|boolean',
        ], [
            'code' => 'Code',
            'annual_quota_days' => 'Annual quota',
            'accrual_days' => 'Accrual per period',
        ])->validated();

        if (isset($data['code'])) {
            // Codes are shown on payslips and reports, so they are normalised
            // to one case rather than left to whoever typed them.
            $data['code'] = strtoupper((string) $data['code']);
        }

        if (isset($data['colour'])) {
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $data['colour']) !== 1) {
                throw HttpException::unprocessable('The colour must be a six digit hex value such as #2563EB.');
            }

            $data['colour'] = strtoupper((string) $data['colour']);
        }

        $data = self::withoutMeaninglessNulls($data);

        // The table refuses an accruing type with nothing to accrue. Checking
        // the merged view means changing only the frequency of a type that
        // already credits days is still accepted.
        $merged = array_merge($existing ?? [], $data);

        if (($merged['accrual_frequency'] ?? 'none') !== 'none' && (float) ($merged['accrual_days'] ?? 0) <= 0) {
            throw HttpException::unprocessable('An accruing leave type must credit more than zero days each period.');
        }

        return $data;
    }

    /**
     * Drops explicit nulls for columns that cannot hold one.
     *
     * A form that submits an untouched field as an empty string would
     * otherwise try to write NULL over a NOT NULL column and fail deep in the
     * driver instead of simply leaving the value alone.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function withoutMeaninglessNulls(array $data): array
    {
        $nullable = ['requires_document_after_days', 'max_consecutive_days'];

        foreach ($data as $field => $value) {
            if ($value === null && !in_array($field, $nullable, true)) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
