-- ---------------------------------------------------------------------------
-- Analytics schema.
--
-- This service owns no business data. Every figure it publishes is assembled
-- over HTTP from the service that owns the underlying records, so the only
-- things stored here are derived: cached results, saved report definitions and
-- a trail of who ran or exported what.
-- ---------------------------------------------------------------------------

-- A single computed figure, pinned to a dimension and a period so a trend can
-- be rebuilt without recalling every owning service.
CREATE TABLE IF NOT EXISTS metric_snapshots (
    id               UUID PRIMARY KEY,
    metric_key       TEXT          NOT NULL,
    dimension_key    TEXT          NOT NULL DEFAULT 'overall',
    dimension_value  TEXT          NOT NULL DEFAULT 'all',
    period           TEXT          NOT NULL,
    value            NUMERIC(18,4) NOT NULL DEFAULT 0,
    captured_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    -- One row per figure per period: recomputing overwrites rather than
    -- appending, so a chart can never show the same month twice.
    CONSTRAINT metric_snapshots_identity_key
        UNIQUE (metric_key, dimension_key, dimension_value, period)
);

CREATE INDEX IF NOT EXISTS metric_snapshots_series_idx
    ON metric_snapshots (metric_key, dimension_key, period);

CREATE INDEX IF NOT EXISTS metric_snapshots_captured_idx
    ON metric_snapshots (captured_at DESC);

-- The report catalogue. required_permission is what decides whether a caller
-- ever sees a definition, so it is NOT NULL and checked on every run.
CREATE TABLE IF NOT EXISTS report_definitions (
    id                  UUID PRIMARY KEY,
    name                TEXT        NOT NULL,
    slug                TEXT        NOT NULL UNIQUE,
    description         TEXT        NOT NULL DEFAULT '',
    report_type         TEXT        NOT NULL DEFAULT 'people'
                        CHECK (report_type IN ('attendance','leave','people','payroll','expense','learning','document','performance')),
    default_filters     JSONB       NOT NULL DEFAULT '{}'::JSONB,
    required_permission TEXT        NOT NULL,
    is_active           BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS report_definitions_active_idx
    ON report_definitions (is_active, report_type, name);

-- Who ran or exported which report, with which filters, and how much data came
-- back. An export is a bulk disclosure of personal data, so it is recorded
-- whether or not anything downstream goes wrong.
CREATE TABLE IF NOT EXISTS report_runs (
    id                   UUID        PRIMARY KEY,
    report_definition_id UUID        NOT NULL REFERENCES report_definitions (id) ON DELETE CASCADE,
    -- The identity service owns accounts, so this is a plain UUID column with
    -- no foreign key: that table lives in a schema this role cannot see.
    run_by               UUID        NOT NULL,
    filters              JSONB       NOT NULL DEFAULT '{}'::JSONB,
    row_count            INTEGER     NOT NULL DEFAULT 0 CHECK (row_count >= 0),
    format               TEXT        NOT NULL DEFAULT 'json'
                         CHECK (format IN ('json','csv','pdf')),
    duration_ms          INTEGER     NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS report_runs_definition_idx
    ON report_runs (report_definition_id, created_at DESC);

CREATE INDEX IF NOT EXISTS report_runs_actor_idx
    ON report_runs (run_by, created_at DESC);

-- Short-lived assembled dashboards. cache_key is derived from the caller's
-- identity and effective permissions, never from the shape of the request, so
-- two people can never share an entry.
CREATE TABLE IF NOT EXISTS dashboard_cache (
    id         UUID        PRIMARY KEY,
    cache_key  TEXT        NOT NULL UNIQUE,
    scope_key  TEXT        NOT NULL,
    payload    JSONB       NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS dashboard_cache_expiry_idx
    ON dashboard_cache (expires_at);

CREATE INDEX IF NOT EXISTS dashboard_cache_scope_idx
    ON dashboard_cache (scope_key);
