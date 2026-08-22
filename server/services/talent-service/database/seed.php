<?php

declare(strict_types=1);

/**
 * Talent seed data.
 *
 * Runs on every boot, so nothing here may create a second copy of anything.
 * Every row carries an identifier derived from a stable key, and every insert
 * is written so that finding the row already present is a no-op.
 *
 * The competency framework is reference data and is always seeded. Goals,
 * cycles, appraisals and feedback are demonstration data and only appear when
 * SEED_DEMO_DATA is on.
 */

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;

$pdo = Connection::pdo();

/**
 * A version 3 (name based) identifier.
 *
 * The same key always yields the same value, which is what lets the seed be
 * re-run without generating duplicates or needing sixty literal UUIDs here.
 */
$identifier = static function (string $key): string {
    $bytes = (string) hex2bin(md5('dayflow.talent.' . $key));
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x30);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
};

// ---------------------------------------------------------------------------
// Reference data: the competency framework every review is scored against.
// ---------------------------------------------------------------------------

/** @var list<array{0: string, 1: string, 2: string}> */
$competencies = [
    ['Communication', 'Explains ideas clearly in writing and in person, and pitches the message at the audience in front of them.', 'core'],
    ['Ownership', 'Carries a piece of work through to the outcome, and raises a problem while it is still small.', 'core'],
    ['Technical Depth', 'Brings current, well-grounded knowledge of their craft and makes trade-offs they can defend.', 'craft'],
    ['Collaboration', 'Works across team boundaries, shares context generously and leaves colleagues better equipped.', 'core'],
    ['Customer Focus', 'Starts from the customer problem and judges success by what the customer actually got.', 'core'],
    ['Problem Solving', 'Breaks an ambiguous problem down, tests the assumptions in it and arrives at a defensible answer.', 'core'],
    ['Leadership', 'Sets a direction others can follow, decides when a decision is needed and holds a standard without drama.', 'leadership'],
    ['Adaptability', 'Stays effective when priorities move, and treats new information as useful rather than unwelcome.', 'core'],
    ['Quality', 'Produces work that holds up under scrutiny and leaves the surrounding system tidier than it was.', 'craft'],
    ['Mentoring', 'Grows other people on purpose, through honest feedback, pairing and well-chosen stretch work.', 'leadership'],
];

$insertCompetency = $pdo->prepare(
    'INSERT INTO competencies (id, name, description, category, applies_to_designation_id, display_order, is_active, created_at)
     VALUES (:id, :name, :description, :category, NULL, :display_order, TRUE, :created_at)
     ON CONFLICT DO NOTHING'
);

$now = Clock::now();
$nowIso = $now->format(DateTimeInterface::ATOM);
$competencyIds = [];

