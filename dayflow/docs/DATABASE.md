# Dayflow Database

Generated from the running schema, so it reflects what the migrations
actually produce rather than what they were meant to produce.

---

## How it is arranged

One PostgreSQL database, **one schema per microservice**, plus a shared
`platform` schema for the audit trail, rate-limit counters and company
settings.

Each service connects with **its own database role**, granted rights on its
own schema only. That is what makes service isolation a property of the
database rather than a convention in the code:

```
payroll role  →  CREATE TABLE identity.x   →  permission denied for schema identity
payroll role  →  DELETE FROM audit_log     →  permission denied for table audit_log
```

### Conventions

| | |
| --- | --- |
| Primary keys | `UUID`, never sequential. A sequential id in a URL reveals how many records exist and invites walking the range. |
| Money | `BIGINT` in minor units (paise). `4500000` is Rs 45,000.00. No float ever touches a payslip. |
| Instants | `TIMESTAMPTZ`, stored UTC. |
| Calendar dates | `DATE`. |
| Status columns | `TEXT` with a real `CHECK` constraint, so an invalid state cannot be written even by hand. |
| Cross-service references | Plain `UUID` with **no** foreign key — the target lives in a schema this role cannot see. |
| Every table | `created_at`, and `updated_at` where rows are mutable. |

### Tables the kernel creates

Two tables appear in every service schema and are created by the shared
kernel rather than by that service's migrations:

- **`schema_migrations`** — which migration files have been applied. This is
  what makes `docker compose up` create the tables exactly once.
- **`event_outbox`** — the transactional outbox. A domain event is written
  here in the same transaction as the change that produced it, so an event
  can never announce a change that was rolled back.

---

## `platform`

**Owned by Shared platform.** Cross-cutting concerns every service writes to.

3 tables (3 business, 0 created by the kernel).

### `platform.audit_log`

*55 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `occurred_at` | timestamptz |  |  | default `now()` |
| `service` | text |  |  |  |
| `action` | text |  |  |  |
| `subject_type` | text |  |  |  |
| `subject_id` | text | yes |  |  |
| `actor_id` | text | yes |  |  |
| `actor_email` | text | yes |  |  |
| `actor_role` | text | yes |  |  |
| `ip_address` | text | yes |  |  |
| `user_agent` | text | yes |  |  |
| `before_state` | jsonb | yes |  |  |
| `after_state` | jsonb | yes |  |  |
| `context` | jsonb | yes |  |  |
| `request_id` | text | yes |  |  |

Indexed on: `action`, `actor_id, occurred_at DESC`, `occurred_at DESC`, `subject_type, subject_id`

### `platform.rate_limits`

*42 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `bucket_key` | text |  | PK |  |
| `hits` | int |  |  | default `0` |
| `expires_at` | timestamptz |  |  |  |

Indexed on: `expires_at`

### `platform.settings`

*8 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `key` | text |  | PK |  |
| `value` | jsonb |  |  |  |
| `updated_at` | timestamptz |  |  | default `now()` |
| `updated_by` | text | yes |  |  |

---

## `identity`

**Owned by identity-service.** Accounts, credentials, tokens, roles and the account recovery flows.

10 tables (7 business, 3 created by the kernel).

### `identity.email_verifications`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `user_id` | uuid |  | FK | → `users.id` |
| `token_hash` | text |  |  |  |
| `expires_at` | timestamptz |  |  |  |
| `consumed_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `expires_at`, `user_id, created_at DESC`

### `identity.login_attempts`

*35 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `email_hash` | text |  |  |  |
| `ip_address` | text | yes |  |  |
| `successful` | bool |  |  | default `false` |
| `attempted_at` | timestamptz |  |  | default `now()` |
| `failure_reason` | text | yes |  |  |

Indexed on: `attempted_at DESC`, `email_hash, attempted_at DESC`, `ip_address, attempted_at DESC`

### `identity.password_resets`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `user_id` | uuid |  | FK | → `users.id` |
| `token_hash` | text |  |  |  |
| `expires_at` | timestamptz |  |  |  |
| `consumed_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `requested_ip` | text | yes |  |  |

Indexed on: `expires_at`, `user_id, created_at DESC`

### `identity.refresh_tokens`

*30 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `user_id` | uuid |  | FK | → `users.id` |
| `token_hash` | text |  |  |  |
| `family_id` | uuid |  |  |  |
| `parent_id` | uuid | yes | FK | → `refresh_tokens.id` |
| `issued_at` | timestamptz |  |  | default `now()` |
| `expires_at` | timestamptz |  |  |  |
| `used_at` | timestamptz | yes |  |  |
| `revoked_at` | timestamptz | yes |  |  |
| `revoked_reason` | text | yes |  |  |
| `user_agent` | text | yes |  |  |
| `ip_address` | text | yes |  |  |

Indexed on: `family_id`, `user_id, expires_at`, `user_id, issued_at DESC`

### `identity.revoked_tokens`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `token_id` | text |  | PK |  |
| `user_id` | uuid |  | FK | → `users.id` |
| `revoked_at` | timestamptz |  |  | default `now()` |
| `expires_at` | timestamptz |  |  |  |

Indexed on: `expires_at`, `user_id`

### `identity.user_roles`

*12 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `user_id` | uuid |  | FK UQ UQ | → `users.id` |
| `role` | text |  | UQ UQ | one of: `employee`, `finance`, `hr_admin`, `hr_officer`, `manager`, `super_admin` |
| `granted_by` | uuid | yes | FK | → `users.id` |
| `granted_at` | timestamptz |  |  | default `now()` |

Indexed on: `role`, `user_id`

