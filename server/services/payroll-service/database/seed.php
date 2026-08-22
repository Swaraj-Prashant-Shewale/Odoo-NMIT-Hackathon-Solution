<?php

declare(strict_types=1);

/**
 * Payroll reference and demonstration data.
 *
 * Runs on every boot, so every insert is guarded by a check first. Reference
 * data (the component catalogue and the tax slabs) is always present; the
 * sample salary structures, payroll runs, bank details and expense claims are
 * only written when SEED_DEMO_DATA is on.
 *
 * Employee identifiers are copied verbatim from the platform's fixed seed
 * identifiers so payroll, attendance and leave all describe the same people.
 */

use App\Models\BankAccounts;
use App\Models\ExpenseClaims;
use App\Models\PayComponents;
use App\Models\PayrollRuns;
use App\Models\PayslipLines;
use App\Models\Payslips;
use App\Models\SalaryStructureLines;
use App\Models\SalaryStructures;
use App\Models\TaxSlabs;
use App\Services\CompanyCalendar;
use App\Services\PayslipCalculator;
use App\Services\Period;
use App\Services\TaxCalculator;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Security\Encryptor;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

// ---------------------------------------------------------------------------
// Reference data: the pay component catalogue
// ---------------------------------------------------------------------------

$payComponents = new PayComponents();

$componentCatalogue = [
    [
        'name' => 'Basic', 'code' => 'basic',
        'component_type' => 'earning', 'calculation' => 'percent_of_ctc', 'percentage' => 40.000,
        'is_taxable' => true, 'is_statutory' => false, 'display_order' => 10,
    ],
    [
        'name' => 'House Rent Allowance', 'code' => 'hra',
        'component_type' => 'earning', 'calculation' => 'percent_of_basic', 'percentage' => 50.000,
        'is_taxable' => true, 'is_statutory' => false, 'display_order' => 20,
    ],
    [
        'name' => 'Conveyance Allowance', 'code' => 'conveyance-allowance',
        'component_type' => 'earning', 'calculation' => 'fixed', 'percentage' => null,
        'is_taxable' => true, 'is_statutory' => false, 'display_order' => 30,
    ],
    [
        'name' => 'Special Allowance', 'code' => 'special-allowance',
        'component_type' => 'earning', 'calculation' => 'fixed', 'percentage' => null,
        'is_taxable' => true, 'is_statutory' => false, 'display_order' => 40,
    ],
    [
        'name' => 'Performance Bonus', 'code' => 'performance-bonus',
        'component_type' => 'earning', 'calculation' => 'fixed', 'percentage' => null,
        'is_taxable' => true, 'is_statutory' => false, 'display_order' => 50,
    ],
    [
        'name' => 'Provident Fund', 'code' => 'provident-fund',
        'component_type' => 'deduction', 'calculation' => 'percent_of_basic', 'percentage' => 12.000,
        'is_taxable' => false, 'is_statutory' => true, 'display_order' => 60,
    ],
    [
        'name' => 'Professional Tax', 'code' => 'professional-tax',
        'component_type' => 'deduction', 'calculation' => 'fixed', 'percentage' => null,
        'is_taxable' => false, 'is_statutory' => true, 'display_order' => 70,
    ],
    [
        'name' => 'Income Tax', 'code' => 'income-tax',
        'component_type' => 'deduction', 'calculation' => 'slab', 'percentage' => null,
        'is_taxable' => false, 'is_statutory' => true, 'display_order' => 80,
    ],
    [
        'name' => 'Health Insurance', 'code' => 'health-insurance',
        'component_type' => 'deduction', 'calculation' => 'fixed', 'percentage' => null,
        'is_taxable' => false, 'is_statutory' => false, 'display_order' => 90,
    ],
    [
        'name' => 'Employer PF', 'code' => 'employer-provident-fund',
        'component_type' => 'employer_contribution', 'calculation' => 'percent_of_basic', 'percentage' => 12.000,
        'is_taxable' => false, 'is_statutory' => true, 'display_order' => 100,
    ],
];

foreach ($componentCatalogue as $definition) {
    if ($payComponents->findByCode($definition['code']) !== null) {
        continue;
    }

    $payComponents->create($definition + ['is_active' => true]);
}

$componentsByCode = [];
foreach ($payComponents->ordered() as $component) {
    $componentsByCode[(string) $component['code']] = $component;
}