foreach ($competencies as $position => [$name, $description, $category]) {
    $competencyId = $identifier('competency:' . $name);
    $competencyIds[$name] = $competencyId;

    $insertCompetency->execute([
        'id' => $competencyId,
        'name' => $name,
        'description' => $description,
        'category' => $category,
        'display_order' => ($position + 1) * 10,
        'created_at' => $nowIso,
    ]);
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demonstration data.
//
// Employee identifiers are copied verbatim from docs/SEED-IDENTIFIERS.md so
// that the nine services describe the same twelve people.
// ---------------------------------------------------------------------------

// Goals and reviews belong to the people still employed.
$employees = DemoCohort::activeEmployeeIds();

$reportsTo = DemoCohort::managers();

$year = (int) $now->format('Y');
$previousYear = $year - 1;

$clampToNow = static function (string $moment) use ($now): string {
    $parsed = Clock::parse($moment);

    // Seed data should never claim something happened in the future, however
    // early in the year the platform is first started.
    return ($parsed > $now ? $now : $parsed)->format(DateTimeInterface::ATOM);
};

// --- Goal cycle -------------------------------------------------------------

$goalCycleId = $identifier('goal-cycle:' . $year);

$pdo->prepare(
    'INSERT INTO goal_cycles (id, name, period_start, period_end, status, created_by, created_at, updated_at)
     VALUES (:id, :name, :period_start, :period_end, :status, :created_by, :created_at, :updated_at)
     ON CONFLICT DO NOTHING'
)->execute([
    'id' => $goalCycleId,
    'name' => sprintf('FY %d Goals', $year),
    'period_start' => sprintf('%d-01-01', $year),
    'period_end' => sprintf('%d-12-31', $year),
    'status' => 'open',
    'created_by' => $employees['DF-0002'],
    'created_at' => $nowIso,
    'updated_at' => $nowIso,
]);

// --- Goals ------------------------------------------------------------------

/**
 * Weights inside each list add up to exactly 100, which is the same rule the
 * API enforces when somebody adds a goal through the interface.
 *
 * @var array<string, list<array{title: string, category: string, metric: string, target: float, current: float, unit: string, weight: float, progress: float, status: string, note: string}>>
 */
$goalPlan = [
    'DF-0001' => [
        ['title' => 'Grow annual recurring revenue to 18 crore', 'category' => 'business', 'metric' => 'Annual recurring revenue', 'target' => 18.0, 'current' => 12.4, 'unit' => 'crore', 'weight' => 40.0, 'progress' => 68.9, 'status' => 'active', 'note' => 'Two enterprise renewals signed this quarter; the rest depends on the west region pipeline.'],
        ['title' => 'Close the Series B funding round', 'category' => 'business', 'metric' => 'Capital raised', 'target' => 60.0, 'current' => 0.0, 'unit' => 'crore', 'weight' => 30.0, 'progress' => 25.0, 'status' => 'active', 'note' => 'Data room assembled and three term sheet conversations under way.'],
        ['title' => 'Lift the company engagement score to 4.3', 'category' => 'behavioural', 'metric' => 'Engagement survey score', 'target' => 4.3, 'current' => 4.0, 'unit' => 'score', 'weight' => 30.0, 'progress' => 60.0, 'status' => 'active', 'note' => 'Second pulse survey up 0.2 on the baseline.'],
    ],
    'DF-0002' => [
        ['title' => 'Bring voluntary attrition below 9 percent', 'category' => 'business', 'metric' => 'Rolling 12 month attrition', 'target' => 9.0, 'current' => 11.2, 'unit' => 'percent', 'weight' => 30.0, 'progress' => 55.0, 'status' => 'active', 'note' => 'Stay interviews now running for everyone past 18 months of service.'],
        ['title' => 'Roll out the competency based review framework', 'category' => 'business', 'metric' => 'Framework adoption', 'target' => 100.0, 'current' => 100.0, 'unit' => 'percent', 'weight' => 25.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'Ten competencies published and used in the last review cycle.'],
        ['title' => 'Close 24 open roles at a 45 day average', 'category' => 'business', 'metric' => 'Roles closed', 'target' => 24.0, 'current' => 15.0, 'unit' => 'roles', 'weight' => 25.0, 'progress' => 62.5, 'status' => 'active', 'note' => 'Engineering hiring is on plan; sales is two roles behind.'],
        ['title' => 'Complete senior HR certification', 'category' => 'learning', 'metric' => 'Certification', 'target' => 1.0, 'current' => 0.0, 'unit' => 'certification', 'weight' => 20.0, 'progress' => 40.0, 'status' => 'active', 'note' => 'Four of ten modules finished, examination booked for the autumn.'],
    ],
    'DF-0003' => [
        ['title' => 'Onboard every joiner within three working days', 'category' => 'business', 'metric' => 'On time onboarding', 'target' => 100.0, 'current' => 92.0, 'unit' => 'percent', 'weight' => 35.0, 'progress' => 92.0, 'status' => 'active', 'note' => 'Two late starts were both caused by asset delivery, not paperwork.'],
        ['title' => 'Run skip level conversations across Engineering', 'category' => 'behavioural', 'metric' => 'Conversations held', 'target' => 12.0, 'current' => 7.0, 'unit' => 'sessions', 'weight' => 25.0, 'progress' => 58.3, 'status' => 'active', 'note' => 'Seven done, remaining five scheduled before the next cycle opens.'],
        ['title' => 'Digitise the handbook and policy library', 'category' => 'business', 'metric' => 'Policies migrated', 'target' => 100.0, 'current' => 100.0, 'unit' => 'percent', 'weight' => 20.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'All 34 policies are now searchable in one place.'],
        ['title' => 'Finish the employment law workshop', 'category' => 'learning', 'metric' => 'Workshop', 'target' => 1.0, 'current' => 1.0, 'unit' => 'course', 'weight' => 20.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'Completed with the updated contract templates already in use.'],
    ],
    'DF-0004' => [
        ['title' => 'Close the monthly books within four working days', 'category' => 'business', 'metric' => 'Days to close', 'target' => 4.0, 'current' => 5.0, 'unit' => 'days', 'weight' => 35.0, 'progress' => 70.0, 'status' => 'active', 'note' => 'Down from seven days at the start of the year.'],
        ['title' => 'Eliminate payroll processing errors', 'category' => 'business', 'metric' => 'Errors per run', 'target' => 0.0, 'current' => 1.0, 'unit' => 'errors', 'weight' => 30.0, 'progress' => 75.0, 'status' => 'active', 'note' => 'One arrears miscalculation in June, corrected in the same cycle.'],
        ['title' => 'Automate expense reimbursement reporting', 'category' => 'business', 'metric' => 'Reports automated', 'target' => 100.0, 'current' => 60.0, 'unit' => 'percent', 'weight' => 20.0, 'progress' => 60.0, 'status' => 'active', 'note' => 'Category and cost centre reports are live; approvals ageing is next.'],
        ['title' => 'Complete the accounting standards refresher', 'category' => 'learning', 'metric' => 'Certification', 'target' => 1.0, 'current' => 0.0, 'unit' => 'certification', 'weight' => 15.0, 'progress' => 30.0, 'status' => 'active', 'note' => 'Enrolled, with the first two assessments passed.'],
    ],
    'DF-0005' => [
        ['title' => 'Reduce production incidents by 40 percent', 'category' => 'business', 'metric' => 'Incident reduction', 'target' => 40.0, 'current' => 26.0, 'unit' => 'percent', 'weight' => 30.0, 'progress' => 65.0, 'status' => 'active', 'note' => 'Alert tuning removed most of the noise; the rest is deployment safety.'],
        ['title' => 'Ship the platform re-architecture', 'category' => 'business', 'metric' => 'Migration complete', 'target' => 100.0, 'current' => 55.0, 'unit' => 'percent', 'weight' => 30.0, 'progress' => 55.0, 'status' => 'active', 'note' => 'Five of nine services migrated with no customer facing downtime.'],
        ['title' => 'Grow the team to seven engineers', 'category' => 'business', 'metric' => 'Team size', 'target' => 7.0, 'current' => 5.0, 'unit' => 'engineers', 'weight' => 20.0, 'progress' => 40.0, 'status' => 'active', 'note' => 'One offer accepted, one role still at first interview stage.'],
        ['title' => 'Hold fortnightly one to ones with every report', 'category' => 'behavioural', 'metric' => 'Sessions held', 'target' => 24.0, 'current' => 16.0, 'unit' => 'sessions', 'weight' => 20.0, 'progress' => 66.7, 'status' => 'active', 'note' => 'No cancellations this quarter.'],
    ],
    'DF-0006' => [
        ['title' => 'Bring p95 API latency under 250 milliseconds', 'category' => 'business', 'metric' => 'p95 latency', 'target' => 250.0, 'current' => 310.0, 'unit' => 'ms', 'weight' => 35.0, 'progress' => 62.0, 'status' => 'active', 'note' => 'Query plan work took 180ms off the slowest endpoint.'],
        ['title' => 'Raise service test coverage to 85 percent', 'category' => 'business', 'metric' => 'Line coverage', 'target' => 85.0, 'current' => 78.0, 'unit' => 'percent', 'weight' => 25.0, 'progress' => 82.0, 'status' => 'active', 'note' => 'Remaining gap is almost entirely error handling paths.'],
        ['title' => 'Mentor two engineers through their first on call rotation', 'category' => 'behavioural', 'metric' => 'Engineers mentored', 'target' => 2.0, 'current' => 2.0, 'unit' => 'engineers', 'weight' => 20.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'Both now run their own rotation unaided.'],
        ['title' => 'Complete the distributed systems course', 'category' => 'learning', 'metric' => 'Course progress', 'target' => 100.0, 'current' => 45.0, 'unit' => 'percent', 'weight' => 20.0, 'progress' => 45.0, 'status' => 'active', 'note' => 'Through consensus and replication; capstone still to come.'],
    ],
    'DF-0007' => [
        ['title' => 'Deliver the notifications rewrite', 'category' => 'business', 'metric' => 'Delivery', 'target' => 100.0, 'current' => 70.0, 'unit' => 'percent', 'weight' => 40.0, 'progress' => 70.0, 'status' => 'active', 'note' => 'In-app channel shipped; email templating is the remaining piece.'],
        ['title' => 'Bring review turnaround under eight hours', 'category' => 'behavioural', 'metric' => 'Median review time', 'target' => 8.0, 'current' => 11.0, 'unit' => 'hours', 'weight' => 25.0, 'progress' => 55.0, 'status' => 'active', 'note' => 'A morning review slot has helped, but afternoon requests still queue.'],
        ['title' => 'Complete the database performance course', 'category' => 'learning', 'metric' => 'Course progress', 'target' => 100.0, 'current' => 100.0, 'unit' => 'percent', 'weight' => 35.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'Indexing changes from the final module are already in production.'],
    ],
    'DF-0008' => [
        ['title' => 'Automate 200 regression cases', 'category' => 'business', 'metric' => 'Cases automated', 'target' => 200.0, 'current' => 148.0, 'unit' => 'test cases', 'weight' => 40.0, 'progress' => 74.0, 'status' => 'active', 'note' => 'Payroll and leave suites finished; attendance is in progress.'],
        ['title' => 'Keep escaped defects below three per release', 'category' => 'business', 'metric' => 'Escaped defects', 'target' => 3.0, 'current' => 4.0, 'unit' => 'defects', 'weight' => 30.0, 'progress' => 60.0, 'status' => 'active', 'note' => 'Four in the last release, all in one payroll edge case.'],
        ['title' => 'Add accessibility checks to the release pipeline', 'category' => 'learning', 'metric' => 'Pipeline coverage', 'target' => 100.0, 'current' => 35.0, 'unit' => 'percent', 'weight' => 30.0, 'progress' => 35.0, 'status' => 'active', 'note' => 'Automated colour contrast and landmark checks running on two services.'],
    ],
    'DF-0009' => [
        ['title' => 'Achieve 6.5 crore in regional bookings', 'category' => 'business', 'metric' => 'Bookings', 'target' => 6.5, 'current' => 4.1, 'unit' => 'crore', 'weight' => 45.0, 'progress' => 63.0, 'status' => 'active', 'note' => 'Ahead of the same point last year by just over a crore.'],
        ['title' => 'Open twelve enterprise accounts in the west region', 'category' => 'business', 'metric' => 'New accounts', 'target' => 12.0, 'current' => 7.0, 'unit' => 'accounts', 'weight' => 30.0, 'progress' => 58.3, 'status' => 'active', 'note' => 'Seven closed, three in contract review.'],
        ['title' => 'Bring the team to full quota attainment', 'category' => 'behavioural', 'metric' => 'Quota attainment', 'target' => 100.0, 'current' => 72.0, 'unit' => 'percent', 'weight' => 25.0, 'progress' => 72.0, 'status' => 'active', 'note' => 'Two of three account executives are already above plan.'],
    ],
    'DF-0010' => [
        ['title' => 'Close 1.8 crore of new business', 'category' => 'business', 'metric' => 'New business closed', 'target' => 1.8, 'current' => 1.15, 'unit' => 'crore', 'weight' => 45.0, 'progress' => 64.0, 'status' => 'active', 'note' => 'The Nagpur manufacturing account closed a quarter early.'],
        ['title' => 'Maintain a three times qualified pipeline', 'category' => 'business', 'metric' => 'Pipeline coverage', 'target' => 3.0, 'current' => 2.4, 'unit' => 'x', 'weight' => 30.0, 'progress' => 80.0, 'status' => 'active', 'note' => 'Coverage dipped after two large closes and is rebuilding.'],
        ['title' => 'Complete the value selling programme', 'category' => 'learning', 'metric' => 'Programme progress', 'target' => 100.0, 'current' => 100.0, 'unit' => 'percent', 'weight' => 25.0, 'progress' => 100.0, 'status' => 'achieved', 'note' => 'Certified, and the discovery framework is now used on every deal.'],
    ],
    'DF-0011' => [
        ['title' => 'Grow organic sessions to 60,000 a month', 'category' => 'business', 'metric' => 'Monthly organic sessions', 'target' => 60000.0, 'current' => 41500.0, 'unit' => 'sessions', 'weight' => 40.0, 'progress' => 69.0, 'status' => 'active', 'note' => 'Up 38 percent since January on the back of the comparison pages.'],
        ['title' => 'Publish 24 long form articles', 'category' => 'business', 'metric' => 'Articles published', 'target' => 24.0, 'current' => 15.0, 'unit' => 'articles', 'weight' => 30.0, 'progress' => 62.5, 'status' => 'active', 'note' => 'Running one ahead of the fortnightly cadence.'],
        ['title' => 'Launch the customer story series', 'category' => 'business', 'metric' => 'Stories published', 'target' => 6.0, 'current' => 2.0, 'unit' => 'stories', 'weight' => 30.0, 'progress' => 33.3, 'status' => 'active', 'note' => 'Two published, four interviews recorded and awaiting approval.'],
    ],
    'DF-0012' => [
        ['title' => 'Ship version two of the design system', 'category' => 'business', 'metric' => 'Components migrated', 'target' => 100.0, 'current' => 80.0, 'unit' => 'percent', 'weight' => 35.0, 'progress' => 80.0, 'status' => 'active', 'note' => 'Forms and navigation are done; data tables are the last set.'],
        ['title' => 'Raise usability test task completion to 90 percent', 'category' => 'business', 'metric' => 'Task completion', 'target' => 90.0, 'current' => 81.0, 'unit' => 'percent', 'weight' => 35.0, 'progress' => 76.0, 'status' => 'active', 'note' => 'Leave application flow improved most after the second round.'],
        ['title' => 'Run monthly design critique sessions', 'category' => 'behavioural', 'metric' => 'Sessions held', 'target' => 12.0, 'current' => 8.0, 'unit' => 'sessions', 'weight' => 30.0, 'progress' => 66.7, 'status' => 'active', 'note' => 'Attendance now includes engineering as well as design.'],
    ],
];

$insertGoal = $pdo->prepare(
    'INSERT INTO goals (id, employee_id, goal_cycle_id, title, description, category, metric,
                        target_value, current_value, unit, weight_percent, status, progress_percent,
                        starts_on, due_on, completed_at, created_by, created_at, updated_at)
     VALUES (:id, :employee_id, :goal_cycle_id, :title, :description, :category, :metric,
             :target_value, :current_value, :unit, :weight_percent, :status, :progress_percent,
             :starts_on, :due_on, :completed_at, :created_by, :created_at, :updated_at)
     ON CONFLICT DO NOTHING'
);

