-- ---------------------------------------------------------------------------
-- Sign-in sessions.
--
-- Refresh tokens rotate: every use issues a successor and retires the token
-- presented. All the successors of one sign-in share a family_id, which is
-- what makes theft detectable. A token that is presented twice can only mean
-- the value leaked, so the whole family is revoked rather than the single row.
--
-- Only a keyed digest of a token is ever stored. The plaintext exists once, in
-- the response that hands it to the client, and is never written down.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id             UUID PRIMARY KEY,
    user_id        UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash     TEXT        NOT NULL,
    family_id      UUID        NOT NULL,
    parent_id      UUID        REFERENCES refresh_tokens (id) ON DELETE SET NULL,
    issued_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at     TIMESTAMPTZ NOT NULL,
    used_at        TIMESTAMPTZ,
    revoked_at     TIMESTAMPTZ,
    revoked_reason TEXT,
    user_agent     TEXT,
    ip_address     TEXT,

    CONSTRAINT refresh_tokens_expiry_after_issue CHECK (expires_at > issued_at)
);

CREATE UNIQUE INDEX IF NOT EXISTS refresh_tokens_hash_unique ON refresh_tokens (token_hash);
CREATE INDEX IF NOT EXISTS refresh_tokens_user_idx   ON refresh_tokens (user_id, issued_at DESC);
CREATE INDEX IF NOT EXISTS refresh_tokens_family_idx ON refresh_tokens (family_id);

-- The session list and the rotation lookup both ask the same question: which
-- token of this family is still live? A partial index keeps that cheap while
-- the retired history grows.
CREATE INDEX IF NOT EXISTS refresh_tokens_live_idx
    ON refresh_tokens (user_id, expires_at)
    WHERE used_at IS NULL AND revoked_at IS NULL;

-- ---------------------------------------------------------------------------
-- Access tokens surrendered before they expired.
--
-- Access tokens are stateless and short lived, but signing out has to take
-- effect immediately rather than up to a token lifetime later. The gateway
-- checks this list on every call, so it holds only the token id and the point
-- at which the entry stops mattering.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS revoked_tokens (
    token_id   TEXT PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    revoked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS revoked_tokens_expires_idx ON revoked_tokens (expires_at);
CREATE INDEX IF NOT EXISTS revoked_tokens_user_idx    ON revoked_tokens (user_id);