// ---------------------------------------------------------------------------
// Reference data: income tax slabs, new regime, financial year 2026-27
//
// Surcharge is carried on the band it applies to, which is why the 30% rate
// appears four times: the rate does not change above 24,00,000 but the
// surcharge does.
// ---------------------------------------------------------------------------

$taxSlabs = new TaxSlabs();
$financialYear = '2026-27';

$bands = [
    ['lower' => 0,             'upper' => 40_000_000,    'rate' => 0.000,  'surcharge' => 0.000],
    ['lower' => 40_000_000,    'upper' => 80_000_000,    'rate' => 5.000,  'surcharge' => 0.000],
    ['lower' => 80_000_000,    'upper' => 120_000_000,   'rate' => 10.000, 'surcharge' => 0.000],
    ['lower' => 120_000_000,   'upper' => 160_000_000,   'rate' => 15.000, 'surcharge' => 0.000],
    ['lower' => 160_000_000,   'upper' => 200_000_000,   'rate' => 20.000, 'surcharge' => 0.000],
    ['lower' => 200_000_000,   'upper' => 240_000_000,   'rate' => 25.000, 'surcharge' => 0.000],
    ['lower' => 240_000_000,   'upper' => 500_000_000,   'rate' => 30.000, 'surcharge' => 0.000],
    ['lower' => 500_000_000,   'upper' => 1_000_000_000, 'rate' => 30.000, 'surcharge' => 10.000],
    ['lower' => 1_000_000_000, 'upper' => 2_000_000_000, 'rate' => 30.000, 'surcharge' => 15.000],
    ['lower' => 2_000_000_000, 'upper' => null,          'rate' => 30.000, 'surcharge' => 25.000],
];

$existingBands = [];
foreach ($taxSlabs->forYear($financialYear) as $band) {
    $existingBands[(int) $band['lower_minor']] = true;
}

