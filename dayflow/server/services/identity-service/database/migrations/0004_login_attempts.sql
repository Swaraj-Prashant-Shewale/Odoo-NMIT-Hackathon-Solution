-- ---------------------------------------------------------------------------
-- Every sign-in attempt, successful or not.
--
-- The address attempted is stored as a keyed digest. The table exists to
-- answer "is this account under attack?", which a digest answers perfectly
-- well, and storing it this way means the security log can never become a
-- harvestable list of everybody's email address.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    id             UUID PRIMARY KEY,
    email_hash     TEXT        NOT NULL,
    ip_address     TEXT,
    successful     BOOLEAN     NOT NULL DEFAULT FALSE,
    attempted_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    failure_reason TEXT
);

CREATE INDEX IF NOT EXISTS login_attempts_email_idx  ON login_attempts (email_hash, attempted_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_ip_idx     ON login_attempts (ip_address, attempted_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_recent_idx ON login_attempts (attempted_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_failed_idx
    ON login_attempts (attempted_at DESC)
    WHERE successful = FALSE;
