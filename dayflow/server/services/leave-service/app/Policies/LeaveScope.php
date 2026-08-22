<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Answers "whose leave may this person see?".
 *
 * A permission says what kind of thing a role may do; it never says which
 * records. Every list and every calendar in this service narrows its query
 * through this class, so the scope rule is written once and cannot drift
 * between endpoints.
 *
 * The reporting line lives in the employee service, so team membership is
 * fetched rather than inferred. A failed lookup narrows the scope instead of
 * widening it: the caller sees less than they might, never more.
 */
final class LeaveScope
{
    /** @var list<string>|null */
    private ?array $directReports = null;

    /** @var list<string>|null */
    private ?array $peers = null;

    public function __construct(private readonly ?string $bearerToken = null)
    {
    }

    /** The caller's own employee record, or a clear refusal when they have none. */
    public static function employeeId(Principal $principal): string
    {
        if ($principal->employeeId === null || $principal->employeeId === '') {
            throw HttpException::forbidden('Your account is not linked to an employee record yet.');
        }

        return $principal->employeeId;
    }

    /**
     * Confirms an employee id taken from a request body names a real person.
     *
     * A privileged caller may act on someone else's balance, but a typo would
     * otherwise open a balance against an identifier belonging to nobody, which
     * then sits in the ledger with no way to reconcile it. The lookup is
     * essential rather than decorative, so a downstream failure is surfaced as
     * a failure instead of being read as "no such employee".
     *
     * @return array<string, mixed> The canonical employee record.
     */
    public function requireEmployee(string $employeeId): array
    {
        $response = ServiceClient::for('employee', $this->bearerToken)->get('/employees/' . rawurlencode($employeeId));
        $employee = $response['data'] ?? null;

        if (!is_array($employee) || !isset($employee['id'])) {
            throw HttpException::unprocessable('That employee record could not be found.');
        }

        return $employee;
    }

    /**
     * The employees a caller may see leave for.
     *
     * Null means no restriction at all, which only HR-level access earns.
     *
     * @return list<string>|null
     */
    public function visibleEmployeeIds(Principal $principal): ?array
    {
        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            return null;
        }

        $self = self::employeeId($principal);

        if ($principal->can(Permissions::LEAVE_VIEW_TEAM)) {
            return array_values(array_unique(array_merge([$self], $this->directReports($principal))));
        }

        return [$self];
    }

    /**
     * The employees whose absences appear on the caller's calendar.
     *
     * An employee sees the people they work alongside, because planning around
     * a colleague's leave is the entire point of a shared calendar. They do
     * not see why anyone is away; the controller strips that.
     *
     * @return list<string>|null
     */
    public function calendarEmployeeIds(Principal $principal): ?array
    {
        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            return null;
        }

        $self = self::employeeId($principal);

        if ($principal->can(Permissions::LEAVE_VIEW_TEAM)) {
            return array_values(array_unique(array_merge([$self], $this->directReports($principal))));
        }

        return array_values(array_unique(array_merge([$self], $this->peers($principal))));
    }

    /** True when the caller may look at one named employee's leave. */
    public function canView(Principal $principal, string $employeeId): bool
    {
        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            return true;
        }

        if ($principal->owns($employeeId)) {
            return true;
        }

        return $principal->can(Permissions::LEAVE_VIEW_TEAM)
            && in_array($employeeId, $this->directReports($principal), true);
    }

    /**
     * Employees reporting directly to the caller.
     *
     * @return list<string>
     */
    public function directReports(Principal $principal): array
    {
        if ($this->directReports !== null) {
            return $this->directReports;
        }

        if ($principal->employeeId === null) {
            return $this->directReports = [];
        }

        return $this->directReports = $this->employeeIdsWhere(['manager_id' => $principal->employeeId]);
    }

    /**
     * Colleagues who report to the same manager as the caller.
     *
     * @return list<string>
     */
    private function peers(Principal $principal): array
    {
        if ($this->peers !== null) {
            return $this->peers;
        }

        if ($principal->managerId === null || $principal->managerId === '') {
            return $this->peers = [];
        }

        return $this->peers = $this->employeeIdsWhere(['manager_id' => $principal->managerId]);
    }

    /**
     * @param array<string, mixed> $query
     * @return list<string>
     */
    private function employeeIdsWhere(array $query): array
    {
        $rows = ServiceClient::for('employee', $this->bearerToken)
            ->tryGet('/employees', $query + ['per_page' => 100], []);

        if (!is_array($rows)) {
            return [];
        }

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