### `identity.users`

*12 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid | yes |  |  |
| `employee_code` | text | yes |  |  |
| `email` | text |  |  |  |
| `password_hash` | text |  |  |  |
| `first_name` | text |  |  |  |
| `last_name` | text |  |  |  |
| `is_active` | bool |  |  | default `true` |
| `email_verified_at` | timestamptz | yes |  |  |
| `must_change_password` | bool |  |  | default `false` |
| `failed_login_count` | int |  |  | default `0` |
| `locked_until` | timestamptz | yes |  |  |
| `last_login_at` | timestamptz | yes |  |  |
| `last_login_ip` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |
| `deleted_at` | timestamptz | yes |  |  |

Indexed on: `is_active, created_at DESC`, `last_name, first_name`, `locked_until`

---

## `employee`

**Owned by employee-service.** The system of record for people and organisation structure.

11 tables (9 business, 2 created by the kernel).

### `employee.checklist_templates`

*19 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `kind` | text |  |  | one of: `offboarding`, `onboarding` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `category` | text |  |  | default `'general'` |
| `sequence` | int |  |  | default `0` |
| `owner_role` | text |  |  | one of: `employee`, `finance`, `hr_admin`, `hr_officer`, `manager`, `super_admin`; default `'hr_officer'` |
| `due_offset_days` | int |  |  | default `0` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `kind, sequence`

### `employee.company_assets`

*9 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `asset_tag` | text |  |  |  |
| `category` | text |  |  | one of: `access_card`, `desktop`, `furniture`, `laptop`, `monitor`, `other`, `peripheral`, `phone`, `software_licence`, `tablet`, `vehicle` |
| `name` | text |  |  |  |
| `serial_number` | text | yes |  |  |
| `purchased_on` | date | yes |  |  |
| `value_minor` | bigint |  |  | default `0`; minor units |
| `condition` | text |  |  | one of: `damaged`, `fair`, `good`, `new`, `poor`; default `'good'` |
| `assigned_to` | uuid | yes | FK | → `employees.id` |
| `assigned_on` | date | yes |  |  |
| `returned_on` | date | yes |  |  |
| `status` | text |  |  | one of: `assigned`; default `'available'` |
| `notes` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |
| `search_text` | text | yes |  |  |

Indexed on: `assigned_to`, `category`, `status`

### `employee.departments`

*8 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `code` | text |  |  |  |
| `description` | text | yes |  |  |
| `parent_id` | uuid | yes | FK | → `departments.id` |
| `head_employee_id` | uuid | yes | FK | → `employees.id` |
| `cost_centre` | text | yes |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active`, `parent_id`

### `employee.designations`

*15 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `title` | text |  |  |  |
| `code` | text |  |  |  |
| `level` | int |  |  | default `1` |
| `department_id` | uuid | yes | FK | → `departments.id` |
| `description` | text | yes |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `department_id`, `level DESC`

### `employee.employee_documents`

*6 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | FK | → `employees.id` |
| `category` | text |  |  | one of: `address`, `contract`, `education`, `employment`, `identity`, `medical`, `other`, `payroll`, `photo` |
| `title` | text |  |  |  |
| `original_filename` | text |  |  |  |
| `stored_filename` | text |  |  |  |
| `mime_type` | text |  |  |  |
| `size_bytes` | bigint |  |  |  |
| `checksum` | text |  |  |  |
| `issued_on` | date | yes |  |  |
| `expires_on` | date | yes |  |  |
| `status` | text |  |  | one of: `expired`, `pending`, `rejected`, `verified`; default `'pending'` |
| `verified_by` | uuid | yes |  |  |
| `verified_at` | timestamptz | yes |  |  |
| `uploaded_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `category`, `employee_id, created_at DESC`, `expires_on`, `status`

### `employee.employees`

*12 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_code` | text |  |  |  |
| `user_id` | uuid | yes |  |  |
| `first_name` | text |  |  |  |
| `last_name` | text |  |  |  |
| `work_email` | text |  |  |  |
| `personal_email` | text | yes |  |  |
| `phone` | text | yes |  |  |
| `alternate_phone` | text | yes |  |  |
| `date_of_birth` | date | yes |  |  |
| `gender` | text | yes |  | one of: `female`, `male`, `other`, `undisclosed` |
| `blood_group` | text | yes |  | one of: `A+`, `A-`, `AB+`, `AB-`, `B+`, `B-`, `O+`, `O-` |
| `marital_status` | text | yes |  | one of: `divorced`, `married`, `single`, `undisclosed`, `widowed` |
| `address_line1` | text | yes |  |  |
| `address_line2` | text | yes |  |  |
| `city` | text | yes |  |  |
| `state` | text | yes |  |  |
| `country` | text | yes |  |  |
| `postal_code` | text | yes |  |  |
| `emergency_contact_name` | text | yes |  |  |
| `emergency_contact_phone` | text | yes |  |  |
| `emergency_contact_relation` | text | yes |  |  |
| `department_id` | uuid | yes | FK | → `departments.id` |
| `designation_id` | uuid | yes | FK | → `designations.id` |
| `location_id` | uuid | yes | FK | → `locations.id` |
| `manager_id` | uuid | yes | FK | → `employees.id` |
| `employment_type` | text |  |  | one of: `consultant`, `contract`, `full_time`, `intern`, `part_time`; default `'full_time'` |
| `employment_status` | text |  |  | one of: `confirmed`, `notice_period`, `probation`, `resigned`, `terminated`; default `'probation'` |
| `joined_on` | date |  |  |  |
| `probation_end_on` | date | yes |  |  |
| `confirmed_on` | date | yes |  |  |
| `notice_start_on` | date | yes |  |  |
| `exit_date` | date | yes |  |  |
| `exit_reason` | text | yes |  |  |
| `photo_document_id` | uuid | yes | FK | → `employee_documents.id` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |
| `deleted_at` | timestamptz | yes |  |  |
| `search_text` | text | yes |  |  |

Indexed on: `department_id`, `designation_id`, `employment_status`, `joined_on DESC`, `last_name, first_name`, `location_id`, `manager_id`

### `employee.locations`

*3 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `address_line1` | text | yes |  |  |
| `address_line2` | text | yes |  |  |
| `city` | text | yes |  |  |
| `state` | text | yes |  |  |
| `country` | text |  |  | default `'India'` |
| `postal_code` | text | yes |  |  |
| `timezone` | text |  |  | default `'Asia/Kolkata'` |
| `is_remote` | bool |  |  | default `false` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active`

