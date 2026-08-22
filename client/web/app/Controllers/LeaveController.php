<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;

/**
 * Time off: balances, requests, the shared absence calendar.
 *
 * Nothing here decides anything. Whether a person has the days, whether the
 * dates clash with leave they already booked, how many of those calendar days
 * are actually working days — every one of those questions is answered by the
 * leave service, which owns the policy and the ledger. This controller's job
 * is to ask the right question, and to put the service's answer next to the
 * field it belongs to.
 *
 * The one number the client works out for itself is the count of calendar days
 * between two dates, which is arithmetic rather than policy and is shown as
 * such: the working-day count that the request is finally charged for always
 * comes back from the service.
 */
final class LeaveController extends Controller
{
    /** The statuses offered as filters, in the order they are shown. */
    private const FILTER_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /**
     * How many pages of the directory a name lookup will read.
     *
     * A page render must never turn into an unbounded number of calls, so the
     * search stops here and anything still unresolved is shown as an unnamed
     * record rather than chased.
     */
    private const DIRECTORY_PAGE_LIMIT = 5;

    /** GET /leave */
    public function index(): void
    {
        $employeeId = Session::employeeId();
        $seesOthers = Session::canAny(Permissions::LEAVE_VIEW_TEAM, Permissions::LEAVE_VIEW_ALL);

        $tab = $this->input('tab') === 'others' && $seesOthers ? 'others' : 'mine';
        $status = $this->input('status');
        $status = in_array($status, self::FILTER_STATUSES, true) ? $status : '';

        $query = ['page' => $this->page(), 'per_page' => 20];

        if ($status !== '') {
            $query['status'] = $status;
        }

        // Without an employee_id the service widens the list to everything the
        // caller may see, which is exactly what the second tab wants and
        // exactly what the first tab must not have.
        if ($tab === 'mine' && $employeeId !== null) {
            $query['employee_id'] = $employeeId;
        }

        $response = Api::get('/leave/requests', $query);
        $this->guard($response, '/');

        $records = is_array($response['data']) ? $response['data'] : [];

        $balances = Api::collection('/leave-balances', ['year' => (int) Clock::now()->format('Y')]);

        View::render('leave/index', [
            'pageTitle' => 'Time off',
            'breadcrumbs' => [['Time off', '']],
            'records' => $records,
            'meta' => $response['meta'],
            'balances' => $balances,
            'names' => $this->names($this->idsIn($records, ['employee_id', 'approver_id'])),
            'tab' => $tab,
            'status' => $status,
            'statuses' => self::FILTER_STATUSES,
            'seesOthers' => $seesOthers,
            'employeeId' => $employeeId,
            'today' => Clock::today(),
        ]);
    }

    /**
     * GET /leave/apply
     *
     * The form is arranged as two steps for a reason that is not cosmetic. The
     * leave type and the dates are chosen first and travel in the query
     * string, so the server can come back with the real balance for that type,
     * and can offer the half-day option only when the range is a single day.
     * The reason and the contact number stay in the second step, where they
     * are posted rather than put in a URL — a reason for absence is nobody's
     * business but the employee's and should not end up in a browser history
     * or a proxy log.
     */
    public function showApply(): void
    {
        $selectedType = $this->remembered('leave_type_id');
        $startsOn = $this->rememberedDate('starts_on');
        $endsOn = $this->rememberedDate('ends_on');
        $isHalfDay = $this->remembered('is_half_day') !== '';
        $halfDayPeriod = $this->remembered('half_day_period');

        // The balance year follows the dates being planned, because a request
        // in January is charged against a different year's entitlement.
        $year = $startsOn === '' ? (int) Clock::now()->format('Y') : (int) substr($startsOn, 0, 4);

        $balances = Api::collection('/leave-balances', ['year' => $year]);
        $types = Api::collection('/leave-types');

        $options = $this->mergeTypesWithBalances($types, $balances);
        $selected = $options[$selectedType] ?? null;

        $rangeIsSingleDay = $startsOn !== '' && $startsOn === $endsOn;

        // The half day is dropped whenever the choice above it stops allowing
        // one. Ticking it for a type that permits half days and then switching
        // to a type that does not would otherwise leave the tick in place: the
        // summary would promise half a day, the hidden field would send it,
        // and the service would refuse the request outright.
        if (!$rangeIsSingleDay || ($selected['allows_half_day'] ?? false) !== true) {
            $isHalfDay = false;
            $halfDayPeriod = '';
        }

        $calendarDays = $startsOn !== '' && $endsOn !== '' && $endsOn >= $startsOn
            ? Clock::inclusiveDays($startsOn, $endsOn)
            : 0;

        View::render('leave/apply', [
            'pageTitle' => 'Apply for time off',
            'breadcrumbs' => [['Time off', '/leave'], ['Apply', '']],
            'options' => $options,
            'selected' => $selected,
            'selectedType' => $selectedType,
            'startsOn' => $startsOn,
            'endsOn' => $endsOn,
            'isHalfDay' => $isHalfDay,
            'halfDayPeriod' => $halfDayPeriod,
            'rangeIsSingleDay' => $rangeIsSingleDay,
            'calendarDays' => $calendarDays,
            'year' => $year,
            'documents' => Api::collection('/documents', ['per_page' => 50]),
            'today' => Clock::today(),
            'ready' => $selected !== null && $calendarDays > 0,
        ]);
    }