$insertGoalUpdate = $pdo->prepare(
    'INSERT INTO goal_updates (id, goal_id, employee_id, progress_percent, note, created_by, created_at)
     VALUES (:id, :goal_id, :employee_id, :progress_percent, :note, :created_by, :created_at)
     ON CONFLICT DO NOTHING'
);

// The twelve goal sets above are written out because they are the ones a
// demonstration walks through, and a page of "Improve performance by 10%"
// reads as filler. Everyone else gets goals drawn from a small library keyed
// to what they actually do, so a manager's team view and the company goal
// donut are counting real rows rather than twelve.
$goalLibrary = [
    'engineering' => [
        ['Cut p95 API latency to 250ms', 'business', 'p95 latency', 250.0, 'ms'],
        ['Raise automated test coverage to 80 percent', 'business', 'Coverage', 80.0, 'percent'],
        ['Close every critical defect within two working days', 'business', 'Time to fix', 2.0, 'days'],
        ['Complete the secure coding course', 'learning', 'Course', 1.0, 'course'],
    ],
    'design' => [
        ['Publish the shared component library', 'business', 'Components', 40.0, 'components'],
        ['Run usability testing on every major release', 'behavioural', 'Sessions', 12.0, 'sessions'],
        ['Bring the design system to full accessibility compliance', 'business', 'Compliance', 100.0, 'percent'],
        ['Complete the accessibility fundamentals course', 'learning', 'Course', 1.0, 'course'],
    ],
    'sales' => [
        ['Reach the annual new business quota', 'business', 'Bookings', 120.0, 'lakh'],
        ['Keep the qualified pipeline at three times quota', 'business', 'Pipeline cover', 3.0, 'multiple'],
        ['Lift win rate to 28 percent', 'business', 'Win rate', 28.0, 'percent'],
        ['Complete the consultative selling programme', 'learning', 'Course', 1.0, 'course'],
    ],
    'marketing' => [
        ['Double organic traffic year on year', 'business', 'Sessions', 200.0, 'percent'],
        ['Deliver 400 marketing qualified leads', 'business', 'Leads', 400.0, 'leads'],
        ['Publish two case studies a quarter', 'business', 'Case studies', 8.0, 'articles'],
        ['Complete the content strategy workshop', 'learning', 'Course', 1.0, 'course'],
    ],
    'success' => [
        ['Keep gross revenue retention above 94 percent', 'business', 'Retention', 94.0, 'percent'],
        ['Answer every ticket within four working hours', 'business', 'First response', 4.0, 'hours'],
        ['Raise the customer satisfaction score to 4.6', 'behavioural', 'CSAT', 4.6, 'score'],
        ['Complete the product certification', 'learning', 'Certification', 1.0, 'certification'],
    ],
    'finance' => [
        ['Close the monthly books within four working days', 'business', 'Days to close', 4.0, 'days'],
        ['Bring forecast accuracy within five percent', 'business', 'Variance', 5.0, 'percent'],
        ['Automate the quarterly board pack', 'business', 'Automated', 100.0, 'percent'],
        ['Complete the financial modelling course', 'learning', 'Course', 1.0, 'course'],
    ],
    'people' => [
        ['Fill open roles at a 45 day average', 'business', 'Time to hire', 45.0, 'days'],
        ['Reach 90 percent onboarding satisfaction', 'behavioural', 'Satisfaction', 90.0, 'percent'],
        ['Run a manager development track for every lead', 'business', 'Managers trained', 8.0, 'people'],
        ['Complete the interviewing skills workshop', 'learning', 'Course', 1.0, 'course'],
    ],
    'executive' => [
        ['Deliver the annual operating plan', 'business', 'Plan delivery', 100.0, 'percent'],
        ['Keep engagement above 4.2', 'behavioural', 'Engagement', 4.2, 'score'],
        ['Complete the leadership programme', 'learning', 'Course', 1.0, 'course'],
    ],
];

