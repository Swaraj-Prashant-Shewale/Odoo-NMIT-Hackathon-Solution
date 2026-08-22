<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PayslipLines;
use App\Models\Payslips;
use App\Models\PlatformSettings;
use App\Policies\PayrollAccessPolicy;
use App\Services\AmountInWords;
use App\Services\EmployeeDirectory;
use App\Services\Money;
use App\Services\PayslipPdf;
use App\Services\Period;
use App\Services\RouteInput;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/** Employee-facing salary statements. */
final class PayslipController
{
    private const COMPANY_NAME_DEFAULT = 'Dayflow Technologies Pvt. Ltd.';

    private Payslips $payslips;

    private PayslipLines $lines;

    private PlatformSettings $settings;

    public function __construct()
    {
        $this->payslips = new Payslips();
        $this->lines = new PayslipLines();
        $this->settings = new PlatformSettings();
    }

    /** The caller's own payslips, or another person's with the right permission. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'period' => 'nullable|string|max:7',
        ])->validated();

        $principal = $request->principal();
        $employeeId = PayrollAccessPolicy::resolveSubject($principal, $filters['employee_id'] ?? null);

        $builder = $this->payslips->query()->where('employee_id', '=', $employeeId);

        if (!PayrollAccessPolicy::seesEveryone($principal)) {
            // Drafts are working figures and stay invisible until published.
            $builder->whereNotNull('published_at');
        }

        if (isset($filters['period'])) {
            $builder->where('period', '=', Period::normalise((string) $filters['period']));
        }

        $builder->orderBy('period', 'desc');

        return Response::page($this->payslips->paginate($builder, $request->page(), $request->perPage()));
    }

    /** One payslip with its full component breakdown. */
    public function show(Request $request): Response
    {
        $payslip = $this->requirePayslip($request);
        $lines = $this->lines->forPayslip((string) $payslip['id']);

        $directory = new EmployeeDirectory($request->bearerToken());

        return Response::ok($payslip + [
            'period_label' => Period::label((string) $payslip['period']),
            'currency' => Money::currencyCode(),
            'net_in_words' => AmountInWords::rupees((int) $payslip['net_minor']),
            'employee' => $directory->summary((string) $payslip['employee_id']),
            'earnings' => $this->ofType($lines, 'earning'),
            'deductions' => $this->ofType($lines, 'deduction'),
            'employer_contributions' => $this->ofType($lines, 'employer_contribution'),
            'lines' => $lines,
        ]);
    }

    /** The same statement as a printable PDF. */
    public function download(Request $request): Response
    {
        $payslip = $this->requirePayslip($request);
        $lines = $this->lines->forPayslip((string) $payslip['id']);

        $directory = new EmployeeDirectory($request->bearerToken());
        $employee = $directory->find((string) $payslip['employee_id']);

        $renderer = new PayslipPdf();
        $bytes = $renderer->render(
            $payslip,
            $lines,
            $employee,
            $this->settings->string('company.name', self::COMPANY_NAME_DEFAULT)
        );

        // Downloading someone's salary statement is worth a trail entry even
        // though nothing changed: it is a disclosure of the most sensitive
        // record the platform holds.
        AuditLog::record(
            $request,
            'payroll.payslip.downloaded',
            'payslip',
            (string) $payslip['id'],
            [],
            [],
            ['employee_id' => $payslip['employee_id'], 'period' => $payslip['period']]
        );

        return Response::download($bytes, $renderer->filename($payslip, $employee), 'application/pdf');
    }

    /** @return array<string, mixed> */
    private function requirePayslip(Request $request): array
    {
        $payslip = $this->payslips->find(RouteInput::uuid($request));

        if ($payslip === null) {
            throw HttpException::notFound();
        }

        PayrollAccessPolicy::assertMayView($request->principal(), $payslip);

        return $payslip;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function ofType(array $lines, string $type): array
    {
        return array_values(array_filter(
            $lines,
            static fn (array $line): bool => (string) $line['component_type'] === $type
        ));
    }
}
