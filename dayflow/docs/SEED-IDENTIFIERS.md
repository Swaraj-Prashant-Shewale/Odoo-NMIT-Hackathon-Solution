# Dayflow Seed Identifiers

Nine services seed their own demo data independently. They can only produce a
coherent picture if they agree on identifiers, so every demo UUID is fixed
here rather than generated at run time.

**Rule:** if your seed references a person, a department, a designation or a
location, copy the UUID from this file verbatim. Never invent one, and never
call `Str::uuid()` for a record another service also seeds.

Records your service alone owns (a leave request, a payslip, an enrolment) may
use generated UUIDs freely.

---

## Departments (owned by employee-service)

| Department | UUID |
| ---------- | ---- |
| Executive | `922f3d9b-20be-461f-9b69-90928701ce93` |
| People & Culture | `2458e540-24e4-46e5-b2cf-6caac4b9c637` |
| Finance | `a15919aa-5727-4fb9-84e9-2980007cfc58` |
| Engineering | `7ec7b7c3-126b-412a-babf-fd79d002e921` |
| Sales | `9b8d41e8-ad45-4b7d-a541-38d781be09da` |
| Marketing | `d171a368-ea6a-45f5-9b66-6767052fe916` |
| Design | `923c3a06-0b09-4573-a4da-142c606ae61e` |
| Customer Success | `1a530254-57f0-4a8d-adb9-7d5520153f50` |

## Locations (owned by employee-service)

| Location | UUID |
| -------- | ---- |
| Mumbai HQ | `d5bb3746-fdf8-424d-ba9d-3d7d407756a9` |
| Bengaluru Tech Park | `624423f1-04b4-404c-8d55-d1cb82fa8ae4` |
| Remote - India | `9f48758c-53ae-4d68-a454-d9c10767104e` |

## Designations (owned by employee-service)

| Designation | UUID |
| ----------- | ---- |
| Account Executive | `329f1e51-a2ee-4d24-ab04-a7720bf3eac7` |
| Chief Executive Officer | `db3205fc-c11b-431e-950e-573c5bcb4013` |
| Content Strategist | `54592217-2316-4ce5-8a1f-e96689271325` |
| Design Lead | `e87c7e19-87dd-4a7b-96b4-a45783ff2e5a` |
| Engineering Manager | `63eb9418-6dd3-4b81-acea-60100d6c34f4` |
| Finance Manager | `f40ac94b-3792-4be6-b014-7ea7e096d903` |
| HR Business Partner | `2ec743f1-5668-473b-80a0-cd58e856a3ee` |
| Head of People | `298fdc62-813c-42ed-9ff8-fa69b3b0e545` |
| Marketing Manager | `45703372-73ad-40cf-9a64-5116d3746f1e` |
| Product Designer | `e8174888-ed8c-4588-9834-1904a711c4fc` |
| QA Engineer | `12cfde66-4ff3-4686-9d8b-863041025353` |
| Regional Sales Head | `8176a283-be13-4eae-b0b2-32e4529688fe` |
| Senior Software Engineer | `96ce567b-48d0-40de-af4e-52f9e4245e7a` |
| Software Engineer | `e3e8d1ec-c401-4979-8dc4-7034fc84ed23` |
| Support Specialist | `a2316ce2-8eb6-490c-8927-37eba16c368f` |

## People

`user_id` is seeded by identity-service. `employee_id` is seeded by
employee-service. Every other service references **`employee_id` only**.

The first row is the real administrator account, created from
`SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD`. Every other account is demo data
and is created with the password `Dayflow@2026` so the roles can be explored.

