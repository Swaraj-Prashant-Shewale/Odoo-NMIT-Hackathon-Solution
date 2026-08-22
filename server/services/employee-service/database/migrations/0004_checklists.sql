-- ---------------------------------------------------------------------------
-- Joiner and leaver checklists. The two tables are deliberately identical in
-- shape: the same screen and the same completion rules serve both, and keeping
-- them apart means a closed offboarding can never be miscounted as an open
-- onboarding.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS onboarding_tasks (
    id            UUID PRIMARY KEY,
    employee_id   UUID        NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
    title         TEXT        NOT NULL,
    description   TEXT,
    category      TEXT        NOT NULL DEFAULT 'general',
    sequence      INTEGER     NOT NULL DEFAULT 0,
    owner_role    TEXT        NOT NULL DEFAULT 'hr_officer',
    assigned_to   UUID        REFERENCES employees (id) ON DELETE SET NULL,
    due_on        DATE,
    completed_at  TIMESTAMPTZ,
    completed_by  UUID,
    status        TEXT        NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT onboarding_tasks_status CHECK (
        status IN ('pending', 'in_progress', 'completed', 'skipped')
    ),
    CONSTRAINT onboarding_tasks_owner_role CHECK (
        owner_role IN ('super_admin', 'hr_admin', 'hr_officer', 'manager', 'finance', 'employee')
    ),
    CONSTRAINT onboarding_tasks_completion_consistent CHECK (
        (status = 'completed') = (completed_at IS NOT NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS onboarding_tasks_employee_title_key
    ON onboarding_tasks (employee_id, LOWER(title));
CREATE INDEX IF NOT EXISTS onboarding_tasks_employee_idx ON onboarding_tasks (employee_id, sequence);
CREATE INDEX IF NOT EXISTS onboarding_tasks_status_idx   ON onboarding_tasks (status);
CREATE INDEX IF NOT EXISTS onboarding_tasks_assignee_idx ON onboarding_tasks (assigned_to);
CREATE INDEX IF NOT EXISTS onboarding_tasks_due_idx      ON onboarding_tasks (due_on) WHERE status <> 'completed';

CREATE TABLE IF NOT EXISTS offboarding_tasks (
    id            UUID PRIMARY KEY,
    employee_id   UUID        NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
    title         TEXT        NOT NULL,
    description   TEXT,
    category      TEXT        NOT NULL DEFAULT 'general',
    sequence      INTEGER     NOT NULL DEFAULT 0,
    owner_role    TEXT        NOT NULL DEFAULT 'hr_officer',
    assigned_to   UUID        REFERENCES employees (id) ON DELETE SET NULL,
    due_on        DATE,
    completed_at  TIMESTAMPTZ,
    completed_by  UUID,
    status        TEXT        NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT offboarding_tasks_status CHECK (
        status IN ('pending', 'in_progress', 'completed', 'skipped')
    ),
    CONSTRAINT offboarding_tasks_owner_role CHECK (
        owner_role IN ('super_admin', 'hr_admin', 'hr_officer', 'manager', 'finance', 'employee')
    ),
    CONSTRAINT offboarding_tasks_completion_consistent CHECK (
        (status = 'completed') = (completed_at IS NOT NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS offboarding_tasks_employee_title_key
    ON offboarding_tasks (employee_id, LOWER(title));
CREATE INDEX IF NOT EXISTS offboarding_tasks_employee_idx ON offboarding_tasks (employee_id, sequence);
CREATE INDEX IF NOT EXISTS offboarding_tasks_status_idx   ON offboarding_tasks (status);
CREATE INDEX IF NOT EXISTS offboarding_tasks_assignee_idx ON offboarding_tasks (assigned_to);
CREATE INDEX IF NOT EXISTS offboarding_tasks_due_idx      ON offboarding_tasks (due_on) WHERE status <> 'completed';
