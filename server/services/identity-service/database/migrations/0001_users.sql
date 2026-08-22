-- ---------------------------------------------------------------------------
-- Login accounts and the roles granted to them.
--
-- A user account is deliberately separate from the employee record it usually
-- points at: an account is created during hiring before the person record is
-- complete, and the person record outlives the account after someone leaves.
-- employee_id is therefore a plain UUID with no foreign key, because the
-- employee table lives in a schema this database role cannot see.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id                   UUID PRIMARY KEY,
    employee_id          UUID,
    employee_code        TEXT,
    email                TEXT        NOT NULL,
    password_hash        TEXT        NOT NULL,
    first_name           TEXT        NOT NULL,
    last_name            TEXT        NOT NULL,
    is_active            BOOLEAN     NOT NULL DEFAULT TRUE,
    email_verified_at    TIMESTAMPTZ,
    must_change_password BOOLEAN     NOT NULL DEFAULT FALSE,
    failed_login_count   INTEGER     NOT NULL DEFAULT 0,
    locked_until         TIMESTAMPTZ,
    last_login_at        TIMESTAMPTZ,
    last_login_ip        TEXT,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at           TIMESTAMPTZ,

    -- The citext extension is not available to a service role, so case
    -- insensitivity is achieved by storing the address folded and refusing
    -- anything else at the database level rather than trusting the caller.
    CONSTRAINT users_email_is_folded    CHECK (email = lower(email)),
    CONSTRAINT users_email_not_blank    CHECK (length(email) BETWEEN 3 AND 190),
    CONSTRAINT users_names_not_blank    CHECK (length(first_name) > 0 AND length(last_name) > 0),
    CONSTRAINT users_failed_count_sane  CHECK (failed_login_count >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique         ON users (email);
CREATE UNIQUE INDEX IF NOT EXISTS users_employee_id_unique   ON users (employee_id) WHERE employee_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS users_employee_code_unique ON users (upper(employee_code)) WHERE employee_code IS NOT NULL;

CREATE INDEX IF NOT EXISTS users_active_idx  ON users (is_active, created_at DESC);
CREATE INDEX IF NOT EXISTS users_name_idx    ON users (last_name, first_name);
CREATE INDEX IF NOT EXISTS users_locked_idx  ON users (locked_until) WHERE locked_until IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Role grants.
--
-- The catalogue of roles and the permissions behind them live in code, not in
-- a table, so that a permission check cannot be widened by editing a row. Only
-- the grant itself is data.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_roles (
    id         UUID PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role       TEXT        NOT NULL,
    granted_by UUID        REFERENCES users (id) ON DELETE SET NULL,
    granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT user_roles_known_role CHECK (
        role IN ('super_admin', 'hr_admin', 'hr_officer', 'finance', 'manager', 'employee')
    ),
    CONSTRAINT user_roles_one_per_user UNIQUE (user_id, role)
);

CREATE INDEX IF NOT EXISTS user_roles_user_idx ON user_roles (user_id);
CREATE INDEX IF NOT EXISTS user_roles_role_idx ON user_roles (role);
