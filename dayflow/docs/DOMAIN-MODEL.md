# Dayflow Domain Model

Shared vocabulary for all nine services. Anything in this file is a contract:
change it in one service and you break the others.

---

## 1. The two identifiers that cross service boundaries

| Identifier    | Owned by         | Meaning                                              |
| ------------- | ---------------- | ---------------------------------------------------- |
| `user_id`     | identity-service | A login account. UUID.                               |
| `employee_id` | employee-service | A person employed by the company. UUID.              |

They are deliberately separate. An account can exist before a person record is
completed during onboarding, and a person record can outlive the account after
someone leaves.

Every other service stores `employee_id` and nothing else about a person. It
never stores a name, an email address or a department — those are fetched from
employee-service when needed, so there is exactly one place a correction has to
be made.

**Both appear in the access token**, so a service usually does not need a call
to know who is asking:

```json
{
  "sub": "<user_id>",
  "employee_id": "<employee_id>",
  "email": "person@example.com",
  "name": "Priya Sharma",
  "roles": ["employee"],
  "department_id": "<uuid>",
  "manager_id": "<employee_id of their manager>",
  "type": "access",
  "exp": 1700000900,
  "jti": "<token id>"
}
```

`manager_id` is what makes "my team" cheap: a manager's own `employee_id`
matched against the `manager_id` of others.

## 2. Canonical employee record

`GET /employees/{id}` returns this shape. Other services rely on these key
names:

```json
{
  "id": "uuid",
  "employee_code": "DF-0007",
  "first_name": "Priya",
  "last_name": "Sharma",
  "full_name": "Priya Sharma",
  "work_email": "priya@example.com",
  "personal_email": "…",
  "phone": "+91…",
  "photo_url": "/documents/photo/uuid",
  "department_id": "uuid",
  "department_name": "Engineering",
  "designation_id": "uuid",
  "designation_name": "Senior Engineer",
  "location_id": "uuid",
  "manager_id": "uuid|null",
  "manager_name": "…|null",
  "employment_type": "full_time|part_time|contract|intern",
  "employment_status": "probation|confirmed|notice_period|resigned|terminated",
  "joined_on": "2024-03-01",
  "confirmed_on": "2024-09-01|null",
  "exit_date": "…|null",
  "is_active": true
}
```

## 3. Enumerated values

Use these strings exactly. They are enforced by `CHECK` constraints.

| Concept                | Allowed values                                                                 |
| ---------------------- | ------------------------------------------------------------------------------ |
| Attendance status      | `present`, `absent`, `half_day`, `on_leave`, `holiday`, `weekly_off`, `wfh`     |
| Leave request status   | `pending`, `approved`, `rejected`, `cancelled`, `withdrawn`                     |
| Leave category         | `paid`, `sick`, `unpaid`, `casual`, `maternity`, `paternity`, `comp_off`, `bereavement` |
| Employment type        | `full_time`, `part_time`, `contract`, `intern`, `consultant`                    |
| Employment status      | `probation`, `confirmed`, `notice_period`, `resigned`, `terminated`             |
| Payroll run status     | `draft`, `processing`, `approved`, `paid`, `cancelled`                          |
| Pay component type     | `earning`, `deduction`, `employer_contribution`                                 |
| Expense claim status   | `draft`, `submitted`, `approved`, `rejected`, `reimbursed`                      |
| Enrolment status       | `not_started`, `in_progress`, `completed`, `expired`                            |
| Goal status            | `draft`, `active`, `achieved`, `missed`, `cancelled`                            |
| Review cycle status    | `draft`, `open`, `in_review`, `closed`                                          |
| Regularisation status  | `pending`, `approved`, `rejected`                                               |
| Notification channel   | `in_app`, `email`                                                               |
| Document status        | `pending`, `verified`, `rejected`, `expired`                                    |

## 4. Money

Every monetary value is a **`BIGINT` of minor units** (paise for INR, cents for
USD). `4500000` means ₹45,000.00.

- Column names end in `_minor` when there is any chance of confusion
  (`gross_minor`, `net_minor`, `basic_minor`).
- The `money` validation rule accepts a decimal string from a form and returns
  minor units, so a controller never does the conversion itself.
- `Str::money($minor, '₹')` formats for display.
- Percentages are `NUMERIC(6,3)`, expressed as `12.500` for 12.5%.

## 5. Dates and times

- Calendar dates: `DATE`, formatted `YYYY-MM-DD`.
- Instants: `TIMESTAMPTZ`, stored UTC, rendered in `APP_TIMEZONE`.
- Times of day (shift start): `TIME`, formatted `HH:MM`.
- Never store a local time without a zone.

## 6. Domain events

Published with `EventPublisher::publish($name, $payload)`. The notification
service subscribes to all of them; anything else that wants one subscribes in
`EventPublisher::subscribers()`.

