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
 * One queue for everything waiting on this person's decision.
 *
 * Three services own the three kinds of request — leave, attendance
 * corrections and expense claims — and each of them decides for itself whether
 * this particular caller is entitled to sign this particular record. Holding
 * "leave.approve" makes somebody an approver in general; it never makes them
 * the approver of a given request. So the sections below are hidden when the
 * permission is missing, which keeps the page honest, and the decision itself
 * is still refused by the service if it was not theirs to make.
 *
 * The three services also spell their decisions differently: leave takes
 * approve/reject, regularisation takes approve/reject under a different key,
 * and an expense claim takes the finished status. Translating that is this
 * controller's job and is deliberately not pushed into the templates.
 */
final class ApprovalController extends Controller
{
    /** How many pages of the directory a name lookup will read. */
    private const DIRECTORY_PAGE_LIMIT = 5;

    /**
     * How many records each paginated queue asks for.
     *
     * A queue longer than this is read back from the API's own count so the
     * page can say how many are still waiting, rather than quietly showing a
     * truncated list as though it were the whole thing.
     */
    private const QUEUE_PAGE_SIZE = 50;

    /** GET /approvals */
    public function index(): void
    {
        $seesLeave = Session::can(Permissions::LEAVE_APPROVE);
        $seesCorrections = Session::can(Permissions::ATTENDANCE_APPROVE_REGULARISATION);
        $seesExpenses = Session::can(Permissions::EXPENSE_APPROVE);

        $leave = $seesLeave
            ? Api::collection('/leave/pending-approvals')
            : [];

        // "assigned_to_me" is the queue that was routed to this approver,
        // which is not the same set of records as the people who report to
        // them: a correction can be routed elsewhere and still concern a
        // direct report.
        [$corrections, $correctionsTotal] = $seesCorrections
            ? $this->queue('/regularisations', [
                'status' => 'pending',
                'assigned_to_me' => 'true',
            ])
            : [[], 0];

        [$expenses, $expensesTotal] = $seesExpenses
            ? $this->queue('/expenses', [
                'scope' => 'approvals',
                'status' => 'submitted',
            ])
            : [[], 0];

        $people = $this->names(array_merge(
            $this->idsIn($leave, ['employee_id', 'delegated_from']),
            $this->idsIn($corrections, ['employee_id']),
            $this->idsIn($expenses, ['employee_id'])
        ));

        View::render('approvals/index', [
            'pageTitle' => 'Approvals',
            'breadcrumbs' => [['Approvals', '']],
            'leave' => $leave,
            'corrections' => $corrections,
            'expenses' => $expenses,
            'seesLeave' => $seesLeave,
            'seesCorrections' => $seesCorrections,
            'seesExpenses' => $seesExpenses,
            'names' => $people,
            'leaveTotal' => count($leave),
            'correctionsTotal' => $correctionsTotal,
            'expensesTotal' => $expensesTotal,
            'total' => count($leave) + $correctionsTotal + $expensesTotal,
        ]);
    }

    /** POST /approvals/leave/{id} */
    public function decideLeave(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $decision = $this->decision();
        $note = $this->note($decision, 'Say why you are turning this leave down.');

        $response = Api::post('/leave/requests/' . rawurlencode($id) . '/decide', [
            'status' => $decision === 'approve' ? 'approve' : 'reject',
            'note' => $note,
        ]);

        $this->report($response, $decision, 'The leave request has been');
    }

    /** POST /approvals/regularisation/{id} */
    public function decideRegularisation(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $decision = $this->decision();
        $note = $this->note($decision, 'Say why this correction is being turned down.');

        $response = Api::post('/regularisations/' . rawurlencode($id) . '/decide', [
            'decision' => $decision === 'approve' ? 'approve' : 'reject',
            'note' => $note,
        ]);

        $this->report($response, $decision, 'The attendance correction has been');
    }

    /** POST /approvals/expense/{id} */
    public function decideExpense(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $decision = $this->decision();
        $note = $this->note($decision, 'Say why this claim is being turned down.');

        // Payroll records the finished status rather than the verb, so the
        // same two buttons have to be spelled differently here.
        $response = Api::post('/expenses/' . rawurlencode($id) . '/decide', [
            'decision' => $decision === 'approve' ? 'approved' : 'rejected',
            'note' => $note,
        ]);

        $this->report($response, $decision, 'The expense claim has been');
    }

