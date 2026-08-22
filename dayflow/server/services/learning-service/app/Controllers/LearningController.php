<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Certificates;
use App\Models\CourseCategories;
use App\Models\Courses;
use App\Models\Enrolments;
use App\Policies\EnrolmentPolicy;
use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * The two aggregated views over learning: one for the individual, one for HR.
 */
final class LearningController
{
    private Courses $courses;
    private CourseCategories $categories;
    private Enrolments $enrolments;
    private Certificates $certificates;

    public function __construct()
    {
        $this->courses = new Courses();
        $this->categories = new CourseCategories();
        $this->enrolments = new Enrolments();
        $this->certificates = new Certificates();
    }

    /** The catalogue's category list, for the filter bar. */
    public function categories(Request $request): Response
    {
        return Response::ok($this->categories->active());
    }

    /** Everything the signed-in person needs on their learning home screen. */
    public function myDashboard(Request $request): Response
    {
        $employeeId = EnrolmentPolicy::requireEmployeeId($request->principal());
        $today = Clock::today();

        $rows = $this->enrolments->query()
            ->select('enrolments.*')
            ->selectRaw('"courses"."title" AS course_title')
            ->selectRaw('"courses"."slug" AS course_slug')
            ->selectRaw('"courses"."level" AS course_level')
            ->selectRaw('"courses"."estimated_minutes" AS course_estimated_minutes')
            ->selectRaw('"courses"."is_mandatory" AS course_is_mandatory')
            ->join('courses', 'courses.id', '=', 'enrolments.course_id')
            ->where('enrolments.employee_id', '=', $employeeId)
            ->orderBy('enrolments.due_on')
            ->orderBy('enrolments.updated_at', 'desc')
            ->get();

        $enrolments = array_map([$this->enrolments, 'present'], $rows);

        $assigned = [];
        $inProgress = [];
        $overdue = [];
        $completed = [];

        foreach ($enrolments as $enrolment) {
            if ((bool) $enrolment['is_overdue']) {
                $overdue[] = $enrolment;
            }

            switch ($enrolment['status']) {
                case 'completed':
                    $completed[] = $enrolment;
                    break;
                case 'in_progress':
                    $inProgress[] = $enrolment;
                    break;
                default:
                    // "Assigned" means somebody else put it there; a course the
                    // learner picked up themselves is simply not started.
                    if (($enrolment['assigned_by'] ?? null) !== null) {
                        $assigned[] = $enrolment;
                    } else {
                        $inProgress[] = $enrolment;
                    }
            }
        }

        [$quarterStart, $quarterEnd, $quarterLabel] = $this->currentQuarter();
        $quarterSeconds = $this->enrolments->watchedSecondsBetween($employeeId, $quarterStart, $quarterEnd);

        return Response::ok([
            'as_of' => $today,
            'assigned' => $assigned,
            'in_progress' => $inProgress,
            'overdue' => $overdue,
            'recently_completed' => array_slice($completed, 0, 5),
            'certificates' => $this->certificates->forEmployee($employeeId),
            'summary' => [
                'enrolled' => count($enrolments),
                'assigned' => count($assigned),
                'in_progress' => count($inProgress),
                'overdue' => count($overdue),
                'completed' => count($completed),
                'quarter' => $quarterLabel,
                'quarter_minutes' => intdiv($quarterSeconds, 60),
                'quarter_time_label' => Str::duration($quarterSeconds),
            ],
        ]);
    }

