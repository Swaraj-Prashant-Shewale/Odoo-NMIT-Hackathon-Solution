-- ---------------------------------------------------------------------------
-- Email templates and the outbox every message is written to before it leaves.
--
-- Queueing first means a failed send is a row to retry rather than a message
-- that silently vanished, and it gives local development a readable inbox.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_templates (
    id         UUID PRIMARY KEY,
    event_name TEXT        NOT NULL UNIQUE,
    subject    TEXT        NOT NULL,
    body_html  TEXT        NOT NULL,
    body_text  TEXT        NOT NULL,
    is_active  BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS email_outbox (
    id              UUID PRIMARY KEY,
    to_address      TEXT        NOT NULL,
    to_name         TEXT        NOT NULL DEFAULT '',
    subject         TEXT        NOT NULL,
    body_html       TEXT        NOT NULL,
    body_text       TEXT        NOT NULL,
    status          TEXT        NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued', 'sent', 'failed')),
    attempts        INTEGER     NOT NULL DEFAULT 0,
    last_error      TEXT,
    queued_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sent_at         TIMESTAMPTZ,
    event_name      TEXT,
    related_user_id UUID,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS email_outbox_created_idx
    ON email_outbox (created_at DESC);

CREATE INDEX IF NOT EXISTS email_outbox_pending_idx
    ON email_outbox (queued_at) WHERE status = 'queued';

CREATE INDEX IF NOT EXISTS email_outbox_recipient_idx
    ON email_outbox (related_user_id, created_at DESC);
