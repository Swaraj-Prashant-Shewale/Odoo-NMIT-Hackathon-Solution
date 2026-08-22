<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DashboardCache;
use App\Models\MetricSnapshots;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;

/**
 * Assembles the whole home screen in one pass.
 *
 * The screen is described as a list of sections, each with the permission that
 * entitles a caller to it. Only the entitled sections are attempted, so a
 * regular employee never causes a call to payroll and an unavailable service
 * costs one card rather than the page.
 */
final class DashboardAssembler
{
    /**
     * How long an assembled dashboard stays usable.
     *
     * Long enough to absorb a double refresh, short enough that a check-in or
     * an approval shows up while the person is still looking at the screen.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Bumped whenever the shape of an assembled payload changes, so a rolling
     * deployment cannot serve a new client an old structure.
     */
    private const CACHE_VERSION = 'v1';

    private readonly EmployeeDirectory $directory;

    private readonly DashboardSections $sections;

    public function __construct(
        private readonly Downstream $downstream,
        private readonly DashboardCache $cache,
        MetricSnapshots $snapshots,
        Principal $principal,
    ) {
        $this->directory = new EmployeeDirectory($this->downstream);
        $this->sections = new DashboardSections($this->downstream, $this->directory, $snapshots, $principal);
    }

    /**
     * Returns the dashboard for one caller, from cache when it is fresh.
     *
     * @return array<string, mixed>
     */
    public function forPrincipal(Principal $principal, bool $forceRefresh = false): array
    {
        $key = $this->cacheKey($principal);

        if ($forceRefresh) {
            $this->cache->forgetScope($this->scopeKey($principal));
        } else {
            $cached = $this->cache->read($key);

            if ($cached !== null) {
                return ['cached' => true] + $cached;
            }
        }

        $payload = $this->build($principal);

        // The cache is an optimisation and never a dependency: a dashboard that
        // has already been assembled must reach the caller even if storing it
        // fails.
        try {
            $this->cache->write($key, $this->scopeKey($principal), $payload, self::CACHE_TTL_SECONDS);

            // Expired entries are swept occasionally rather than on every
            // request: the table would otherwise grow without bound, and a
            // scheduled job for a minute-long cache is not worth operating.
            if (random_int(1, 20) === 1) {
                $this->cache->forgetExpired();
            }
        } catch (\Throwable $exception) {
            Logger::warning('Dashboard cache write failed', ['error' => $exception->getMessage()]);
        }

        return ['cached' => false] + $payload;
    }

    /**
     * The cache key for one caller.
     *
     * This is the single most safety-critical line in the service. A dashboard
     * is assembled from data that this caller alone is entitled to see, so the
     * key is derived from who they are AND what they may do:
     *
     *   - the user id, so two people can never share an entry;
     *   - the employee id, because the self-service cards are about that person;
     *   - a digest of the effective permission set, so a role change takes
     *     effect on the next request instead of after the entry expires;
     *   - the calendar date, so "today" cannot be inherited across midnight.
     *
     * A key built from anything less - a role name, a department, a date alone -
     * would eventually collide, and a collision here does not lose data: it
     * serves one person's salary, team and leave to somebody else. That is the
     * defining failure mode of a caching dashboard, so the key names every
     * input explicitly rather than relying on a convenient shorthand.
     */
    public function cacheKey(Principal $principal): string
    {
        $permissions = $principal->permissions;
        sort($permissions);

        $scope = implode('|', [
            'user:' . $principal->userId,
            'employee:' . ($principal->employeeId ?? 'none'),
            'permissions:' . hash('sha256', implode(',', $permissions)),
            'date:' . Clock::today(),
        ]);

        return hash('sha256', 'dashboard:' . self::CACHE_VERSION . ':' . $scope);
    }

    /** Every cache entry belonging to one person, so a refresh can clear them all. */
    public function scopeKey(Principal $principal): string
    {
        return 'user:' . $principal->userId;
    }

