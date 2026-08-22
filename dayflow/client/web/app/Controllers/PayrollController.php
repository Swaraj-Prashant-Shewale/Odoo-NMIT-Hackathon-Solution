<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Env;

/**
 * Payroll, payslips, salary structures and expense claims.
 *
 * This is the most sensitive area of the product and two rules shape every
 * method in it.
 *
 * The self-service screens never name an employee. They call endpoints that
 * resolve the subject from the caller's own token, so there is no identifier
 * on a payroll page for a curious person to change into somebody else's. The
 * only screens that reach across people — the runs and the structure editor —
 * are behind a permission the router checks before this class is entered, and
 * the payroll service checks again for itself.
 *
 * The second rule is that money is never added up in a template. Amounts
 * arrive as integer minor units; every subtotal, every year-to-date figure and
 * every chart value is computed here, and the view only formats what it is
 * handed.
 */
final class PayrollController extends Controller
{
    /** Payslips per page on the personal payroll screen. Twelve is a year. */
    private const PAYSLIPS_PER_PAGE = 12;

    private const RUNS_PER_PAGE = 20;

    /** Page size used whenever a whole collection has to be read. */
    private const PAGE_SIZE = 100;

    /**
     * Ceiling on a bounded walk.
     *
     * Some screens need a set the service cannot narrow for them — claims for
     * one department, totals across every claim. Reading those page by page is
     * fine; reading them without a limit would turn one slow afternoon into an
     * unbounded number of gateway calls, so the walk stops here and says so.
     */
    private const MAX_PAGES = 5;

    private const COMPANY_NAME_DEFAULT = 'Dayflow Technologies Pvt. Ltd.';

    /** @var list<string> Mirrors the categories the payroll service accepts. */
    private const EXPENSE_CATEGORIES = [
        'travel', 'meals', 'accommodation', 'equipment', 'software',
        'training', 'communication', 'client_entertainment', 'medical', 'other',
    ];