    /** GET /approvals/delegations */
    public function delegations(): void
    {
        $response = Api::get('/approvals/delegations');
        $this->guard($response, '/approvals');

        $records = is_array($response['data']) ? $response['data'] : [];

        $self = (string) Session::employeeId();
        $today = Clock::today();

        View::render('approvals/delegations', [
            'pageTitle' => 'Delegate my approvals',
            'breadcrumbs' => [['Approvals', '/approvals'], ['Delegation', '']],
            'delegations' => $records,
            'names' => $this->names($this->idsIn($records, ['delegator_id', 'delegate_id'])),
            'colleagues' => array_values(array_filter(
                Api::collection('/directory', ['per_page' => 100]),
                static fn (mixed $person): bool => is_array($person)
                    && ($person['id'] ?? '') !== ''
                    && (string) $person['id'] !== $self
            )),
            'self' => $self,
            'today' => $today,
            'canDelegate' => Session::can(Permissions::LEAVE_APPROVE),
        ]);
    }

    /** POST /approvals/delegations */
    public function storeDelegation(): void
    {
        $payload = [
            'delegate_id' => $this->input('delegate_id'),
            'starts_on' => $this->input('starts_on'),
            'ends_on' => $this->input('ends_on'),
        ];

        $reason = $this->input('reason');
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        $response = Api::post('/approvals/delegations', $payload);

        if (!$response['ok']) {
            /** @var array<string, mixed> $error */
            $error = is_array($response['error'] ?? null) ? $response['error'] : [];
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];

            // An overlap is refused with the identifier of the arrangement that
            // clashes; the sentence belongs against the dates that caused it.
            if (isset($details['conflicting_delegation_id'])) {
                $message = (string) ($error['message'] ?? 'Those dates clash with another delegation.');
                $details += ['starts_on' => [$message], 'ends_on' => [$message]];
            }

            $error['details'] = $details;
            $response['error'] = $error;

            $this->backWithErrors($response, $payload, '/approvals/delegations');
        }

        Flash::success('Your approvals will be handled by your delegate for that period.');
        $this->redirect('/approvals/delegations');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Reads which of the two buttons was pressed.
     *
     * Neither answer is a safe default. Assuming "approve" would sign something
     * nobody agreed to, and assuming "reject" would refuse somebody's leave
     * because a form arrived oddly, so an unrecognised value is refused
     * outright rather than guessed at.
     */
    private function decision(): string
    {
        $decision = $this->input('decision');

        if ($decision !== 'approve' && $decision !== 'reject') {
            Flash::error('Choose whether to approve or reject before submitting.');
            $this->back('/approvals');
        }

        return $decision;
    }

    /**
     * The note attached to a decision, insisting on one for a refusal.
     *
     * Two of the three services enforce this as well. Asking here means the
     * approver is told before the round trip rather than after it, and the
     * person whose request was turned down always learns why.
     */
    private function note(string $decision, string $complaint): ?string
    {
        $note = $this->input('note');

        if ($decision === 'reject' && $note === '') {
            Flash::error($complaint);
            $this->back('/approvals');
        }

        return $note === '' ? null : $note;
    }

    /**
     * Reports the outcome and returns the approver to their queue.
     *
     * @param array{ok: bool, status: int, error: ?array} $response
     */
    private function report(array $response, string $decision, string $subject): never
    {
        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That decision could not be recorded.'));
            $this->back('/approvals');
        }

        Flash::success($subject . ' ' . ($decision === 'approve' ? 'approved' : 'rejected') . '.');
        $this->back('/approvals');
    }

    /**
     * One page of a paginated queue, with the number actually waiting.
     *
     * The count comes back from the API rather than from the rows on hand, so
     * an approver with more than a page of work is told there is more instead
     * of being shown a truncated list that looks complete.
     *
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function queue(string $path, array $filters): array
    {
        $response = Api::get($path, $filters + ['per_page' => self::QUEUE_PAGE_SIZE]);

        $rows = $response['ok'] && is_array($response['data']) ? $response['data'] : [];
        $total = (int) ($response['meta']['total'] ?? count($rows));

        return [$rows, max($total, count($rows))];
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
     * A queue row carries an employee_id and nothing else about the person, so
     * the name comes from the directory. The read is bounded: after this many
     * pages anything still unresolved is shown without a name rather than
     * chased across an unbounded number of calls.
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
}