### `employee.offboarding_tasks`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | FK | → `employees.id` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `category` | text |  |  | default `'general'` |
| `sequence` | int |  |  | default `0` |
| `owner_role` | text |  |  | one of: `employee`, `finance`, `hr_admin`, `hr_officer`, `manager`, `super_admin`; default `'hr_officer'` |
| `assigned_to` | uuid | yes | FK | → `employees.id` |
| `due_on` | date | yes |  |  |
| `completed_at` | timestamptz | yes |  |  |
| `completed_by` | uuid | yes |  |  |
| `status` | text |  |  | one of: `completed`, `in_progress`, `pending`, `skipped`; default `'pending'` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `assigned_to`, `due_on`, `employee_id, sequence`, `status`

### `employee.onboarding_tasks`

*120 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | FK | → `employees.id` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `category` | text |  |  | default `'general'` |
| `sequence` | int |  |  | default `0` |
| `owner_role` | text |  |  | one of: `employee`, `finance`, `hr_admin`, `hr_officer`, `manager`, `super_admin`; default `'hr_officer'` |
| `assigned_to` | uuid | yes | FK | → `employees.id` |
| `due_on` | date | yes |  |  |
| `completed_at` | timestamptz | yes |  |  |
| `completed_by` | uuid | yes |  |  |
| `status` | text |  |  | one of: `completed`, `in_progress`, `pending`, `skipped`; default `'pending'` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `assigned_to`, `due_on`, `employee_id, sequence`, `status`

---

## `attendance`

**Owned by attendance-service.** Punches, daily records, shifts, rosters, holidays and timesheets.

10 tables (8 business, 2 created by the kernel).

### `attendance.attendance_punches`

*1038 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `attendance_record_id` | uuid |  | FK | → `attendance_records.id` |
| `employee_id` | uuid |  |  |  |
| `punched_at` | timestamptz |  |  |  |
| `direction` | text |  |  | one of: `in`, `out` |
| `ip_address` | text | yes |  |  |
| `user_agent` | text | yes |  |  |
| `source` | text |  |  | one of: `biometric`, `import`, `manual`, `mobile`, `web`; default `'web'` |
| `latitude` | numeric(9,6) | yes |  |  |
| `longitude` | numeric(9,6) | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `attendance_record_id, punched_at`, `employee_id, punched_at DESC`

### `attendance.attendance_records`

*530 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | UQ UQ |  |
| `work_date` | date |  | UQ UQ |  |
| `shift_id` | uuid | yes | FK | → `shifts.id` |
| `check_in_at` | timestamptz | yes |  |  |
| `check_out_at` | timestamptz | yes |  |  |
| `check_in_ip` | text | yes |  |  |
| `check_out_ip` | text | yes |  |  |
| `check_in_source` | text |  |  | one of: `biometric`, `import`, `manual`, `mobile`, `web`; default `'web'` |
| `worked_seconds` | int |  |  | default `0` |
| `break_seconds` | int |  |  | default `0` |
| `overtime_seconds` | int |  |  | default `0` |
| `late_minutes` | int |  |  | default `0` |
| `early_leave_minutes` | int |  |  | default `0` |
| `status` | text |  |  | one of: `absent`, `half_day`, `holiday`, `on_leave`, `present`, `weekly_off`, `wfh`; default `'absent'` |
| `is_regularised` | bool |  |  | default `false` |
| `remarks` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, work_date DESC`, `work_date, status`

### `attendance.holidays`

*9 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `holiday_date` | date |  |  |  |
| `holiday_type` | text |  |  | one of: `company`, `public`, `restricted`; default `'public'` |
| `location_id` | uuid | yes |  |  |
| `description` | text | yes |  |  |
| `year` | int |  |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `holiday_date`, `year, holiday_date`

### `attendance.regularisations`

*7 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `work_date` | date |  |  |  |
| `requested_check_in` | timestamptz | yes |  |  |
| `requested_check_out` | timestamptz | yes |  |  |
| `requested_status` | text | yes |  | one of: `half_day`, `present`, `wfh` |
| `reason` | text |  |  |  |
| `status` | text |  |  | one of: `approved`, `pending`, `rejected`; default `'pending'` |
| `approver_id` | uuid | yes |  |  |
| `decided_by` | uuid | yes |  |  |
| `decided_at` | timestamptz | yes |  |  |
| `decision_note` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `approver_id, status, created_at DESC`, `employee_id, work_date DESC`

### `attendance.rosters`

*4 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | UQ UQ |  |
| `shift_id` | uuid |  | FK | → `shifts.id` |
| `roster_date` | date |  | UQ UQ |  |
| `notes` | text | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `roster_date, employee_id`, `shift_id`

### `attendance.shift_assignments`

*24 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `shift_id` | uuid |  | FK | → `shifts.id` |
| `effective_from` | date |  |  |  |
| `effective_to` | date | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, effective_from DESC`, `shift_id`