// goals.status is a checked enum: draft | active | achieved | missed |
// cancelled. A goal nobody got to is "missed", not "at risk".
$statuses = ['active', 'active', 'achieved', 'active', 'missed', 'active'];

foreach (DemoCohort::extended() as $person) {
    $code = $person['code'];

    // Somebody who has left is not carrying goals, and the hand-written twelve
    // are not overwritten.
    if (isset($goalPlan[$code]) || $person['exit_date'] !== null) {
        continue;
    }

    $library = $goalLibrary[$person['department']] ?? $goalLibrary['engineering'];
    $serial = (int) substr($code, 3);
    $plan = [];

    // Three goals each, chosen by a rotation through the library so two people
    // in the same team do not end up with an identical scorecard, and weighted
    // to sum to 100 as the review framework requires.
    $weights = [40.0, 35.0, 25.0];

    foreach ([0, 1, 2] as $slot) {
        [$title, $category, $metric, $target, $unit] = $library[($serial + $slot) % count($library)];

        $progress = (float) (25 + (($serial * 13 + $slot * 29) % 70));
        $status = $progress >= 100.0 ? 'achieved' : $statuses[($serial + $slot) % count($statuses)];

        if ($status === 'achieved') {
            $progress = 100.0;
        }

        $plan[] = [
            'title' => $title,
            'category' => $category,
            'metric' => $metric,
            'target' => $target,
            'current' => round($target * ($progress / 100), 2),
            'unit' => $unit,
            'weight' => $weights[$slot],
            'progress' => $progress,
            'status' => $status,
            'note' => sprintf('Reviewed at the last check-in; %d%% of the way there.', (int) $progress),
        ];
    }

    $goalPlan[$code] = $plan;
}

foreach ($goalPlan as $code => $plan) {
    if (!isset($employees[$code])) {
        continue;
    }

    $employeeId = $employees[$code];
    $managerCode = $reportsTo[$code] ?? null;
    $setBy = $managerCode === null || !isset($employees[$managerCode])
        ? $employeeId
        : $employees[$managerCode];

    foreach ($plan as $index => $goal) {
        $goalId = $identifier(sprintf('goal:%s:%d', $code, $index));

        $insertGoal->execute([
            'id' => $goalId,
            'employee_id' => $employeeId,
            'goal_cycle_id' => $goalCycleId,
            'title' => $goal['title'],
            'description' => $goal['note'],
            'category' => $goal['category'],
            'metric' => $goal['metric'],
            'target_value' => $goal['target'],
            'current_value' => $goal['current'],
            'unit' => $goal['unit'],
            'weight_percent' => $goal['weight'],
            'status' => $goal['status'],
            'progress_percent' => $goal['progress'],
            'starts_on' => sprintf('%d-01-01', $year),
            'due_on' => sprintf('%d-12-31', $year),
            'completed_at' => $goal['status'] === 'achieved'
                ? $now->modify('-' . (30 + $index * 9) . ' days')->format(DateTimeInterface::ATOM)
                : null,
            'created_by' => $setBy,
            'created_at' => $now->modify('-' . (200 - $index) . ' days')->format(DateTimeInterface::ATOM),
            'updated_at' => $nowIso,
        ]);

        $insertGoalUpdate->execute([
            'id' => $identifier(sprintf('goal-update:%s:%d', $code, $index)),
            'goal_id' => $goalId,
            'employee_id' => $employeeId,
            'progress_percent' => $goal['progress'],
            'note' => $goal['note'],
            'created_by' => $employeeId,
            'created_at' => $now->modify('-' . (7 + $index * 5) . ' days')->format(DateTimeInterface::ATOM),
        ]);
    }
}

