<?php

declare(strict_types=1);

/**
 * Employee service seed data.
 *
 * Runs on every boot, so every insert is guarded by a lookup on the key that
 * makes the row unique. Reference data - the organisation structure and the
 * standard checklists - is always present. The twelve demo people, their
 * equipment and their paperwork appear only when SEED_DEMO_DATA is on.
 *
 * Identifiers for departments, designations, locations and people are fixed
 * in docs/SEED-IDENTIFIERS.md and copied verbatim: eight other services join
 * against them.
 */

use App\Models\ChecklistTemplates;
use App\Models\CompanyAssets;
use App\Models\Departments;
use App\Models\Designations;
use App\Models\EmployeeDocuments;
use App\Models\Employees;
use App\Models\Locations;
use App\Models\OnboardingTasks;
use App\Services\ChecklistBuilder;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

$departments = new Departments();
$designations = new Designations();
$locations = new Locations();
$templates = new ChecklistTemplates();
$employees = new Employees();
$documents = new EmployeeDocuments();
$assets = new CompanyAssets();
$onboarding = new OnboardingTasks();
$pdo = \Dayflow\Kernel\Database\Connection::pdo();

// Departments, locations and designations are declared once, in the shared
// roster, because every other service's seed joins against them too.
$DEPARTMENT = DemoCohort::DEPARTMENT;
$LOCATION = DemoCohort::LOCATION;
$DESIGNATION = DemoCohort::DESIGNATION;

// ---------------------------------------------------------------------------
// Departments. Parents are listed before their children so the self
// referencing foreign key is always satisfiable.
// ---------------------------------------------------------------------------
$departmentRows = [
    ['id' => $DEPARTMENT['executive'], 'name' => 'Executive', 'code' => 'EXEC', 'parent_id' => null,
        'cost_centre' => 'CC-100', 'description' => 'Company leadership and strategy.'],
    ['id' => $DEPARTMENT['people'], 'name' => 'People & Culture', 'code' => 'PC', 'parent_id' => $DEPARTMENT['executive'],
        'cost_centre' => 'CC-200', 'description' => 'Hiring, employee experience and HR operations.'],
    ['id' => $DEPARTMENT['finance'], 'name' => 'Finance', 'code' => 'FIN', 'parent_id' => $DEPARTMENT['executive'],
        'cost_centre' => 'CC-300', 'description' => 'Accounting, payroll funding and financial reporting.'],
    ['id' => $DEPARTMENT['engineering'], 'name' => 'Engineering', 'code' => 'ENG', 'parent_id' => $DEPARTMENT['executive'],
        'cost_centre' => 'CC-400', 'description' => 'Product engineering and platform reliability.'],
    ['id' => $DEPARTMENT['sales'], 'name' => 'Sales', 'code' => 'SALES', 'parent_id' => $DEPARTMENT['executive'],
        'cost_centre' => 'CC-500', 'description' => 'New business and account growth.'],
    ['id' => $DEPARTMENT['marketing'], 'name' => 'Marketing', 'code' => 'MKT', 'parent_id' => $DEPARTMENT['executive'],
        'cost_centre' => 'CC-600', 'description' => 'Brand, content and demand generation.'],
    ['id' => $DEPARTMENT['design'], 'name' => 'Design', 'code' => 'DSGN', 'parent_id' => $DEPARTMENT['engineering'],
        'cost_centre' => 'CC-410', 'description' => 'Product design and user research.'],
    ['id' => $DEPARTMENT['success'], 'name' => 'Customer Success', 'code' => 'CS', 'parent_id' => $DEPARTMENT['sales'],
        'cost_centre' => 'CC-510', 'description' => 'Onboarding, support and retention.'],
];

foreach ($departmentRows as $row) {
    if ($departments->find($row['id']) === null) {
        $departments->create($row + ['is_active' => true]);
    }
}