### `attendance.shifts`

*3 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `code` | text |  | UQ |  |
| `starts_at` | time |  |  |  |
| `ends_at` | time |  |  |  |
| `break_minutes` | int |  |  | default `60` |
| `full_day_hours` | numeric(4,2) |  |  | default `8.00` |
| `half_day_hours` | numeric(4,2) |  |  | default `4.00` |
| `grace_minutes` | int |  |  | default `15` |
| `is_night_shift` | bool |  |  | default `false` |
| `working_days` | jsonb |  |  | default `'["mon", "tue", "wed", "thu", "fri"]'` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active, code`

### `attendance.timesheets`

*278 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `work_date` | date |  |  |  |
| `project_code` | text |  |  |  |
| `task_description` | text |  |  |  |
| `hours` | numeric(5,2) |  |  |  |
| `billable` | bool |  |  | default `true` |
| `approved_by` | uuid | yes |  |  |
| `approved_at` | timestamptz | yes |  |  |
| `status` | text |  |  | one of: `approved`, `draft`, `rejected`, `submitted`; default `'draft'` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, work_date DESC`, `project_code, work_date DESC`, `status, work_date DESC`

---

## `leave_management`

**Owned by leave-service.** Leave types and policy, balances, requests and approval chains.

9 tables (7 business, 2 created by the kernel).

### `leave_management.approval_delegations`

*3 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `delegator_id` | uuid |  |  |  |
| `delegate_id` | uuid |  |  |  |
| `starts_on` | date |  |  |  |
| `ends_on` | date |  |  |  |
| `reason` | text | yes |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `delegate_id, is_active, starts_on, ends_on`, `delegator_id, is_active, starts_on DESC`

### `leave_management.leave_adjustments`

*4 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `leave_type_id` | uuid |  | FK | → `leave_types.id` |
| `year` | int |  |  |  |
| `delta_days` | numeric(6,2) |  |  |  |
| `reason` | text |  |  |  |
| `adjusted_by` | uuid |  |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, year, created_at DESC`, `leave_type_id, year`

### `leave_management.leave_approvals`

*33 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `leave_request_id` | uuid |  | FK | → `leave_requests.id` |
| `level` | int |  |  | default `1` |
| `approver_id` | uuid |  |  |  |
| `status` | text |  |  | one of: `approved`, `pending`, `rejected`, `skipped`; default `'pending'` |
| `note` | text | yes |  |  |
| `decided_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `approver_id, status, level`

### `leave_management.leave_balances`

*73 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `leave_type_id` | uuid |  | FK | → `leave_types.id` |
| `year` | int |  |  |  |
| `opening_days` | numeric(6,2) |  |  | default `0` |
| `accrued_days` | numeric(6,2) |  |  | default `0` |
| `used_days` | numeric(6,2) |  |  | default `0` |
| `pending_days` | numeric(6,2) |  |  | default `0` |
| `carried_forward_days` | numeric(6,2) |  |  | default `0` |
| `adjusted_days` | numeric(6,2) |  |  | default `0` |
| `last_accrual_period` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, year`

### `leave_management.leave_policies`

*14 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `leave_type_id` | uuid |  | FK | → `leave_types.id` |
| `employment_type` | text |  |  | one of: `consultant`, `contract`, `full_time`, `intern`, `part_time` |
| `applies_after_months` | int |  |  | default `0` |
| `quota_override_days` | numeric(5,2) | yes |  |  |
| `effective_from` | date |  |  |  |
| `effective_to` | date | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employment_type, leave_type_id, effective_from DESC`

### `leave_management.leave_requests`

*33 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `leave_type_id` | uuid |  | FK | → `leave_types.id` |
| `starts_on` | date |  |  |  |
| `ends_on` | date |  |  |  |
| `day_count` | numeric(5,2) |  |  | default `0` |
| `is_half_day` | bool |  |  | default `false` |
| `half_day_period` | text | yes |  | one of: `first_half`, `second_half` |
| `reason` | text | yes |  |  |
| `contact_during_leave` | text | yes |  |  |
| `status` | text |  |  | one of: `approved`, `cancelled`, `pending`, `rejected`, `withdrawn`; default `'pending'` |
| `approver_id` | uuid | yes |  |  |
| `decided_by` | uuid | yes |  |  |
| `decided_at` | timestamptz | yes |  |  |
| `decision_note` | text | yes |  |  |
| `cancelled_at` | timestamptz | yes |  |  |
| `cancelled_by` | uuid | yes |  |  |
| `supporting_document_id` | uuid | yes |  |  |
| `holiday_calendar_applied` | bool |  |  | default `true` |
| `applied_at` | timestamptz |  |  | default `now()` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `approver_id, applied_at`, `employee_id, starts_on DESC`, `starts_on, ends_on`, `status, applied_at DESC`

### `leave_management.leave_types`