// --- Review cycles ----------------------------------------------------------

$previousCycleId = $identifier('review-cycle:h2:' . $previousYear);
$currentCycleId = $identifier('review-cycle:h1:' . $year);

$insertReviewCycle = $pdo->prepare(
    'INSERT INTO review_cycles (id, name, period_start, period_end, self_review_due_on,
                                manager_review_due_on, status, rating_scale_max, instructions,
                                created_by, created_at, updated_at)
     VALUES (:id, :name, :period_start, :period_end, :self_review_due_on,
             :manager_review_due_on, :status, :rating_scale_max, :instructions,
             :created_by, :created_at, :updated_at)
     ON CONFLICT DO NOTHING'
);

$insertReviewCycle->execute([
    'id' => $previousCycleId,
    'name' => sprintf('H2 %d Performance Review', $previousYear),
    'period_start' => sprintf('%d-07-01', $previousYear),
    'period_end' => sprintf('%d-12-31', $previousYear),
    'self_review_due_on' => sprintf('%d-01-10', $year),
    'manager_review_due_on' => sprintf('%d-01-24', $year),
    'status' => 'closed',
    'rating_scale_max' => 5,
    'instructions' => 'Rate against the published competency framework. Give a concrete example for every score, and keep the written sections to what the person can act on.',
    'created_by' => $employees['DF-0002'],
    'created_at' => $clampToNow(sprintf('%d-12-01', $previousYear)),
    'updated_at' => $clampToNow(sprintf('%d-02-01', $year)),
]);

$insertReviewCycle->execute([
    'id' => $currentCycleId,
    'name' => sprintf('H1 %d Performance Review', $year),
    'period_start' => sprintf('%d-01-01', $year),
    'period_end' => sprintf('%d-06-30', $year),
    'self_review_due_on' => sprintf('%d-07-10', $year),
    'manager_review_due_on' => sprintf('%d-07-24', $year),
    'status' => 'open',
    'rating_scale_max' => 5,
    'instructions' => 'Write the self review first. Managers should read it before scoring, and bring one clear development priority to the conversation.',
    'created_by' => $employees['DF-0002'],
    'created_at' => $clampToNow(sprintf('%d-06-15', $year)),
    'updated_at' => $nowIso,
]);

$insertParticipant = $pdo->prepare(
    'INSERT INTO review_participants (id, review_cycle_id, employee_id, manager_id, status, created_at)
     VALUES (:id, :review_cycle_id, :employee_id, :manager_id, :status, :created_at)
     ON CONFLICT DO NOTHING'
);

$insertReview = $pdo->prepare(
    'INSERT INTO reviews (id, review_cycle_id, employee_id, reviewer_id, review_type, overall_rating,
                          strengths, improvements, achievements, manager_comments, employee_comments,
                          is_anonymous, status, submitted_at, acknowledged_at, created_at, updated_at)
     VALUES (:id, :review_cycle_id, :employee_id, :reviewer_id, :review_type, :overall_rating,
             :strengths, :improvements, :achievements, :manager_comments, :employee_comments,
             :is_anonymous, :status, :submitted_at, :acknowledged_at, :created_at, :updated_at)
     ON CONFLICT DO NOTHING'
);

$insertRating = $pdo->prepare(
    'INSERT INTO review_ratings (id, review_id, competency_id, rating, comment, created_at)
     VALUES (:id, :review_id, :competency_id, :rating, :comment, :created_at)
     ON CONFLICT DO NOTHING'
);

/** Manager ratings from the closed cycle, on the five point scale. */
$closedCycleRating = [
    'DF-0001' => 4.6, 'DF-0002' => 4.4, 'DF-0003' => 4.0, 'DF-0004' => 4.2,
    'DF-0005' => 4.3, 'DF-0006' => 4.5, 'DF-0007' => 3.8, 'DF-0008' => 4.1,
    'DF-0009' => 4.4, 'DF-0010' => 3.9, 'DF-0011' => 3.6, 'DF-0012' => 4.2,
];