| Code | Name | Email | Role | Department | Designation | Location | Manager | Joined | user_id | employee_id |
| ---- | ---- | ----- | ---- | ---------- | ----------- | -------- | ------- | ------ | ------- | ----------- |
| DF-0001 | Akshat Panicker | akshatpanicker@gmail.com | `super_admin` | Executive | Chief Executive Officer | Mumbai HQ | — | 2019-04-01 | `8044f8e4-46c5-442a-bfcb-ae491dcc9ded` | `28010836-0cfb-4b4d-aa20-b0b0f2bedfe3` |
| DF-0002 | Meera Iyer | meera.iyer@dayflow.local | `hr_admin` | People & Culture | Head of People | Mumbai HQ | `28010836-0cfb-4b4d-aa20-b0b0f2bedfe3` | 2019-06-17 | `b988b84d-bdef-4ef7-9809-a14bd4a07350` | `a9f5c390-db56-42fa-96d9-fb1ff57ca041` |
| DF-0003 | Rahul Deshmukh | rahul.deshmukh@dayflow.local | `hr_officer` | People & Culture | HR Business Partner | Mumbai HQ | `a9f5c390-db56-42fa-96d9-fb1ff57ca041` | 2021-02-08 | `6b46a7fa-5737-4095-ba77-ec70b858dceb` | `bf5b3018-b6eb-4646-8507-8b6e6fdca490` |
| DF-0004 | Sneha Kulkarni | sneha.kulkarni@dayflow.local | `finance` | Finance | Finance Manager | Mumbai HQ | `28010836-0cfb-4b4d-aa20-b0b0f2bedfe3` | 2020-08-03 | `cc6201b4-274b-4599-9a97-42b368cedd53` | `31033589-5508-433c-966e-508f653b54be` |
| DF-0005 | Arjun Nair | arjun.nair@dayflow.local | `manager` | Engineering | Engineering Manager | Bengaluru Tech Park | `28010836-0cfb-4b4d-aa20-b0b0f2bedfe3` | 2020-01-13 | `78886f55-a3df-4831-8ea0-36204747eb75` | `e3dbeba9-d9d2-4153-9934-471a08bb9cd6` |
| DF-0006 | Priya Sharma | priya.sharma@dayflow.local | `employee` | Engineering | Senior Software Engineer | Bengaluru Tech Park | `e3dbeba9-d9d2-4153-9934-471a08bb9cd6` | 2021-07-05 | `208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237` | `5f2fc57a-9d26-4279-bbdf-054496fd35ea` |
| DF-0007 | Vikram Reddy | vikram.reddy@dayflow.local | `employee` | Engineering | Software Engineer | Bengaluru Tech Park | `e3dbeba9-d9d2-4153-9934-471a08bb9cd6` | 2022-09-12 | `a26c2525-f201-44d6-9ccc-d06ea655b536` | `c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700` |
| DF-0008 | Ananya Bose | ananya.bose@dayflow.local | `employee` | Engineering | QA Engineer | Bengaluru Tech Park | `e3dbeba9-d9d2-4153-9934-471a08bb9cd6` | 2023-01-09 | `0008e98e-7d40-451a-88c5-53419b6c993f` | `5419981a-cd0a-4f65-9ff0-bcd16dd43a91` |
| DF-0009 | Karthik Menon | karthik.menon@dayflow.local | `manager` | Sales | Regional Sales Head | Mumbai HQ | `28010836-0cfb-4b4d-aa20-b0b0f2bedfe3` | 2020-11-02 | `7569e1f1-3659-42b9-89d3-bb90ab97c323` | `4449d500-6fb5-48f3-8b9f-4fac8516da38` |
| DF-0010 | Divya Raghavan | divya.raghavan@dayflow.local | `employee` | Sales | Account Executive | Mumbai HQ | `4449d500-6fb5-48f3-8b9f-4fac8516da38` | 2022-03-21 | `1c85286f-f790-4c8b-808d-bd63d619c205` | `2b5d3904-9f2f-4ae3-9248-88adac38023a` |
| DF-0011 | Imran Qureshi | imran.qureshi@dayflow.local | `employee` | Marketing | Content Strategist | Remote - India | `a9f5c390-db56-42fa-96d9-fb1ff57ca041` | 2023-05-15 | `23513a48-7f1d-483b-8280-75a1c98aa8b9` | `a2f82591-b4b7-4de2-99ae-1098b9111ae7` |
| DF-0012 | Neha Joshi | neha.joshi@dayflow.local | `employee` | Design | Product Designer | Bengaluru Tech Park | `e3dbeba9-d9d2-4153-9934-471a08bb9cd6` | 2023-08-28 | `051c1f79-ef38-41d1-a44b-f21077a131a4` | `87ae5bec-96ae-4226-92df-519a54dbda64` |

### Manager lines, by code

- **DF-0001** Akshat Panicker — reports to (nobody)
- **DF-0002** Meera Iyer — reports to DF-0001
- **DF-0003** Rahul Deshmukh — reports to DF-0002
- **DF-0004** Sneha Kulkarni — reports to DF-0001
- **DF-0005** Arjun Nair — reports to DF-0001
- **DF-0006** Priya Sharma — reports to DF-0005
- **DF-0007** Vikram Reddy — reports to DF-0005
- **DF-0008** Ananya Bose — reports to DF-0005
- **DF-0009** Karthik Menon — reports to DF-0001
- **DF-0010** Divya Raghavan — reports to DF-0009
- **DF-0011** Imran Qureshi — reports to DF-0002
- **DF-0012** Neha Joshi — reports to DF-0005