*8 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `code` | text |  |  |  |
| `category` | text |  |  | one of: `bereavement`, `casual`, `comp_off`, `maternity`, `paid`, `paternity`, `sick`, `unpaid` |
| `colour` | text |  |  | one of: `^#[0-9A-Fa-f]{6}$`; default `'#64748B'` |
| `annual_quota_days` | numeric(5,2) |  |  | default `0` |
| `accrual_frequency` | text |  |  | one of: `monthly`, `none`, `quarterly`, `yearly`; default `'none'` |
| `accrual_days` | numeric(5,2) |  |  | default `0` |
| `max_carry_forward_days` | numeric(5,2) |  |  | default `0` |
| `allows_half_day` | bool |  |  | default `true` |
| `requires_document_after_days` | int | yes |  |  |
| `min_notice_days` | int |  |  | default `0` |
| `max_consecutive_days` | int | yes |  |  |
| `is_paid` | bool |  |  | default `true` |
| `applies_to_gender` | text |  |  | one of: `any`, `female`, `male`; default `'any'` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active, name`

---

## `payroll`

**Owned by payroll-service.** Salary structures, payroll runs, payslips, bank details and expenses.

11 tables (9 business, 2 created by the kernel).

### `payroll.bank_accounts`

*4 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  | UQ |  |
| `account_number_encrypted` | text |  |  |  |
| `account_number_blind` | text |  | UQ |  |
| `account_last4` | text |  |  | one of: `^[0-9]{4}$` |
| `bank_name` | text |  |  |  |
| `ifsc_code` | text |  |  | one of: `^[A-Z]{4}0[A-Z0-9]{6}$` |
| `account_holder_name` | text |  |  |  |
| `tax_identifier_encrypted` | text | yes |  |  |
| `tax_identifier_last4` | text | yes |  |  |
| `verified_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

### `payroll.expense_claims`

*6 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `claim_number` | text |  | UQ |  |
| `category` | text |  |  | one of: `accommodation`, `client_entertainment`, `communication`, `equipment`, `meals`, `medical`, `other`, `software`, `training`, `travel` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `incurred_on` | date |  |  |  |
| `amount_minor` | bigint |  |  | minor units |
| `currency` | text |  |  | default `'INR'` |
| `receipt_document_id` | uuid | yes |  |  |
| `status` | text |  |  | one of: `approved`, `draft`, `reimbursed`, `rejected`, `submitted`; default `'submitted'` |
| `approver_id` | uuid | yes |  |  |
| `decided_by` | uuid | yes |  |  |
| `decided_at` | timestamptz | yes |  |  |
| `decision_note` | text | yes |  |  |
| `reimbursed_at` | timestamptz | yes |  |  |
| `reimbursed_reference` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `approver_id, status`, `employee_id, incurred_on DESC`, `status, created_at DESC`

### `payroll.pay_components`

*10 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `code` | text |  | UQ |  |
| `component_type` | text |  |  | one of: `deduction`, `earning`, `employer_contribution` |
| `calculation` | text |  |  | one of: `fixed`, `percent_of_basic`, `percent_of_ctc`, `slab` |
| `percentage` | numeric(6,3) | yes |  |  |
| `is_taxable` | bool |  |  | default `true` |
| `is_statutory` | bool |  |  | default `false` |
| `display_order` | int |  |  | default `0` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `display_order, name`, `is_active, display_order`

### `payroll.payroll_runs`

*3 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `period` | text |  | UQ | one of: `^[0-9]{4}-(0[1-9]|1[0-2])$` |
| `run_label` | text |  |  |  |
| `status` | text |  |  | one of: `approved`, `cancelled`, `draft`, `paid`, `processing`; default `'draft'` |
| `employee_count` | int |  |  | default `0` |
| `total_gross_minor` | bigint |  |  | default `0`; minor units |
| `total_deductions_minor` | bigint |  |  | default `0`; minor units |
| `total_net_minor` | bigint |  |  | default `0`; minor units |
| `processed_by` | uuid | yes |  |  |
| `processed_at` | timestamptz | yes |  |  |
| `approved_by` | uuid | yes |  |  |
| `approved_at` | timestamptz | yes |  |  |
| `paid_at` | timestamptz | yes |  |  |
| `notes` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `period DESC`, `status, period DESC`

### `payroll.payslip_lines`

*360 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `payslip_id` | uuid |  | FK | → `payslips.id` |
| `pay_component_id` | uuid |  | FK | → `pay_components.id` |
| `component_name` | text |  |  |  |
| `component_type` | text |  |  | one of: `deduction`, `earning`, `employer_contribution` |
| `amount_minor` | bigint |  |  | default `0`; minor units |
| `display_order` | int |  |  | default `0` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `component_type`, `payslip_id, display_order`

### `payroll.payslips`

*36 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `payroll_run_id` | uuid |  | FK UQ UQ | → `payroll_runs.id` |
| `employee_id` | uuid |  | UQ UQ |  |
| `period` | text |  |  | one of: `^[0-9]{4}-(0[1-9]|1[0-2])$` |
| `payable_days` | numeric(5,2) |  |  | default `0` |
| `present_days` | numeric(5,2) |  |  | default `0` |
| `leave_days` | numeric(5,2) |  |  | default `0` |
| `lop_days` | numeric(5,2) |  |  | default `0` |
| `gross_minor` | bigint |  |  | default `0`; minor units |
| `total_deductions_minor` | bigint |  |  | default `0`; minor units |
| `net_minor` | bigint |  |  | default `0`; minor units |
| `tax_minor` | bigint |  |  | default `0`; minor units |
| `published_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, period DESC`, `employee_id, published_at DESC`, `period`

### `payroll.salary_structure_lines`

*120 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `salary_structure_id` | uuid |  | FK UQ UQ | → `salary_structures.id` |
| `pay_component_id` | uuid |  | FK UQ UQ | → `pay_components.id` |
| `amount_monthly_minor` | bigint |  |  | default `0`; minor units |
| `percentage` | numeric(6,3) | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `salary_structure_id`

### `payroll.salary_structures`