    /** POST /leave/apply */
    public function apply(): void
    {
        $payload = [
            'leave_type_id' => $this->input('leave_type_id'),
            'starts_on' => $this->input('starts_on'),
            'ends_on' => $this->input('ends_on'),
            'reason' => $this->input('reason'),
        ];

        $contact = $this->input('contact_during_leave');
        if ($contact !== '') {
            $payload['contact_during_leave'] = $contact;
        }

        $document = $this->input('supporting_document_id');
        if ($document !== '') {
            $payload['supporting_document_id'] = $document;
        }

        if ($this->inputBool('is_half_day')) {
            $payload['is_half_day'] = true;
            $payload['half_day_period'] = $this->input('half_day_period');
        }

        $fallback = '/leave/apply?' . http_build_query(array_filter([
            'leave_type_id' => $payload['leave_type_id'],
            'starts_on' => $payload['starts_on'],
            'ends_on' => $payload['ends_on'],
            'is_half_day' => isset($payload['is_half_day']) ? '1' : '',
            'half_day_period' => $payload['half_day_period'] ?? '',
        ], static fn (string $value): bool => $value !== ''));

        // The service accepts a request with no reason at all. The form asks
        // for one anyway, because an approver reading a bare set of dates has
        // nothing to decide on.
        if ($payload['reason'] === '') {
            Flash::error('Please say why you are taking this time off.');
            Flash::withErrors(['reason' => ['A reason helps your approver decide.']]);
            Flash::withInput($payload);
            $this->redirect($fallback);
        }

        $response = Api::post('/leave/requests', $payload);

        if (!$response['ok']) {
            $error = is_array($response['error'] ?? null) ? $response['error'] : [];
            $error['details'] = $this->attachToFields($error);
            $response['error'] = $error;

            $this->backWithErrors($response, $payload, $fallback);
        }

        $record = is_array($response['data']) ? $response['data'] : [];
        $days = (float) ($record['day_count'] ?? 0);

        Flash::success(sprintf(
            'Your request for %s working day%s is with your approver.',
            $this->days($days),
            abs($days - 1.0) < 0.001 ? '' : 's'
        ));

        $this->redirect(isset($record['id']) ? '/leave/' . rawurlencode((string) $record['id']) : '/leave');
    }

    /** GET /leave/{id} */
    public function show(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/leave/requests/' . rawurlencode($id));
        $this->guard($response, '/leave');

        $record = is_array($response['data']) ? $response['data'] : [];
        $approvals = is_array($record['approvals'] ?? null) ? $record['approvals'] : [];

        $people = $this->names(array_merge(
            [
                (string) ($record['employee_id'] ?? ''),
                (string) ($record['approver_id'] ?? ''),
                (string) ($record['decided_by'] ?? ''),
                (string) ($record['cancelled_by'] ?? ''),
            ],
            $this->idsIn($approvals, ['approver_id'])
        ));

        $isMine = Session::employeeId() !== null
            && Session::employeeId() === (string) ($record['employee_id'] ?? '');

        View::render('leave/show', [
            'pageTitle' => 'Leave request',
            'breadcrumbs' => [['Time off', '/leave'], ['Request', '']],
            'record' => $record,
            'names' => $people,
            'timeline' => $this->timeline($record, $approvals, $people),
            'isMine' => $isMine,
            'canCancel' => $isMine
                && Session::can(Permissions::LEAVE_CANCEL_SELF)
                && $this->cancellable($record, Clock::today()),
            'canDecide' => !$isMine
                && Session::can(Permissions::LEAVE_APPROVE)
                && (string) ($record['status'] ?? '') === 'pending',
        ]);
    }

