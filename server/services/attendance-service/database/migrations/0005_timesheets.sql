-- ---------------------------------------------------------------------------
-- Project time logs.
--
-- Attendance answers "was this person at work"; a timesheet answers "on what".
-- The two are deliberately separate: a day can be fully present and still have
-- no billable hours recorded against it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS timesheets (
    id               UUID PRIMARY KEY,
    employee_id      UUID         NOT NULL,
    work_date        DATE         NOT NULL,
    project_code     TEXT         NOT NULL,
    task_description TEXT         NOT NULL,
    hours            NUMERIC(5,2) NOT NULL,
    billable         BOOLEAN      NOT NULL DEFAULT TRUE,
    approved_by      UUID,
    approved_at      TIMESTAMPTZ,
    status           TEXT         NOT NULL DEFAULT 'draft',
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT timesheets_status_known CHECK (status IN ('draft', 'submitted', 'approved', 'rejected')),
    CONSTRAINT timesheets_hours_sane CHECK (hours > 0 AND hours <= 24),
    CONSTRAINT timesheets_decision_complete CHECK (
        (status IN ('draft', 'submitted') AND approved_by IS NULL AND approved_at IS NULL)
            OR (status IN ('approved', 'rejected') AND approved_by IS NOT NULL AND approved_at IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS timesheets_employee_idx ON timesheets (employee_id, work_date DESC);
CREATE INDEX IF NOT EXISTS timesheets_project_idx  ON timesheets (project_code, work_date DESC);
CREATE INDEX IF NOT EXISTS timesheets_status_idx   ON timesheets (status, work_date DESC);
