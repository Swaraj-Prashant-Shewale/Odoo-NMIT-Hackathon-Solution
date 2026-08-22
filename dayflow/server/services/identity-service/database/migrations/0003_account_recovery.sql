-- ---------------------------------------------------------------------------
-- Single-use, expiring links for proving control of an address.
--
-- Both tables hold a keyed digest rather than the token itself, so a copy of
-- this database does not let anyone verify an address or take over an account.
-- consumed_at makes a link single use even if it is clicked twice.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_verifications (
    id          UUID PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash  TEXT        NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS email_verifications_hash_unique ON email_verifications (token_hash);
CREATE INDEX IF NOT EXISTS email_verifications_user_idx ON email_verifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS email_verifications_open_idx
    ON email_verifications (expires_at)
    WHERE consumed_at IS NULL;

CREATE TABLE IF NOT EXISTS password_resets (
    id           UUID PRIMARY KEY,
    user_id      UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash   TEXT        NOT NULL,
    expires_at   TIMESTAMPTZ NOT NULL,
    consumed_at  TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    requested_ip TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS password_resets_hash_unique ON password_resets (token_hash);
CREATE INDEX IF NOT EXISTS password_resets_user_idx ON password_resets (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS password_resets_open_idx
    ON password_resets (expires_at)
    WHERE consumed_at IS NULL;
