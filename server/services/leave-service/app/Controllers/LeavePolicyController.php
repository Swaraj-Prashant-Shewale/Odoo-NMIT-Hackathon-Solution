<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LeavePolicies;
use App\Models\LeaveTypes;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

final class LeavePolicyController
{
    private LeavePolicies $policies;
    private LeaveTypes $types;

    public function __construct()
    {
        $this->policies = new LeavePolicies();
        $this->types = new LeaveTypes();
    }

    /** GET /leave-policies */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'leave_type_id' => 'nullable|uuid',
            'employment_type' => 'nullable|in:' . implode(',', LeavePolicies::EMPLOYMENT_TYPES),
        ])->validated();

        $builder = $this->policies->query();

        if (isset($filters['leave_type_id'])) {
            $builder->where('leave_type_id', '=', $filters['leave_type_id']);
        }

        if (isset($filters['employment_type'])) {
            $builder->where('employment_type', '=', $filters['employment_type']);
        }

        $page = $this->policies->paginate(
            $builder->orderBy('employment_type')->orderBy('effective_from', 'desc'),
            $request->page(),
            $request->perPage()
        );

        $page['data'] = $this->decorate($page['data']);

        return Response::page($page);
    }

    /** GET /leave-policies/{id} */
    public function show(Request $request): Response
    {
        $policy = $this->policies->find(RouteId::of($request));

        if ($policy === null) {
            throw HttpException::notFound();
        }

        return Response::ok($this->decorate([$policy])[0]);
    }

    /** POST /leave-policies */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, true);

        if ($this->types->find((string) $data['leave_type_id']) === null) {
            throw HttpException::unprocessable('That leave type does not exist.');
        }

        $clash = $this->policies->query()
            ->where('leave_type_id', '=', $data['leave_type_id'])
            ->where('employment_type', '=', $data['employment_type'])
            ->where('effective_from', '=', $data['effective_from'])
            ->first();

        if ($clash !== null) {
            throw HttpException::conflict('A policy for that employment type already starts on that date.');
        }

        $record = $this->policies->create($data);

        AuditLog::record($request, 'leave.policy.created', 'leave_policy', (string) $record['id'], [], $record);

        return Response::created($this->decorate([$record])[0]);
    }

    /** PUT /leave-policies/{id} */
    public function update(Request $request): Response
    {
        $existing = $this->policies->find(RouteId::of($request));

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $data = $this->validate($request, false);

        if (isset($data['leave_type_id']) && $this->types->find((string) $data['leave_type_id']) === null) {
            throw HttpException::unprocessable('That leave type does not exist.');
        }

        $merged = array_merge($existing, $data);

        if ($merged['effective_to'] !== null && strtotime((string) $merged['effective_to']) < strtotime((string) $merged['effective_from'])) {
            throw HttpException::unprocessable('The policy cannot end before it starts.');
        }

        $record = $this->policies->update((string) $existing['id'], $data);

        AuditLog::record($request, 'leave.policy.updated', 'leave_policy', (string) $existing['id'], $existing, $record ?? []);

        return Response::ok($this->decorate([$record ?? $existing])[0]);
    }

    /**
     * DELETE /leave-policies/{id}
     *
     * Removing a policy affects future runs only. Days already credited stay
     * on the balance along with the period they were credited for, so history
     * remains explicable even after the rule behind it is withdrawn.
     */
    public function destroy(Request $request): Response
    {
        $existing = $this->policies->find(RouteId::of($request));

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $this->policies->delete((string) $existing['id']);

        AuditLog::record($request, 'leave.policy.deleted', 'leave_policy', (string) $existing['id'], $existing, []);

        return Response::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'leave_type_id' => $required . '|uuid',
            'employment_type' => $required . '|in:' . implode(',', LeavePolicies::EMPLOYMENT_TYPES),
            'effective_from' => $required . '|date',
            'applies_after_months' => 'nullable|int|between:0,120',
            'quota_override_days' => 'nullable|numeric|between:0,366',
            'effective_to' => 'nullable|date',
        ], [
            'leave_type_id' => 'Leave type',
            'applies_after_months' => 'Qualifying period',
            'quota_override_days' => 'Quota override',
        ])->validated();

        if ($creating
            && isset($data['effective_to'])
            && strtotime((string) $data['effective_to']) < strtotime((string) $data['effective_from'])
        ) {
            throw HttpException::unprocessable('The policy cannot end before it starts.');
        }

        // quota_override_days and effective_to are genuinely nullable; the
        // rest cannot be cleared, so an empty submission leaves them alone.
        foreach ($data as $field => $value) {
            if ($value === null && !in_array($field, ['quota_override_days', 'effective_to'], true)) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $types = $this->types->keyedById();

        foreach ($rows as $index => $row) {
            $type = $types[(string) ($row['leave_type_id'] ?? '')] ?? null;

            $rows[$index]['leave_type'] = $type === null ? null : [
                'id' => $type['id'],
                'name' => $type['name'],
                'code' => $type['code'],
                'annual_quota_days' => $type['annual_quota_days'],
            ];
        }

        return array_values($rows);
    }
}
