-- ---------------------------------------------------------------------------
-- The company holiday calendar.
--
-- location_id is a plain UUID with no foreign key: locations belong to the
-- employee service, whose schema this database role cannot see. A NULL value
-- means the holiday applies to every office.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS holidays (
    id           UUID PRIMARY KEY,
    name         TEXT        NOT NULL,
    holiday_date DATE        NOT NULL,
    holiday_type TEXT        NOT NULL DEFAULT 'public',
    location_id  UUID,
    description  TEXT,
    year         INTEGER     NOT NULL,
    is_active    BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT holidays_type_known CHECK (holiday_type IN ('public', 'restricted', 'company')),
    CONSTRAINT holidays_year_matches_date CHECK (year = EXTRACT(YEAR FROM holiday_date)::INTEGER)
);

-- A NULL location is a real value here ("everywhere"), so it is folded into a
-- sentinel to keep the uniqueness rule meaningful: PostgreSQL would otherwise
-- treat two company-wide entries for the same day as distinct.
CREATE UNIQUE INDEX IF NOT EXISTS holidays_day_scope_unique
    ON holidays (holiday_date, lower(name), COALESCE(location_id, '00000000-0000-0000-0000-000000000000'::UUID));

CREATE INDEX IF NOT EXISTS holidays_year_idx ON holidays (year, holiday_date);
CREATE INDEX IF NOT EXISTS holidays_date_idx ON holidays (holiday_date) WHERE is_active;