    /** @var list<string> */
    private const EXPENSE_STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'reimbursed'];

    /** @var list<string> */
    private const RUN_STATUSES = ['draft', 'processing', 'approved', 'paid', 'cancelled'];

    /** @var array<string, mixed>|null Platform settings, read at most once per request. */
    private ?array $settings = null;

    // -----------------------------------------------------------------------
    // Self-service payroll
    // -----------------------------------------------------------------------

    /** The caller's own payroll: latest payslip, year to date, and the history. */
    public function index(): void
    {
        $seesEveryone = Session::can(Permissions::PAYROLL_VIEW_ALL);
        $employeeId = Session::employeeId();
        $hasOwnPayroll = $employeeId !== null && $employeeId !== '';

        // An account with no person record has no payslips of its own. That is
        // the end of the page for most people, but a finance account in the
        // same position still has a company to run payroll for.
        if (!$hasOwnPayroll && !$seesEveryone) {
            $this->requireEmployeeRecord('/');
        }

        $page = $this->page();
        $payslips = [];
        $meta = [];

        if ($hasOwnPayroll) {
            $listing = Api::get('/payslips', ['page' => $page, 'per_page' => self::PAYSLIPS_PER_PAGE]);
            $this->guard($listing, '/');

            $payslips = $this->labelPeriods($this->rows($listing));
            $meta = $listing['meta'];
        }

        // The year-to-date figures and the trend describe a whole financial
        // year rather than whichever page is on screen. A financial year is at
        // most twelve months and payslips come back newest first, so the first
        // page always contains every statement those figures need.
        $recent = $page === 1 || !$hasOwnPayroll
            ? $payslips
            : $this->labelPeriods(Api::collection('/payslips', ['page' => 1, 'per_page' => self::PAYSLIPS_PER_PAGE]));

        [$yearFrom, $yearTo] = $this->financialYearBounds();

        $yearToDate = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'tax' => 0, 'months' => 0];
        $months = [];

        // Oldest first, so the chart reads left to right the way a year does.
        foreach (array_reverse($recent) as $slip) {
            $period = (string) ($slip['period'] ?? '');

            if ($period === '' || strcmp($period, $yearFrom) < 0 || strcmp($period, $yearTo) > 0) {
                continue;
            }

            $yearToDate['gross'] += (int) ($slip['gross_minor'] ?? 0);
            $yearToDate['deductions'] += (int) ($slip['total_deductions_minor'] ?? 0);
            $yearToDate['net'] += (int) ($slip['net_minor'] ?? 0);
            $yearToDate['tax'] += (int) ($slip['tax_minor'] ?? 0);
            $yearToDate['months']++;

            $months[] = [
                'label' => $this->monthLabel($period, 'M'),
                'net_minor' => (int) ($slip['net_minor'] ?? 0),
            ];
        }

        // Finance and anyone else entrusted with the whole company's pay get
        // the position of the current cycle above their own figures.
        $summary = null;
        if ($seesEveryone) {
            $data = Api::data('/payroll/summary', [], null);
            $summary = is_array($data) ? $data : null;
        }

        View::render('payroll/index', [
            'pageTitle' => 'Payroll',
            'breadcrumbs' => [['Payroll', '']],
            'latest' => $recent[0] ?? null,
            'payslips' => $payslips,
            'meta' => $meta,
            'yearToDate' => $yearToDate,
            'financialYear' => $this->financialYearLabel(),
            'netChart' => [
                'type' => 'bar',
                'labels' => array_map(static fn (array $month): string => $month['label'], $months),
                // charts.js draws the numbers it is given, so the one place
                // minor units become major units is here, never in the view.
                'values' => array_map(
                    static fn (array $month): float => round($month['net_minor'] / 100, 2),
                    $months
                ),
                'format' => 'money',
                'symbol' => $this->currencySymbol(),
            ],
            'summary' => $summary,
        ]);
    }

    /** One payslip, laid out to be read on screen and printed unchanged. */
    public function payslip(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/payslips/' . rawurlencode($id));
        $this->guard($response, '/payroll');

        $payslip = is_array($response['data']) ? $response['data'] : [];

        $earnings = $this->linesOf($payslip, 'earnings');
        $deductions = $this->linesOf($payslip, 'deductions');
        $contributions = $this->linesOf($payslip, 'employer_contributions');

        // The bank block carries the last four digits and nothing else: the
        // payroll service never releases a full account number, to this client
        // or to anybody else. The subject is named so that a finance officer
        // reading somebody else's statement sees that person's account rather
        // than their own; the service refuses the pairing if they may not.
        $subject = (string) ($payslip['employee_id'] ?? '');
        $bank = Api::data('/payroll/bank-account', $subject === '' ? [] : ['employee_id' => $subject], []);

        $period = (string) ($payslip['period'] ?? '');

        View::render('payroll/payslip', [
            'pageTitle' => 'Payslip ' . $this->monthLabel($period, 'F Y'),
            'breadcrumbs' => [['Payroll', '/payroll'], [$this->monthLabel($period, 'F Y'), '']],
            'payslip' => $payslip,
            'employee' => is_array($payslip['employee'] ?? null) ? $payslip['employee'] : [],
            'earnings' => $earnings,
            'deductions' => $deductions,
            'contributions' => $contributions,
            'earningsTotal' => $this->sumLines($earnings),
            'deductionsTotal' => $this->sumLines($deductions),
            'contributionsTotal' => $this->sumLines($contributions),
            'bank' => is_array($bank) ? $bank : [],
            'companyName' => $this->setting('company.name', self::COMPANY_NAME_DEFAULT),
        ]);
    }

    /** Pipes the service's rendered PDF straight through to the browser. */
    public function downloadPayslip(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        if (Api::stream('/payslips/' . rawurlencode($id) . '/download')) {
            return;
        }

        // Emitting half a PDF would leave the browser with a file it cannot
        // open and no explanation, so a failure goes back as a message.
        Flash::error('That payslip could not be prepared for download. Please try again in a moment.');
        $this->back('/payroll');
    }

    /** The caller's own salary structure and the revisions behind it. */
    public function salaryStructure(): void
    {
        $employeeId = $this->requireEmployeeRecord('/payroll');

        $response = Api::get('/salary-structures/' . rawurlencode($employeeId));

        // A person with no structure recorded yet is a normal state during
        // onboarding, not an error worth a 404 page.
        if (!$response['ok'] && $response['status'] !== 404) {
            $this->guard($response, '/payroll');
        }

        $structure = $response['ok'] && is_array($response['data']) ? $response['data'] : null;
        $history = Api::collection('/salary-structures/' . rawurlencode($employeeId) . '/history');

        $components = $structure === null ? [] : $this->priceComponents($structure);
        $earnings = array_values(array_filter(
            $components,
            static fn (array $line): bool => $line['component_type'] === 'earning'
        ));

        View::render('payroll/salary', [
            'pageTitle' => 'Salary structure',
            'breadcrumbs' => [['Payroll', '/payroll'], ['Salary structure', '']],
            'structure' => $structure,
            'components' => $components,
            'revisions' => $this->pairRevisions($history),
            'earningsChart' => [
                'type' => 'donut',
                'labels' => array_map(static fn (array $line): string => $line['component_name'], $earnings),
                'values' => array_map(
                    static fn (array $line): float => round($line['amount_minor'] / 100, 2),
                    $earnings
                ),
                'format' => 'money',
                'symbol' => $this->currencySymbol(),
                'centreLabel' => 'Monthly gross',
                'centreValue' => $structure === null
                    ? '—'
                    : money((int) ($structure['gross_monthly_minor'] ?? 0)),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Payroll runs
    // -----------------------------------------------------------------------

    /** Every payroll cycle the company has opened. */
    public function runs(): void
    {
        $status = $this->input('status');
        $query = ['page' => $this->page(), 'per_page' => self::RUNS_PER_PAGE];

        if (in_array($status, self::RUN_STATUSES, true)) {
            $query['status'] = $status;
        }

        $response = Api::get('/payroll/runs', $query);
        $this->guard($response, '/payroll');

        View::render('payroll/runs', [
            'pageTitle' => 'Payroll runs',
            'breadcrumbs' => [['Payroll', '/payroll'], ['Runs', '']],
            'runs' => $this->labelPeriods($this->rows($response)),
            'meta' => $response['meta'],
            'status' => in_array($status, self::RUN_STATUSES, true) ? $status : '',
            'statuses' => self::RUN_STATUSES,
            'userNames' => $this->userNames(),
            'currentPeriod' => date('Y-m'),
        ]);
    }

    /** One run, its totals and every payslip in it. */
    public function run(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/payroll/runs/' . rawurlencode($id));
        $this->guard($response, '/payroll/runs');

        $run = is_array($response['data']) ? $response['data'] : [];
        $payslips = is_array($run['payslips'] ?? null) ? $run['payslips'] : [];

        $label = (string) ($run['period_label'] ?? $this->monthLabel((string) ($run['period'] ?? ''), 'F Y'));

        View::render('payroll/run', [
            'pageTitle' => $label === '' ? 'Payroll run' : $label . ' payroll',
            'breadcrumbs' => [
                ['Payroll', '/payroll'],
                ['Runs', '/payroll/runs'],
                [$label, ''],
            ],
            'run' => $run,
            'payslips' => $payslips,
            'employees' => $this->employeeNames(),
            'userNames' => $this->userNames(),
            'mayRun' => Session::can(Permissions::PAYROLL_RUN),
        ]);
    }

    /** Opens a draft run for a month. */
    public function createRun(): void
    {
        $payload = ['period' => $this->input('period')];

        foreach (['run_label', 'notes'] as $optional) {
            $value = $this->input($optional);

            if ($value !== '') {
                $payload[$optional] = $value;
            }
        }

        $response = Api::post('/payroll/runs', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/payroll/runs');
        }

        $id = is_array($response['data']) ? (string) ($response['data']['id'] ?? '') : '';

        Flash::success('The run is open as a draft. Process it to calculate the payslips.');
        $this->redirect($id === '' ? '/payroll/runs' : '/payroll/runs/' . rawurlencode($id));
    }

    /** Calculates every payslip in a run. */
    public function processRun(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $response = Api::post('/payroll/runs/' . rawurlencode($id) . '/process');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That run could not be processed.'));
            $this->redirect('/payroll/runs/' . rawurlencode($id));
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $processed = (int) ($data['processed_employees'] ?? 0);
        $skipped = is_array($data['skipped'] ?? null) ? count($data['skipped']) : 0;

        $message = sprintf('%d payslip%s calculated.', $processed, $processed === 1 ? '' : 's');

        if ($skipped > 0) {
            // Someone has to be told, or a person quietly missing from payroll
            // is only discovered on pay day.
            $message .= sprintf(
                ' %d employee%s skipped for want of a salary structure.',
                $skipped,
                $skipped === 1 ? ' was' : 's were'
            );
        }

        Flash::success($message);
        $this->redirect('/payroll/runs/' . rawurlencode($id));
    }

    /** Signs a processed run off for payment. */
    public function approveRun(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $response = Api::post('/payroll/runs/' . rawurlencode($id) . '/approve');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That run could not be approved.'));
            $this->redirect('/payroll/runs/' . rawurlencode($id));
        }

        Flash::success('The run is approved. Publishing it will release the payslips to employees.');
        $this->redirect('/payroll/runs/' . rawurlencode($id));
    }

    /** Releases the payslips in an approved run to the people they belong to. */
    public function publishRun(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $response = Api::post('/payroll/runs/' . rawurlencode($id) . '/publish');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That run could not be published.'));
            $this->redirect('/payroll/runs/' . rawurlencode($id));
        }

        $published = is_array($response['data']) ? (int) ($response['data']['published_payslips'] ?? 0) : 0;

        Flash::success(sprintf(
            '%d payslip%s published. Everyone in this run can now see their statement.',
            $published,
            $published === 1 ? ' was' : 's were'
        ));
        $this->redirect('/payroll/runs/' . rawurlencode($id));
    }

    // -----------------------------------------------------------------------
    // Salary structures
    // -----------------------------------------------------------------------

    /** Find an employee, read their structure, and record a revision. */
    public function structures(): void
    {
        $search = $this->input('search');
        $employeeId = $this->input('employee_id');

        $matches = $search === ''
            ? []
            : Api::collection('/employees', ['search' => $search, 'per_page' => 20]);

        $employee = null;
        $structure = null;
        $revisions = [];
        $components = [];

        if ($employeeId !== '') {
            $record = Api::data('/employees/' . rawurlencode($employeeId), [], null);
            $employee = is_array($record) ? $record : null;

            $current = Api::get('/salary-structures/' . rawurlencode($employeeId));

            if (!$current['ok'] && $current['status'] !== 404) {
                $this->guard($current, '/payroll/structures');
            }

            $structure = $current['ok'] && is_array($current['data']) ? $current['data'] : null;

            if ($structure !== null) {
                $components = $this->priceComponents($structure);
            }

            $revisions = $this->pairRevisions(
                Api::collection('/salary-structures/' . rawurlencode($employeeId) . '/history')
            );
        }

        View::render('payroll/structures', [
            'pageTitle' => 'Salary structures',
            'breadcrumbs' => [['Payroll', '/payroll'], ['Salary structures', '']],
            'search' => $search,
            'matches' => $matches,
            'employeeId' => $employeeId,
            'employee' => $employee,
            'structure' => $structure,
            'components' => $components,
            'revisions' => $revisions,
            'catalogue' => Api::collection('/pay-components', ['active_only' => 'true']),
            'defaults' => $this->formDefaults($structure),
            'currencySymbol' => $this->currencySymbol(),
        ]);
    }

    /** Records a revision, which closes whatever structure is open today. */
    public function storeStructure(): void
    {
        $payload = [
            'employee_id' => $this->input('employee_id'),
            'effective_from' => $this->input('effective_from'),
            'ctc_annual' => $this->input('ctc_annual'),
            'gross_monthly' => $this->input('gross_monthly'),
            'basic_monthly' => $this->input('basic_monthly'),
        ];

        $reason = $this->input('revision_reason');

        if ($reason !== '') {
            $payload['revision_reason'] = $reason;
        }

        $destination = '/payroll/structures?employee_id=' . urlencode($payload['employee_id']);

        $response = Api::post('/salary-structures', $payload + ['lines' => $this->submittedLines()]);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, $destination);
        }

        Flash::success('The revision is recorded. The previous structure was closed the day before it takes effect.');
        $this->redirect($destination);
    }

    // -----------------------------------------------------------------------
    // Expense claims
    // -----------------------------------------------------------------------

    /** The caller's claims, or every claim for whoever settles them. */
    public function expenses(): void
    {
        $seesEveryone = Session::can(Permissions::EXPENSE_VIEW_ALL);

        // Only the personal listing is answered from the caller's own employee
        // record; the company-wide one is scoped by permission and works for a
        // finance account that has no person record of its own.
        if (!$seesEveryone) {
            $this->requireEmployeeRecord('/');
        }

        $query = ['scope' => $seesEveryone ? 'all' : 'own'];

        $status = $this->input('status');
        if (in_array($status, self::EXPENSE_STATUSES, true)) {
            $query['status'] = $status;
        }

        $category = $this->input('category');
        if (in_array($category, self::EXPENSE_CATEGORIES, true)) {
            $query['category'] = $category;
        }

        $search = $this->input('search');
        if ($search !== '') {
            $query['search'] = $search;
        }

        $walk = $this->walk('/expenses', $query, true);
        $claims = $walk['rows'];

        $employees = $seesEveryone ? $this->employeeRecords() : [];
        $departmentId = $seesEveryone ? $this->input('department_id') : '';

        if ($departmentId !== '') {
            // The payroll service filters claims by employee, never by
            // department — it holds no department at all. So the department's
            // members are resolved from the people records and the filter is
            // applied to the claims here.
            $claims = array_values(array_filter(
                $claims,
                static function (array $claim) use ($employees, $departmentId): bool {
                    $employee = $employees[(string) ($claim['employee_id'] ?? '')] ?? null;

                    return is_array($employee)
                        && (string) ($employee['department_id'] ?? '') === $departmentId;
                }
            ));
        }

        View::render('expenses/index', [
            'pageTitle' => 'Expense claims',
            'breadcrumbs' => [['Expenses', '']],
            'claims' => $claims,
            'totals' => $this->summariseClaims($claims),
            'truncated' => $walk['truncated'],
            'seesEveryone' => $seesEveryone,
            'mayReimburse' => Session::can(Permissions::EXPENSE_REIMBURSE),
            'employees' => $employees,
            'departments' => $seesEveryone ? Api::collection('/departments') : [],
            'departmentId' => $departmentId,
            'status' => $query['status'] ?? '',
            'category' => $query['category'] ?? '',
            'search' => $search,
            'categories' => self::EXPENSE_CATEGORIES,
            'statuses' => self::EXPENSE_STATUSES,
        ]);
    }

    /** The claim form, with the approver it will be routed to. */
    public function newExpense(): void
    {
        $employeeId = $this->requireEmployeeRecord('/expenses');

        $employee = Api::data('/employees/' . rawurlencode($employeeId), [], []);
        $employee = is_array($employee) ? $employee : [];

        View::render('expenses/new', [
            'pageTitle' => 'New expense claim',
            'breadcrumbs' => [['Expenses', '/expenses'], ['New claim', '']],
            'categories' => self::EXPENSE_CATEGORIES,
            'approverName' => (string) ($employee['manager_name'] ?? ''),
            'documents' => Api::collection('/documents', ['per_page' => self::PAGE_SIZE]),
            'currencySymbol' => $this->currencySymbol(),
            'today' => date('Y-m-d'),
        ]);
    }

    /** Submits a claim. A claim is only ever filed for the caller. */
    public function storeExpense(): void
    {
        $payload = [
            'category' => $this->input('category'),
            'title' => $this->input('title'),
            'incurred_on' => $this->input('incurred_on'),
            'amount' => $this->input('amount'),
        ];

        foreach (['description', 'receipt_document_id'] as $optional) {
            $value = $this->input($optional);

            if ($value !== '') {
                $payload[$optional] = $value;
            }
        }

        $response = Api::post('/expenses', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/expenses/new');
        }

        $number = is_array($response['data']) ? (string) ($response['data']['claim_number'] ?? '') : '';

        Flash::success($number === ''
            ? 'Your claim has been submitted for approval.'
            : sprintf('Claim %s has been submitted for approval.', $number));

        $this->redirect('/expenses');
    }

    /** Marks an approved claim as paid back, against a payment reference. */
    public function reimburseExpense(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $payload = ['reference' => $this->input('reference')];

        $response = Api::post('/expenses/' . rawurlencode($id) . '/reimburse', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/expenses');
        }

        Flash::success('The claim is marked as reimbursed.');
        $this->back('/expenses');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Stops a payroll page before it asks for records that cannot exist.
     *
     * An account that has not been joined to a person record yet — the state
     * between registering and onboarding finishing — has no payroll of any
     * kind, and every payroll endpoint answers it with a flat refusal. Saying
     * so plainly is better than a permission error the person cannot act on.
     */
    private function requireEmployeeRecord(string $fallback): string
    {
        $employeeId = Session::employeeId();

        if ($employeeId === null || $employeeId === '') {
            Flash::warning('Your account is not linked to an employee record yet, so there is no payroll to show.');
            $this->redirect($fallback);
        }

        return $employeeId;
    }

    /**
     * Reads a paginated collection in bounded pages.
     *
     * @param array<string, string|int> $query
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    private function walk(string $path, array $query = [], bool $essential = false): array
    {
        $rows = [];
        $page = 1;
        $totalPages = 1;

        while ($page <= $totalPages && $page <= self::MAX_PAGES) {
            $response = Api::get($path, $query + ['page' => $page, 'per_page' => self::PAGE_SIZE]);

            if (!$response['ok']) {
                // A decorative walk degrades: a run page missing a name is far
                // better than a run page that will not open at all.
                if ($essential && $page === 1) {
                    $this->guard($response, '/');
                }

                break;
            }

            foreach ($this->rows($response) as $row) {
                $rows[] = $row;
            }

            $totalPages = max(1, (int) ($response['meta']['total_pages'] ?? 1));
            $page++;
        }

        return ['rows' => $rows, 'truncated' => $totalPages > self::MAX_PAGES];
    }

    /**
     * The data element of a response as a list of records.
     *
     * @param array{data: mixed} $response
     * @return list<array<string, mixed>>
     */
    private function rows(array $response): array
    {
        if (!is_array($response['data'] ?? null)) {
            return [];
        }

        return array_values(array_filter($response['data'], 'is_array'));
    }

    /**
     * People records keyed by employee id, for pages that list many of them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function employeeRecords(): array
    {
        $records = [];

        foreach ($this->walk('/employees')['rows'] as $employee) {
            $id = (string) ($employee['id'] ?? '');

            if ($id !== '') {
                $records[$id] = $employee;
            }
        }

        return $records;
    }

    /**
     * Employee id to display name.
     *
     * @return array<string, string>
     */
    private function employeeNames(): array
    {
        $names = [];

        foreach ($this->employeeRecords() as $id => $employee) {
            $name = trim((string) ($employee['full_name'] ?? ''));

            if ($name === '') {
                $name = trim(((string) ($employee['first_name'] ?? '')) . ' ' . ((string) ($employee['last_name'] ?? '')));
            }

            // An entry with no name at all is left out so the page can say the
            // record is unavailable rather than showing an empty cell.
            if ($name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * Account id to display name, for the "processed by" and "approved by"
     * columns, which record accounts rather than people.
     *
     * @return array<string, string>
     */
    private function userNames(): array
    {
        // The identity service only lists accounts for someone entitled to see
        // everybody's profile. Without that the columns simply stay blank.
        if (!Session::can(Permissions::PROFILE_VIEW_ALL)) {
            return [];
        }

        $names = [];

        foreach ($this->walk('/users')['rows'] as $user) {
            $id = (string) ($user['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $name = trim((string) ($user['full_name'] ?? ''));
            $names[$id] = $name === '' ? (string) ($user['email'] ?? $id) : $name;
        }

        return $names;
    }

    /**
     * One group of payslip lines.
     *
     * @param array<string, mixed> $payslip
     * @return list<array<string, mixed>>
     */
    private function linesOf(array $payslip, string $key): array
    {
        $lines = $payslip[$key] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /**
     * Subtotal of a group of payslip lines, in minor units.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function sumLines(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += (int) ($line['amount_minor'] ?? 0);
        }

        return $total;
    }

    /**
     * Works out what each structure line is actually worth in a month.
     *
     * A structure line carries either a fixed amount or a percentage, and the
     * percentage is of the basic or of the monthly cost to company depending
     * on the component. Resolving that here keeps the arithmetic out of the
     * template and matches what the payroll calculator will do when the run
     * is processed.
     *
     * @param array<string, mixed> $structure
     * @return list<array<string, mixed>>
     */
    private function priceComponents(array $structure): array
    {
        $basic = (int) ($structure['basic_monthly_minor'] ?? 0);
        $gross = (int) ($structure['gross_monthly_minor'] ?? 0);
        $monthlyCost = intdiv((int) ($structure['ctc_annual_minor'] ?? 0), 12);

        $lines = is_array($structure['lines'] ?? null) ? $structure['lines'] : [];
        $priced = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $percentage = $line['percentage'] ?? $line['component_percentage'] ?? null;
            $percentage = $percentage === null ? null : (float) $percentage;

            $amount = match ((string) ($line['calculation'] ?? 'fixed')) {
                'percent_of_basic' => (int) round(($basic * (float) $percentage) / 100),
                'percent_of_ctc' => (int) round(($monthlyCost * (float) $percentage) / 100),
                // A slab component is only known once the tax calculation
                // runs, so it has no figure until a payslip is produced.
                'slab' => 0,
                default => (int) ($line['amount_monthly_minor'] ?? 0),
            };

            $priced[] = [
                'component_name' => (string) ($line['component_name'] ?? 'Component'),
                'component_code' => (string) ($line['component_code'] ?? ''),
                'component_type' => (string) ($line['component_type'] ?? 'earning'),
                'calculation' => (string) ($line['calculation'] ?? 'fixed'),
                'percentage' => $percentage,
                'is_statutory' => (bool) ($line['is_statutory'] ?? false),
                'is_taxable' => (bool) ($line['is_taxable'] ?? false),
                'amount_minor' => $amount,
                'share' => $gross > 0 ? percent(($amount / $gross) * 100) : 0,
            ];
        }

        return $priced;
    }

    /**
     * Pairs each revision with the one it replaced, for the history timeline.
     *
     * The service returns revisions newest first, so the structure a revision
     * superseded is the next entry along.
     *
     * @param list<array<string, mixed>> $history
     * @return list<array{structure: array<string, mixed>, previous: array<string, mixed>|null}>
     */
    private function pairRevisions(array $history): array
    {
        $revisions = [];
        $count = count($history);

        for ($index = 0; $index < $count; $index++) {
            $revisions[] = [
                'structure' => $history[$index],
                'previous' => $history[$index + 1] ?? null,
            ];
        }

        return $revisions;
    }

    /**
     * Starting values for the revision form, as the amounts a form accepts.
     *
     * The payroll service takes decimal amounts and converts them itself, so
     * the one conversion out of minor units happens here. A template asked to
     * divide by a hundred would eventually be asked to add up as well.
     *
     * @param array<string, mixed>|null $structure
     * @return array{
     *     ctc_annual: string,
     *     gross_monthly: string,
     *     basic_monthly: string,
     *     lines: array<string, array{amount: string, percentage: string}>
     * }
     */
    private function formDefaults(?array $structure): array
    {
        // A first structure starts from a blank form rather than from zeroes,
        // which would have to be cleared before anything could be typed.
        if ($structure === null) {
            return ['ctc_annual' => '', 'gross_monthly' => '', 'basic_monthly' => '', 'lines' => []];
        }

        $amount = static fn (mixed $minor): string => number_format(((int) $minor) / 100, 2, '.', '');

        $defaults = [
            'ctc_annual' => $amount($structure['ctc_annual_minor'] ?? 0),
            'gross_monthly' => $amount($structure['gross_monthly_minor'] ?? 0),
            'basic_monthly' => $amount($structure['basic_monthly_minor'] ?? 0),
            'lines' => [],
        ];

        $lines = is_array($structure['lines'] ?? null) ? $structure['lines'] : [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $componentId = (string) ($line['pay_component_id'] ?? '');

            if ($componentId === '') {
                continue;
            }

            $percentage = $line['percentage'] ?? null;

            $defaults['lines'][$componentId] = [
                'amount' => $amount($line['amount_monthly_minor'] ?? 0),
                'percentage' => $percentage === null ? '' : rtrim(rtrim(number_format((float) $percentage, 3, '.', ''), '0'), '.'),
            ];
        }

        return $defaults;
    }

    /**
     * The component rows submitted by the structure form.
     *
     * Each component is keyed by its own identifier rather than by a row
     * number, so a form that renders the catalogue in a different order still
     * submits the same structure.
     *
     * @return list<array<string, string>>
     */
    private function submittedLines(): array
    {
        $submitted = $_POST['lines'] ?? [];

        if (!is_array($submitted)) {
            return [];
        }

        $lines = [];

        foreach ($submitted as $componentId => $row) {
            if (!is_array($row) || !isset($row['include'])) {
                continue;
            }

            $line = ['pay_component_id' => trim((string) $componentId)];

            foreach (['amount_monthly', 'percentage'] as $field) {
                $value = $row[$field] ?? '';
                $value = is_scalar($value) ? trim((string) $value) : '';

                if ($value !== '') {
                    $line[$field] = $value;
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Claim totals by state, in minor units.
     *
     * @param list<array<string, mixed>> $claims
     * @return array{submitted: int, approved: int, reimbursed: int, pending: int, count: int}
     */
    private function summariseClaims(array $claims): array
    {
        $totals = ['submitted' => 0, 'approved' => 0, 'reimbursed' => 0, 'pending' => 0, 'count' => 0];

        foreach ($claims as $claim) {
            $amount = (int) ($claim['amount_minor'] ?? 0);
            $status = (string) ($claim['status'] ?? '');

            $totals['count']++;
            $totals['submitted'] += $amount;

            if ($status === 'approved') {
                $totals['approved'] += $amount;
            }

            if ($status === 'reimbursed') {
                $totals['reimbursed'] += $amount;
            }

            if ($status === 'submitted') {
                $totals['pending'] += $amount;
            }
        }

        return $totals;
    }

    /**
     * The first and last period of the financial year today falls in.
     *
     * @return array{0: string, 1: string} Two YYYY-MM values.
     */
    private function financialYearBounds(): array
    {
        $startMonth = $this->financialYearStartMonth();

        $year = (int) date('Y');

        if ((int) date('n') < $startMonth) {
            $year--;
        }

        $from = sprintf('%04d-%02d', $year, $startMonth);

        return [$from, date('Y-m', (int) strtotime($from . '-01 +11 months'))];
    }

    /** "2026-27", the way a financial year is written. */
    private function financialYearLabel(): string
    {
        $year = (int) substr($this->financialYearBounds()[0], 0, 4);

        return sprintf('%04d-%02d', $year, ($year + 1) % 100);
    }

    private function financialYearStartMonth(): int
    {
        $month = (int) substr($this->setting('company.financial_year_start', '04-01'), 0, 2);

        return ($month >= 1 && $month <= 12) ? $month : 4;
    }

    /**
     * Adds a readable period to each row.
     *
     * A period is a month, and rendering "2026-04" as "1 Apr 2026" would
     * invent a day that means nothing on a monthly statement.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function labelPeriods(array $rows): array
    {
        return array_map(
            fn (array $row): array => $row + [
                'period_label' => $this->monthLabel((string) ($row['period'] ?? ''), 'F Y') ?: '—',
            ],
            $rows
        );
    }

    /** Formats a YYYY-MM period for a heading or an axis. */
    private function monthLabel(string $period, string $format): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return $period;
        }

        $timestamp = strtotime($period . '-01');

        return $timestamp === false ? $period : date($format, $timestamp);
    }

    private function currencySymbol(): string
    {
        return Env::get('CURRENCY_SYMBOL', '₹');
    }

    /**
     * A company-wide setting, read from the identity service once per request.
     *
     * Every page in this area wants at most two of them, and a page that
     * cannot read the settings still has to render, so a missing value falls
     * back rather than failing.
     */
    private function setting(string $key, string $default): string
    {
        if ($this->settings === null) {
            $data = Api::data('/settings', [], []);
            $values = is_array($data) && is_array($data['settings'] ?? null) ? $data['settings'] : [];

            $this->settings = $values;
        }

        $value = $this->settings[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