| Event                              | Published by | Payload keys                                                    |
| ---------------------------------- | ------------ | --------------------------------------------------------------- |
| `identity.account.registered`      | identity     | `user_id`, `email`, `first_name`, `verification_token`           |
| `identity.email.verified`          | identity     | `user_id`, `email`                                               |
| `identity.password.reset_requested`| identity     | `user_id`, `email`, `first_name`, `reset_token`                  |
| `identity.password.changed`        | identity     | `user_id`, `email`                                               |
| `identity.account.locked`          | identity     | `user_id`, `email`, `until`                                      |
| `employee.onboarded`               | employee     | `employee_id`, `employee_code`, `full_name`, `manager_id`        |
| `employee.profile.updated`         | employee     | `employee_id`, `changed_fields`                                  |
| `employee.document.expiring`       | employee     | `employee_id`, `document_id`, `expires_on`                       |
| `employee.offboarded`              | employee     | `employee_id`, `exit_date`                                       |
| `attendance.checked_in`            | attendance   | `employee_id`, `date`, `time`                                    |
| `attendance.checked_out`           | attendance   | `employee_id`, `date`, `worked_seconds`                          |
| `attendance.absent_flagged`        | attendance   | `employee_id`, `date`                                            |
| `attendance.regularisation.raised` | attendance   | `employee_id`, `request_id`, `date`, `approver_id`               |
| `attendance.regularisation.decided`| attendance   | `employee_id`, `request_id`, `status`                            |
| `leave.request.submitted`          | leave        | `employee_id`, `request_id`, `leave_type`, `starts_on`, `ends_on`, `approver_id` |
| `leave.request.decided`            | leave        | `employee_id`, `request_id`, `status`, `decided_by`, `note`      |
| `leave.request.cancelled`          | leave        | `employee_id`, `request_id`                                      |
| `leave.balance.adjusted`           | leave        | `employee_id`, `leave_type_id`, `delta_days`, `reason`           |
| `payroll.run.approved`             | payroll      | `run_id`, `period`, `employee_count`, `total_net_minor`          |
| `payroll.payslip.published`        | payroll      | `employee_id`, `payslip_id`, `period`                            |
| `payroll.salary.revised`           | payroll      | `employee_id`, `effective_from`, `new_ctc_minor`                 |
| `payroll.expense.decided`          | payroll      | `employee_id`, `claim_id`, `status`                              |
| `learning.course.assigned`         | learning     | `employee_id`, `course_id`, `course_title`, `due_on`             |
| `learning.course.completed`        | learning     | `employee_id`, `course_id`, `score`                              |
| `talent.review.opened`             | talent       | `employee_id`, `cycle_id`, `reviewer_id`                         |
| `talent.review.submitted`          | talent       | `employee_id`, `cycle_id`, `rating`                              |
| `talent.goal.updated`              | talent       | `employee_id`, `goal_id`, `progress`                             |

Payloads carry identifiers and the few fields a notification needs — never a
whole record. A subscriber that needs more calls the owning service for it.

## 6.1 How the notification service uses events

`POST /events` receives `{ event, payload, published_at, source }`. It looks up
a template by event name, resolves the recipient's contact details from
employee-service, writes an in-app notification and queues an email. An event
with no matching template is acknowledged and ignored, so adding a new event
never breaks delivery.

## 7. Approval routing

Anything requiring approval resolves its approver the same way:

1. The employee's `manager_id`, when one is set.
2. Otherwise any user holding `leave.approve` (or the relevant permission) in the
   same department.
3. Otherwise HR: any user holding the `hr_officer` role.

Approvers are recorded on the request as `approver_id` at submission time so the
queue stays stable even if the reporting line changes afterwards.

## 8. Pagination

List endpoints accept `?page=1&per_page=20&search=…` and return:

```json
{
  "data": [ … ],
  "meta": { "page": 1, "per_page": 20, "total": 137, "total_pages": 7 }
}
```

`per_page` is capped at 100 by `Request::perPage()`.

## 9. Company defaults

Seeded into `platform.settings` by identity-service and readable by all:

| Key                    | Default                                  |
| ---------------------- | ---------------------------------------- |
| `company.name`         | `Dayflow Technologies Pvt. Ltd.`         |
| `company.working_days` | `["mon","tue","wed","thu","fri"]`        |
| `company.work_hours`   | `{"start":"09:30","end":"18:30"}`        |
| `company.half_day_hours` | `4`                                    |
| `company.full_day_hours` | `8`                                    |
| `company.late_grace_minutes` | `15`                                |
| `company.currency`     | `{"code":"INR","symbol":"₹"}`            |
| `company.financial_year_start` | `04-01`                          |