    /** @return array<string, mixed> */
    private function build(Principal $principal): array
    {
        $sections = [];
        $audience = [];

        foreach ($this->plan($principal) as $entry) {
            if (!$entry['when']) {
                continue;
            }

            $audience[$entry['group']] = true;
            $sections[$entry['key']] = $this->render($entry['key'], $entry['build']);
        }

        return [
            'generated_at' => Clock::iso(),
            'as_of' => Clock::today(),
            'viewer' => [
                'user_id' => $principal->userId,
                'employee_id' => $principal->employeeId,
                'name' => $principal->displayName,
                'role' => $principal->primaryRole(),
                'role_label' => $principal->roleLabel(),
                'department_id' => $principal->departmentId,
            ],
            'audience' => array_keys($audience),
            'sections' => $sections,
            'unavailable_services' => $this->downstream->unavailableServices(),
        ];
    }

    /**
     * The section catalogue, in the order the home screen renders it.
     *
     * @return list<array{key: string, group: string, when: bool, build: callable}>
     */
    private function plan(Principal $principal): array
    {
        $sections = $this->sections;
        $employeeId = $principal->employeeId;

        // Every self-service card is about a specific person. An account that
        // has not been linked to an employee record yet - a half-finished
        // onboarding - has nothing personal to show.
        $isEmployee = $employeeId !== null;

        $isManager = $principal->canAny(Permissions::ATTENDANCE_VIEW_TEAM, Permissions::LEAVE_VIEW_TEAM);
        $seesPeople = $principal->can(Permissions::PROFILE_VIEW_ALL);
        $seesLeave = $principal->can(Permissions::LEAVE_VIEW_ALL);
        $seesAttendance = $principal->can(Permissions::ATTENDANCE_VIEW_ALL);
        $seesPayroll = $principal->can(Permissions::PAYROLL_VIEW_ALL);

        return [
            [
                'key' => 'attendance_today',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::ATTENDANCE_VIEW_SELF),
                'build' => static fn (): ?array => $sections->attendanceToday(),
            ],
            [
                'key' => 'attendance_this_month',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::ATTENDANCE_VIEW_SELF),
                'build' => static fn (): ?array => $sections->attendanceThisMonth(),
            ],
            [
                'key' => 'attendance_week',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::ATTENDANCE_VIEW_SELF),
                'build' => static fn (): ?array => $sections->attendanceWeek(),
            ],
            [
                'key' => 'leave_balances',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::LEAVE_VIEW_SELF),
                'build' => static fn (): ?array => $sections->leaveBalances(),
            ],
            [
                'key' => 'leave_pending',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::LEAVE_VIEW_SELF),
                'build' => static fn (): ?array => $sections->pendingLeaveRequests(),
            ],
            [
                'key' => 'latest_payslip',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::PAYROLL_VIEW_SELF),
                'build' => static fn (): ?array => $sections->latestPayslip(),
            ],
            [
                'key' => 'learning',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::LEARNING_VIEW_CATALOGUE),
                'build' => static fn (): ?array => $sections->learning(),
            ],
            [
                'key' => 'goals',
                'group' => 'self',
                'when' => $isEmployee && $principal->can(Permissions::TALENT_VIEW_SELF),
                'build' => static fn (): ?array => $sections->goals(),
            ],
            [
                'key' => 'inbox',
                'group' => 'self',
                'when' => $principal->can(Permissions::NOTIFICATION_VIEW_SELF),
                'build' => static fn (): ?array => $sections->inbox(),
            ],

            [
                'key' => 'team_today',
                'group' => 'team',
                'when' => $isManager && $principal->can(Permissions::ATTENDANCE_VIEW_TEAM),
                'build' => static fn (): ?array => $sections->teamToday($employeeId),
            ],
            [
                'key' => 'pending_approvals',
                'group' => 'team',
                'when' => $principal->canAny(
                    Permissions::LEAVE_APPROVE,
                    Permissions::ATTENDANCE_APPROVE_REGULARISATION,
                    Permissions::EXPENSE_APPROVE
                ),
                'build' => static fn (): ?array => $sections->pendingApprovals(
                    $principal->can(Permissions::LEAVE_APPROVE),
                    $principal->can(Permissions::ATTENDANCE_APPROVE_REGULARISATION),
                    $principal->can(Permissions::EXPENSE_APPROVE)
                ),
            ],
            [
                'key' => 'team_leave_calendar',
                'group' => 'team',
                'when' => $principal->can(Permissions::LEAVE_VIEW_TEAM),
                'build' => static fn (): ?array => $sections->teamLeaveCalendar(14),
            ],
            [
                'key' => 'team_goals',
                'group' => 'team',
                'when' => $principal->can(Permissions::TALENT_VIEW_TEAM),
                'build' => static fn (): ?array => $sections->teamGoals(),
            ],

            [
                'key' => 'headcount',
                'group' => 'organisation',
                'when' => $seesPeople,
                'build' => static fn (): ?array => $sections->headcount(),
            ],
            [
                'key' => 'workforce_composition',
                'group' => 'organisation',
                'when' => $seesPeople,
                'build' => static fn (): ?array => $sections->workforceComposition(),
            ],
            [
                'key' => 'headcount_trend',
                'group' => 'organisation',
                'when' => $seesPeople,
                'build' => static fn (): ?array => $sections->headcountTrend(12),
            ],
            [
                'key' => 'attendance_rate_trend',
                'group' => 'organisation',
                'when' => $seesAttendance || $principal->can(Permissions::REPORT_VIEW_ALL),
                'build' => static fn (): ?array => $sections->attendanceRateTrend(6),
            ],
            [
                'key' => 'absence_by_department',
                'group' => 'organisation',
                'when' => $seesAttendance || $principal->can(Permissions::REPORT_VIEW_ALL),
                'build' => static fn (): ?array => $sections->absenceByDepartment(5),
            ],
            [
                'key' => 'leave_utilisation',
                'group' => 'organisation',
                'when' => $seesLeave,
                'build' => static fn (): ?array => $sections->leaveUtilisation(),
            ],
            [
                'key' => 'onboarding',
                'group' => 'organisation',
                // The endpoint behind this card is guarded by onboarding.manage on
                // its own; profile.view.all is not enough to read it.
                'when' => $principal->can(Permissions::ONBOARDING_MANAGE),
                'build' => static fn (): ?array => $sections->onboarding(),
            ],
            [
                'key' => 'documents_expiring',
                'group' => 'organisation',
                'when' => $principal->can(Permissions::DOCUMENT_VIEW_ALL),
                'build' => static fn (): ?array => $sections->documentsExpiring(30),
            ],
            [
                'key' => 'training_compliance',
                'group' => 'organisation',
                'when' => $principal->canAny(Permissions::REPORT_VIEW_ALL, Permissions::LEARNING_ASSIGN_ANY),
                'build' => static fn (): ?array => $sections->trainingCompliance(),
            ],

            [
                'key' => 'payroll_cost_trend',
                'group' => 'finance',
                'when' => $seesPayroll,
                'build' => static fn (): ?array => $sections->payrollCostTrend(6),
            ],
            [
                'key' => 'payroll_cost_by_department',
                'group' => 'finance',
                'when' => $seesPayroll,
                'build' => static fn (): ?array => $sections->payrollCostByDepartment(),
            ],
            [
                'key' => 'expense_claims',
                'group' => 'finance',
                'when' => $principal->canAny(Permissions::EXPENSE_VIEW_ALL, Permissions::PAYROLL_VIEW_ALL),
                'build' => static fn (): ?array => $sections->expenseClaims(),
            ],
        ];
    }

    /**
     * Runs one section builder and wraps the result.
     *
     * Every section carries an explicit availability flag. A client that only
     * saw an absent key would have to guess whether the figure is zero or
     * simply unknown, and a placeholder is the honest answer to the second.
     *
     * @return array{available: bool, data: mixed}
     */
    private function render(string $key, callable $build): array
    {
        try {
            $data = $build();
        } catch (\Throwable $exception) {
            // The section list is the last line of defence: a malformed payload
            // from one service must not take the whole home screen down.
            Logger::warning('Dashboard section failed', [
                'section' => $key,
                'error' => $exception->getMessage(),
            ]);

            return ['available' => false, 'data' => null];
        }

        return $data === null
            ? ['available' => false, 'data' => null]
            : ['available' => true, 'data' => $data];
    }
}
