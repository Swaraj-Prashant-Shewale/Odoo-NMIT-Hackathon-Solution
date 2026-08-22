-- ---------------------------------------------------------------------------
-- Bookkeeping for work the seed must not repeat.
--
-- The identity seed has one step that is not cheap. Confirming the
-- administrator's password still matches the configured one costs an Argon2id
-- verification, roughly a fifth of a second, and unlike every other seeded row
-- it cannot be settled with an "insert if missing" statement.
--
-- How often a seed is loaded is the kernel's business and has changed before,
-- so that step claims a slot here rather than assuming it runs rarely. One
-- process per interval does the work and the rest skip it, which keeps a
-- changed credential taking effect within a minute of a restart while costing
-- nothing in between however often the seed is asked to run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS seed_state (
    name       TEXT PRIMARY KEY,
    checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
