-- ---------------------------------------------------------------------------
-- The ledger of events this service has already acted on.
--
-- A publisher retries anything it was not given a 2xx for, so the same event
-- arrives more than once as a matter of course rather than as a fault. Without
-- a record of what has been handled, a redelivered leave approval would write
-- the same "on leave" days a second time.
--
-- event_id is a digest of the event name, its payload and its source, so the
-- same occurrence always produces the same key however many times it is sent.
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