/** @var array<string, array<string, string>> */
$reviewNotes = [
    'DF-0001' => [
        'strengths' => 'Kept the company pointed at one number through a noisy half, and made the hard call on the pricing change early rather than late.',
        'improvements' => 'Decisions land faster than the context behind them travels; the leadership team is sometimes reconstructing the reasoning afterwards.',
        'achievements' => 'Revenue up 46 percent year on year, and the leadership bench filled without an external hire.',
        'manager_comments' => 'Reviewed by the board rather than a line manager, and endorsed without reservation.',
        'employee_comments' => 'Agreed on the communication point. I will write the reasoning down before the decision, not after it.',
    ],
    'DF-0002' => [
        'strengths' => 'Turned people operations from a request queue into something the business plans around. The review framework is hers end to end.',
        'improvements' => 'Takes on delivery work that the team could carry, which slows the team down learning it.',
        'achievements' => 'Attrition down 3.1 points, and the first company-wide competency framework published and used.',
        'manager_comments' => 'The strongest half she has had. The next step is visibly delegating the operational load.',
        'employee_comments' => 'Fair. I will hand the onboarding programme over fully this quarter.',
    ],
    'DF-0003' => [
        'strengths' => 'New joiners consistently say the first week was the best they have had. That does not happen by accident.',
        'improvements' => 'Policy work stalls when it needs a decision from outside the team; it needs chasing sooner.',
        'achievements' => 'Thirty four policies migrated and the onboarding cycle cut from nine days to three.',
        'manager_comments' => 'Reliable, thorough and improving quickly. Ready for a larger remit next cycle.',
        'employee_comments' => 'Noted on chasing decisions. I will set a two week limit before escalating.',
    ],
    'DF-0004' => [
        'strengths' => 'Brought real discipline to the close, and the audit went through with no adjustments for the first time.',
        'improvements' => 'Reporting still leans on manual assembly, which puts the month end at risk when she is away.',
        'achievements' => 'Close cut from seven days to five, and payroll errors down to a single incident.',
        'manager_comments' => 'Excellent control environment. Automation is the right focus for the coming half.',
        'employee_comments' => 'Agreed. The reimbursement reports are already automated and the rest follows this quarter.',
    ],
    'DF-0005' => [
        'strengths' => 'Held the re-architecture to a schedule without letting quality slip, and shielded the team from the noise around it.',
        'improvements' => 'Scope arrives mid-sprint more often than it should; the team feels the churn even when the work is right.',
        'achievements' => 'Incidents down 26 percent and five of nine services migrated with no customer facing downtime.',
        'manager_comments' => 'A very strong half. Protect the sprint boundary and the team gets faster still.',
        'employee_comments' => 'The scope point is right. I am moving to a fixed mid-sprint review instead of ad hoc changes.',
    ],
    'DF-0006' => [
        'strengths' => 'The deepest technical judgement on the team, and she reasons out loud so others learn from it.',
        'improvements' => 'Takes the hardest piece herself when handing it over would have grown somebody else.',
        'achievements' => 'Took 180ms off the slowest endpoint and brought two engineers through their first rotation.',
        'manager_comments' => 'Operating well beyond her level. The development priority is deliberate delegation.',
        'employee_comments' => 'I will take the second hardest task next quarter and coach on the first.',
    ],
    'DF-0007' => [
        'strengths' => 'Picks up unfamiliar parts of the system quickly and asks precise questions rather than broad ones.',
        'improvements' => 'Pull requests arrive large and late, which makes them slow to review and risky to land.',
        'achievements' => 'Shipped the in-app notification channel and finished the database performance course.',
        'manager_comments' => 'Solid progress. Smaller, earlier changes will unlock the next level.',
        'employee_comments' => 'Agreed. I am splitting work into changes that can land inside a day.',
    ],
    'DF-0008' => [
        'strengths' => 'Finds the defects that matter, and writes them up so clearly that they are usually fixed the same day.',
        'improvements' => 'Automation coverage grows steadily but the plan for it is held in her head rather than written down.',
        'achievements' => 'One hundred and forty eight regression cases automated across payroll and leave.',
        'manager_comments' => 'Quietly raised the quality bar for the whole team this half.',
        'employee_comments' => 'I will publish the automation roadmap so the sequencing is visible.',
    ],
    'DF-0009' => [
        'strengths' => 'Reads a deal accurately and coaches the team through the difficult part rather than taking it over.',
        'improvements' => 'Forecasting is optimistic early in the quarter, which makes planning harder than it needs to be.',
        'achievements' => 'Seven new enterprise accounts and the region tracking ahead of last year.',
        'manager_comments' => 'Strong commercial half. Tighten the early quarter forecast and the rest follows.',
        'employee_comments' => 'Fair. I am moving to a stage-weighted forecast from this quarter.',
    ],
    'DF-0010' => [
        'strengths' => 'Excellent discovery work; customers describe her as the person who understood the problem first.',
        'improvements' => 'Pipeline building drops off while she is closing, which creates a gap a quarter later.',
        'achievements' => 'Closed the Nagpur manufacturing account a full quarter ahead of plan.',
        'manager_comments' => 'Good half with a clear pattern to fix. Protect prospecting time every week.',
        'employee_comments' => 'Agreed. Two prospecting blocks are now fixed in my calendar.',
    ],
    'DF-0011' => [
        'strengths' => 'Writes clearly about a complicated product, and the comparison pages are the strongest content we have.',
        'improvements' => 'Publishing cadence slips when interviews are involved, because the approval loop is not planned in.',
        'achievements' => 'Organic sessions up 38 percent and fifteen long form pieces published.',
        'manager_comments' => 'Real results this half. Build the approval time into the schedule rather than around it.',
        'employee_comments' => 'Noted. I will book approval slots at the same time as the interview.',
    ],
    'DF-0012' => [
        'strengths' => 'Turns vague requirements into something concrete quickly, and defends the user without being precious about it.',
        'improvements' => 'Engineering is brought in late in the design, which surfaces constraints after the work is committed.',
        'achievements' => 'Design system version two at 80 percent, and task completion up nine points.',
        'manager_comments' => 'Strong half. Bring engineering into the second review, not the final one.',
        'employee_comments' => 'Agreed, and the critique sessions now include two engineers by default.',
    ],
];

$competencyOffsets = [-0.5, 0.0, 0.5, -0.25, 0.25, 0.0, 0.5, -0.25, 0.0, 0.25];
$competencyNames = array_keys($competencyIds);

$scoreFor = static function (float $base, int $position, int $shift) use ($competencyOffsets): float {
    $offset = $competencyOffsets[($position + $shift) % count($competencyOffsets)];

    return round(max(1.0, min(5.0, $base + $offset)), 2);
};

$selfSubmittedAt = $clampToNow(sprintf('%d-01-08', $year));
$managerSubmittedAt = $clampToNow(sprintf('%d-01-21', $year));
$acknowledgedAt = $clampToNow(sprintf('%d-01-27', $year));

