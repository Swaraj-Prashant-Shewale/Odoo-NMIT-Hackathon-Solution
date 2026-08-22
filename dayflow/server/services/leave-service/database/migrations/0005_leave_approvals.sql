-- The approval chain for a request, one row per level.
--
-- Most requests need a single signature, but holding the chain as rows means
-- a second level can be inserted for long absences without changing the shape
-- of the request itself, and the history of who signed what survives.

CREATE TABLE IF NOT EXISTS leave_approvals (
    id               UUID PRIMARY KEY,
    leave_request_id UUID        NOT NULL REFERENCES leave_requests (id) ON DELETE CASCADE,
    level            INTEGER     NOT NULL DEFAULT 1 CHECK (level >= 1),
    approver_id      UUID        NOT NULL,
    status           TEXT        NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','approved','rejected','skipped')),
    note             TEXT,
    decided_at       TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT leave_approvals_decision_dated
        CHECK (status = 'pending' OR decided_at IS NOT NULL)
);

CREATE UNIQUE INDEX IF NOT EXISTS leave_approvals_level_key
    ON leave_approvals (leave_request_id, level);

CREATE INDEX IF NOT EXISTS leave_approvals_queue_idx
    ON leave_approvals (approver_id, status, level);
