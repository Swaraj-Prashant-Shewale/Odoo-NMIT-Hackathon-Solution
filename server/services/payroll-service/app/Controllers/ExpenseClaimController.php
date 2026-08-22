<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ExpenseClaims;
use App\Policies\ExpenseAccessPolicy;
use App\Services\ApproverResolver;
use App\Services\EmployeeDirectory;
use App\Services\Money;
use App\Services\RouteInput;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/** Employee expense claims, from submission through to reimbursement. */
final class ExpenseClaimController
{
    private const CATEGORIES = 'travel,meals,accommodation,equipment,software,training,communication,client_entertainment,medical,other';

    private const STATUSES = 'draft,submitted,approved,rejected,reimbursed';

    /** A single claim above this needs to go through finance, not an expense form. */
    private const MAXIMUM_CLAIM_MINOR = 100_000_000;

    private ExpenseClaims $claims;

    public function __construct()
    {
        $this->claims = new ExpenseClaims();
    }

    /**
     * Scoped listing.
     *
     * Finance and HR see everything; everybody else sees their own claims.
     * An approver can ask for "approvals" to work the queue that was routed
     * to them, which is a different set of records from their own spending
     * and so is a separate listing rather than a widened one.
     */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'scope' => 'nullable|in:all,own,approvals',
            'status' => 'nullable|in:' . self::STATUSES,
            'category' => 'nullable|in:' . self::CATEGORIES,
            'employee_id' => 'nullable|uuid',
            'search' => 'nullable|safe_text|max:120',
        ])->validated();

        $principal = $request->principal();
        $seesEveryone = ExpenseAccessPolicy::seesEveryone($principal);
        $scope = (string) ($filters['scope'] ?? ($seesEveryone ? 'all' : 'own'));

        $builder = $this->claims->query();

        if ($scope === 'all') {
            if (!$seesEveryone) {
                throw HttpException::forbidden('You may only view your own expense claims.');
            }

            if (isset($filters['employee_id'])) {
                $builder->where('employee_id', '=', $filters['employee_id']);
            }
        } elseif ($scope === 'approvals') {
            if (!$principal->can(Permissions::EXPENSE_APPROVE)) {
                throw HttpException::forbidden('You do not approve expense claims.');
            }

            $builder->where('approver_id', '=', $this->callerEmployeeId($principal));
        } else {
            if (isset($filters['employee_id']) && !$principal->owns($filters['employee_id'])) {
                throw HttpException::forbidden('You may only view your own expense claims.');
            }

            $builder->where('employee_id', '=', $this->callerEmployeeId($principal));
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        if (isset($filters['category'])) {
            $builder->where('category', '=', $filters['category']);
        }

        if (isset($filters['search'])) {
            $builder->whereAnyLike(['title', 'claim_number', 'description'], (string) $filters['search']);
        }

        $builder->orderBy('incurred_on', 'desc')->orderBy('created_at', 'desc');

        $page = $this->claims->paginate($builder, $request->page(), $request->perPage());
        $page['meta']['currency'] = Money::currencyCode();

        return Response::page($page);
    }

    public function show(Request $request): Response
    {
        $claim = $this->requireClaim($request);
        ExpenseAccessPolicy::assertMayView($request->principal(), $claim);

        $directory = new EmployeeDirectory($request->bearerToken());

        return Response::ok($claim + [
            'currency_code' => Money::currencyCode(),
            'employee' => $directory->summary((string) $claim['employee_id']),
            'approver' => $claim['approver_id'] === null
                ? null
                : $directory->summary((string) $claim['approver_id']),
        ]);
    }

    /** Submits a claim for the caller. Claims are never filed on somebody else's behalf. */
    public function store(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = $principal->employeeId;

        if ($employeeId === null || $employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        $data = Validator::make($request->all(), [
            'category' => 'required|in:' . self::CATEGORIES,
            'title' => 'required|safe_text|max:160',
            'description' => 'nullable|safe_text|max:2000',
            'incurred_on' => 'required|date|before_or_equal:today',
            'amount' => 'required|money|min:1',
            'currency' => 'nullable|string|min:3|max:3',
            'receipt_document_id' => 'nullable|uuid',
        ])->validated();

        if ((int) $data['amount'] > self::MAXIMUM_CLAIM_MINOR) {
            throw HttpException::unprocessable('A single expense claim cannot exceed ' . Money::forDocument(self::MAXIMUM_CLAIM_MINOR) . '.');
        }

        $approverId = (new ApproverResolver($request->bearerToken()))->forEmployee($principal, $employeeId);

        $claim = $this->claims->create([
            'employee_id' => $employeeId,
            'claim_number' => $this->claims->nextClaimNumber(),
            'category' => $data['category'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'incurred_on' => $data['incurred_on'],
            'amount_minor' => $data['amount'],
            'currency' => strtoupper((string) ($data['currency'] ?? Money::currencyCode())),
            'receipt_document_id' => $data['receipt_document_id'] ?? null,
            'status' => 'submitted',
            'approver_id' => $approverId,
        ]);

        AuditLog::record($request, 'payroll.expense.submitted', 'expense_claim', (string) $claim['id'], [], $claim);

        return Response::created($claim);
    }

    /** Approves or rejects a claim. */
    public function decide(Request $request): Response
    {
        $claim = $this->requireClaim($request);
        $principal = $request->principal();

        ExpenseAccessPolicy::assertMayDecide($principal, $claim);

        if ((string) $claim['status'] !== 'submitted') {
            throw HttpException::conflict(
                'Only a submitted claim can be decided.',
                ['status' => $claim['status']]
            );
        }

        $data = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected',
            'note' => 'nullable|safe_text|max:1000',
        ])->validated();

        if ($data['decision'] === 'rejected' && ($data['note'] ?? null) === null) {
            throw HttpException::unprocessable('A rejection needs a reason so the claimant knows what to correct.');
        }

        // The status was read a moment ago without a lock, so it is read again
        // under one before anything is written: two approvers acting together
        // must not both be told they decided the claim.
        $updated = Connection::transaction(function () use ($claim, $data, $principal): array {
            $current = $this->lockClaim((string) $claim['id']);

            $this->assertStatus($current, 'submitted', 'Only a submitted claim can be decided.');

            return $this->claims->update((string) $current['id'], [
                'status' => $data['decision'],
                'decided_by' => $principal->userId,
                'decided_at' => Clock::iso(),
                'decision_note' => $data['note'] ?? null,
            ]) ?? $current;
        });

        AuditLog::record($request, 'payroll.expense.decided', 'expense_claim', (string) $claim['id'], $claim, $updated);

        EventPublisher::publish('payroll.expense.decided', [
            'employee_id' => (string) $claim['employee_id'],
            'claim_id' => (string) $claim['id'],
            'status' => (string) $data['decision'],
        ]);

        return Response::ok($updated);
    }

    /** Marks an approved claim as paid back. */
    public function reimburse(Request $request): Response
    {
        $claim = $this->requireClaim($request);
        $principal = $request->principal();

        // Releasing the money is the second half of the same decision, so the
        // same separation of duties applies: holding expense.reimburse must
        // not let somebody pay their own claim out to themselves.
        if ($principal->owns($claim['employee_id'] ?? null)) {
            throw HttpException::forbidden('You cannot reimburse your own expense claim.');
        }

        if ((string) $claim['status'] !== 'approved') {
            throw HttpException::conflict(
                'Only an approved claim can be reimbursed.',
                ['status' => $claim['status']]
            );
        }

        $data = Validator::make($request->all(), [
            'reference' => 'required|safe_text',
        ])->validated();

        $reference = trim((string) $data['reference']);

        // A payment reference is frequently all digits, so its length is
        // checked directly rather than through the numeric-aware max rule.
        if (preg_match('/^[A-Za-z0-9\/-]{4,64}$/', $reference) !== 1) {
            throw HttpException::unprocessable('A payment reference must be 4 to 64 letters, digits, slashes or hyphens.');
        }

        // Under a lock, so a claim cannot be paid twice against two references
        // by two finance users pressing the button at the same moment.
        $updated = Connection::transaction(function () use ($claim, $reference): array {
            $current = $this->lockClaim((string) $claim['id']);

            $this->assertStatus($current, 'approved', 'Only an approved claim can be reimbursed.');

            return $this->claims->update((string) $current['id'], [
                'status' => 'reimbursed',
                'reimbursed_at' => Clock::iso(),
                'reimbursed_reference' => $reference,
            ]) ?? $current;
        });

        AuditLog::record($request, 'payroll.expense.reimbursed', 'expense_claim', (string) $claim['id'], $claim, $updated);

        EventPublisher::publish('payroll.expense.decided', [
            'employee_id' => (string) $claim['employee_id'],
            'claim_id' => (string) $claim['id'],
            'status' => 'reimbursed',
        ]);

        return Response::ok($updated);
    }

    private function callerEmployeeId(Principal $principal): string
    {
        $employeeId = (string) $principal->employeeId;

        if ($employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        return $employeeId;
    }

    /** @return array<string, mixed> */
    private function requireClaim(Request $request): array
    {
        $claim = $this->claims->find(RouteInput::uuid($request));

        if ($claim === null) {
            throw HttpException::notFound('That expense claim does not exist.');
        }

        return $claim;
    }

    /**
     * Re-reads a claim under a row lock held for the rest of the transaction.
     *
     * @return array<string, mixed>
     */
    private function lockClaim(string $id): array
    {
        $claim = $this->claims->lockForUpdate($id);

        if ($claim === null) {
            throw HttpException::notFound('That expense claim does not exist.');
        }

        return $claim;
    }

    /**
     * @param array<string, mixed> $claim
     *
     * @throws HttpException 409 when the claim moved on before the lock was taken.
     */
    private function assertStatus(array $claim, string $expected, string $message): void
    {
        if ((string) $claim['status'] !== $expected) {
            throw HttpException::conflict($message, ['status' => $claim['status']]);
        }
    }
}