foreach ($employees as $code => $employeeId) {
    $managerCode = $reportsTo[$code];
    $managerId = $managerCode === null ? null : $employees[$managerCode];
    $notes = $reviewNotes[$code];
    $managerRating = $closedCycleRating[$code];
    $selfRating = round(min(5.0, $managerRating + 0.2), 2);

    // --- the closed cycle: everything submitted, most of it acknowledged ----
    $insertParticipant->execute([
        'id' => $identifier('participant:previous:' . $code),
        'review_cycle_id' => $previousCycleId,
        'employee_id' => $employeeId,
        'manager_id' => $managerId,
        'status' => 'completed',
        'created_at' => $clampToNow(sprintf('%d-12-05', $previousYear)),
    ]);

    $selfReviewId = $identifier('review:previous:self:' . $code);

    $insertReview->execute([
        'id' => $selfReviewId,
        'review_cycle_id' => $previousCycleId,
        'employee_id' => $employeeId,
        'reviewer_id' => $employeeId,
        'review_type' => 'self',
        'overall_rating' => $selfRating,
        'strengths' => $notes['strengths'],
        'improvements' => $notes['improvements'],
        'achievements' => $notes['achievements'],
        'manager_comments' => null,
        'employee_comments' => null,
        'is_anonymous' => 'false',
        'status' => 'submitted',
        'submitted_at' => $selfSubmittedAt,
        'acknowledged_at' => null,
        'created_at' => $clampToNow(sprintf('%d-12-20', $previousYear)),
        'updated_at' => $selfSubmittedAt,
    ]);

    foreach ($competencyNames as $position => $competencyName) {
        $insertRating->execute([
            'id' => $identifier(sprintf('rating:previous:self:%s:%s', $code, $competencyName)),
            'review_id' => $selfReviewId,
            'competency_id' => $competencyIds[$competencyName],
            'rating' => $scoreFor($selfRating, $position, 3),
            'comment' => null,
            'created_at' => $selfSubmittedAt,
        ]);
    }

    if ($managerId !== null) {
        $managerReviewId = $identifier('review:previous:manager:' . $code);

        $insertReview->execute([
            'id' => $managerReviewId,
            'review_cycle_id' => $previousCycleId,
            'employee_id' => $employeeId,
            'reviewer_id' => $managerId,
            'review_type' => 'manager',
            'overall_rating' => $managerRating,
            'strengths' => $notes['strengths'],
            'improvements' => $notes['improvements'],
            'achievements' => $notes['achievements'],
            'manager_comments' => $notes['manager_comments'],
            'employee_comments' => $notes['employee_comments'],
            'is_anonymous' => 'false',
            'status' => 'acknowledged',
            'submitted_at' => $managerSubmittedAt,
            'acknowledged_at' => $acknowledgedAt,
            'created_at' => $clampToNow(sprintf('%d-01-05', $year)),
            'updated_at' => $acknowledgedAt,
        ]);

        foreach ($competencyNames as $position => $competencyName) {
            $insertRating->execute([
                'id' => $identifier(sprintf('rating:previous:manager:%s:%s', $code, $competencyName)),
                'review_id' => $managerReviewId,
                'competency_id' => $competencyIds[$competencyName],
                'rating' => $scoreFor($managerRating, $position, 0),
                'comment' => null,
                'created_at' => $managerSubmittedAt,
            ]);
        }
    }

    // --- the open cycle: forms created, nothing written yet -----------------
    $insertParticipant->execute([
        'id' => $identifier('participant:current:' . $code),
        'review_cycle_id' => $currentCycleId,
        'employee_id' => $employeeId,
        'manager_id' => $managerId,
        'status' => 'pending',
        'created_at' => $clampToNow(sprintf('%d-06-20', $year)),
    ]);

    $insertReview->execute([
        'id' => $identifier('review:current:self:' . $code),
        'review_cycle_id' => $currentCycleId,
        'employee_id' => $employeeId,
        'reviewer_id' => $employeeId,
        'review_type' => 'self',
        'overall_rating' => null,
        'strengths' => null,
        'improvements' => null,
        'achievements' => null,
        'manager_comments' => null,
        'employee_comments' => null,
        'is_anonymous' => 'false',
        'status' => 'draft',
        'submitted_at' => null,
        'acknowledged_at' => null,
        'created_at' => $clampToNow(sprintf('%d-06-20', $year)),
        'updated_at' => $clampToNow(sprintf('%d-06-20', $year)),
    ]);

    if ($managerId !== null) {
        $insertReview->execute([
            'id' => $identifier('review:current:manager:' . $code),
            'review_cycle_id' => $currentCycleId,
            'employee_id' => $employeeId,
            'reviewer_id' => $managerId,
            'review_type' => 'manager',
            'overall_rating' => null,
            'strengths' => null,
            'improvements' => null,
            'achievements' => null,
            'manager_comments' => null,
            'employee_comments' => null,
            'is_anonymous' => 'false',
            'status' => 'draft',
            'submitted_at' => null,
            'acknowledged_at' => null,
            'created_at' => $clampToNow(sprintf('%d-06-20', $year)),
            'updated_at' => $clampToNow(sprintf('%d-06-20', $year)),
        ]);
    }
}

// Two peer reviews in the closed cycle, one of them anonymous, so the rule
// that an anonymous reviewer is never named has something to act on.
$peerReviews = [
    [
        'key' => 'peer:previous:priya',
        'subject' => 'DF-0006',
        'reviewer' => 'DF-0007',
        'rating' => 4.7,
        'anonymous' => true,
        'strengths' => 'Explains a design decision so that you can argue with it, which is rarer than it sounds.',
        'improvements' => 'Occasionally answers the question asked rather than the one behind it.',
    ],
    [
        'key' => 'peer:previous:vikram',
        'subject' => 'DF-0007',
        'reviewer' => 'DF-0008',
        'rating' => 3.9,
        'anonymous' => false,
        'strengths' => 'Responds to a failing test with curiosity rather than defensiveness, every time.',
        'improvements' => 'Test data setup is often left to whoever picks the work up next.',
    ],
];

foreach ($peerReviews as $peer) {
    $peerReviewId = $identifier('review:' . $peer['key']);

    $insertReview->execute([
        'id' => $peerReviewId,
        'review_cycle_id' => $previousCycleId,
        'employee_id' => $employees[$peer['subject']],
        'reviewer_id' => $employees[$peer['reviewer']],
        'review_type' => 'peer',
        'overall_rating' => $peer['rating'],
        'strengths' => $peer['strengths'],
        'improvements' => $peer['improvements'],
        'achievements' => null,
        'manager_comments' => null,
        'employee_comments' => null,
        'is_anonymous' => $peer['anonymous'] ? 'true' : 'false',
        'status' => 'submitted',
        'submitted_at' => $managerSubmittedAt,
        'acknowledged_at' => null,
        'created_at' => $clampToNow(sprintf('%d-01-06', $year)),
        'updated_at' => $managerSubmittedAt,
    ]);

    foreach ($competencyNames as $position => $competencyName) {
        $insertRating->execute([
            'id' => $identifier(sprintf('rating:%s:%s', $peer['key'], $competencyName)),
            'review_id' => $peerReviewId,
            'competency_id' => $competencyIds[$competencyName],
            'rating' => $scoreFor((float) $peer['rating'], $position, 5),
            'comment' => null,
            'created_at' => $managerSubmittedAt,
        ]);
    }
}

// --- Continuous feedback -----------------------------------------------------