*12 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `effective_from` | date |  |  |  |
| `effective_to` | date | yes |  |  |
| `ctc_annual_minor` | bigint |  |  | minor units |
| `gross_monthly_minor` | bigint |  |  | minor units |
| `basic_monthly_minor` | bigint |  |  | minor units |
| `currency` | text |  |  | default `'INR'` |
| `revision_reason` | text | yes |  |  |
| `approved_by` | uuid | yes |  |  |
| `approved_at` | timestamptz | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, effective_from DESC`

### `payroll.tax_slabs`

*10 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `regime` | text |  | UQ UQ UQ | one of: `new`, `old` |
| `financial_year` | text |  | UQ UQ UQ | one of: `^[0-9]{4}-[0-9]{2}$` |
| `lower_minor` | bigint |  | UQ UQ UQ | minor units |
| `upper_minor` | bigint | yes |  | minor units |
| `rate` | numeric(6,3) |  |  |  |
| `surcharge` | numeric(6,3) |  |  | default `0` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `financial_year, regime, lower_minor`

---

## `learning`

**Owned by learning-service.** Course catalogue, video lessons, enrolments, quizzes and certificates.

11 tables (9 business, 2 created by the kernel).

### `learning.certificates`

*3 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `enrolment_id` | uuid |  | FK UQ | → `enrolments.id` |
| `employee_id` | uuid |  |  |  |
| `course_id` | uuid |  | FK | → `courses.id` |
| `certificate_number` | text |  | UQ |  |
| `issued_on` | date |  |  |  |
| `score_percent` | int |  |  | default `0` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, issued_on DESC`

### `learning.course_categories`

*6 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `slug` | text |  | UQ |  |
| `description` | text | yes |  |  |
| `icon` | text | yes |  |  |
| `colour` | text | yes |  |  |
| `display_order` | int |  |  | default `0` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `display_order, name`

### `learning.courses`

*14 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `category_id` | uuid |  | FK | → `course_categories.id` |
| `title` | text |  |  |  |
| `slug` | text |  | UQ |  |
| `summary` | text | yes |  |  |
| `description` | text | yes |  |  |
| `thumbnail_url` | text | yes |  |  |
| `level` | text |  |  | one of: `advanced`, `beginner`, `intermediate`; default `'beginner'` |
| `estimated_minutes` | int |  |  | default `0` |
| `is_mandatory` | bool |  |  | default `false` |
| `mandatory_for_department_id` | uuid | yes |  |  |
| `mandatory_for_designation_id` | uuid | yes |  |  |
| `passing_score` | int |  |  | default `70` |
| `certificate_enabled` | bool |  |  | default `false` |
| `published_at` | timestamptz | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `category_id, title`, `is_active, published_at DESC`, `is_mandatory`, `level`

### `learning.enrolments`

*50 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `course_id` | uuid |  | FK UQ UQ | → `courses.id` |
| `employee_id` | uuid |  | UQ UQ |  |
| `assigned_by` | uuid | yes |  |  |
| `assigned_at` | timestamptz | yes |  |  |
| `due_on` | date | yes |  |  |
| `started_at` | timestamptz | yes |  |  |
| `completed_at` | timestamptz | yes |  |  |
| `status` | text |  |  | one of: `completed`, `expired`, `in_progress`, `not_started`; default `'not_started'` |
| `progress_percent` | int |  |  | default `0` |
| `last_lesson_id` | uuid | yes | FK | → `lessons.id` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `course_id, status`, `due_on`, `employee_id, status`

### `learning.lesson_progress`

*131 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `enrolment_id` | uuid |  | FK UQ UQ | → `enrolments.id` |
| `lesson_id` | uuid |  | FK UQ UQ | → `lessons.id` |
| `employee_id` | uuid |  |  |  |
| `watched_seconds` | int |  |  | default `0` |
| `completed_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, updated_at DESC`, `lesson_id`

### `learning.lessons`

*58 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `course_id` | uuid |  | FK UQ UQ | → `courses.id` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `video_url` | text |  |  |  |
| `video_id` | text |  |  | one of: `^[A-Za-z0-9_-]{11}$` |
| `duration_seconds` | int |  |  | default `0` |
| `sequence` | int |  | UQ UQ | default `1` |
| `is_preview` | bool |  |  | default `false` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `course_id, sequence`

### `learning.quiz_attempts`

*7 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `quiz_id` | uuid |  | FK UQ UQ UQ | → `quizzes.id` |
| `enrolment_id` | uuid |  | FK UQ UQ UQ | → `enrolments.id` |
| `employee_id` | uuid |  |  |  |
| `answers` | jsonb |  |  | default `'[]'` |
| `score_percent` | int |  |  | default `0` |
| `passed` | bool |  |  | default `false` |
| `started_at` | timestamptz | yes |  |  |
| `submitted_at` | timestamptz | yes |  |  |
| `attempt_number` | int |  | UQ UQ UQ | default `1` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, submitted_at DESC`, `enrolment_id, attempt_number`

### `learning.quiz_questions`

*10 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `quiz_id` | uuid |  | FK | → `quizzes.id` |
| `question` | text |  |  |  |
| `options` | jsonb |  |  |  |
| `correct_index` | int |  |  | one of: `array` |
| `explanation` | text | yes |  |  |
| `points` | int |  |  | default `1` |
| `sequence` | int |  |  | default `1` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `quiz_id, sequence`

### `learning.quizzes`

*2 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `course_id` | uuid |  | FK | → `courses.id` |
| `title` | text |  |  |  |
| `pass_percent` | int |  |  | default `70` |
| `max_attempts` | int |  |  | default `3` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |

---

## `talent`

**Owned by talent-service.** Goals, review cycles, competency ratings and feedback.

11 tables (9 business, 2 created by the kernel).

### `talent.competencies`

*10 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `description` | text | yes |  |  |
| `category` | text |  |  | default `'core'` |
| `applies_to_designation_id` | uuid | yes |  |  |
| `display_order` | int |  |  | default `0` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active, display_order, name`

