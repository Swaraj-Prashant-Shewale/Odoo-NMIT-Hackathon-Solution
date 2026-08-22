<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PayComponents;
use App\Services\RouteInput;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * The pay component catalogue.
 *
 * Components are referenced by salary structures and by every payslip ever
 * issued, so they are created and retired rather than deleted.
 */
final class PayComponentController
{
    private const TYPES = 'earning,deduction,employer_contribution';

    private const CALCULATIONS = 'fixed,percent_of_basic,percent_of_ctc,slab';

    private PayComponents $components;

    public function __construct()
    {
        $this->components = new PayComponents();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'active_only' => 'nullable|boolean',
        ])->validated();

        $components = $this->components->ordered((bool) ($filters['active_only'] ?? false));

        return Response::ok($components, ['total' => count($components)]);
    }

    public function show(Request $request): Response
    {
        return Response::ok($this->requireComponent($request));
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|safe_text|max:120',
            'code' => 'required|slug|max:40',
            'component_type' => 'required|in:' . self::TYPES,
            'calculation' => 'required|in:' . self::CALCULATIONS,
            'percentage' => 'nullable|numeric|between:0,100',
            'is_taxable' => 'nullable|boolean',
            'is_statutory' => 'nullable|boolean',
            'display_order' => 'nullable|integer|between:0,999',
        ])->validated();

        $this->assertPercentagePresent($data);

        if ($this->components->findByCode((string) $data['code']) !== null) {
            throw HttpException::conflict('A pay component with that code already exists.', ['code' => $data['code']]);
        }

        $component = $this->components->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'component_type' => $data['component_type'],
            'calculation' => $data['calculation'],
            'percentage' => $data['percentage'] ?? null,
            'is_taxable' => $data['is_taxable'] ?? true,
            'is_statutory' => $data['is_statutory'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => true,
        ]);

        AuditLog::record($request, 'payroll.component.created', 'pay_component', (string) $component['id'], [], $component);

        return Response::created($component);
    }

    public function update(Request $request): Response
    {
        $existing = $this->requireComponent($request);

        $data = Validator::make($request->all(), [
            'name' => 'nullable|safe_text|max:120',
            'component_type' => 'nullable|in:' . self::TYPES,
            'calculation' => 'nullable|in:' . self::CALCULATIONS,
            'percentage' => 'nullable|numeric|between:0,100',
            'is_taxable' => 'nullable|boolean',
            'is_statutory' => 'nullable|boolean',
            'display_order' => 'nullable|integer|between:0,999',
            'is_active' => 'nullable|boolean',
        ])->validated();

        // The code is the stable key other records were written against, so it
        // is deliberately not editable once the component exists.
        $merged = array_filter($data, static fn (mixed $value): bool => $value !== null) + [
            'calculation' => $existing['calculation'],
            'percentage' => $existing['percentage'],
        ];

        $this->assertPercentagePresent($merged);

        $updated = $this->components->update((string) $existing['id'], $merged);

        if ($updated === null) {
            throw HttpException::notFound();
        }

        AuditLog::record(
            $request,
            'payroll.component.updated',
            'pay_component',
            (string) $existing['id'],
            $existing,
            $updated,
            ['changed' => AuditLog::diff($existing, $updated)]
        );

        return Response::ok($updated);
    }

    /** @param array<string, mixed> $data */
    private function assertPercentagePresent(array $data): void
    {
        $calculation = (string) ($data['calculation'] ?? 'fixed');

        if (!in_array($calculation, ['percent_of_basic', 'percent_of_ctc'], true)) {
            return;
        }

        if (($data['percentage'] ?? null) === null) {
            throw HttpException::unprocessable('A percentage-based component needs a percentage.');
        }
    }

    /** @return array<string, mixed> */
    private function requireComponent(Request $request): array
    {
        $component = $this->components->find(RouteInput::uuid($request));

        if ($component === null) {
            throw HttpException::notFound('That pay component does not exist.');
        }

        return $component;
    }
}