// ---------------------------------------------------------------------------
// Designations. Level is the seniority ladder, so a numeric comparison can
// answer "who is more senior" without interpreting the title text.
// ---------------------------------------------------------------------------
$designationRows = [
    ['id' => $DESIGNATION['ceo'], 'title' => 'Chief Executive Officer', 'code' => 'CEO', 'level' => 10,
        'department_id' => $DEPARTMENT['executive']],
    ['id' => $DESIGNATION['head_of_people'], 'title' => 'Head of People', 'code' => 'HOP', 'level' => 8,
        'department_id' => $DEPARTMENT['people']],
    ['id' => $DESIGNATION['finance_manager'], 'title' => 'Finance Manager', 'code' => 'FINMGR', 'level' => 7,
        'department_id' => $DEPARTMENT['finance']],
    ['id' => $DESIGNATION['engineering_manager'], 'title' => 'Engineering Manager', 'code' => 'ENGMGR', 'level' => 7,
        'department_id' => $DEPARTMENT['engineering']],
    ['id' => $DESIGNATION['regional_sales_head'], 'title' => 'Regional Sales Head', 'code' => 'SALESHEAD', 'level' => 7,
        'department_id' => $DEPARTMENT['sales']],
    ['id' => $DESIGNATION['marketing_manager'], 'title' => 'Marketing Manager', 'code' => 'MKTMGR', 'level' => 6,
        'department_id' => $DEPARTMENT['marketing']],
    ['id' => $DESIGNATION['design_lead'], 'title' => 'Design Lead', 'code' => 'DSGNLEAD', 'level' => 6,
        'department_id' => $DEPARTMENT['design']],
    ['id' => $DESIGNATION['hr_business_partner'], 'title' => 'HR Business Partner', 'code' => 'HRBP', 'level' => 5,
        'department_id' => $DEPARTMENT['people']],
    ['id' => $DESIGNATION['senior_software_engineer'], 'title' => 'Senior Software Engineer', 'code' => 'SSE', 'level' => 5,
        'department_id' => $DEPARTMENT['engineering']],
    ['id' => $DESIGNATION['product_designer'], 'title' => 'Product Designer', 'code' => 'PRODDSGN', 'level' => 4,
        'department_id' => $DEPARTMENT['design']],
    ['id' => $DESIGNATION['software_engineer'], 'title' => 'Software Engineer', 'code' => 'SWE', 'level' => 3,
        'department_id' => $DEPARTMENT['engineering']],
    ['id' => $DESIGNATION['qa_engineer'], 'title' => 'QA Engineer', 'code' => 'QAE', 'level' => 3,
        'department_id' => $DEPARTMENT['engineering']],
    ['id' => $DESIGNATION['account_executive'], 'title' => 'Account Executive', 'code' => 'ACCEXEC', 'level' => 3,
        'department_id' => $DEPARTMENT['sales']],
    ['id' => $DESIGNATION['content_strategist'], 'title' => 'Content Strategist', 'code' => 'CONTSTRAT', 'level' => 3,
        'department_id' => $DEPARTMENT['marketing']],
    ['id' => $DESIGNATION['support_specialist'], 'title' => 'Support Specialist', 'code' => 'SUPPSPEC', 'level' => 2,
        'department_id' => $DEPARTMENT['success']],
    ['id' => $DESIGNATION['customer_success_manager'], 'title' => 'Customer Success Manager', 'code' => 'CSMGR', 'level' => 6,
        'department_id' => $DEPARTMENT['success']],
    ['id' => $DESIGNATION['senior_qa_engineer'], 'title' => 'Senior QA Engineer', 'code' => 'SQAE', 'level' => 5,
        'department_id' => $DEPARTMENT['engineering']],
    ['id' => $DESIGNATION['ux_researcher'], 'title' => 'UX Researcher', 'code' => 'UXR', 'level' => 4,
        'department_id' => $DEPARTMENT['design']],
    ['id' => $DESIGNATION['financial_analyst'], 'title' => 'Financial Analyst', 'code' => 'FINANL', 'level' => 4,
        'department_id' => $DEPARTMENT['finance']],
    ['id' => $DESIGNATION['recruiter'], 'title' => 'Recruiter', 'code' => 'RECR', 'level' => 3,
        'department_id' => $DEPARTMENT['people']],
    ['id' => $DESIGNATION['sales_development_rep'], 'title' => 'Sales Development Representative', 'code' => 'SDR', 'level' => 2,
        'department_id' => $DEPARTMENT['sales']],
];

foreach ($designationRows as $row) {
    if ($designations->find($row['id']) === null) {
        $designations->create($row + ['is_active' => true]);
    }
}

// ---------------------------------------------------------------------------
// Locations.
// ---------------------------------------------------------------------------
$locationRows = [
    [
        'id' => $LOCATION['mumbai'],
        'name' => 'Mumbai HQ',
        'address_line1' => 'Level 8, Trident Business Park',
        'address_line2' => 'Bandra Kurla Complex',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'country' => 'India',
        'postal_code' => '400051',
        'timezone' => 'Asia/Kolkata',
        'is_remote' => false,
    ],
    [
        'id' => $LOCATION['bengaluru'],
        'name' => 'Bengaluru Tech Park',
        'address_line1' => 'Tower C, Prestige Tech Park',
        'address_line2' => 'Outer Ring Road, Marathahalli',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'country' => 'India',
        'postal_code' => '560103',
        'timezone' => 'Asia/Kolkata',
        'is_remote' => false,
    ],
    [
        'id' => $LOCATION['remote'],
        'name' => 'Remote - India',
        'address_line1' => null,
        'address_line2' => null,
        'city' => null,
        'state' => null,
        'country' => 'India',
        'postal_code' => null,
        'timezone' => 'Asia/Kolkata',
        'is_remote' => true,
    ],
];

foreach ($locationRows as $row) {
    if ($locations->find($row['id']) === null) {
        $locations->create($row + ['is_active' => true]);
    }
}