### `talent.feedback`

*16 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `from_employee_id` | uuid |  |  |  |
| `to_employee_id` | uuid |  |  |  |
| `feedback_type` | text |  |  | one of: `appreciation`, `constructive`, `request` |
| `visibility` | text |  |  | one of: `manager`, `private`, `public`; default `'private'` |
| `subject` | text |  |  |  |
| `body` | text |  |  |  |
| `related_goal_id` | uuid | yes | FK | → `goals.id` |
| `is_anonymous` | bool |  |  | default `false` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `from_employee_id, created_at DESC`, `related_goal_id`, `to_employee_id, created_at DESC`, `to_employee_id, visibility, created_at DESC`

### `talent.goal_cycles`

*1 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `period_start` | date |  |  |  |
| `period_end` | date |  |  |  |
| `status` | text |  |  | one of: `closed`, `draft`, `open`; default `'draft'` |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `status, period_start DESC`

### `talent.goal_updates`

*41 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `goal_id` | uuid |  | FK | → `goals.id` |
| `employee_id` | uuid |  |  |  |
| `progress_percent` | numeric(6,3) |  |  |  |
| `note` | text | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, created_at DESC`, `goal_id, created_at DESC`

### `talent.goals`

*41 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid |  |  |  |
| `goal_cycle_id` | uuid |  | FK | → `goal_cycles.id` |
| `title` | text |  |  |  |
| `description` | text | yes |  |  |
| `category` | text |  |  | one of: `behavioural`, `business`, `learning`, `personal`; default `'business'` |
| `metric` | text | yes |  |  |
| `target_value` | numeric(16,3) | yes |  |  |
| `current_value` | numeric(16,3) |  |  | default `0` |
| `unit` | text | yes |  |  |
| `weight_percent` | numeric(6,3) |  |  | default `0` |
| `status` | text |  |  | one of: `achieved`, `active`, `cancelled`, `draft`, `missed`; default `'draft'` |
| `progress_percent` | numeric(6,3) |  |  | default `0` |
| `starts_on` | date | yes |  |  |
| `due_on` | date | yes |  |  |
| `completed_at` | timestamptz | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, goal_cycle_id`, `employee_id, status, due_on`, `goal_cycle_id, status`

### `talent.review_cycles`

*2 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `period_start` | date |  |  |  |
| `period_end` | date |  |  |  |
| `self_review_due_on` | date | yes |  |  |
| `manager_review_due_on` | date | yes |  |  |
| `status` | text |  |  | one of: `closed`, `draft`, `in_review`, `open`; default `'draft'` |
| `rating_scale_max` | int |  |  | default `5` |
| `instructions` | text | yes |  |  |
| `created_by` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `status, period_start DESC`

### `talent.review_participants`

