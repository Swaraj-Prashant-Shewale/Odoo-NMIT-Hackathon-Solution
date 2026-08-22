-- One row per employee, leave type and leave year.
--
-- The row is a ledger of movements rather than a single number, so a balance
-- can always be explained: what was opened with, what accrued, what was
-- carried over, what an administrator corrected, and what is spent or held.
--
-- available = opening + accrued + carried_forward + adjusted - used - pending

CREATE TABLE IF NOT EXISTS leave_balances (
    id                   UUID PRIMARY KEY,
    employee_id          UUID         NOT NULL,
    leave_type_id        UUID         NOT NULL REFERENCES leave_types (id) ON DELETE CASCADE,
    year                 INTEGER      NOT NULL CHECK (year BETWEEN 2000 AND 2200),
    opening_days         NUMERIC(6,2) NOT NULL DEFAULT 0 CHECK (opening_days >= 0),
    accrued_days         NUMERIC(6,2) NOT NULL DEFAULT 0 CHECK (accrued_days >= 0),
    used_days            NUMERIC(6,2) NOT NULL DEFAULT 0 CHECK (used_days >= 0),
    pending_days         NUMERIC(6,2) NOT NULL DEFAULT 0 CHECK (pending_days >= 0),
    carried_forward_days NUMERIC(6,2) NOT NULL DEFAULT 0 CHECK (carried_forward_days >= 0),
    -- Signed: an administrator can take days away as well as grant them.
    adjusted_days        NUMERIC(6,2) NOT NULL DEFAULT 0,
    -- The accrual period already credited to this row ("2026-08", "2026-Q3",
    -- "2026-A"). Re-running the accrual for the same period is a no-op, which
    -- is what makes the job safe to schedule and safe to retry by hand.
    last_accrual_period  TEXT,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS leave_balances_unique_key
    ON leave_balances (employee_id, leave_type_id, year);

CREATE INDEX IF NOT EXISTS leave_balances_employee_year_idx
    ON leave_balances (employee_id, year);
