-- Manual corrections to a balance.
--
-- The balance row carries only the running total, so every change made by a
-- person is written here as well: a balance that moved without an explanation
-- is the one thing an employee will always dispute.

CREATE TABLE IF NOT EXISTS leave_adjustments (
    id            UUID PRIMARY KEY,
    employee_id   UUID         NOT NULL,
    leave_type_id UUID         NOT NULL REFERENCES leave_types (id),
    year          INTEGER      NOT NULL CHECK (year BETWEEN 2000 AND 2200),
    delta_days    NUMERIC(6,2) NOT NULL CHECK (delta_days <> 0),
    reason        TEXT         NOT NULL,
    adjusted_by   UUID         NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS leave_adjustments_employee_idx
    ON leave_adjustments (employee_id, year, created_at DESC);

CREATE INDEX IF NOT EXISTS leave_adjustments_type_idx
    ON leave_adjustments (leave_type_id, year);
