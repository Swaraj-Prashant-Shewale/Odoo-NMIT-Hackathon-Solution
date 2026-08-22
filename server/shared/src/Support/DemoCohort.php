<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/**
 * The demo company, defined once for all nine services.
 *
 * Every service seeds its own slice of the same organisation - attendance for
 * these people, payslips for these people, goals for these people - so they
 * have to agree on who exists and what identifier each person has before any
 * of them writes a row.
 *
 * The twelve founding records keep the literal identifiers published in
 * docs/SEED-IDENTIFIERS.md, because they are quoted in the documentation and
 * used in worked examples. Everyone after them has an identifier derived from
 * their employee code, so a service can work it out for itself: there is no
 * table of UUIDs to copy into nine files and keep in step, and no ordering
 * requirement between the seeds.
 *
 * Twelve people is too few for a dashboard to say anything. A headcount chart
 * with twelve dots, an attendance rate over twelve rows and a payroll cost
 * built from twelve salaries all read as placeholder data. Sixty is enough for
 * every figure on every screen to look like it came from a real company.
 */
final class DemoCohort
{
    /** Namespace for every derived identifier. Changing it renames everyone. */
    private const NAMESPACE_EMPLOYEE = '3f9a7c21-5d84-4e6b-9c07-2a1e8b5d43f0';
    private const NAMESPACE_USER = '6c1d4b83-2e75-4a19-8f36-0b9d7e2c51a4';

    public const EMAIL_DOMAIN = 'dayflow.local';

    /** The password every demo account is created with. */
    public const PASSWORD = 'Dayflow@2026';

    /** Departments, by the key the seeds use. */
    public const DEPARTMENT = [
        'executive' => '922f3d9b-20be-461f-9b69-90928701ce93',
        'people' => '2458e540-24e4-46e5-b2cf-6caac4b9c637',
        'finance' => 'a15919aa-5727-4fb9-84e9-2980007cfc58',
        'engineering' => '7ec7b7c3-126b-412a-babf-fd79d002e921',
        'sales' => '9b8d41e8-ad45-4b7d-a541-38d781be09da',
        'marketing' => 'd171a368-ea6a-45f5-9b66-6767052fe916',
        'design' => '923c3a06-0b09-4573-a4da-142c606ae61e',
        'success' => '1a530254-57f0-4a8d-adb9-7d5520153f50',
    ];

    public const LOCATION = [
        'mumbai' => 'd5bb3746-fdf8-424d-ba9d-3d7d407756a9',
        'bengaluru' => '624423f1-04b4-404c-8d55-d1cb82fa8ae4',
        'remote' => '9f48758c-53ae-4d68-a454-d9c10767104e',
    ];

    public const DESIGNATION = [
        'account_executive' => '329f1e51-a2ee-4d24-ab04-a7720bf3eac7',
        'ceo' => 'db3205fc-c11b-431e-950e-573c5bcb4013',
        'content_strategist' => '54592217-2316-4ce5-8a1f-e96689271325',
        'design_lead' => 'e87c7e19-87dd-4a7b-96b4-a45783ff2e5a',
        'engineering_manager' => '63eb9418-6dd3-4b81-acea-60100d6c34f4',
        'finance_manager' => 'f40ac94b-3792-4be6-b014-7ea7e096d903',
        'hr_business_partner' => '2ec743f1-5668-473b-80a0-cd58e856a3ee',
        'head_of_people' => '298fdc62-813c-42ed-9ff8-fa69b3b0e545',
        'marketing_manager' => '45703372-73ad-40cf-9a64-5116d3746f1e',
        'product_designer' => 'e8174888-ed8c-4588-9834-1904a711c4fc',
        'qa_engineer' => '12cfde66-4ff3-4686-9d8b-863041025353',
        'regional_sales_head' => '8176a283-be13-4eae-b0b2-32e4529688fe',
        'senior_software_engineer' => '96ce567b-48d0-40de-af4e-52f9e4245e7a',
        'software_engineer' => 'e3e8d1ec-c401-4979-8dc4-7034fc84ed23',
        'support_specialist' => 'a2316ce2-8eb6-490c-8927-37eba16c368f',
        // Added with the wider cohort so Customer Success has someone to report
        // to rather than hanging off Sales.
        'customer_success_manager' => '5b6c0f2a-1d47-4a93-8e25-c3f80a6b7d19',
        'senior_qa_engineer' => 'b0e5d31c-7a48-4f62-9d15-6e2c8a94f307',
        'financial_analyst' => 'd42a6f80-3c95-4b17-a6e8-91f05d7b2c63',
        'recruiter' => '7e91b524-8d0a-4c36-b58f-2a6d13c9e04b',
        'sales_development_rep' => 'c8305a17-6b29-4de4-91a0-5f7c24b8e63d',
        'ux_researcher' => '9a47c6b3-0e51-482f-b73d-84c1f5029ae6',
    ];