/** @var list<array{from: string, to: string, type: string, visibility: string, subject: string, body: string, anonymous: bool, goal: ?string, days: int}> */
$feedbackEntries = [
    ['from' => 'DF-0005', 'to' => 'DF-0006', 'type' => 'appreciation', 'visibility' => 'manager', 'subject' => 'Latency work paid off', 'body' => 'The query plan analysis you did on the reporting endpoint took 180ms off the slowest call. Customers noticed before we announced it.', 'anonymous' => false, 'goal' => 'goal:DF-0006:0', 'days' => 4],
    ['from' => 'DF-0006', 'to' => 'DF-0007', 'type' => 'appreciation', 'visibility' => 'public', 'subject' => 'Thanks for the on-call swap', 'body' => 'You took the Diwali weekend rotation at two days notice and handled the two incidents that came up without escalating either. That was a real favour.', 'anonymous' => false, 'goal' => null, 'days' => 9],
    ['from' => 'DF-0007', 'to' => 'DF-0006', 'type' => 'request', 'visibility' => 'private', 'subject' => 'Pairing on the caching layer', 'body' => 'Could we spend an hour on the invalidation strategy before I commit to an approach? I would rather borrow your judgement early than rework it later.', 'anonymous' => false, 'goal' => null, 'days' => 6],
    ['from' => 'DF-0008', 'to' => 'DF-0005', 'type' => 'constructive', 'visibility' => 'manager', 'subject' => 'Release checklist is drifting', 'body' => 'The last two releases skipped the regression sign-off step because the cut happened early. It would help to move the cut or the sign-off, rather than dropping it quietly.', 'anonymous' => false, 'goal' => null, 'days' => 12],
    ['from' => 'DF-0002', 'to' => 'DF-0003', 'type' => 'appreciation', 'visibility' => 'manager', 'subject' => 'Onboarding week ran flawlessly', 'body' => 'Four joiners, four laptops ready, four managers briefed and nobody chasing anything on day one. Please write down how you did it.', 'anonymous' => false, 'goal' => null, 'days' => 15],
    ['from' => 'DF-0003', 'to' => 'DF-0011', 'type' => 'appreciation', 'visibility' => 'public', 'subject' => 'The culture piece landed well', 'body' => 'Three candidates mentioned the article about how we run reviews, unprompted, in their first interview. It is doing recruitment work as well as marketing work.', 'anonymous' => false, 'goal' => null, 'days' => 18],
    ['from' => 'DF-0009', 'to' => 'DF-0010', 'type' => 'appreciation', 'visibility' => 'manager', 'subject' => 'Nagpur close was excellent', 'body' => 'You held the price through three rounds and still had the relationship intact at signature. That is the hardest version of that deal to win.', 'anonymous' => false, 'goal' => 'goal:DF-0010:0', 'days' => 21],
    ['from' => 'DF-0010', 'to' => 'DF-0009', 'type' => 'request', 'visibility' => 'private', 'subject' => 'Coaching on enterprise negotiation', 'body' => 'I would like to sit in on the next two procurement conversations you run. Watching the sequencing would help more than another framework.', 'anonymous' => false, 'goal' => null, 'days' => 23],
    ['from' => 'DF-0012', 'to' => 'DF-0006', 'type' => 'constructive', 'visibility' => 'manager', 'subject' => 'Engineering input arrives late in design', 'body' => 'Twice this quarter a constraint surfaced after the design was signed off. If someone from the team joined the second review rather than the last one, we would catch those earlier.', 'anonymous' => false, 'goal' => null, 'days' => 26],
    ['from' => 'DF-0006', 'to' => 'DF-0012', 'type' => 'appreciation', 'visibility' => 'public', 'subject' => 'Design system version two', 'body' => 'The component documentation is good enough that we stopped guessing. Build time for a new screen has roughly halved.', 'anonymous' => false, 'goal' => 'goal:DF-0012:0', 'days' => 29],
    ['from' => 'DF-0004', 'to' => 'DF-0002', 'type' => 'constructive', 'visibility' => 'manager', 'subject' => 'Headcount approvals arriving late', 'body' => 'Two of the last three approvals reached finance after the month end cut, which forced a manual accrual. A day earlier would remove the whole problem.', 'anonymous' => false, 'goal' => null, 'days' => 31],
    ['from' => 'DF-0011', 'to' => 'DF-0002', 'type' => 'request', 'visibility' => 'private', 'subject' => 'Editing mentor for long form', 'body' => 'I would like a regular editor for the long form pieces. Someone senior reading a draft before it goes out would raise the standard faster than more volume will.', 'anonymous' => false, 'goal' => null, 'days' => 34],
    ['from' => 'DF-0007', 'to' => 'DF-0005', 'type' => 'constructive', 'visibility' => 'manager', 'subject' => 'Scope changes mid-sprint', 'body' => 'Work has been added after the sprint started in three of the last four sprints. The additions were reasonable each time, but the churn is what makes the estimates unreliable.', 'anonymous' => true, 'goal' => null, 'days' => 11],
    ['from' => 'DF-0010', 'to' => 'DF-0009', 'type' => 'constructive', 'visibility' => 'manager', 'subject' => 'Pipeline reviews overrun', 'body' => 'The weekly review regularly runs 30 minutes long and the last few accounts get rushed. Splitting it by segment would give the smaller deals a fair hearing.', 'anonymous' => true, 'goal' => null, 'days' => 17],
    ['from' => 'DF-0001', 'to' => 'DF-0002', 'type' => 'appreciation', 'visibility' => 'public', 'subject' => 'Retention work is showing', 'body' => 'Attrition is down three points and the exit interviews read completely differently from a year ago. That is the clearest result on the leadership scorecard.', 'anonymous' => false, 'goal' => null, 'days' => 38],
    ['from' => 'DF-0005', 'to' => 'DF-0008', 'type' => 'appreciation', 'visibility' => 'manager', 'subject' => 'Regression automation', 'body' => 'The payroll suite caught an arrears bug before it reached staging. That single case justified the whole quarter of automation work.', 'anonymous' => false, 'goal' => 'goal:DF-0008:0', 'days' => 8],
];

$insertFeedback = $pdo->prepare(
    'INSERT INTO feedback (id, from_employee_id, to_employee_id, feedback_type, visibility,
                           subject, body, related_goal_id, is_anonymous, created_at)
     VALUES (:id, :from_employee_id, :to_employee_id, :feedback_type, :visibility,
             :subject, :body, :related_goal_id, :is_anonymous, :created_at)
     ON CONFLICT DO NOTHING'
);

foreach ($feedbackEntries as $index => $entry) {
    $insertFeedback->execute([
        'id' => $identifier(sprintf('feedback:%d', $index)),
        'from_employee_id' => $employees[$entry['from']],
        'to_employee_id' => $employees[$entry['to']],
        'feedback_type' => $entry['type'],
        'visibility' => $entry['visibility'],
        'subject' => $entry['subject'],
        'body' => $entry['body'],
        'related_goal_id' => $entry['goal'] === null ? null : $identifier($entry['goal']),
        'is_anonymous' => $entry['anonymous'] ? 'true' : 'false',
        'created_at' => $now->modify('-' . $entry['days'] . ' days')->format(DateTimeInterface::ATOM),
    ]);
}