    /** POST /leave/{id}/cancel */
    public function cancel(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::post('/leave/requests/' . rawurlencode($id) . '/cancel');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That request could not be cancelled.'));
            $this->back('/leave');
        }

        Flash::success('That request has been cancelled and the days are back on your balance.');
        $this->redirect('/leave/' . rawurlencode($id));
    }

    /** GET /leave-calendar */
    public function calendar(): void
    {
        $month = $this->input('month');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = Clock::now()->format('Y-m');
        }

        $response = Api::get('/leave/calendar', ['month' => $month]);
        $this->guard($response, '/leave');

        $days = is_array($response['data']) ? $response['data'] : [];

        $entries = [];
        foreach ($days as $onDate => $dayEntries) {
            if (is_array($dayEntries)) {
                $entries[(string) $onDate] = $dayEntries;
            }
        }

        $everyone = [];
        foreach ($entries as $dayEntries) {
            foreach ($dayEntries as $entry) {
                if (is_array($entry) && isset($entry['employee_id'])) {
                    $everyone[] = (string) $entry['employee_id'];
                }
            }
        }

        $cursor = Clock::parse($month . '-01');

        View::render('leave/calendar', [
            'pageTitle' => 'Leave calendar',
            'breadcrumbs' => [['Time off', '/leave'], ['Calendar', '']],
            'month' => $month,
            'monthLabel' => $cursor->format('F Y'),
            'previousMonth' => $cursor->modify('-1 month')->format('Y-m'),
            'nextMonth' => $cursor->modify('+1 month')->format('Y-m'),
            'cells' => $this->monthGrid($month, $entries),
            'entries' => $entries,
            'types' => Api::collection('/leave-types'),
            'names' => $this->names($everyone),
            'scope' => (string) ($response['meta']['scope'] ?? 'team'),
            'employeeId' => Session::employeeId(),
        ]);
    }

    /** GET /leave-balances */
    public function balances(): void
    {
        $seesEveryone = Session::can(Permissions::LEAVE_VIEW_ALL);

        $year = $this->inputInt('year', (int) Clock::now()->format('Y'));
        $year = $year >= 2000 && $year <= 2200 ? $year : (int) Clock::now()->format('Y');

        $query = ['year' => $year];

        $requested = $this->input('employee_id');
        if ($requested !== '' && $seesEveryone) {
            $query['employee_id'] = $requested;
        }

        $response = Api::get('/leave-balances', $query);
        $this->guard($response, '/leave');

        $adjustments = Api::collection('/leave-balances/adjustments', $query);

        $subject = (string) ($response['meta']['employee_id'] ?? (string) Session::employeeId());
        $people = $this->names(array_merge([$subject], $this->idsIn($adjustments, ['adjusted_by'])));

        $isSelf = $subject === (string) Session::employeeId();

        // Falling back to the signed-in person's own name would put their name
        // on somebody else's statement whenever the directory lookup came back
        // short, which is the one mistake this page must not make.
        $subjectName = $people[$subject] ?? ($isSelf ? Session::displayName() : 'This employee');

        $current = (int) Clock::now()->format('Y');

        View::render('leave/balances', [
            'pageTitle' => 'Leave balances',
            'breadcrumbs' => [['Time off', '/leave'], ['Balances', '']],
            'statements' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'adjustments' => $adjustments,
            'names' => $people,
            'subject' => $subject,
            'subjectName' => $subjectName,
            'isSelf' => $isSelf,
            'year' => $year,
            'years' => range($current + 1, $current - 3),
            'seesEveryone' => $seesEveryone,
            'colleagues' => $seesEveryone ? Api::collection('/directory', ['per_page' => 100]) : [],
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The value for a planning field: what was just rejected, else the query.
     *
     * A failed submission returns to the same URL, so both sources are present
     * and the one the person typed most recently has to win.
     */
    private function remembered(string $key): string
    {
        $old = Flash::old($key);

        return $old !== '' ? $old : $this->input($key);
    }

    private function rememberedDate(string $key): string
    {
        $value = $this->remembered($key);

        return self::isDate($value) ? $value : '';
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * One row per leave type carrying both its policy and this year's balance.
     *
     * @param list<array<string, mixed>> $types
     * @param list<array<string, mixed>> $balances
     * @return array<string, array<string, mixed>> Keyed by leave type id.
     */
    private function mergeTypesWithBalances(array $types, array $balances): array
    {
        $merged = [];

        foreach ($balances as $balance) {
            if (!is_array($balance) || !isset($balance['leave_type_id'])) {
                continue;
            }

            $merged[(string) $balance['leave_type_id']] = $balance;
        }

        foreach ($types as $type) {
            if (!is_array($type) || !isset($type['id'])) {
                continue;
            }

            $id = (string) $type['id'];

            $merged[$id] = ($merged[$id] ?? [
                'leave_type_id' => $id,
                'leave_type_name' => $type['name'] ?? '',
                'leave_type_code' => $type['code'] ?? '',
                'colour' => $type['colour'] ?? null,
                'available_days' => 0.0,
                'annual_quota_days' => $type['annual_quota_days'] ?? 0,
            ]) + [
                'is_paid' => $type['is_paid'] ?? true,
                'allows_half_day' => $type['allows_half_day'] ?? false,
                'min_notice_days' => $type['min_notice_days'] ?? 0,
                'max_consecutive_days' => $type['max_consecutive_days'] ?? null,
                'requires_document_after_days' => $type['requires_document_after_days'] ?? null,
                'category' => $type['category'] ?? '',
            ];
        }

        // Types that have been retired still hold a balance row, but they can
        // no longer be applied for, so they are not offered.
        $offered = [];
        foreach ($types as $type) {
            if (is_array($type) && isset($type['id']) && isset($merged[(string) $type['id']])) {
                $offered[(string) $type['id']] = $merged[(string) $type['id']];
            }
        }

        return $offered;
    }

    /**
     * Turns the service's rejection into a message beside the guilty field.
     *
     * The leave service answers an overlap or a shortfall with one clear
     * sentence and a machine-readable detail block. The sentence is worth far
     * more attached to the date or the type it is about than floating at the
     * top of the page, so the detail keys are used to place it.
     *
     * @param array<string, mixed> $error
     * @return array<string, mixed>
     */
    private function attachToFields(array $error): array
    {
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $message = (string) ($error['message'] ?? 'That request could not be filed.');

        $placed = [];

        if (isset($details['conflicting_request_id'])) {
            $placed['starts_on'] = [$message];
            $placed['ends_on'] = [$message];
        }

        if (isset($details['available_days'])) {
            $placed['leave_type_id'] = [$message];
        }

        if (isset($details['earliest_start'])) {
            $placed['starts_on'] = [$message];
        }

        if (isset($details['max_consecutive_days'])) {
            $placed['ends_on'] = [$message];
        }

        if (isset($details['requires_document_after_days'])) {
            $placed['supporting_document_id'] = [$message];
        }

        // Only a list of messages is a field error. Alongside the machine
        // readable keys above, the service returns plain context values under
        // names that collide with form fields — an overlap is reported with
        // the clashing request's own starts_on and ends_on — and those would
        // win the union below, silently discarding the placement just made and
        // leaving the dates unmarked. They are dropped here so that only real
        // messages reach the form.
        $reported = array_filter($details, static fn (mixed $value): bool => is_array($value));

        // A field the validator already complained about keeps its own
        // message; these only fill the gaps.
        return $reported + $placed;
    }

    /** True when this request is still the employee's to withdraw. */
    private function cancellable(array $record, string $today): bool
    {
        $status = (string) ($record['status'] ?? '');

        if ($status === 'pending') {
            return true;
        }

        return $status === 'approved' && (string) ($record['starts_on'] ?? '') > $today;
    }

    /**
     * What happened to this request, in order.
     *
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $approvals
     * @param array<string, string> $names
     * @return list<array{icon: string, colour: string, title: string, who: string, at: ?string, note: ?string, muted: bool}>
     */
    private function timeline(array $record, array $approvals, array $names): array
    {
        $unknown = 'an employee no longer in the directory';

        $items = [[
            'icon' => 'fa-paper-plane',
            'colour' => 'primary',
            'title' => 'Request submitted',
            'who' => $names[(string) ($record['employee_id'] ?? '')] ?? $unknown,
            'at' => isset($record['applied_at']) ? (string) $record['applied_at'] : null,
            'note' => isset($record['reason']) && $record['reason'] !== '' ? (string) $record['reason'] : null,
            'muted' => false,
        ]];

        foreach ($approvals as $approval) {
            if (!is_array($approval)) {
                continue;
            }

            $status = (string) ($approval['status'] ?? 'pending');

            $items[] = [
                'icon' => match ($status) {
                    'approved' => 'fa-check',
                    'rejected' => 'fa-times',
                    'skipped' => 'fa-forward',
                    default => 'fa-hourglass-half',
                },
                'colour' => status_colour($status),
                'title' => sprintf('Level %d %s', (int) ($approval['level'] ?? 1), match ($status) {
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                    'skipped' => 'no longer needed',
                    default => 'waiting for a decision',
                }),
                'who' => $names[(string) ($approval['approver_id'] ?? '')] ?? $unknown,
                'at' => isset($approval['decided_at']) ? (string) $approval['decided_at'] : null,
                'note' => isset($approval['note']) && $approval['note'] !== '' ? (string) $approval['note'] : null,
                'muted' => $status === 'pending' || $status === 'skipped',
            ];
        }

        $status = (string) ($record['status'] ?? '');

        if (($status === 'approved' || $status === 'rejected') && isset($record['decided_at'])) {
            $items[] = [
                'icon' => $status === 'approved' ? 'fa-check-circle' : 'fa-ban',
                'colour' => status_colour($status),
                'title' => $status === 'approved' ? 'Leave approved' : 'Leave rejected',
                'who' => $names[(string) ($record['decided_by'] ?? '')] ?? $unknown,
                'at' => (string) $record['decided_at'],
                'note' => isset($record['decision_note']) && $record['decision_note'] !== ''
                    ? (string) $record['decision_note']
                    : null,
                'muted' => false,
            ];
        }

        if ($status === 'cancelled' && isset($record['cancelled_at'])) {
            $items[] = [
                'icon' => 'fa-undo',
                'colour' => 'secondary',
                'title' => 'Request cancelled',
                'who' => $names[(string) ($record['cancelled_by'] ?? '')] ?? $unknown,
                'at' => (string) $record['cancelled_at'],
                'note' => null,
                'muted' => true,
            ];
        }

        return $items;
    }

    /**
     * The cells of one month, padded to whole weeks starting on Monday.
     *
     * @param array<string, list<array<string, mixed>>> $entries
     * @return list<array{date: string, day: string, outside: bool, today: bool, weekend: bool, entries: list<array<string, mixed>>}>
     */
    private function monthGrid(string $month, array $entries): array
    {
        $first = Clock::parse($month . '-01');
        $today = Clock::today();

        $lead = ((int) $first->format('N')) - 1;
        $cursor = $first->modify(sprintf('-%d days', $lead));

        $total = (int) ceil(($lead + (int) $first->format('t')) / 7) * 7;

        $cells = [];

        for ($index = 0; $index < $total; $index++) {
            $date = $cursor->format('Y-m-d');

            $cells[] = [
                'date' => $date,
                'day' => $cursor->format('j'),
                'outside' => $cursor->format('Y-m') !== $month,
                'today' => $date === $today,
                'weekend' => in_array((int) $cursor->format('N'), [6, 7], true),
                'entries' => $entries[$date] ?? [],
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return $cells;
    }

    /**
     * Every non-empty identifier held under the given keys.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keys
     * @return list<string>
     */
    private function idsIn(array $rows, array $keys): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $row[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return $ids;
    }

    /**
     * Resolves employee identifiers to display names.
     *
     * Every service stores an employee_id and nothing else about a person, so
     * the name has to be fetched from the directory. Pages are read until each
     * wanted identifier is accounted for, which is a single call for an
     * organisation of ordinary size, and the loop is bounded so one page
     * render can never become a hundred requests.
     *
     * @param list<string> $employeeIds
     * @return array<string, string>
     */
    private function names(array $employeeIds): array
    {
        $wanted = array_values(array_unique(array_filter(
            $employeeIds,
            static fn (string $id): bool => $id !== ''
        )));

        if ($wanted === [] || !Session::can(Permissions::DIRECTORY_VIEW)) {
            return [];
        }

        $found = [];

        for ($page = 1; $page <= self::DIRECTORY_PAGE_LIMIT; $page++) {
            $response = Api::get('/directory', ['page' => $page, 'per_page' => 100]);

            if (!$response['ok'] || !is_array($response['data'])) {
                break;
            }

            foreach ($response['data'] as $person) {
                if (is_array($person) && isset($person['id'])) {
                    $found[(string) $person['id']] = trim((string) ($person['full_name'] ?? ''));
                }
            }

            if (array_diff($wanted, array_keys($found)) === []) {
                break;
            }

            if ($page >= (int) ($response['meta']['total_pages'] ?? 1)) {
                break;
            }
        }

        return array_filter(
            array_intersect_key($found, array_flip($wanted)),
            static fn (string $name): bool => $name !== ''
        );
    }

    /** Trims a day count for display: 1.0 reads as "1", 0.5 stays "0.5". */
    private function days(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