    /**
     * The twelve founding records, identifiers as published.
     *
     * @return array<string, string> employee code => employee id
     */
    public const FOUNDING = [
        'DF-0001' => '28010836-0cfb-4b4d-aa20-b0b0f2bedfe3',
        'DF-0002' => 'a9f5c390-db56-42fa-96d9-fb1ff57ca041',
        'DF-0003' => 'bf5b3018-b6eb-4646-8507-8b6e6fdca490',
        'DF-0004' => '31033589-5508-433c-966e-508f653b54be',
        'DF-0005' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
        'DF-0006' => '5f2fc57a-9d26-4279-bbdf-054496fd35ea',
        'DF-0007' => 'c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700',
        'DF-0008' => '5419981a-cd0a-4f65-9ff0-bcd16dd43a91',
        'DF-0009' => '4449d500-6fb5-48f3-8b9f-4fac8516da38',
        'DF-0010' => '2b5d3904-9f2f-4ae3-9248-88adac38023a',
        'DF-0011' => 'a2f82591-b4b7-4de2-99ae-1098b9111ae7',
        'DF-0012' => '87ae5bec-96ae-4226-92df-519a54dbda64',
    ];

    /** The user ids of the twelve, as published. */
    public const FOUNDING_USERS = [
        'DF-0001' => '8044f8e4-46c5-442a-bfcb-ae491dcc9ded',
        'DF-0002' => 'b988b84d-bdef-4ef7-9809-a14bd4a07350',
        'DF-0003' => '6b46a7fa-5737-4095-ba77-ec70b858dceb',
        'DF-0004' => 'cc6201b4-274b-4599-9a97-42b368cedd53',
        'DF-0005' => '78886f55-a3df-4831-8ea0-36204747eb75',
        'DF-0006' => '208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237',
        'DF-0007' => 'a26c2525-f201-44d6-9ccc-d06ea655b536',
        'DF-0008' => '0008e98e-7d40-451a-88c5-53419b6c993f',
        'DF-0009' => '7569e1f1-3659-42b9-89d3-bb90ab97c323',
        'DF-0010' => '1c85286f-f790-4c8b-808d-bd63d619c205',
        'DF-0011' => '23513a48-7f1d-483b-8280-75a1c98aa8b9',
        'DF-0012' => '051c1f79-ef38-41d1-a44b-f21077a131a4',
    ];

