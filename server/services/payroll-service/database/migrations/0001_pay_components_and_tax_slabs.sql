-- ---------------------------------------------------------------------------
-- Pay components and income tax slabs.
--
-- These two tables are reference data: they describe how a salary is broken
-- up and how tax is charged, and every payslip line traces back to one of
-- them. Amounts are BIGINT minor units (paise); percentages are NUMERIC(6,3)
-- so 12.5% is stored as 12.500.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pay_components (
    id              UUID PRIMARY KEY,
    name            TEXT        NOT NULL,
    code            TEXT        NOT NULL UNIQUE,
    component_type  TEXT        NOT NULL
                    CHECK (component_type IN ('earning', 'deduction', 'employer_contribution')),
    calculation     TEXT        NOT NULL
                    CHECK (calculation IN ('fixed', 'percent_of_basic', 'percent_of_ctc', 'slab')),
    percentage      NUMERIC(6,3),
    is_taxable      BOOLEAN     NOT NULL DEFAULT TRUE,
    is_statutory    BOOLEAN     NOT NULL DEFAULT FALSE,
    display_order   INTEGER     NOT NULL DEFAULT 0,
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT pay_components_percentage_range
        CHECK (percentage IS NULL OR (percentage >= 0 AND percentage <= 100)),

    -- A percentage-based component with no percentage would silently pay zero
    -- on every payslip, so the rate is required at the database level.
    CONSTRAINT pay_components_percentage_required
        CHECK (calculation NOT IN ('percent_of_basic', 'percent_of_ctc') OR percentage IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS pay_components_order_idx  ON pay_components (display_order, name);
CREATE INDEX IF NOT EXISTS pay_components_active_idx ON pay_components (is_active, display_order);

CREATE TABLE IF NOT EXISTS tax_slabs (
    id              UUID PRIMARY KEY,
    regime          TEXT         NOT NULL CHECK (regime IN ('new', 'old')),
    financial_year  TEXT         NOT NULL CHECK (financial_year ~ '^[0-9]{4}-[0-9]{2}$'),
    lower_minor     BIGINT       NOT NULL CHECK (lower_minor >= 0),
    -- A NULL ceiling marks the top band, which is unbounded above.
    upper_minor     BIGINT       CHECK (upper_minor IS NULL OR upper_minor > lower_minor),
    rate            NUMERIC(6,3) NOT NULL CHECK (rate >= 0 AND rate <= 100),
    surcharge       NUMERIC(6,3) NOT NULL DEFAULT 0 CHECK (surcharge >= 0 AND surcharge <= 100),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT tax_slabs_band_unique UNIQUE (regime, financial_year, lower_minor)
);

CREATE INDEX IF NOT EXISTS tax_slabs_year_idx ON tax_slabs (financial_year, regime, lower_minor);
