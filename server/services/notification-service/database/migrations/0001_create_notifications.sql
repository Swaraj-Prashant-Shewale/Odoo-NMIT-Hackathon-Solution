-- ---------------------------------------------------------------------------
-- In-app notifications and the per-user channel preferences that gate them.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id          UUID PRIMARY KEY,
    employee_id UUID,
    user_id     UUID,
    category    TEXT        NOT NULL,
    event_name  TEXT        NOT NULL,
    title       TEXT        NOT NULL,
    body        TEXT        NOT NULL DEFAULT '',
    action_url  TEXT,
    icon        TEXT        NOT NULL DEFAULT 'bell',
    severity    TEXT        NOT NULL DEFAULT 'info'
                CHECK (severity IN ('info', 'success', 'warning', 'critical')),
    read_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- A notification nobody can be shown to is a bug, not a valid row.
    CONSTRAINT notifications_recipient_present
        CHECK (employee_id IS NOT NULL OR user_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS notifications_employee_idx
    ON notifications (employee_id, created_at DESC);

CREATE INDEX IF NOT EXISTS notifications_user_idx
    ON notifications (user_id, created_at DESC);

-- The navigation bar asks for the unread count on every page load, so the
-- unread rows get their own partial index rather than a filtered scan.
CREATE INDEX IF NOT EXISTS notifications_unread_idx
    ON notifications (employee_id, user_id) WHERE read_at IS NULL;

CREATE TABLE IF NOT EXISTS notification_prefs (
    id             UUID PRIMARY KEY,
    user_id        UUID        NOT NULL,
    category       TEXT        NOT NULL,
    in_app_enabled BOOLEAN     NOT NULL DEFAULT TRUE,
    email_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT notification_prefs_user_category_unique UNIQUE (user_id, category)
);

CREATE INDEX IF NOT EXISTS notification_prefs_user_idx
    ON notification_prefs (user_id);
