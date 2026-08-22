-- ---------------------------------------------------------------------------
-- Goal setting: the cycle a goal belongs to, the goal itself and the running
-- log of progress entries recorded against it.
--
-- created_by and employee_id are employee identifiers owned by the employee
-- service. They are plain UUID columns with no foreign key because that table
-- lives in a schema this database role cannot see.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS goal_cycles (
    id            UUID PRIMARY KEY,
    name          TEXT        NOT NULL,
    period_start  DATE        NOT NULL,
    period_end    DATE        NOT NULL,
    status        TEXT        NOT NULL DEFAULT 'draft'
                  CHECK (status IN ('draft', 'open', 'closed')),
    created_by    UUID,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT goal_cycle_period_ordered CHECK (period_end >= period_start)
);

CREATE INDEX IF NOT EXISTS goal_cycles_status_idx
    ON goal_cycles (status, period_start DESC);

CREATE UNIQUE INDEX IF NOT EXISTS goal_cycles_name_period_idx
    ON goal_cycles (lower(name), period_start);

CREATE TABLE IF NOT EXISTS goals (
    id                UUID PRIMARY KEY,
    employee_id       UUID         NOT NULL,
    goal_cycle_id     UUID         NOT NULL REFERENCES goal_cycles (id),
    title             TEXT         NOT NULL,
    description       TEXT,
    category          TEXT         NOT NULL DEFAULT 'business'
                      CHECK (category IN ('business', 'personal', 'learning', 'behavioural')),
    metric            TEXT,
    target_value      NUMERIC(16,3),
    current_value     NUMERIC(16,3) NOT NULL DEFAULT 0,
    unit              TEXT,
    weight_percent    NUMERIC(6,3)  NOT NULL DEFAULT 0
                      CHECK (weight_percent >= 0 AND weight_percent <= 100),
    status            TEXT          NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'active', 'achieved', 'missed', 'cancelled')),
    progress_percent  NUMERIC(6,3)  NOT NULL DEFAULT 0
                      CHECK (progress_percent >= 0 AND progress_percent <= 100),
    starts_on         DATE,
    due_on            DATE,
    completed_at      TIMESTAMPTZ,
    created_by        UUID,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    CONSTRAINT goal_dates_ordered
        CHECK (starts_on IS NULL OR due_on IS NULL OR due_on >= starts_on)
);

CREATE INDEX IF NOT EXISTS goals_employee_cycle_idx
    ON goals (employee_id, goal_cycle_id);

CREATE INDEX IF NOT EXISTS goals_employee_status_idx
    ON goals (employee_id, status, due_on);

CREATE INDEX IF NOT EXISTS goals_cycle_status_idx
    ON goals (goal_cycle_id, status);

CREATE TABLE IF NOT EXISTS goal_updates (
    id                UUID PRIMARY KEY,
    goal_id           UUID         NOT NULL REFERENCES goals (id) ON DELETE CASCADE,
    employee_id       UUID         NOT NULL,
    progress_percent  NUMERIC(6,3) NOT NULL
                      CHECK (progress_percent >= 0 AND progress_percent <= 100),
    note              TEXT,
    created_by        UUID,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS goal_updates_goal_idx
    ON goal_updates (goal_id, created_at DESC);

CREATE INDEX IF NOT EXISTS goal_updates_employee_idx
    ON goal_updates (employee_id, created_at DESC);
