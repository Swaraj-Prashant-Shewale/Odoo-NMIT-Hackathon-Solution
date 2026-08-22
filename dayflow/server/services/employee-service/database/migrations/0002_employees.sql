-- ---------------------------------------------------------------------------
-- The people record. This table is the system of record for the whole
-- platform: every other service stores only an employee_id and asks here for
-- anything else it needs to display.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS employees (
    id                          UUID PRIMARY KEY,
    employee_code               TEXT        NOT NULL,
    user_id                     UUID,
    first_name                  TEXT        NOT NULL,
    last_name                   TEXT        NOT NULL,
    work_email                  TEXT        NOT NULL,
    personal_email              TEXT,
    phone                       TEXT,
    alternate_phone             TEXT,
    date_of_birth               DATE,
    gender                      TEXT,
    blood_group                 TEXT,
    marital_status              TEXT,
    address_line1               TEXT,
    address_line2               TEXT,
    city                        TEXT,
    state                       TEXT,
    country                     TEXT,
    postal_code                 TEXT,
    emergency_contact_name      TEXT,
    emergency_contact_phone     TEXT,
    emergency_contact_relation  TEXT,
    department_id               UUID        REFERENCES departments (id) ON DELETE RESTRICT,
    designation_id              UUID        REFERENCES designations (id) ON DELETE RESTRICT,
    location_id                 UUID        REFERENCES locations (id) ON DELETE SET NULL,
    manager_id                  UUID        REFERENCES employees (id) ON DELETE SET NULL,
    employment_type             TEXT        NOT NULL DEFAULT 'full_time',
    employment_status           TEXT        NOT NULL DEFAULT 'probation',
    joined_on                   DATE        NOT NULL,
    probation_end_on            DATE,
    confirmed_on                DATE,
    notice_start_on             DATE,
    exit_date                   DATE,
    exit_reason                 TEXT,
    photo_document_id           UUID,
    is_active                   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at                  TIMESTAMPTZ,

    -- Everything the people search looks at, folded into one column the
    -- database maintains. A single column also makes "Priya Sharma" findable,
    -- which searching the name parts separately never could.
    search_text                 TEXT GENERATED ALWAYS AS (
        LOWER(
            COALESCE(first_name, '') || ' ' || COALESCE(last_name, '') || ' ' ||
            COALESCE(employee_code, '') || ' ' || COALESCE(work_email, '')
        )
    ) STORED,

    CONSTRAINT employees_employment_type CHECK (
        employment_type IN ('full_time', 'part_time', 'contract', 'intern', 'consultant')
    ),
    CONSTRAINT employees_employment_status CHECK (
        employment_status IN ('probation', 'confirmed', 'notice_period', 'resigned', 'terminated')
    ),
    CONSTRAINT employees_gender CHECK (
        gender IS NULL OR gender IN ('male', 'female', 'other', 'undisclosed')
    ),
    CONSTRAINT employees_marital_status CHECK (
        marital_status IS NULL OR marital_status IN ('single', 'married', 'divorced', 'widowed', 'undisclosed')
    ),
    CONSTRAINT employees_blood_group CHECK (
        blood_group IS NULL OR blood_group IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')
    ),
    -- A one-step cycle is cheap to forbid outright; longer cycles are rejected
    -- by the application before the update is issued.
    CONSTRAINT employees_not_own_manager CHECK (manager_id IS NULL OR manager_id <> id),
    CONSTRAINT employees_exit_after_join CHECK (exit_date IS NULL OR exit_date >= joined_on),
    CONSTRAINT employees_confirmed_after_join CHECK (confirmed_on IS NULL OR confirmed_on >= joined_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS employees_code_key       ON employees (employee_code);
CREATE UNIQUE INDEX IF NOT EXISTS employees_work_email_key ON employees (LOWER(work_email));

-- A login account maps to at most one person record, but a person record may
-- exist before an account is issued, so the uniqueness is partial.
CREATE UNIQUE INDEX IF NOT EXISTS employees_user_id_key
    ON employees (user_id) WHERE user_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS employees_department_idx  ON employees (department_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS employees_manager_idx     ON employees (manager_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS employees_status_idx      ON employees (employment_status) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS employees_designation_idx ON employees (designation_id);
CREATE INDEX IF NOT EXISTS employees_location_idx    ON employees (location_id);
CREATE INDEX IF NOT EXISTS employees_name_idx        ON employees (last_name, first_name);
CREATE INDEX IF NOT EXISTS employees_joined_idx      ON employees (joined_on DESC);

-- departments and employees reference one another, so the head-of-department
-- key can only be added once both tables exist.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t     ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE c.conname = 'departments_head_employee_fk'
          AND t.relname = 'departments'
          AND n.nspname = current_schema()
    ) THEN
        ALTER TABLE departments
            ADD CONSTRAINT departments_head_employee_fk
            FOREIGN KEY (head_employee_id) REFERENCES employees (id) ON DELETE SET NULL;
    END IF;
END
$$;
