-- ---------------------------------------------------------------------------
-- Shift patterns, the people they apply to, and day-level overrides.
--
-- A shift is the rule; an assignment applies that rule over a date range; a
-- roster row overrides both for a single day. Attendance always resolves in
-- that order, so a one-off swap never requires rewriting an assignment.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shifts (
    id              UUID PRIMARY KEY,
    name            TEXT         NOT NULL,
    code            TEXT         NOT NULL UNIQUE,
    starts_at       TIME         NOT NULL,
    ends_at         TIME         NOT NULL,
    break_minutes   INTEGER      NOT NULL DEFAULT 60,
    full_day_hours  NUMERIC(4,2) NOT NULL DEFAULT 8.00,
    half_day_hours  NUMERIC(4,2) NOT NULL DEFAULT 4.00,
    grace_minutes   INTEGER      NOT NULL DEFAULT 15,
    is_night_shift  BOOLEAN      NOT NULL DEFAULT FALSE,
    working_days    JSONB        NOT NULL DEFAULT '["mon","tue","wed","thu","fri"]'::JSONB,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT shifts_break_sane        CHECK (break_minutes BETWEEN 0 AND 480),
    CONSTRAINT shifts_grace_sane        CHECK (grace_minutes BETWEEN 0 AND 240),
    CONSTRAINT shifts_full_day_sane     CHECK (full_day_hours > 0 AND full_day_hours <= 24),
    CONSTRAINT shifts_half_day_sane     CHECK (half_day_hours > 0 AND half_day_hours <= full_day_hours),
    CONSTRAINT shifts_working_days_list CHECK (jsonb_typeof(working_days) = 'array')
);

CREATE INDEX IF NOT EXISTS shifts_active_idx ON shifts (is_active, code);

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shift_assignments (
    id             UUID PRIMARY KEY,
    employee_id    UUID        NOT NULL,
    shift_id       UUID        NOT NULL REFERENCES shifts (id),
    effective_from DATE        NOT NULL,
    effective_to   DATE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT shift_assignments_dates_ordered
        CHECK (effective_to IS NULL OR effective_to >= effective_from)
);

CREATE INDEX IF NOT EXISTS shift_assignments_employee_idx
    ON shift_assignments (employee_id, effective_from DESC);

CREATE INDEX IF NOT EXISTS shift_assignments_shift_idx
    ON shift_assignments (shift_id);

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rosters (
    id          UUID PRIMARY KEY,
    employee_id UUID        NOT NULL,
    shift_id    UUID        NOT NULL REFERENCES shifts (id),
    roster_date DATE        NOT NULL,
    notes       TEXT,
    created_by  UUID,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT rosters_employee_date_unique UNIQUE (employee_id, roster_date)
);

CREATE INDEX IF NOT EXISTS rosters_date_idx  ON rosters (roster_date, employee_id);
CREATE INDEX IF NOT EXISTS rosters_shift_idx ON rosters (shift_id);