// ---------------------------------------------------------------------------
// Standard checklists.
//
// due_offset_days is counted from the joining date for a starter and from the
// leaving date for a leaver, so a negative offset means "before the day".
// ---------------------------------------------------------------------------
$checklistRows = [
    // Joining.
    [ChecklistTemplates::ONBOARDING, 'Sign and return the employment contract', 'paperwork', 10, 'hr_officer', -7,
        'Countersigned contract received and filed against the employee record.'],
    [ChecklistTemplates::ONBOARDING, 'Create work email and platform account', 'access', 20, 'hr_officer', -3,
        'Account raised with the employee role and the correct reporting line.'],
    [ChecklistTemplates::ONBOARDING, 'Submit identity and address proof', 'paperwork', 30, 'employee', 1,
        'Government issued photo identity plus a current address proof.'],
    [ChecklistTemplates::ONBOARDING, 'Issue laptop and access card', 'equipment', 40, 'hr_officer', 0,
        'Asset tags recorded against the employee so they can be recovered at exit.'],
    [ChecklistTemplates::ONBOARDING, 'Team introduction and workspace tour', 'induction', 50, 'manager', 0,
        'Introductions to the immediate team and a walk through the working day.'],
    [ChecklistTemplates::ONBOARDING, 'Register bank and tax details for payroll', 'payroll', 60, 'employee', 2,
        'Needed before the first payroll run that includes this person.'],
    [ChecklistTemplates::ONBOARDING, 'Submit educational and previous employment records', 'paperwork', 70, 'employee', 5,
        'Degree certificates and the relieving letter from the previous employer.'],
    [ChecklistTemplates::ONBOARDING, 'Complete workplace policy induction', 'induction', 80, 'employee', 7,
        'Code of conduct, information security and anti-harassment policies.'],
    [ChecklistTemplates::ONBOARDING, 'Agree first ninety day objectives', 'induction', 90, 'manager', 10,
        'Written objectives recorded so the probation review has something to measure.'],
    [ChecklistTemplates::ONBOARDING, 'Enrol in health insurance', 'benefits', 100, 'hr_officer', 14,
        'Employee and declared dependants added to the group policy.'],

    // Leaving.
    [ChecklistTemplates::OFFBOARDING, 'File the resignation or termination letter', 'paperwork', 10, 'hr_officer', -30,
        'The document that started the exit, filed against the employee record.'],
    [ChecklistTemplates::OFFBOARDING, 'Agree the handover plan with the manager', 'handover', 20, 'manager', -21,
        'Named owner for each responsibility, with dates.'],
    [ChecklistTemplates::OFFBOARDING, 'Complete knowledge transfer sessions', 'handover', 30, 'manager', -7,
        'Walkthroughs recorded or documented so nothing leaves with the person.'],
    [ChecklistTemplates::OFFBOARDING, 'Hold the exit interview', 'exit', 40, 'hr_officer', -3,
        'Feedback captured while the person is still available to give it.'],
    [ChecklistTemplates::OFFBOARDING, 'Reconcile final attendance and leave balance', 'payroll', 50, 'hr_officer', -2,
        'Unused leave encashed or recovered before the final settlement is calculated.'],
    [ChecklistTemplates::OFFBOARDING, 'Return laptop, access card and other company assets', 'equipment', 60, 'employee', 0,
        'Every asset still shown as issued must be returned or written off.'],
    [ChecklistTemplates::OFFBOARDING, 'Revoke system and building access', 'access', 70, 'hr_officer', 0,
        'Accounts disabled on the last working day, not afterwards.'],
    [ChecklistTemplates::OFFBOARDING, 'Process the full and final settlement', 'payroll', 80, 'finance', 7,
        'Final salary, encashment and any recovery paid in one settlement.'],
    [ChecklistTemplates::OFFBOARDING, 'Issue experience and relieving letters', 'paperwork', 90, 'hr_officer', 10,
        'Sent once the settlement has cleared.'],
];