foreach ($bands as $band) {
    if (isset($existingBands[$band['lower']])) {
        continue;
    }

    $taxSlabs->create([
        'id' => Str::uuid(),
        'regime' => 'new',
        'financial_year' => $financialYear,
        'lower_minor' => $band['lower'],
        'upper_minor' => $band['upper'],
        'rate' => $band['rate'],
        'surcharge' => $band['surcharge'],
        'created_at' => Clock::iso(),
    ]);
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demonstration data
// ---------------------------------------------------------------------------

/**
 * Monthly basic pay for each of the twelve seeded people, in minor units.
 *
 * Everything else is derived from it, which keeps the structures internally
 * consistent: gross is 2.38 times basic and annual CTC is thirty times basic,
 * so Basic really is 40% of monthly CTC and CTC really is gross plus the
 * employer's provident fund contribution.
 */
$demoBasicMonthly = [
    DemoCohort::employeeId('DF-0001') => 21_000_000, // Akshat Panicker, Chief Executive Officer
    DemoCohort::employeeId('DF-0002') => 13_450_000, // Meera Iyer, Head of People
    DemoCohort::employeeId('DF-0003') => 5_670_000,  // Rahul Deshmukh, HR Business Partner
    DemoCohort::employeeId('DF-0004') => 10_925_000, // Sneha Kulkarni, Finance Manager
    DemoCohort::employeeId('DF-0005') => 14_290_000, // Arjun Nair, Engineering Manager
    DemoCohort::employeeId('DF-0006') => 10_500_000, // Priya Sharma, Senior Software Engineer
    DemoCohort::employeeId('DF-0007') => 6_935_000,  // Vikram Reddy, Software Engineer
    DemoCohort::employeeId('DF-0008') => 5_040_000,  // Ananya Bose, QA Engineer
    DemoCohort::employeeId('DF-0009') => 11_765_000, // Karthik Menon, Regional Sales Head
    DemoCohort::employeeId('DF-0010') => 5_460_000,  // Divya Raghavan, Account Executive
    DemoCohort::employeeId('DF-0011') => 4_620_000,  // Imran Qureshi, Content Strategist
    DemoCohort::employeeId('DF-0012') => 6_090_000,  // Neha Joshi, Product Designer
];

// The rest of the company. Their annual cost to company is part of the shared
// roster - it belongs with the job title, not in a table of UUIDs here - and
// the same 40%-of-CTC relationship as above turns it into monthly basic pay.
foreach (DemoCohort::extended() as $person) {
    // Nobody is paid after they leave.
    if ($person['exit_date'] !== null) {
        continue;
    }

    $demoBasicMonthly[$person['employee_id']] = intdiv((int) $person['ctc_annual'] * 100, 30);
}

// Accounts that act on payroll in the demo data. These are identity user ids,
// because processing and approving are things an account does, not a person.
$actorFinance = 'cc6201b4-274b-4599-9a97-42b368cedd53';      // Sneha Kulkarni
$actorExecutive = '8044f8e4-46c5-442a-bfcb-ae491dcc9ded';    // Akshat Panicker
$actorHeadOfPeople = 'b988b84d-bdef-4ef7-9809-a14bd4a07350'; // Meera Iyer
$actorEngineering = '78886f55-a3df-4831-8ea0-36204747eb75';  // Arjun Nair
$actorSales = '7569e1f1-3659-42b9-89d3-bb90ab97c323';        // Karthik Menon

$conveyanceMinor = 160_000;
$professionalTaxMinor = 20_000;
$healthInsuranceMinor = 75_000;

$structures = new SalaryStructures();
$structureLines = new SalaryStructureLines();

// Guarded per employee rather than on the table being empty. An emptiness
// check is only correct the very first time: once twelve structures existed,
// adding forty-eight more people to the roster produced nothing at all for
// them - no salary, no payslip, no line on any payroll report.
$structureExists = Connection::pdo()->prepare(
    'SELECT 1 FROM salary_structures WHERE employee_id = :employee_id LIMIT 1'
);

{
    // Structures start a year before the current month so that every demo
    // payroll run has something in force to calculate against.
    $effectiveFrom = Period::shift(Period::current(), -12) . '-01';

    foreach ($demoBasicMonthly as $employeeId => $basic) {
        $structureExists->execute(['employee_id' => $employeeId]);

        if ($structureExists->fetchColumn() !== false) {
            continue;
        }

        $gross = intdiv($basic * 238, 100);
        $houseRent = intdiv($basic, 2);
        $bonus = intdiv($basic, 10);
        $special = $gross - $basic - $houseRent - $conveyanceMinor - $bonus;

        $structure = $structures->create([
            'employee_id' => $employeeId,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'ctc_annual_minor' => $basic * 30,
            'gross_monthly_minor' => $gross,
            'basic_monthly_minor' => $basic,
            'currency' => 'INR',
            'revision_reason' => 'Annual compensation review',
            'created_by' => $actorFinance,
            'approved_by' => $actorExecutive,
            'approved_at' => Clock::iso(),
        ]);

        $amounts = [
            'basic' => ['amount' => $basic, 'percentage' => 40.000],
            'hra' => ['amount' => $houseRent, 'percentage' => 50.000],
            'conveyance-allowance' => ['amount' => $conveyanceMinor, 'percentage' => null],
            'special-allowance' => ['amount' => $special, 'percentage' => null],
            'performance-bonus' => ['amount' => $bonus, 'percentage' => null],
            'provident-fund' => ['amount' => intdiv($basic * 12, 100), 'percentage' => 12.000],
            'professional-tax' => ['amount' => $professionalTaxMinor, 'percentage' => null],
            'income-tax' => ['amount' => 0, 'percentage' => null],
            'health-insurance' => ['amount' => $healthInsuranceMinor, 'percentage' => null],
            'employer-provident-fund' => ['amount' => intdiv($basic * 12, 100), 'percentage' => 12.000],
        ];

        foreach ($amounts as $code => $line) {
            if (!isset($componentsByCode[$code])) {
                continue;
            }

            $structureLines->create([
                'id' => Str::uuid(),
                'salary_structure_id' => (string) $structure['id'],
                'pay_component_id' => (string) $componentsByCode[$code]['id'],
                'amount_monthly_minor' => $line['amount'],
                'percentage' => $line['percentage'],
                'created_at' => Clock::iso(),
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// Three settled payroll runs for the months leading up to the current one
// ---------------------------------------------------------------------------

$runs = new PayrollRuns();
$payslips = new Payslips();
$payslipLines = new PayslipLines();

// payroll_runs is unique on period, so that is the key to check. Asking
// whether the table is empty meant a roster change never reached payroll.
$runExists = Connection::pdo()->prepare('SELECT * FROM payroll_runs WHERE period = :period LIMIT 1');
$payslipExists = Connection::pdo()->prepare(
    'SELECT 1 FROM payslips WHERE payroll_run_id = :payroll_run_id AND employee_id = :employee_id LIMIT 1'
);

{
    $calculator = new PayslipCalculator(new TaxCalculator($taxSlabs));

    /**
     * Fixed absence and leave for a handful of people, so the demo payslips
     * show pro-rating and loss of pay rather than twelve identical statements.
     * Deliberately not randomised: reseeding must reproduce the same figures.
     */
    $absences = [
        0 => [
            DemoCohort::employeeId('DF-0007') => ['lop' => 1.0, 'leave' => 0.0],
            DemoCohort::employeeId('DF-0006') => ['lop' => 0.0, 'leave' => 2.0],
            DemoCohort::employeeId('DF-0021') => ['lop' => 0.0, 'leave' => 4.0],
            DemoCohort::employeeId('DF-0038') => ['lop' => 1.5, 'leave' => 0.0],
            DemoCohort::employeeId('DF-0049') => ['lop' => 0.0, 'leave' => 2.0],
        ],
        1 => [
            DemoCohort::employeeId('DF-0011') => ['lop' => 0.5, 'leave' => 0.0],
            DemoCohort::employeeId('DF-0008') => ['lop' => 0.0, 'leave' => 3.0],
            DemoCohort::employeeId('DF-0019') => ['lop' => 0.0, 'leave' => 5.0],
            DemoCohort::employeeId('DF-0033') => ['lop' => 1.0, 'leave' => 1.0],
            DemoCohort::employeeId('DF-0053') => ['lop' => 0.0, 'leave' => 2.0],
        ],
        2 => [
            DemoCohort::employeeId('DF-0010') => ['lop' => 0.0, 'leave' => 1.0],
            DemoCohort::employeeId('DF-0012') => ['lop' => 2.0, 'leave' => 0.0],
            DemoCohort::employeeId('DF-0024') => ['lop' => 0.0, 'leave' => 3.0],
            DemoCohort::employeeId('DF-0041') => ['lop' => 0.5, 'leave' => 1.0],
            DemoCohort::employeeId('DF-0057') => ['lop' => 0.0, 'leave' => 2.0],
        ],
    ];

    for ($offset = 3; $offset >= 1; $offset--) {
        $period = Period::shift(Period::current(), -$offset);
        $index = 3 - $offset;

        [, $lastDay] = Period::bounds($period);
        $workingDays = CompanyCalendar::workingDaysIn($period);
        $paidOn = Clock::parse($lastDay)->format('Y-m-d\TH:i:sP');

        $runExists->execute(['period' => $period]);
        $existing = $runExists->fetch();

        if ($existing !== false) {
            // The month has already been run, and payroll_runs is unique on
            // period. Somebody who joined the roster afterwards still needs a
            // payslip for it, so the run is reused and only the missing
            // payslips are filled in below.
            $run = $existing;
        } else {
            $run = $runs->create([
                'period' => $period,
                'run_label' => sprintf('%s payroll', Period::label($period)),
                'status' => 'paid',
                'processed_by' => $actorFinance,
                'processed_at' => $paidOn,
                'approved_by' => $actorExecutive,
                'approved_at' => $paidOn,
                'paid_at' => $paidOn,
                'notes' => 'Processed and disbursed on the last working day of the month.',
            ]);
        }

        $totalGross = 0;
        $totalDeductions = 0;
        $totalNet = 0;
        $headcount = 0;

        foreach (array_keys($demoBasicMonthly) as $employeeId) {
            // payslips is unique on (payroll_run_id, employee_id).
            $payslipExists->execute([
                'payroll_run_id' => (string) $run['id'],
                'employee_id' => $employeeId,
            ]);

            if ($payslipExists->fetchColumn() !== false) {
                continue;
            }

            $structure = $structures->effectiveDuring($employeeId, $period . '-01', $lastDay);

            if ($structure === null) {
                continue;
            }

            $lines = $structureLines->forStructure((string) $structure['id']);

            if ($lines === []) {
                continue;
            }

            $lopDays = (float) ($absences[$index][$employeeId]['lop'] ?? 0.0);
            $leaveDays = (float) ($absences[$index][$employeeId]['leave'] ?? 0.0);

            $attendance = [
                'working_days' => (float) $workingDays,
                'payable_days' => (float) $workingDays - $lopDays,
                'present_days' => (float) $workingDays - $lopDays - $leaveDays,
                'leave_days' => $leaveDays,
                'lop_days' => $lopDays,
            ];

            $result = $calculator->calculate($structure, $lines, $attendance, CompanyCalendar::financialYear($period));

            $payslip = $payslips->create([
                'payroll_run_id' => (string) $run['id'],
                'employee_id' => $employeeId,
                'period' => $period,
                'payable_days' => $attendance['payable_days'],
                'present_days' => $attendance['present_days'],
                'leave_days' => $attendance['leave_days'],
                'lop_days' => $attendance['lop_days'],
                'gross_minor' => $result['gross_minor'],
                'total_deductions_minor' => $result['total_deductions_minor'],
                'net_minor' => $result['net_minor'],
                'tax_minor' => $result['tax_minor'],
                'published_at' => $paidOn,
            ]);

            foreach ($result['lines'] as $line) {
                $payslipLines->create($line + [
                    'payslip_id' => (string) $payslip['id'],
                    'created_at' => Clock::iso(),
                ]);
            }

            $totalGross += $result['gross_minor'];
            $totalDeductions += $result['total_deductions_minor'];
            $totalNet += $result['net_minor'];
            $headcount++;
        }

        // Totalled from the payslips rather than from the counters above, so a
        // run that only had its gaps filled in still reports the whole month.
        $recount = Connection::pdo()->prepare(
            'SELECT COUNT(*) AS employees,
                    COALESCE(SUM(gross_minor), 0) AS gross,
                    COALESCE(SUM(total_deductions_minor), 0) AS deductions,
                    COALESCE(SUM(net_minor), 0) AS net
               FROM payslips WHERE payroll_run_id = :payroll_run_id'
        );
        $recount->execute(['payroll_run_id' => (string) $run['id']]);
        $totals = $recount->fetch() ?: [];

        $runs->update((string) $run['id'], [
            'employee_count' => (int) ($totals['employees'] ?? $headcount),
            'total_gross_minor' => (int) ($totals['gross'] ?? $totalGross),
            'total_deductions_minor' => (int) ($totals['deductions'] ?? $totalDeductions),
            'total_net_minor' => (int) ($totals['net'] ?? $totalNet),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Bank details for four people
// ---------------------------------------------------------------------------

$bankAccounts = new BankAccounts();

$hasBankAccounts = Connection::pdo()->query('SELECT 1 FROM bank_accounts LIMIT 1')->fetchColumn();

if ($hasBankAccounts === false) { // bank details are illustrative, not per-person
    $demoAccounts = [
        [
            'employee_id' => '28010836-0cfb-4b4d-aa20-b0b0f2bedfe3',
            'account_number' => '918010045512307',
            'bank_name' => 'HDFC Bank',
            'ifsc_code' => 'HDFC0000123',
            'account_holder_name' => 'Akshat Panicker',
            'tax_identifier' => 'AKPPN4821J',
        ],
        [
            'employee_id' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
            'account_number' => '502100883417',
            'bank_name' => 'ICICI Bank',
            'ifsc_code' => 'ICIC0004417',
            'account_holder_name' => 'Arjun Nair',
            'tax_identifier' => 'ARJPN7290K',
        ],
        [
            'employee_id' => '5f2fc57a-9d26-4279-bbdf-054496fd35ea',
            'account_number' => '3641902277841',
            'bank_name' => 'State Bank of India',
            'ifsc_code' => 'SBIN0011529',
            'account_holder_name' => 'Priya Sharma',
            'tax_identifier' => 'PRSSH1146C',
        ],
        [
            'employee_id' => '2b5d3904-9f2f-4ae3-9248-88adac38023a',
            'account_number' => '778840021935',
            'bank_name' => 'Axis Bank',
            'ifsc_code' => 'UTIB0002214',
            'account_holder_name' => 'Divya Raghavan',
            'tax_identifier' => 'DVYRG6053M',
        ],
    ];

    foreach ($demoAccounts as $account) {
        $bankAccounts->create([
            'employee_id' => $account['employee_id'],
            'account_number_encrypted' => Encryptor::encrypt($account['account_number']),
            'account_number_blind' => Encryptor::blindIndex($account['account_number']),
            'account_last4' => substr($account['account_number'], -4),
            'bank_name' => $account['bank_name'],
            'ifsc_code' => $account['ifsc_code'],
            'account_holder_name' => $account['account_holder_name'],
            'tax_identifier_encrypted' => Encryptor::encrypt($account['tax_identifier']),
            'tax_identifier_last4' => substr($account['tax_identifier'], -4),
            'verified_at' => Clock::iso(),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Expense claims in mixed states
// ---------------------------------------------------------------------------

$expenseClaims = new ExpenseClaims();

$hasClaims = Connection::pdo()->query('SELECT 1 FROM expense_claims LIMIT 1')->fetchColumn();

if ($hasClaims === false) {
    $daysAgo = static fn (int $days): string => Clock::now()->modify(sprintf('-%d days', $days))->format('Y-m-d');
    $timeAgo = static fn (int $days): string => Clock::now()->modify(sprintf('-%d days', $days))->format('Y-m-d\TH:i:sP');

    $demoClaims = [
        [
            'employee_id' => '5f2fc57a-9d26-4279-bbdf-054496fd35ea', // Priya Sharma
            'category' => 'travel',
            'title' => 'Client visit to Pune, return flights',
            'description' => 'Two-day architecture workshop with the Pune delivery team.',
            'incurred_on' => $daysAgo(6),
            'amount_minor' => 845_000,
            'status' => 'submitted',
            'approver_id' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
        ],
        [
            'employee_id' => 'c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700', // Vikram Reddy
            'category' => 'equipment',
            'title' => 'Replacement mechanical keyboard and monitor arm',
            'description' => 'Approved by IT after the desk assessment.',
            'incurred_on' => $daysAgo(19),
            'amount_minor' => 1_240_000,
            'status' => 'approved',
            'approver_id' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
            'decided_by' => $actorEngineering,
            'decided_at' => $timeAgo(14),
            'decision_note' => 'Within the annual equipment allowance.',
        ],
        [
            'employee_id' => '2b5d3904-9f2f-4ae3-9248-88adac38023a', // Divya Raghavan
            'category' => 'client_entertainment',
            'title' => 'Dinner with the Kotak account team',
            'description' => 'Quarterly review dinner, four attendees.',
            'incurred_on' => $daysAgo(41),
            'amount_minor' => 620_000,
            'status' => 'reimbursed',
            'approver_id' => '4449d500-6fb5-48f3-8b9f-4fac8516da38',
            'decided_by' => $actorSales,
            'decided_at' => $timeAgo(36),
            'decision_note' => 'Approved against the sales entertainment budget.',
            'reimbursed_at' => $timeAgo(29),
            'reimbursed_reference' => 'NEFT-4471902288',
        ],
        [
            'employee_id' => 'a2f82591-b4b7-4de2-99ae-1098b9111ae7', // Imran Qureshi
            'category' => 'training',
            'title' => 'Content strategy certification',
            'description' => 'Six-week online programme with a written assessment.',
            'incurred_on' => $daysAgo(27),
            'amount_minor' => 1_800_000,
            'status' => 'rejected',
            'approver_id' => 'a9f5c390-db56-42fa-96d9-fb1ff57ca041',
            'decided_by' => $actorHeadOfPeople,
            'decided_at' => $timeAgo(22),
            'decision_note' => 'Please resubmit through the learning budget once the new quarter opens.',
        ],
        [
            'employee_id' => '87ae5bec-96ae-4226-92df-519a54dbda64', // Neha Joshi
            'category' => 'software',
            'title' => 'Prototyping tool, annual licence',
            'description' => 'Single seat, renewed for the design team.',
            'incurred_on' => $daysAgo(11),
            'amount_minor' => 149_900,
            'status' => 'submitted',
            'approver_id' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
        ],
        [
            'employee_id' => '5419981a-cd0a-4f65-9ff0-bcd16dd43a91', // Ananya Bose
            'category' => 'meals',
            'title' => 'Team meals during the release weekend',
            'description' => 'Four people, two evenings of the September release.',
            'incurred_on' => $daysAgo(33),
            'amount_minor' => 275_000,
            'status' => 'approved',
            'approver_id' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
            'decided_by' => $actorEngineering,
            'decided_at' => $timeAgo(30),
            'decision_note' => 'Release weekend cover, approved.',
        ],
    ];

    foreach ($demoClaims as $claim) {
        $expenseClaims->create($claim + [
            'claim_number' => $expenseClaims->nextClaimNumber(),
            'currency' => 'INR',
        ]);
    }
}