    /**
     * Mandatory training compliance across the organisation.
     *
     * People with no enrolment at all are the point of this report, so the
     * employee list comes from employee-service rather than from the rows this
     * service happens to hold.
     */
    public function compliance(Request $request): Response
    {
        $directory = new EmployeeDirectory($request->bearerToken());
        $today = Clock::today();

        $courses = $this->courses->mandatory();
        $courseIds = array_map(static fn (array $course): string => (string) $course['id'], $courses);

        $enrolmentsByCourse = [];
        foreach ($this->enrolments->forCourses($courseIds) as $enrolment) {
            $enrolmentsByCourse[(string) $enrolment['course_id']][(string) $enrolment['employee_id']] = $enrolment;
        }

        $people = array_values(array_filter(
            $directory->everyone(),
            static fn (array $employee): bool => (bool) ($employee['is_active'] ?? true)
        ));

        $report = [];
        $totalRequired = 0;
        $totalCompleted = 0;
        $totalOverdue = 0;

        foreach ($courses as $course) {
            $courseId = (string) $course['id'];
            $enrolments = $enrolmentsByCourse[$courseId] ?? [];
            $audience = $this->audienceFor($course, $people, $enrolments);

            $rows = [];
            $counts = [
                'audience' => count($audience),
                'completed' => 0,
                'in_progress' => 0,
                'not_started' => 0,
                'not_enrolled' => 0,
                'overdue' => 0,
            ];

            foreach ($audience as $employeeId => $employee) {
                $enrolment = $enrolments[$employeeId] ?? null;
                $status = $enrolment === null ? 'not_enrolled' : (string) $enrolment['status'];
                $isOverdue = $enrolment !== null && (bool) $enrolment['is_overdue'];

                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if ($isOverdue) {
                    $counts['overdue']++;
                }

                $rows[] = [
                    'employee_id' => $employeeId,
                    'employee_code' => $employee['employee_code'] ?? null,
                    'full_name' => $employee['full_name'] ?? $directory->displayName($employeeId),
                    'department_name' => $employee['department_name'] ?? null,
                    'status' => $status,
                    'progress_percent' => (int) ($enrolment['progress_percent'] ?? 0),
                    'due_on' => $enrolment['due_on'] ?? null,
                    'completed_at' => $enrolment['completed_at'] ?? null,
                    'is_overdue' => $isOverdue,
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                // Outstanding work sorts to the top; that is what the report is
                // read for.
                $weight = static fn (array $row): int => match ($row['status']) {
                    'completed' => 3,
                    'in_progress' => 1,
                    default => 0,
                };

                return [$weight($a), (string) $a['full_name']] <=> [$weight($b), (string) $b['full_name']];
            });

            $totalRequired += $counts['audience'];
            $totalCompleted += $counts['completed'];
            $totalOverdue += $counts['overdue'];

            $report[] = [
                'course_id' => $courseId,
                'title' => $course['title'],
                'level' => $course['level'],
                'estimated_minutes' => $course['estimated_minutes'],
                'mandatory_for_department_id' => $course['mandatory_for_department_id'],
                'mandatory_for_designation_id' => $course['mandatory_for_designation_id'],
                'totals' => $counts,
                'completion_rate' => $counts['audience'] === 0
                    ? 0.0
                    : round(($counts['completed'] / $counts['audience']) * 100, 1),
                'employees' => $rows,
            ];
        }

        return Response::ok([
            'as_of' => $today,
            'summary' => [
                'mandatory_courses' => count($courses),
                'employees' => count($people),
                'obligations' => $totalRequired,
                'completed' => $totalCompleted,
                'outstanding' => max(0, $totalRequired - $totalCompleted),
                'overdue' => $totalOverdue,
                'completion_rate' => $totalRequired === 0
                    ? 0.0
                    : round(($totalCompleted / $totalRequired) * 100, 1),
            ],
            'courses' => $report,
        ]);
    }

    /**
     * Who a mandatory course actually applies to.
     *
     * A course may be restricted to a department or a designation. Anyone
     * already enrolled stays in the audience regardless, so a later narrowing
     * of the rule never hides an obligation that was already handed out.
     *
     * @param array<string, mixed>        $course
     * @param list<array<string, mixed>>  $people
     * @param array<string, array<string, mixed>> $enrolments
     * @return array<string, array<string, mixed>>
     */
    private function audienceFor(array $course, array $people, array $enrolments): array
    {
        $department = $course['mandatory_for_department_id'] ?? null;
        $designation = $course['mandatory_for_designation_id'] ?? null;

        $audience = [];

        foreach ($people as $employee) {
            $employeeId = (string) ($employee['id'] ?? '');
            if ($employeeId === '') {
                continue;
            }

            $matches = true;

            if ($department !== null && (string) ($employee['department_id'] ?? '') !== (string) $department) {
                $matches = false;
            }

            if ($designation !== null && (string) ($employee['designation_id'] ?? '') !== (string) $designation) {
                $matches = false;
            }

            if ($matches || isset($enrolments[$employeeId])) {
                $audience[$employeeId] = [
                    'employee_code' => $employee['employee_code'] ?? null,
                    'full_name' => $employee['full_name'] ?? trim(sprintf(
                        '%s %s',
                        (string) ($employee['first_name'] ?? ''),
                        (string) ($employee['last_name'] ?? '')
                    )),
                    'department_name' => $employee['department_name'] ?? null,
                ];
            }
        }

        // Someone who left the directory's reach but still holds an enrolment
        // must not silently vanish from a compliance report.
        foreach ($enrolments as $employeeId => $enrolment) {
            if (!isset($audience[$employeeId])) {
                $audience[$employeeId] = [
                    'employee_code' => null,
                    'full_name' => null,
                    'department_name' => null,
                ];
            }
        }

        return $audience;
    }

    /**
     * Start, end and label of the calendar quarter containing today.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function currentQuarter(): array
    {
        $now = Clock::now();
        $quarter = intdiv((int) $now->format('n') - 1, 3) + 1;
        $start = $now->setDate((int) $now->format('Y'), (($quarter - 1) * 3) + 1, 1)->setTime(0, 0);
        $end = $start->modify('+3 months');

        return [
            $start->format(\DateTimeInterface::ATOM),
            $end->format(\DateTimeInterface::ATOM),
            sprintf('Q%d %s', $quarter, $start->format('Y')),
        ];
    }
}