foreach ($checklistRows as [$kind, $title, $category, $sequence, $ownerRole, $offset, $description]) {
    $existing = $templates->rawOne(
        'SELECT id FROM checklist_templates WHERE kind = :kind AND LOWER(title) = LOWER(:title)',
        ['kind' => $kind, 'title' => $title]
    );

    if ($existing !== null) {
        continue;
    }

    $templates->create([
        'kind' => $kind,
        'title' => $title,
        'description' => $description,
        'category' => $category,
        'sequence' => $sequence,
        'owner_role' => $ownerRole,
        'due_offset_days' => $offset,
        'is_active' => true,
    ]);
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demo people.
//
// Every one of them joined well over six months ago, so all twelve are past
// probation and confirmed. Managers appear before their reports so the self
// referencing key is satisfiable row by row.
// ---------------------------------------------------------------------------
// Every employee id in the demo, founding and extended, from the shared
// roster. Nothing here invents one.
$PERSON = DemoCohort::employeeIds();

$people = [
    [
        'code' => 'DF-0001', 'user_id' => '8044f8e4-46c5-442a-bfcb-ae491dcc9ded',
        'first_name' => 'Akshat', 'last_name' => 'Panicker',
        'work_email' => 'akshatpanicker@gmail.com', 'personal_email' => 'akshat.panicker@example.com',
        'phone' => '+91 98200 41001', 'alternate_phone' => null,
        'date_of_birth' => '1979-02-11', 'gender' => 'male', 'blood_group' => 'O+', 'marital_status' => 'married',
        'department' => 'executive', 'designation' => 'ceo', 'location' => 'mumbai',
        'manager' => null, 'joined_on' => '2019-04-01',
        'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400050',
        'address_line1' => '14 Carter Road', 'address_line2' => 'Bandra West',
        'emergency_contact_name' => 'Lakshmi Panicker', 'emergency_contact_phone' => '+91 98200 41002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0002', 'user_id' => 'b988b84d-bdef-4ef7-9809-a14bd4a07350',
        'first_name' => 'Meera', 'last_name' => 'Iyer',
        'work_email' => 'meera.iyer@dayflow.local', 'personal_email' => 'meera.iyer@example.com',
        'phone' => '+91 98200 42001', 'alternate_phone' => null,
        'date_of_birth' => '1985-07-23', 'gender' => 'female', 'blood_group' => 'A+', 'marital_status' => 'married',
        'department' => 'people', 'designation' => 'head_of_people', 'location' => 'mumbai',
        'manager' => 'DF-0001', 'joined_on' => '2019-06-17',
        'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400058',
        'address_line1' => '22 Shastri Nagar', 'address_line2' => 'Andheri West',
        'emergency_contact_name' => 'Suresh Iyer', 'emergency_contact_phone' => '+91 98200 42002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0003', 'user_id' => '6b46a7fa-5737-4095-ba77-ec70b858dceb',
        'first_name' => 'Rahul', 'last_name' => 'Deshmukh',
        'work_email' => 'rahul.deshmukh@dayflow.local', 'personal_email' => 'rahul.deshmukh@example.com',
        'phone' => '+91 98200 43001', 'alternate_phone' => null,
        'date_of_birth' => '1992-11-05', 'gender' => 'male', 'blood_group' => 'B+', 'marital_status' => 'single',
        'department' => 'people', 'designation' => 'hr_business_partner', 'location' => 'mumbai',
        'manager' => 'DF-0002', 'joined_on' => '2021-02-08',
        'city' => 'Thane', 'state' => 'Maharashtra', 'postal_code' => '400601',
        'address_line1' => '5 Kalyan Road', 'address_line2' => null,
        'emergency_contact_name' => 'Anita Deshmukh', 'emergency_contact_phone' => '+91 98200 43002',
        'emergency_contact_relation' => 'Mother',
    ],
    [
        'code' => 'DF-0004', 'user_id' => 'cc6201b4-274b-4599-9a97-42b368cedd53',
        'first_name' => 'Sneha', 'last_name' => 'Kulkarni',
        'work_email' => 'sneha.kulkarni@dayflow.local', 'personal_email' => 'sneha.kulkarni@example.com',
        'phone' => '+91 98200 44001', 'alternate_phone' => null,
        'date_of_birth' => '1988-03-19', 'gender' => 'female', 'blood_group' => 'AB+', 'marital_status' => 'married',
        'department' => 'finance', 'designation' => 'finance_manager', 'location' => 'mumbai',
        'manager' => 'DF-0001', 'joined_on' => '2020-08-03',
        'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400028',
        'address_line1' => '9 Gokhale Road', 'address_line2' => 'Dadar West',
        'emergency_contact_name' => 'Mahesh Kulkarni', 'emergency_contact_phone' => '+91 98200 44002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0005', 'user_id' => '78886f55-a3df-4831-8ea0-36204747eb75',
        'first_name' => 'Arjun', 'last_name' => 'Nair',
        'work_email' => 'arjun.nair@dayflow.local', 'personal_email' => 'arjun.nair@example.com',
        'phone' => '+91 98450 45001', 'alternate_phone' => null,
        'date_of_birth' => '1987-09-30', 'gender' => 'male', 'blood_group' => 'O-', 'marital_status' => 'married',
        'department' => 'engineering', 'designation' => 'engineering_manager', 'location' => 'bengaluru',
        'manager' => 'DF-0001', 'joined_on' => '2020-01-13',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560037',
        'address_line1' => '31 Whitefield Main Road', 'address_line2' => null,
        'emergency_contact_name' => 'Divya Nair', 'emergency_contact_phone' => '+91 98450 45002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0006', 'user_id' => '208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237',
        'first_name' => 'Priya', 'last_name' => 'Sharma',
        'work_email' => 'priya.sharma@dayflow.local', 'personal_email' => 'priya.sharma@example.com',
        'phone' => '+91 98450 46001', 'alternate_phone' => null,
        'date_of_birth' => '1994-05-14', 'gender' => 'female', 'blood_group' => 'B-', 'marital_status' => 'single',
        'department' => 'engineering', 'designation' => 'senior_software_engineer', 'location' => 'bengaluru',
        'manager' => 'DF-0005', 'joined_on' => '2021-07-05',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560066',
        'address_line1' => '7 Varthur Road', 'address_line2' => null,
        'emergency_contact_name' => 'Rakesh Sharma', 'emergency_contact_phone' => '+91 98450 46002',
        'emergency_contact_relation' => 'Father',
    ],
    [
        'code' => 'DF-0007', 'user_id' => 'a26c2525-f201-44d6-9ccc-d06ea655b536',
        'first_name' => 'Vikram', 'last_name' => 'Reddy',
        'work_email' => 'vikram.reddy@dayflow.local', 'personal_email' => 'vikram.reddy@example.com',
        'phone' => '+91 98450 47001', 'alternate_phone' => null,
        'date_of_birth' => '1996-12-02', 'gender' => 'male', 'blood_group' => 'A-', 'marital_status' => 'single',
        'department' => 'engineering', 'designation' => 'software_engineer', 'location' => 'bengaluru',
        'manager' => 'DF-0005', 'joined_on' => '2022-09-12',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560103',
        'address_line1' => '18 Bellandur Gate', 'address_line2' => null,
        'emergency_contact_name' => 'Sudha Reddy', 'emergency_contact_phone' => '+91 98450 47002',
        'emergency_contact_relation' => 'Mother',
    ],
    [
        'code' => 'DF-0008', 'user_id' => '0008e98e-7d40-451a-88c5-53419b6c993f',
        'first_name' => 'Ananya', 'last_name' => 'Bose',
        'work_email' => 'ananya.bose@dayflow.local', 'personal_email' => 'ananya.bose@example.com',
        'phone' => '+91 98450 48001', 'alternate_phone' => null,
        'date_of_birth' => '1997-08-21', 'gender' => 'female', 'blood_group' => 'O+', 'marital_status' => 'single',
        'department' => 'engineering', 'designation' => 'qa_engineer', 'location' => 'bengaluru',
        'manager' => 'DF-0005', 'joined_on' => '2023-01-09',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560048',
        'address_line1' => '44 Kadugodi Main Road', 'address_line2' => null,
        'emergency_contact_name' => 'Partha Bose', 'emergency_contact_phone' => '+91 98450 48002',
        'emergency_contact_relation' => 'Father',
    ],
    [
        'code' => 'DF-0009', 'user_id' => '7569e1f1-3659-42b9-89d3-bb90ab97c323',
        'first_name' => 'Karthik', 'last_name' => 'Menon',
        'work_email' => 'karthik.menon@dayflow.local', 'personal_email' => 'karthik.menon@example.com',
        'phone' => '+91 98200 49001', 'alternate_phone' => null,
        'date_of_birth' => '1986-01-27', 'gender' => 'male', 'blood_group' => 'B+', 'marital_status' => 'married',
        'department' => 'sales', 'designation' => 'regional_sales_head', 'location' => 'mumbai',
        'manager' => 'DF-0001', 'joined_on' => '2020-11-02',
        'city' => 'Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400076',
        'address_line1' => '3 Powai Lake View', 'address_line2' => null,
        'emergency_contact_name' => 'Radhika Menon', 'emergency_contact_phone' => '+91 98200 49002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0010', 'user_id' => '1c85286f-f790-4c8b-808d-bd63d619c205',
        'first_name' => 'Divya', 'last_name' => 'Raghavan',
        'work_email' => 'divya.raghavan@dayflow.local', 'personal_email' => 'divya.raghavan@example.com',
        'phone' => '+91 98200 50001', 'alternate_phone' => null,
        'date_of_birth' => '1995-04-08', 'gender' => 'female', 'blood_group' => 'A+', 'marital_status' => 'single',
        'department' => 'sales', 'designation' => 'account_executive', 'location' => 'mumbai',
        'manager' => 'DF-0009', 'joined_on' => '2022-03-21',
        'city' => 'Navi Mumbai', 'state' => 'Maharashtra', 'postal_code' => '400614',
        'address_line1' => '12 Palm Beach Road', 'address_line2' => 'Belapur',
        'emergency_contact_name' => 'Geetha Raghavan', 'emergency_contact_phone' => '+91 98200 50002',
        'emergency_contact_relation' => 'Mother',
    ],
    [
        'code' => 'DF-0011', 'user_id' => '23513a48-7f1d-483b-8280-75a1c98aa8b9',
        'first_name' => 'Imran', 'last_name' => 'Qureshi',
        'work_email' => 'imran.qureshi@dayflow.local', 'personal_email' => 'imran.qureshi@example.com',
        'phone' => '+91 98330 51001', 'alternate_phone' => null,
        'date_of_birth' => '1993-10-16', 'gender' => 'male', 'blood_group' => 'AB-', 'marital_status' => 'married',
        'department' => 'marketing', 'designation' => 'content_strategist', 'location' => 'remote',
        'manager' => 'DF-0002', 'joined_on' => '2023-05-15',
        'city' => 'Pune', 'state' => 'Maharashtra', 'postal_code' => '411001',
        'address_line1' => '27 Camp Road', 'address_line2' => null,
        'emergency_contact_name' => 'Sana Qureshi', 'emergency_contact_phone' => '+91 98330 51002',
        'emergency_contact_relation' => 'Spouse',
    ],
    [
        'code' => 'DF-0012', 'user_id' => '051c1f79-ef38-41d1-a44b-f21077a131a4',
        'first_name' => 'Neha', 'last_name' => 'Joshi',
        'work_email' => 'neha.joshi@dayflow.local', 'personal_email' => 'neha.joshi@example.com',
        'phone' => '+91 98450 52001', 'alternate_phone' => null,
        'date_of_birth' => '1996-06-29', 'gender' => 'female', 'blood_group' => 'O+', 'marital_status' => 'single',
        'department' => 'design', 'designation' => 'product_designer', 'location' => 'bengaluru',
        'manager' => 'DF-0005', 'joined_on' => '2023-08-28',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560076',
        'address_line1' => '65 Bannerghatta Road', 'address_line2' => null,
        'emergency_contact_name' => 'Vinod Joshi', 'emergency_contact_phone' => '+91 98450 52002',
        'emergency_contact_relation' => 'Father',
    ],
];

// The rest of the company. The twelve above are written out one by one because
// they are quoted in the documentation and used in worked examples; everybody
// else comes from the shared roster, which is also what the other eight
// services read.
foreach (DemoCohort::extended() as $extra) {
    $people[] = $extra;
}

$seedingOn = Clock::today();

// Employee code and work email are both unique, and either of them can already
// be held by a record somebody created through the application - the code
// sequence hands out DF-0013 to the next new starter whether or not the demo
// roster also wants it. Checking the identifier alone would then abort the
// whole seed on a constraint violation part way through, taking the service
// down with it. So each of the three keys is checked, and a person whose keys
// are spoken for is skipped with a line in the log.
$claimed = $pdo->prepare(
    'SELECT id FROM employees WHERE employee_code = :employee_code OR work_email = :work_email LIMIT 1'
);

/** @var array<string, true> Codes that could not be seeded, so their reports know. */
$skipped = [];

foreach ($people as $person) {
    $id = $PERSON[$person['code']];

    if ($employees->find($id) !== null) {
        continue;
    }

    $claimed->execute([
        'employee_code' => $person['code'],
        'work_email' => $person['work_email'],
    ]);

    if ($claimed->fetchColumn() !== false) {
        Logger::warning('Demo employee skipped: code or work address already in use', [
            'employee_code' => $person['code'],
            'work_email' => $person['work_email'],
        ]);

        $skipped[$person['code']] = true;

        continue;
    }

    // A manager who was skipped is not in the table, and manager_id is a
    // self-referencing foreign key. Inserting the report anyway would abort
    // the seed on a constraint violation and take the service down with it,
    // so the person is seeded without a manager rather than not at all.
    $managerCode = $person['manager'];

    if ($managerCode !== null && (isset($skipped[$managerCode]) || $employees->find($PERSON[$managerCode]) === null)) {
        Logger::warning('Demo employee seeded without a manager: the manager was skipped', [
            'employee_code' => $person['code'],
            'manager_code' => $managerCode,
        ]);

        $managerCode = null;
    }

    $joinedOn = new DateTimeImmutable($person['joined_on']);
    $probationEnd = $joinedOn->modify('+6 months');

    // Not everyone joined years ago any more. Somebody who started last month
    // is on probation, and a company where nobody ever is has no probation
    // report worth opening.
    $confirmed = $probationEnd->format('Y-m-d') <= $seedingOn;

    // A few people have left. They stay on the record - an HR system that
    // forgets somebody the day they go cannot answer a question about last
    // quarter - but inactive, which is what gives attrition and the exits
    // report something real to count.
    $exitDate = $person['exit_date'] ?? null;

    $employees->create([
        'id' => $id,
        'employee_code' => $person['code'],
        'user_id' => $person['user_id'],
        'first_name' => $person['first_name'],
        'last_name' => $person['last_name'],
        'work_email' => $person['work_email'],
        'personal_email' => $person['personal_email'],
        'phone' => $person['phone'],
        'alternate_phone' => $person['alternate_phone'],
        'date_of_birth' => $person['date_of_birth'],
        'gender' => $person['gender'],
        'blood_group' => $person['blood_group'],
        'marital_status' => $person['marital_status'],
        'address_line1' => $person['address_line1'],
        'address_line2' => $person['address_line2'],
        'city' => $person['city'],
        'state' => $person['state'],
        'country' => 'India',
        'postal_code' => $person['postal_code'],
        'emergency_contact_name' => $person['emergency_contact_name'],
        'emergency_contact_phone' => $person['emergency_contact_phone'],
        'emergency_contact_relation' => $person['emergency_contact_relation'],
        'department_id' => $DEPARTMENT[$person['department']],
        'designation_id' => $DESIGNATION[$person['designation']],
        'location_id' => $LOCATION[$person['location']],
        'manager_id' => $managerCode === null ? null : $PERSON[$managerCode],
        'employment_type' => 'full_time',
        // "resigned" rather than an invented "exited": employment_status is a
        // checked enum of probation | confirmed | notice_period | resigned |
        // terminated, and every departure in the roster is a voluntary one.
        'employment_status' => $exitDate !== null
            ? 'resigned'
            : ($confirmed ? 'confirmed' : 'probation'),
        'joined_on' => $person['joined_on'],
        'probation_end_on' => $probationEnd->format('Y-m-d'),
        'confirmed_on' => $confirmed ? $probationEnd->format('Y-m-d') : null,
        'exit_date' => $exitDate,
        'exit_reason' => $person['exit_reason'] ?? null,
        'is_active' => $exitDate === null,
    ]);
}

// Department heads can only be set once the people exist.
$heads = [
    'executive' => 'DF-0001',
    'people' => 'DF-0002',
    'finance' => 'DF-0004',
    'engineering' => 'DF-0005',
    'sales' => 'DF-0009',
    'design' => 'DF-0013',
    'marketing' => 'DF-0014',
    'success' => 'DF-0015',
];

foreach ($heads as $departmentKey => $personCode) {
    $department = $departments->find($DEPARTMENT[$departmentKey]);

    if ($employees->find($PERSON[$personCode]) === null) {
        continue;
    }

    if ($department !== null && $department['head_employee_id'] === null) {
        $departments->update($DEPARTMENT[$departmentKey], ['head_employee_id' => $PERSON[$personCode]]);
    }
}

// ---------------------------------------------------------------------------
// Completed joiner checklists.
//
// Everyone in the sample settled in long ago, so their checklists are built
// from the same templates a new starter gets and then closed. That leaves the
// in-flight onboarding queue correctly empty while still giving each person a
// real checklist to open.
// ---------------------------------------------------------------------------
$checklistBuilder = new ChecklistBuilder($templates);
$hrOfficerId = $PERSON['DF-0003'];

foreach ($people as $person) {
    $employee = $employees->find($PERSON[$person['code']]);

    if ($employee === null) {
        continue;
    }

    $checklistBuilder->apply(
        $onboarding,
        ChecklistTemplates::ONBOARDING,
        $employee,
        (string) $employee['joined_on']
    );

    $onboarding->execute(
        "UPDATE onboarding_tasks
            SET status = 'completed',
                completed_at = COALESCE(due_on, :joined_on) + TIME '17:30',
                completed_by = :completed_by,
                updated_at = NOW()
          WHERE employee_id = :employee_id AND status <> 'completed'",
        [
            'employee_id' => $employee['id'],
            'joined_on' => $employee['joined_on'],
            'completed_by' => $hrOfficerId,
        ]
    );
}

// ---------------------------------------------------------------------------
// Company equipment. The asset tag is the natural key, so a re-run finds the
// existing row rather than issuing a second laptop to the same person.
// ---------------------------------------------------------------------------
$assetRows = [
    ['DF-AST-0001', 'laptop', 'MacBook Pro 14 inch M3', 'C02XK1PQMD6T', '2023-11-14', 18999900, 'good', 'DF-0005', '2023-11-20'],
    ['DF-AST-0002', 'laptop', 'MacBook Air 13 inch M2', 'C02YL2QRND7U', '2023-06-02', 11499900, 'good', 'DF-0006', '2023-06-09'],
    ['DF-AST-0003', 'laptop', 'Dell Latitude 7440', 'DL7440X2291', '2022-10-11', 9899900, 'fair', 'DF-0007', '2022-10-17'],
    ['DF-AST-0004', 'laptop', 'Dell Latitude 5440', 'DL5440X8830', '2023-02-06', 8499900, 'good', 'DF-0008', '2023-02-13'],
    ['DF-AST-0005', 'monitor', 'Dell UltraSharp U2723QE 27 inch', 'MN27U993412', '2023-03-15', 5499900, 'good', 'DF-0006', '2023-03-22'],
    ['DF-AST-0006', 'phone', 'Samsung Galaxy S23', 'SGS23IN774510', '2023-08-01', 7499900, 'good', 'DF-0009', '2023-08-07'],
    ['DF-AST-0007', 'access_card', 'Mumbai HQ access card', 'HQ-CARD-0114', '2022-03-18', 50000, 'good', 'DF-0010', '2022-03-21'],
    ['DF-AST-0008', 'laptop', 'ThinkPad X1 Carbon Gen 11', 'TPX1C55210', '2024-01-22', 15499900, 'new', null, null],
    ['DF-AST-0009', 'monitor', 'LG 27UP850 27 inch', 'LG27UP41277', '2024-01-22', 3899900, 'new', null, null],
];

foreach ($assetRows as [$tag, $category, $name, $serial, $purchasedOn, $valueMinor, $condition, $holderCode, $assignedOn]) {
    if ($assets->findByTag($tag) !== null) {
        continue;
    }

    $assets->create([
        'asset_tag' => $tag,
        'category' => $category,
        'name' => $name,
        'serial_number' => $serial,
        'purchased_on' => $purchasedOn,
        'value_minor' => $valueMinor,
        'condition' => $condition,
        'assigned_to' => $holderCode === null ? null : $PERSON[$holderCode],
        'assigned_on' => $assignedOn,
        'status' => $holderCode === null ? 'available' : 'assigned',
    ]);
}

// ---------------------------------------------------------------------------
// Document metadata.
//
// Deliberately metadata only: no bytes are written to disk by a seed, so the
// listing and expiry screens have something to show while a download honestly
// reports that the file is not there.
// ---------------------------------------------------------------------------
$today = Clock::parse(Clock::today());

$documentRows = [
    ['DF-0006', 'identity', 'Passport', 'priya-passport.pdf', 'application/pdf', 482113, '2019-02-18', '2029-02-17', 'verified'],
    ['DF-0006', 'education', 'B.Tech degree certificate', 'priya-degree.pdf', 'application/pdf', 291044, '2016-06-30', null, 'verified'],
    ['DF-0007', 'identity', 'Driving licence', 'vikram-licence.pdf', 'application/pdf', 154820, '2019-09-04', $today->modify('+18 days')->format('Y-m-d'), 'verified'],
    ['DF-0008', 'contract', 'Signed employment contract', 'ananya-contract.pdf', 'application/pdf', 662901, '2023-01-05', null, 'verified'],
    ['DF-0010', 'identity', 'Passport', 'divya-passport.pdf', 'application/pdf', 501773, '2020-01-30', $today->modify('+120 days')->format('Y-m-d'), 'pending'],
    ['DF-0012', 'education', 'Design portfolio and transcripts', 'neha-portfolio.pdf', 'application/pdf', 1884213, '2023-08-01', null, 'pending'],
];

/**
 * Writes a small, valid PDF for a seeded document.
 *
 * The seed used to record the metadata and nothing else, which read fine on
 * the listing screen and then gave a 404 on every Download button in the
 * application. A placeholder that says what it is beats a dead link: the
 * screen behaves exactly as it will with a real upload, and nobody is left
 * wondering whether the download feature works.
 */
$writePlaceholderPdf = static function (string $storedFilename, string $title, string $owner): int {
    $base = rtrim(str_replace('\\', '/', Env::get('STORAGE_PATH', '/var/www/storage')), '/');
    $directory = $base . '/uploads/documents';

    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        return 0;
    }

    $path = $directory . '/' . $storedFilename;

    if (is_file($path)) {
        return (int) filesize($path);
    }

    $lines = [
        'Dayflow HRMS - sample document',
        $title,
        $owner,
        'This is placeholder content created by the demo seed.',
        'A real upload replaces it with the file that was uploaded.',
    ];

    $content = "BT /F1 13 Tf 58 742 Td 18 TL\n";
    foreach ($lines as $line) {
        $content .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ") Tj T*\n";
    }
    $content .= 'ET';

    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefAt = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefAt . "\n%%EOF";

    return file_put_contents($path, $pdf) === false ? 0 : strlen($pdf);
};