    /**
     * Everyone added on top of the founding twelve.
     *
     * Written out rather than generated from name lists, because a directory
     * full of "Employee 34" is no more convincing than twelve rows. Each entry
     * is a whole person: a department that matches their job title, a manager
     * in the same part of the tree, a joining date consistent with their
     * seniority, and a home city near the office they are attached to.
     *
     * Ordered so that a manager always appears before anyone reporting to them,
     * which is what lets the employee seed insert them one row at a time
     * against a self-referencing foreign key.
     *
     * @return list<array<string, mixed>>
     */
    public static function extended(): array
    {
        // department, designation, location, manager, joined, role, band
        $rows = [
            // --- Leads first ------------------------------------------------
            ['DF-0013', 'Aditi', 'Balakrishnan', 'female', 'design', 'design_lead', 'bengaluru', 'DF-0001', '2020-06-15', 'manager', 2600000],
            ['DF-0014', 'Rohan', 'Chatterjee', 'male', 'marketing', 'marketing_manager', 'mumbai', 'DF-0001', '2020-09-07', 'manager', 2450000],
            ['DF-0015', 'Sanjana', 'Pillai', 'female', 'success', 'customer_success_manager', 'bengaluru', 'DF-0001', '2021-01-11', 'manager', 2300000],

            // --- Engineering, under Arjun Nair (DF-0005) ---------------------
            ['DF-0016', 'Aakash', 'Verma', 'male', 'engineering', 'senior_software_engineer', 'bengaluru', 'DF-0005', '2021-03-15', 'employee', 2150000],
            ['DF-0017', 'Sneha', 'Rajagopal', 'female', 'engineering', 'senior_software_engineer', 'bengaluru', 'DF-0005', '2021-08-02', 'employee', 2100000],
            ['DF-0018', 'Farhan', 'Sheikh', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2022-01-17', 'employee', 1480000],
            ['DF-0019', 'Lakshmi', 'Narayanan', 'female', 'engineering', 'software_engineer', 'remote', 'DF-0005', '2022-04-04', 'employee', 1450000],
            ['DF-0020', 'Tanmay', 'Kulkarni', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2022-06-20', 'employee', 1420000],
            ['DF-0021', 'Ishita', 'Chakraborty', 'female', 'engineering', 'senior_qa_engineer', 'bengaluru', 'DF-0005', '2021-11-08', 'employee', 1720000],
            ['DF-0022', 'Nikhil', 'Ghosh', 'male', 'engineering', 'qa_engineer', 'bengaluru', 'DF-0005', '2023-02-13', 'employee', 1180000],
            ['DF-0023', 'Pooja', 'Deshpande', 'female', 'engineering', 'software_engineer', 'remote', 'DF-0005', '2023-04-24', 'employee', 1380000],
            ['DF-0024', 'Siddharth', 'Rao', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2023-07-10', 'employee', 1350000],
            ['DF-0025', 'Meghna', 'Prasad', 'female', 'engineering', 'qa_engineer', 'remote', 'DF-0005', '2023-10-02', 'employee', 1120000],
            ['DF-0026', 'Yash', 'Agarwal', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2024-01-08', 'employee', 1320000],
            ['DF-0027', 'Rhea', 'Mathew', 'female', 'engineering', 'senior_software_engineer', 'bengaluru', 'DF-0005', '2024-03-18', 'employee', 2050000],
            ['DF-0028', 'Devansh', 'Trivedi', 'male', 'engineering', 'software_engineer', 'remote', 'DF-0005', '2024-06-03', 'employee', 1300000],
            ['DF-0029', 'Kavya', 'Subramanian', 'female', 'engineering', 'qa_engineer', 'bengaluru', 'DF-0005', '2024-08-19', 'employee', 1150000],
            ['DF-0030', 'Arnav', 'Bhattacharya', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2024-11-11', 'employee', 1280000],
            ['DF-0031', 'Shreya', 'Kamath', 'female', 'engineering', 'software_engineer', 'remote', 'DF-0005', '2025-02-10', 'employee', 1260000],
            ['DF-0032', 'Harshil', 'Patel', 'male', 'engineering', 'qa_engineer', 'bengaluru', 'DF-0005', '2025-05-05', 'employee', 1100000],

            // --- Design, under Aditi Balakrishnan (DF-0013) ------------------
            ['DF-0033', 'Ira', 'Sengupta', 'female', 'design', 'product_designer', 'bengaluru', 'DF-0013', '2021-09-20', 'employee', 1620000],
            ['DF-0034', 'Kabir', 'Anand', 'male', 'design', 'product_designer', 'remote', 'DF-0013', '2022-08-16', 'employee', 1580000],
            ['DF-0035', 'Ritika', 'Malhotra', 'female', 'design', 'ux_researcher', 'bengaluru', 'DF-0013', '2023-03-06', 'employee', 1540000],
            ['DF-0036', 'Aryan', 'Saxena', 'male', 'design', 'product_designer', 'bengaluru', 'DF-0013', '2024-05-13', 'employee', 1500000],

            // --- Sales, under Karthik Menon (DF-0009) ------------------------
            ['DF-0037', 'Nandini', 'Shetty', 'female', 'sales', 'account_executive', 'mumbai', 'DF-0009', '2021-05-24', 'employee', 1560000],
            ['DF-0038', 'Rajat', 'Khanna', 'male', 'sales', 'account_executive', 'mumbai', 'DF-0009', '2022-02-07', 'employee', 1520000],
            ['DF-0039', 'Tara', 'Fernandes', 'female', 'sales', 'account_executive', 'remote', 'DF-0009', '2022-11-14', 'employee', 1490000],
            ['DF-0040', 'Manav', 'Chopra', 'male', 'sales', 'sales_development_rep', 'mumbai', 'DF-0009', '2023-06-05', 'employee', 1020000],
            ['DF-0041', 'Zoya', 'Ansari', 'female', 'sales', 'sales_development_rep', 'mumbai', 'DF-0009', '2023-09-25', 'employee', 1000000],
            ['DF-0042', 'Gaurav', 'Sinha', 'male', 'sales', 'account_executive', 'remote', 'DF-0009', '2024-02-26', 'employee', 1460000],
            ['DF-0043', 'Anika', 'Roy', 'female', 'sales', 'sales_development_rep', 'mumbai', 'DF-0009', '2024-09-16', 'employee', 980000],
            ['DF-0044', 'Vivek', 'Nambiar', 'male', 'sales', 'account_executive', 'mumbai', 'DF-0009', '2025-01-13', 'employee', 1440000],

            // --- Marketing, under Rohan Chatterjee (DF-0014) -----------------
            ['DF-0045', 'Diya', 'Kapoor', 'female', 'marketing', 'content_strategist', 'mumbai', 'DF-0014', '2021-10-18', 'employee', 1340000],
            ['DF-0046', 'Ayaan', 'Mirza', 'male', 'marketing', 'content_strategist', 'remote', 'DF-0014', '2023-01-23', 'employee', 1300000],
            ['DF-0047', 'Naina', 'Bajaj', 'female', 'marketing', 'content_strategist', 'mumbai', 'DF-0014', '2024-04-15', 'employee', 1270000],

            // --- Customer Success, under Sanjana Pillai (DF-0015) ------------
            ['DF-0048', 'Rohit', 'Dutta', 'male', 'success', 'support_specialist', 'bengaluru', 'DF-0015', '2021-07-12', 'employee', 980000],
            ['DF-0049', 'Sara', 'Thomas', 'female', 'success', 'support_specialist', 'remote', 'DF-0015', '2022-05-09', 'employee', 960000],
            ['DF-0050', 'Aniket', 'Jadhav', 'male', 'success', 'support_specialist', 'bengaluru', 'DF-0015', '2023-08-07', 'employee', 940000],
            ['DF-0051', 'Bhavna', 'Gupta', 'female', 'success', 'support_specialist', 'remote', 'DF-0015', '2024-07-22', 'employee', 920000],
            ['DF-0052', 'Omkar', 'Sawant', 'male', 'success', 'support_specialist', 'bengaluru', 'DF-0015', '2025-03-17', 'employee', 900000],

            // --- Finance, under Sneha Kulkarni (DF-0004) ---------------------
            ['DF-0053', 'Pallavi', 'Hegde', 'female', 'finance', 'financial_analyst', 'mumbai', 'DF-0004', '2021-12-06', 'employee', 1420000],
            ['DF-0054', 'Nitin', 'Bansal', 'male', 'finance', 'financial_analyst', 'mumbai', 'DF-0004', '2023-05-29', 'employee', 1380000],
            ['DF-0055', 'Juhi', 'Varma', 'female', 'finance', 'financial_analyst', 'remote', 'DF-0004', '2024-10-14', 'employee', 1340000],

            // --- People & Culture, under Meera Iyer (DF-0002) ----------------
            ['DF-0056', 'Aparna', 'Krishnan', 'female', 'people', 'hr_business_partner', 'mumbai', 'DF-0002', '2022-07-11', 'hr_officer', 1680000],
            ['DF-0057', 'Dev', 'Shah', 'male', 'people', 'recruiter', 'mumbai', 'DF-0002', '2023-11-20', 'employee', 1180000],
            ['DF-0058', 'Sonal', 'Mehta', 'female', 'people', 'recruiter', 'remote', 'DF-0002', '2024-12-09', 'employee', 1160000],

            // --- The last twelve months --------------------------------------
            // A headcount chart needs the headcount to change. Without joiners
            // and leavers inside the window the trend is a flat line and the
            // "joiners this month" and "leavers this month" tiles read zero
            // forever, whatever the rest of the data says.
            ['DF-0059', 'Ved', 'Iyengar', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2025-09-15', 'employee', 1250000],
            ['DF-0060', 'Amrita', 'Sen', 'female', 'success', 'support_specialist', 'remote', 'DF-0015', '2025-10-06', 'employee', 890000],
            ['DF-0061', 'Nikita', 'Bhandari', 'female', 'sales', 'sales_development_rep', 'mumbai', 'DF-0009', '2025-11-17', 'employee', 960000],
            ['DF-0062', 'Rehan', 'Kaul', 'male', 'engineering', 'qa_engineer', 'remote', 'DF-0005', '2025-12-08', 'employee', 1080000],
            ['DF-0063', 'Trisha', 'Menon', 'female', 'design', 'product_designer', 'bengaluru', 'DF-0013', '2026-01-19', 'employee', 1470000],
            ['DF-0064', 'Abhinav', 'Joshi', 'male', 'marketing', 'content_strategist', 'mumbai', 'DF-0014', '2026-02-09', 'employee', 1240000],
            ['DF-0065', 'Mira', 'Dsouza', 'female', 'people', 'recruiter', 'remote', 'DF-0002', '2026-03-16', 'employee', 1140000],
            ['DF-0066', 'Kunal', 'Rastogi', 'male', 'engineering', 'software_engineer', 'bengaluru', 'DF-0005', '2026-04-13', 'employee', 1290000],
            ['DF-0067', 'Sara', 'Qadri', 'female', 'success', 'support_specialist', 'bengaluru', 'DF-0015', '2026-05-11', 'employee', 880000],
            ['DF-0068', 'Aarav', 'Bhat', 'male', 'finance', 'financial_analyst', 'mumbai', 'DF-0004', '2026-06-15', 'employee', 1310000],
            ['DF-0069', 'Ipsita', 'Nayak', 'female', 'engineering', 'software_engineer', 'remote', 'DF-0005', '2026-07-06', 'employee', 1270000],
            ['DF-0070', 'Zaid', 'Merchant', 'male', 'sales', 'account_executive', 'mumbai', 'DF-0009', '2026-08-03', 'employee', 1430000],
        ];

        // People who have since left. They stay in the table - an HR system
        // that forgets somebody the day they leave cannot answer a question
        // about last quarter - but they are inactive, and they are what makes
        // the attrition figure and the exits report say anything at all.
        $departures = [
            'DF-0037' => ['2025-11-28', 'Resigned to join a competitor'],
            'DF-0046' => ['2026-02-27', 'Resigned for further study'],
            'DF-0022' => ['2026-05-29', 'Resigned for a better opportunity'],
            'DF-0051' => ['2026-07-31', 'Resigned after relocating'],
        ];

        $cities = [
            'mumbai' => ['Mumbai', 'Maharashtra', '400076'],
            'bengaluru' => ['Bengaluru', 'Karnataka', '560076'],
            'remote' => ['Pune', 'Maharashtra', '411001'],
        ];

        $people = [];

        foreach ($rows as $index => [$code, $first, $last, $gender, $department, $designation, $location, $manager, $joinedOn, $role, $ctc]) {
            [$city, $state, $postcode] = $cities[$location];

            // Deterministic but varied: the same person gets the same details on
            // every boot, and no two people share a phone number.
            $serial = 13 + $index;
            $birthYear = 1985 + (($serial * 7) % 17);
            $birthMonth = 1 + (($serial * 5) % 12);
            $birthDay = 1 + (($serial * 11) % 28);

            $people[] = [
                'code' => $code,
                'employee_id' => self::employeeId($code),
                'user_id' => self::userId($code),
                'first_name' => $first,
                'last_name' => $last,
                'work_email' => self::email($first, $last),
                'personal_email' => strtolower($first . '.' . $last) . '@example.com',
                'phone' => sprintf('+91 9%d %05d', 7000 + ($serial % 3000), 10000 + ($serial * 137) % 89999),
                'alternate_phone' => null,
                'date_of_birth' => sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay),
                'gender' => $gender,
                'blood_group' => ['O+', 'A+', 'B+', 'AB+', 'O-', 'A-'][$serial % 6],
                'marital_status' => $serial % 3 === 0 ? 'married' : 'single',
                'department' => $department,
                'designation' => $designation,
                'location' => $location,
                'manager' => $manager,
                'joined_on' => $joinedOn,
                'city' => $city,
                'state' => $state,
                'postal_code' => $postcode,
                'address_line1' => sprintf('%d %s', 1 + ($serial * 3) % 120, ['Link Road', 'MG Road', 'Church Street', 'Hill View', 'Lake Avenue', 'Park Lane'][$serial % 6]),
                'address_line2' => null,
                'emergency_contact_name' => ['Anil', 'Sunita', 'Ramesh', 'Kavita', 'Prakash', 'Latha'][$serial % 6] . ' ' . $last,
                'emergency_contact_phone' => sprintf('+91 9%d %05d', 7000 + (($serial + 1) % 3000), 10000 + (($serial + 1) * 137) % 89999),
                'emergency_contact_relation' => $serial % 3 === 0 ? 'Spouse' : ($serial % 2 === 0 ? 'Father' : 'Mother'),
                'role' => $role,
                'ctc_annual' => $ctc,
                'exit_date' => $departures[$code][0] ?? null,
                'exit_reason' => $departures[$code][1] ?? null,
            ];
        }

        return $people;
    }

    /**
     * Only the people still employed.
     *
     * Attendance, payroll and training have nothing to say about somebody who
     * left in February, so the seeds that generate those work from this list
     * rather than from the whole roster.
     *
     * @return array<string, string>
     */
    public static function activeEmployeeIds(): array
    {
        $gone = [];

        foreach (self::extended() as $person) {
            if ($person['exit_date'] !== null) {
                $gone[$person['code']] = true;
            }
        }

        return array_filter(
            self::employeeIds(),
            static fn (string $code): bool => !isset($gone[$code]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Every demo person, founding and extended, as code => employee id.
     *
     * This is what the other eight services iterate over.
     *
     * @return array<string, string>
     */
    public static function employeeIds(): array
    {
        $ids = self::FOUNDING;

        foreach (self::extended() as $person) {
            $ids[$person['code']] = $person['employee_id'];
        }

        return $ids;
    }

    /** @return array<string, string> code => user id, founding and extended. */
    public static function userIds(): array
    {
        $ids = self::FOUNDING_USERS;

        foreach (self::extended() as $person) {
            $ids[$person['code']] = $person['user_id'];
        }

        return $ids;
    }

    /** The employee id for a code, whether founding or derived. */
    public static function employeeId(string $code): string
    {
        return self::FOUNDING[$code] ?? Str::uuidFor($code, self::NAMESPACE_EMPLOYEE);
    }

    /** The identity user id for a code, whether founding or derived. */
    public static function userId(string $code): string
    {
        return self::FOUNDING_USERS[$code] ?? Str::uuidFor($code, self::NAMESPACE_USER);
    }

    /**
     * A stable identifier for a record one service owns outright.
     *
     * A payslip or an enrolment is nobody else's business, but it still has to
     * keep the same id across boots or a re-run would insert it a second time.
     */
    public static function recordId(string $service, string $key): string
    {
        return Str::uuidFor($service . ':' . $key, self::NAMESPACE_EMPLOYEE);
    }

    public static function email(string $first, string $last): string
    {
        return strtolower($first . '.' . $last) . '@' . self::EMAIL_DOMAIN;
    }

    /**
     * The reporting line, which is also the approval routing.
     *
     * The leave and talent seeds both need it, and both used to keep their own
     * copy - so a change to the org chart in one of them silently disagreed
     * with the other.
     *
     * @return array<string, string|null> code => manager's code
     */
    public static function managers(): array
    {
        $line = [
            'DF-0001' => null,
            'DF-0002' => 'DF-0001',
            'DF-0003' => 'DF-0002',
            'DF-0004' => 'DF-0001',
            'DF-0005' => 'DF-0001',
            'DF-0006' => 'DF-0005',
            'DF-0007' => 'DF-0005',
            'DF-0008' => 'DF-0005',
            'DF-0009' => 'DF-0001',
            'DF-0010' => 'DF-0009',
            'DF-0011' => 'DF-0002',
            'DF-0012' => 'DF-0005',
        ];

        foreach (self::extended() as $person) {
            $line[$person['code']] = $person['manager'];
        }

        return $line;
    }

    /**
     * The same line, but with somebody to sign off for the person at the top.
     *
     * A leave request needs an approver even when the requester is the chief
     * executive, so the head of people signs those.
     *
     * @return array<string, string>
     */
    public static function approvers(): array
    {
        $line = self::managers();
        $line['DF-0001'] = 'DF-0002';

        /** @var array<string, string> $line */
        return array_filter($line, static fn (?string $code): bool => $code !== null);
    }

    /** Everyone who has at least one direct report. @return list<string> */
    public static function managerCodes(): array
    {
        return array_values(array_unique(array_filter(self::managers())));
    }


    /**
     * Leave requests the demo company has raised.
     *
     * Shared, because two services need the same answer. The leave service
     * writes the requests; the attendance service has to mark those same days
     * "on leave" in its register, and it cannot read the leave schema to find
     * out which they are - each service role holds rights on its own schema
     * and nothing else. Keeping a second, separate list in the attendance seed
     * would have shown people at their desks on days the leave calendar says
     * they were away.
     *
     * Each entry is [code, type, week offset, first day, last day, status,
     * half-day period, reason, decision note]. Dates are resolved against the
     * Monday of the current week by leaveDates() below, so the whole history
     * moves with the calendar and today is always in the middle of it.
     *
     * @return list<array{0:string,1:string,2:int,3:int,4:int,5:string,6:?string,7:string,8:?string}>
     */
    public static function leavePlan(): array
    {
        // [employee, type, week offset, first day, last day, status, half day period, reason, note]
        $plan = [
            ['DF-0006', 'EARNED', -18, 0, 4, 'approved', null, 'Family wedding in Kochi', 'Enjoy the celebration.'],
            ['DF-0007', 'SICK', -17, 2, 3, 'approved', null, 'Viral fever', 'Get well soon.'],
            ['DF-0001', 'EARNED', -16, 0, 4, 'approved', null, 'Annual break', 'Approved.'],
            ['DF-0010', 'CASUAL', -14, 0, 0, 'approved', null, 'Personal errand', 'Approved.'],
            ['DF-0008', 'EARNED', -13, 0, 4, 'approved', null, 'Trip to Coorg', 'Approved.'],
            ['DF-0011', 'SICK', -13, 1, 2, 'cancelled', null, 'Fever, recovered sooner than expected', null],
            ['DF-0012', 'SICK', -12, 2, 2, 'approved', null, 'Migraine', 'Approved.'],
            ['DF-0006', 'CASUAL', -11, 4, 4, 'approved', 'first_half', 'School admission interview', 'Approved.'],
            ['DF-0007', 'EARNED', -11, 0, 4, 'rejected', null, 'Trek in Himachal', 'Release week - please move this to the following month.'],
            ['DF-0011', 'EARNED', -10, 0, 4, 'approved', null, 'Visiting family in Lucknow', 'Approved.'],
            ['DF-0005', 'EARNED', -9, 0, 4, 'approved', null, 'Summer holiday with the family', 'Approved.'],
            ['DF-0003', 'SICK', -8, 1, 2, 'approved', null, 'Food poisoning', 'Approved.'],
            ['DF-0010', 'EARNED', -8, 0, 4, 'rejected', null, 'Extended break', 'Quarter close - only one of the two weeks can be spared.'],
            ['DF-0008', 'CASUAL', -8, 3, 4, 'cancelled', null, 'Family visit, plans changed', null],
            ['DF-0006', 'EARNED', -6, 0, 2, 'cancelled', null, 'Short break, postponed', null],
            ['DF-0009', 'EARNED', -6, 0, 4, 'approved', null, 'Kerala trip', 'Approved.'],
            ['DF-0007', 'CASUAL', -5, 1, 1, 'approved', null, 'House shifting', 'Approved.'],
            ['DF-0012', 'CASUAL', -5, 0, 2, 'rejected', null, 'Long weekend', 'Design review is that week - please reschedule.'],
            ['DF-0004', 'EARNED', -4, 0, 4, 'approved', null, 'Annual leave', 'Approved.'],
            ['DF-0012', 'EARNED', -3, 0, 2, 'approved', null, 'Long weekend in Goa', 'Approved.'],
            ['DF-0002', 'EARNED', -2, 0, 4, 'approved', null, 'Annual leave', 'Approved.'],
            ['DF-0008', 'SICK', -1, 0, 1, 'approved', null, 'Dental surgery', 'Approved.'],
            ['DF-0010', 'SICK', -1, 2, 2, 'approved', null, 'Unwell', 'Approved.'],
            ['DF-0011', 'CASUAL', 0, 0, 0, 'approved', null, 'Passport appointment', 'Approved.'],
            ['DF-0007', 'SICK', 1, 0, 1, 'pending', null, 'Scheduled minor procedure', null],
            ['DF-0012', 'CASUAL', 2, 0, 0, 'pending', null, 'Family function', null],
            ['DF-0003', 'CASUAL', 2, 1, 1, 'pending', 'second_half', 'Bank appointment', null],
            ['DF-0006', 'EARNED', 3, 0, 4, 'pending', null, 'Holiday in Ladakh', null],
            ['DF-0010', 'EARNED', 4, 0, 4, 'pending', null, 'Wedding in the family', null],
            ['DF-0011', 'EARNED', 5, 0, 4, 'pending', null, 'Visiting parents', null],

            // Approved leave that has not been taken yet. Without any, the "Away in
            // the next fortnight" card on every manager's dashboard is permanently
            // empty and the leave calendar has nothing ahead of today - the seed had
            // plenty of approved leave, but all of it in the past.
            ['DF-0016', 'EARNED', 0, 3, 4, 'approved', null, 'Extended weekend', 'Approved.'],
            ['DF-0033', 'CASUAL', 0, 4, 4, 'approved', null, 'Family commitment', 'Approved.'],
            ['DF-0018', 'EARNED', 1, 0, 2, 'approved', null, 'Trip to Hampi', 'Approved.'],
            ['DF-0045', 'SICK', 1, 1, 2, 'approved', null, 'Planned procedure', 'Approved.'],
            ['DF-0053', 'EARNED', 1, 0, 4, 'approved', null, 'Annual leave', 'Approved.'],
            ['DF-0027', 'CASUAL', 1, 3, 3, 'approved', 'second_half', 'Visa appointment', 'Approved.'],
            ['DF-0048', 'EARNED', 2, 0, 4, 'approved', null, 'Holiday in Munnar', 'Approved.'],
            ['DF-0039', 'EARNED', 2, 1, 3, 'approved', null, 'Wedding in the family', 'Approved.'],
            ['DF-0020', 'CASUAL', 2, 0, 0, 'approved', null, 'House move', 'Approved.'],
            ['DF-0056', 'EARNED', 3, 0, 4, 'approved', null, 'Annual leave', 'Approved.'],
            ['DF-0064', 'SICK', 0, 2, 2, 'approved', null, 'Unwell', 'Approved.'],
            ['DF-0035', 'EARNED', 3, 2, 4, 'approved', null, 'Short break', 'Approved.'],
        ];

        // The plan above names a couple of dozen people out of seventy. Everybody else
        // needs some history too, or a leave balance is entitlement with nothing taken
        // against it and the utilisation chart is drawn from a fifth of the company.
        $leaveKinds = ['EARNED', 'EARNED', 'SICK', 'CASUAL', 'EARNED', 'SICK'];
        $leaveReasons = [
            'EARNED' => ['Annual leave', 'Family holiday', 'Short break', 'Visiting family', 'Long weekend'],
            'SICK' => ['Viral fever', 'Unwell', 'Recovering at home', 'Dental appointment'],
            'CASUAL' => ['Personal errand', 'Bank appointment', 'Family function', 'House matters'],
        ];

        $plannedFor = [];
        foreach ($plan as $entry) {
            $plannedFor[$entry[0]] = ($plannedFor[$entry[0]] ?? 0) + 1;
        }

        foreach (DemoCohort::extended() as $person) {
            if ($person['exit_date'] !== null) {
                continue;
            }

            $code = $person['code'];
            $serial = (int) substr($code, 3);

            // Two or three past requests each, deterministic so a re-run reproduces
            // exactly the same history rather than accreting a new one.
            $wanted = 2 + ($serial % 2);

            for ($n = ($plannedFor[$code] ?? 0); $n < $wanted; $n++) {
                $kind = $leaveKinds[($serial + $n) % count($leaveKinds)];
                $reasons = $leaveReasons[$kind];

                // Spread across the trailing five months, avoiding the weeks the
                // hand-written plan already occupies near today.
                $week = -(4 + (($serial * 3 + $n * 5) % 16));
                $first = ($serial + $n) % 3;
                $span = $kind === 'EARNED' ? min(4, $first + 2) : $first;

                $status = (($serial + $n) % 9) === 0
                    ? 'cancelled'
                    : ((($serial + $n) % 11) === 0 ? 'rejected' : 'approved');

                $plan[] = [
                    $code,
                    $kind,
                    $week,
                    $first,
                    max($first, $span),
                    $status,
                    null,
                    $reasons[($serial + $n) % count($reasons)],
                    $status === 'approved' ? 'Approved.' : ($status === 'rejected' ? 'Please reschedule around the release.' : null),
                ];
            }
        }

        return $plan;
    }

    /**
     * The first and last day of one plan entry.
     *
     * @return array{0: string, 1: string} starts_on, ends_on
     */
    public static function leaveDates(int $weekOffset, int $firstDay, int $lastDay, \DateTimeImmutable $thisMonday): array
    {
        $start = $thisMonday
            ->modify(sprintf('%+d weeks', $weekOffset))
            ->modify(sprintf('+%d days', $firstDay));

        $end = $thisMonday
            ->modify(sprintf('%+d weeks', $weekOffset))
            ->modify(sprintf('+%d days', $lastDay));

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * Approved leave as employee code => list of dates away.
     *
     * Weekends are left out: nobody is recorded as taking leave on a Saturday.
     *
     * @return array<string, list<string>>
     */
    public static function approvedLeaveDays(\DateTimeImmutable $thisMonday): array
    {
        $away = [];

        foreach (self::leavePlan() as [$code, , $weekOffset, $firstDay, $lastDay, $status, $halfPeriod]) {
            if ($status !== 'approved' || $halfPeriod !== null) {
                continue;
            }

            [$from, $to] = self::leaveDates($weekOffset, $firstDay, $lastDay, $thisMonday);

            $cursor = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);

            while ($cursor <= $end) {
                if (!in_array((int) $cursor->format('N'), [6, 7], true)) {
                    $away[$code][] = $cursor->format('Y-m-d');
                }

                $cursor = $cursor->modify('+1 day');
            }
        }

        return $away;
    }

    /** The joining date for a code, so other services can bound their history. */
    public static function joinedOn(string $code): ?string
    {
        static $dates = null;

        if ($dates === null) {
            $dates = [
                'DF-0001' => '2019-04-01', 'DF-0002' => '2019-06-17', 'DF-0003' => '2021-02-08',
                'DF-0004' => '2020-08-03', 'DF-0005' => '2020-01-13', 'DF-0006' => '2021-07-05',
                'DF-0007' => '2022-09-12', 'DF-0008' => '2023-01-09', 'DF-0009' => '2020-11-02',
                'DF-0010' => '2022-03-21', 'DF-0011' => '2023-05-15', 'DF-0012' => '2023-08-28',
            ];

            foreach (self::extended() as $person) {
                $dates[$person['code']] = $person['joined_on'];
            }
        }

        return $dates[$code] ?? null;
    }
}
