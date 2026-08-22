-- ---------------------------------------------------------------------------
-- Continuous feedback between colleagues.
--
-- from_employee_id is stored even when the sender asked to stay anonymous.
-- Discarding it would make anonymous feedback untraceable, which is exactly
-- what an abusive sender would rely on; withholding it from the reader is the
-- API's job, not the schema's.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS feedback (
    id                UUID PRIMARY KEY,
    from_employee_id  UUID        NOT NULL,
    to_employee_id    UUID        NOT NULL,
    feedback_type     TEXT        NOT NULL
                      CHECK (feedback_type IN ('appreciation', 'constructive', 'request')),
    visibility        TEXT        NOT NULL DEFAULT 'private'
                      CHECK (visibility IN ('private', 'manager', 'public')),
    subject           TEXT        NOT NULL,
    body              TEXT        NOT NULL,
    related_goal_id   UUID        REFERENCES goals (id) ON DELETE SET NULL,
    is_anonymous      BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT feedback_not_self CHECK (from_employee_id <> to_employee_id)
);

CREATE INDEX IF NOT EXISTS feedback_recipient_idx
    ON feedback (to_employee_id, created_at DESC);

CREATE INDEX IF NOT EXISTS feedback_sender_idx
    ON feedback (from_employee_id, created_at DESC);

CREATE INDEX IF NOT EXISTS feedback_visibility_idx
    ON feedback (to_employee_id, visibility, created_at DESC);

CREATE INDEX IF NOT EXISTS feedback_goal_idx
    ON feedback (related_goal_id);