foreach ($documentRows as [$code, $category, $title, $originalName, $mime, $size, $issuedOn, $expiresOn, $status]) {
    $employeeId = $PERSON[$code];

    $existing = $documents->rawOne(
        'SELECT id FROM employee_documents WHERE employee_id = :employee_id AND LOWER(title) = LOWER(:title)',
        ['employee_id' => $employeeId, 'title' => $title]
    );

    if ($existing !== null) {
        continue;
    }

    $verified = in_array($status, ['verified', 'rejected'], true);

    // Derived rather than random, so a re-run finds the file it wrote last time
    // instead of orphaning it and creating another.
    $storedFilename = Str::uuidFor('document:' . $code . ':' . $title, '3f9a7c21-5d84-4e6b-9c07-2a1e8b5d43f0') . '.pdf';
    $written = $writePlaceholderPdf($storedFilename, $title, $code);

    $documents->create([
        'employee_id' => $employeeId,
        'category' => $category,
        'title' => $title,
        'original_filename' => $originalName,
        'stored_filename' => $storedFilename,
        'mime_type' => $mime,
        // The recorded size has to be the size of the file that is actually
        // there, or a download reports a length it cannot deliver.
        'size_bytes' => $written > 0 ? $written : $size,
        'checksum' => hash('sha256', $employeeId . '|' . $title),
        'issued_on' => $issuedOn,
        'expires_on' => $expiresOn,
        'status' => $status,
        'verified_by' => $verified ? $hrOfficerId : null,
        'verified_at' => $verified ? Clock::iso() : null,
        'uploaded_by' => $employeeId,
    ]);
}
