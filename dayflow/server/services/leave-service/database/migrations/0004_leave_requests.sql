-- An application to be away, and the decision taken on it.

CREATE TABLE IF NOT EXISTS leave_requests (
    id                       UUID PRIMARY KEY,
    employee_id              UUID         NOT NULL,
    leave_type_id            UUID         NOT NULL REFERENCES leave_types (id),
    starts_on                DATE         NOT NULL,
    ends_on                  DATE         NOT NULL,
    -- Working days charged to the balance: weekends and public holidays
    -- inside the range are already excluded.
    day_count                NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (day_count >= 0),
    is_half_day              BOOLEAN      NOT NULL DEFAULT FALSE,
    half_day_period          TEXT         CHECK (half_day_period IN ('first_half','second_half')),
    reason                   TEXT,
    contact_during_leave     TEXT,
    status                   TEXT         NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending','approved','rejected','cancelled','withdrawn')),
    -- Captured at submission so the queue survives a later reporting change.
    approver_id              UUID,
    decided_by               UUID,
    decided_at               TIMESTAMPTZ,
    decision_note            TEXT,
    cancelled_at             TIMESTAMPTZ,
    cancelled_by             UUID,
    supporting_document_id   UUID,
    -- FALSE records that the holiday calendar was unreachable when the days
    -- were counted, so the figure excludes weekends only and may be revisited.
    holiday_calendar_applied BOOLEAN      NOT NULL DEFAULT TRUE,
    applied_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT leave_requests_dates_ordered
        CHECK (ends_on >= starts_on),

    CONSTRAINT leave_requests_half_day_is_one_day
        CHECK (
            is_half_day = FALSE
            OR (starts_on = ends_on AND half_day_period IS NOT NULL AND day_count = 0.5)
        ),

    CONSTRAINT leave_requests_decision_complete
        CHECK (
            status NOT IN ('approved','rejected')
            OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)
        ),

    CONSTRAINT leave_requests_cancellation_complete
        CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS leave_requests_employee_idx
    ON leave_requests (employee_id, starts_on DESC);

-- Serves both the overlap test on submission and the month view of the
-- calendar, which are the two hottest reads on this table.
CREATE INDEX IF NOT EXISTS leave_requests_window_idx
    ON leave_requests (starts_on, ends_on);

CREATE INDEX IF NOT EXISTS leave_requests_status_idx
    ON leave_requests (status, applied_at DESC);

CREATE INDEX IF NOT EXISTS leave_requests_pending_queue_idx
    ON leave_requests (approver_id, applied_at) WHERE status = 'pending';