## Copy-paste blocks

### Employee identifiers, keyed by code

```php
const EMPLOYEES = [
    'DF-0001' => '28010836-0cfb-4b4d-aa20-b0b0f2bedfe3', // Akshat Panicker
    'DF-0002' => 'a9f5c390-db56-42fa-96d9-fb1ff57ca041', // Meera Iyer
    'DF-0003' => 'bf5b3018-b6eb-4646-8507-8b6e6fdca490', // Rahul Deshmukh
    'DF-0004' => '31033589-5508-433c-966e-508f653b54be', // Sneha Kulkarni
    'DF-0005' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6', // Arjun Nair
    'DF-0006' => '5f2fc57a-9d26-4279-bbdf-054496fd35ea', // Priya Sharma
    'DF-0007' => 'c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700', // Vikram Reddy
    'DF-0008' => '5419981a-cd0a-4f65-9ff0-bcd16dd43a91', // Ananya Bose
    'DF-0009' => '4449d500-6fb5-48f3-8b9f-4fac8516da38', // Karthik Menon
    'DF-0010' => '2b5d3904-9f2f-4ae3-9248-88adac38023a', // Divya Raghavan
    'DF-0011' => 'a2f82591-b4b7-4de2-99ae-1098b9111ae7', // Imran Qureshi
    'DF-0012' => '87ae5bec-96ae-4226-92df-519a54dbda64', // Neha Joshi
];
```

### Department identifiers

```php
const DEPARTMENTS = [
    'Executive' => '922f3d9b-20be-461f-9b69-90928701ce93',
    'People & Culture' => '2458e540-24e4-46e5-b2cf-6caac4b9c637',
    'Finance' => 'a15919aa-5727-4fb9-84e9-2980007cfc58',
    'Engineering' => '7ec7b7c3-126b-412a-babf-fd79d002e921',
    'Sales' => '9b8d41e8-ad45-4b7d-a541-38d781be09da',
    'Marketing' => 'd171a368-ea6a-45f5-9b66-6767052fe916',
    'Design' => '923c3a06-0b09-4573-a4da-142c606ae61e',
    'Customer Success' => '1a530254-57f0-4a8d-adb9-7d5520153f50',
];
```

### Location identifiers

```php
const LOCATIONS = [
    'Mumbai HQ' => 'd5bb3746-fdf8-424d-ba9d-3d7d407756a9',
    'Bengaluru Tech Park' => '624423f1-04b4-404c-8d55-d1cb82fa8ae4',
    'Remote - India' => '9f48758c-53ae-4d68-a454-d9c10767104e',
];
```

### Designation identifiers

```php
const DESIGNATIONS = [
    'Account Executive' => '329f1e51-a2ee-4d24-ab04-a7720bf3eac7',
    'Chief Executive Officer' => 'db3205fc-c11b-431e-950e-573c5bcb4013',
    'Content Strategist' => '54592217-2316-4ce5-8a1f-e96689271325',
    'Design Lead' => 'e87c7e19-87dd-4a7b-96b4-a45783ff2e5a',
    'Engineering Manager' => '63eb9418-6dd3-4b81-acea-60100d6c34f4',
    'Finance Manager' => 'f40ac94b-3792-4be6-b014-7ea7e096d903',
    'HR Business Partner' => '2ec743f1-5668-473b-80a0-cd58e856a3ee',
    'Head of People' => '298fdc62-813c-42ed-9ff8-fa69b3b0e545',
    'Marketing Manager' => '45703372-73ad-40cf-9a64-5116d3746f1e',
    'Product Designer' => 'e8174888-ed8c-4588-9834-1904a711c4fc',
    'QA Engineer' => '12cfde66-4ff3-4686-9d8b-863041025353',
    'Regional Sales Head' => '8176a283-be13-4eae-b0b2-32e4529688fe',
    'Senior Software Engineer' => '96ce567b-48d0-40de-af4e-52f9e4245e7a',
    'Software Engineer' => 'e3e8d1ec-c401-4979-8dc4-7034fc84ed23',
    'Support Specialist' => 'a2316ce2-8eb6-490c-8927-37eba16c368f',
];
```