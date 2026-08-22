-- ---------------------------------------------------------------------------
-- Requests to correct a day the clock got wrong.
--
-- The approver is stamped at submission time so the queue stays stable even if
-- the reporting line changes before a decision is made.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS regularisations (
    id                  UUID PRIMARY KEY,
    employee_id         UUID        NOT NULL,
    work_date           DATE        NOT NULL,
    requested_check_in  TIMESTAMPTZ,
    requested_check_out TIMESTAMPTZ,
    requested_status    TEXT,
    reason              TEXT        NOT NULL,
    status              TEXT        NOT NULL DEFAULT 'pending',
    approver_id         UUID,
    decided_by          UUID,
    decided_at          TIMESTAMPTZ,
    decision_note       TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT regularisations_status_known CHECK (status IN ('pending', 'approved', 'rejected')),
    CONSTRAINT regularisations_requested_status_known CHECK (
        requested_status IS NULL OR requested_status IN ('present', 'half_day', 'wfh')
    ),
    CONSTRAINT regularisations_times_ordered CHECK (
        requested_check_out IS NULL OR requested_check_in IS NULL
            OR requested_check_out >= requested_check_in
    ),
    CONSTRAINT regularisations_states_something CHECK (
        requested_check_in IS NOT NULL OR requested_check_out IS NOT NULL OR requested_status IS NOT NULL
    ),
    CONSTRAINT regularisations_decision_complete CHECK (
        (status = 'pending' AND decided_by IS NULL AND decided_at IS NULL)
            OR (status <> 'pending' AND decided_by IS NOT NULL AND decided_at IS NOT NULL)
    )
);

-- One open request per day, enforced by the database rather than only by the
-- controller, so a duplicate cannot slip through two simultaneous submissions.
CREATE UNIQUE INDEX IF NOT EXISTS regularisations_one_pending_per_day
    ON regularisations (employee_id, work_date)
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS regularisations_employee_idx
    ON regularisations (employee_id, work_date DESC);

CREATE INDEX IF NOT EXISTS regularisations_queue_idx
    ON regularisations (approver_id, status, created_at DESC);