*24 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `review_cycle_id` | uuid |  | FK UQ UQ | → `review_cycles.id` |
| `employee_id` | uuid |  | UQ UQ |  |
| `manager_id` | uuid | yes |  |  |
| `status` | text |  |  | one of: `completed`, `in_progress`, `pending`; default `'pending'` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id`, `manager_id, review_cycle_id`

### `talent.review_ratings`

*250 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `review_id` | uuid |  | FK UQ UQ | → `reviews.id` |
| `competency_id` | uuid |  | FK UQ UQ | → `competencies.id` |
| `rating` | numeric(4,2) |  |  |  |
| `comment` | text | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `competency_id`, `review_id`

### `talent.reviews`

*48 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `review_cycle_id` | uuid |  | FK UQ UQ UQ UQ | → `review_cycles.id` |
| `employee_id` | uuid |  | UQ UQ UQ UQ |  |
| `reviewer_id` | uuid |  | UQ UQ UQ UQ |  |
| `review_type` | text |  | UQ UQ UQ UQ | one of: `manager`, `peer`, `self`, `skip_level` |
| `overall_rating` | numeric(4,2) | yes |  |  |
| `strengths` | text | yes |  |  |
| `improvements` | text | yes |  |  |
| `achievements` | text | yes |  |  |
| `manager_comments` | text | yes |  |  |
| `employee_comments` | text | yes |  |  |
| `is_anonymous` | bool |  |  | one of: `peer`; default `false` |
| `status` | text |  |  | one of: `acknowledged`, `draft`, `submitted`; default `'draft'` |
| `submitted_at` | timestamptz | yes |  |  |
| `acknowledged_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, submitted_at DESC`, `review_cycle_id, review_type, status`, `reviewer_id, status`

---

## `notification`

**Owned by notification-service.** In-app notifications, email delivery and announcements.

9 tables (7 business, 2 created by the kernel).

### `notification.announcement_reads`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `announcement_id` | uuid |  | FK UQ UQ | → `announcements.id` |
| `employee_id` | uuid |  | UQ UQ |  |
| `read_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id`

### `notification.announcements`

*5 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `title` | text |  |  |  |
| `body` | text |  |  |  |
| `category` | text |  |  | default `'general'` |
| `severity` | text |  |  | one of: `critical`, `info`, `success`, `warning`; default `'info'` |
| `published_by` | uuid |  |  |  |
| `published_at` | timestamptz |  |  | default `now()` |
| `expires_on` | date | yes |  |  |
| `pinned` | bool |  |  | default `false` |
| `target_department_id` | uuid | yes |  |  |
| `target_role` | text | yes |  | one of: `employee`, `finance`, `hr_admin`, `hr_officer`, `manager`, `super_admin` |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `expires_on`, `is_active, pinned DESC, published_at DESC`, `target_department_id`

### `notification.email_outbox`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `to_address` | text |  |  |  |
| `to_name` | text |  |  | default `''` |
| `subject` | text |  |  |  |
| `body_html` | text |  |  |  |
| `body_text` | text |  |  |  |
| `status` | text |  |  | one of: `failed`, `queued`, `sent`; default `'queued'` |
| `attempts` | int |  |  | default `0` |
| `last_error` | text | yes |  |  |
| `queued_at` | timestamptz |  |  | default `now()` |
| `sent_at` | timestamptz | yes |  |  |
| `event_name` | text | yes |  |  |
| `related_user_id` | uuid | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `created_at DESC`, `queued_at`, `related_user_id, created_at DESC`

### `notification.email_templates`

*27 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `event_name` | text |  | UQ |  |
| `subject` | text |  |  |  |
| `body_html` | text |  |  |  |
| `body_text` | text |  |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

### `notification.notification_prefs`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `user_id` | uuid |  | UQ UQ |  |
| `category` | text |  | UQ UQ |  |
| `in_app_enabled` | bool |  |  | default `true` |
| `email_enabled` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `user_id`

### `notification.notifications`

*37 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `employee_id` | uuid | yes |  |  |
| `user_id` | uuid | yes |  |  |
| `category` | text |  |  |  |
| `event_name` | text |  |  |  |
| `title` | text |  |  |  |
| `body` | text |  |  | default `''` |
| `action_url` | text | yes |  |  |
| `icon` | text |  |  | default `'bell'` |
| `severity` | text |  |  | one of: `critical`, `info`, `success`, `warning`; default `'info'` |
| `read_at` | timestamptz | yes |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `employee_id, created_at DESC`, `employee_id, user_id`, `user_id, created_at DESC`

### `notification.processed_events`

*7 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `event_id` | text |  | UQ |  |
| `event_name` | text |  |  |  |
| `source` | text |  |  | default `''` |
| `processed_at` | timestamptz |  |  | default `now()` |

Indexed on: `event_name, processed_at DESC`

---

## `analytics`

**Owned by analytics-service.** Cached dashboards and saved report definitions.

6 tables (4 business, 2 created by the kernel).

### `analytics.dashboard_cache`

*1 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `cache_key` | text |  | UQ |  |
| `scope_key` | text |  |  |  |
| `payload` | jsonb |  |  |  |
| `expires_at` | timestamptz |  |  |  |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `expires_at`, `scope_key`

### `analytics.metric_snapshots`

*9 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `metric_key` | text |  | UQ UQ UQ UQ |  |
| `dimension_key` | text |  | UQ UQ UQ UQ | default `'overall'` |
| `dimension_value` | text |  | UQ UQ UQ UQ | default `'all'` |
| `period` | text |  | UQ UQ UQ UQ |  |
| `value` | numeric(18,4) |  |  | default `0` |
| `captured_at` | timestamptz |  |  | default `now()` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `captured_at DESC`, `metric_key, dimension_key, period`

### `analytics.report_definitions`

*12 rows seeded.*

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `name` | text |  |  |  |
| `slug` | text |  | UQ |  |
| `description` | text |  |  | default `''` |
| `report_type` | text |  |  | one of: `attendance`, `document`, `expense`, `learning`, `leave`, `payroll`, `people`, `performance`; default `'people'` |
| `default_filters` | jsonb |  |  | default `'{}'` |
| `required_permission` | text |  |  |  |
| `is_active` | bool |  |  | default `true` |
| `created_at` | timestamptz |  |  | default `now()` |
| `updated_at` | timestamptz |  |  | default `now()` |

Indexed on: `is_active, report_type, name`

### `analytics.report_runs`

| Column | Type | Null | Key | Notes |
| --- | --- | --- | --- | --- |
| `id` | uuid |  | PK |  |
| `report_definition_id` | uuid |  | FK | → `report_definitions.id` |
| `run_by` | uuid |  |  |  |
| `filters` | jsonb |  |  | default `'{}'` |
| `row_count` | int |  |  | default `0` |
| `format` | text |  |  | one of: `csv`, `json`, `pdf`; default `'json'` |
| `duration_ms` | int |  |  | default `0` |
| `created_at` | timestamptz |  |  | default `now()` |

Indexed on: `report_definition_id, created_at DESC`, `run_by, created_at DESC`

---

## Totals

| | |
| --- | --- |
| Schemas | 10 |
| Tables | 91 |
| Business columns | 795 |
| Database roles | 9, one per service |

## Working with the database directly

```bash
# a shell
docker compose exec postgres psql -U dayflow_owner -d dayflow

# a browser at http://localhost:8081
docker compose --profile tools up -d adminer
```

Useful queries:

```sql
-- everything that happened to one person, across every service
SELECT occurred_at, service, action, actor_email
FROM platform.audit_log
WHERE subject_id = '<employee_id>'
ORDER BY occurred_at DESC;

-- events waiting to be delivered
SELECT event_name, attempts, created_at
FROM leave_management.event_outbox
WHERE delivered_at IS NULL;

-- which migrations a service has applied
SELECT * FROM payroll.schema_migrations ORDER BY migration;
```

## Backups

```bash
docker compose exec -T postgres pg_dump -U dayflow_owner -d dayflow --format=custom > dayflow.dump
docker compose exec -T postgres pg_restore -U dayflow_owner -d dayflow --clean --if-exists < dayflow.dump
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for a scheduled backup script.
