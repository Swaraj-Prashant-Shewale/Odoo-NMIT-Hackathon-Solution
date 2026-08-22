-- A manager hands their approval queue to someone else while they are away.
--
-- The delegation is never copied onto the requests themselves. Requests keep
-- the original approver, and the delegate is granted the right to act on that
-- approver's behalf only for as long as the window is open, so revoking a
-- delegation takes effect immediately and leaves no orphaned authority.

CREATE TABLE IF NOT EXISTS approval_delegations (
    id           UUID        PRIMARY KEY,
    delegator_id UUID        NOT NULL,
    delegate_id  UUID        NOT NULL,
    starts_on    DATE        NOT NULL,
    ends_on      DATE        NOT NULL,
    reason       TEXT,
    is_active    BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT approval_delegations_dates_ordered
        CHECK (ends_on >= starts_on),

    CONSTRAINT approval_delegations_distinct_parties
        CHECK (delegator_id <> delegate_id)
);

CREATE INDEX IF NOT EXISTS approval_delegations_delegate_idx
    ON approval_delegations (delegate_id, is_active, starts_on, ends_on);

CREATE INDEX IF NOT EXISTS approval_delegations_delegator_idx
    ON approval_delegations (delegator_id, is_active, starts_on DESC);
