-- ---------------------------------------------------------------------------
-- Delivery ledger for the event sink.
--
-- Publishing services retry an event until the sink confirms it, so the same
-- event arrives more than once whenever this service is restarted or briefly
-- unreachable. The unique event_id is what turns those retries into no-ops
-- instead of duplicate notifications and duplicate email.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS processed_events (
    id           UUID PRIMARY KEY,
    event_id     TEXT        NOT NULL UNIQUE,
    event_name   TEXT        NOT NULL,
    source       TEXT        NOT NULL DEFAULT '',
    processed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS processed_events_name_idx
    ON processed_events (event_name, processed_at DESC);
