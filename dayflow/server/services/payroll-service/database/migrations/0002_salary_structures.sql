-- ---------------------------------------------------------------------------
-- Salary structures and their component lines.
--
-- A structure is never edited in place. A revision closes the previous row by
-- setting effective_to, so the history of what somebody was paid, and when,
-- survives intact and a payroll run from two years ago can still be explained.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS salary_structures (
    id                   UUID PRIMARY KEY,
    employee_id          UUID        NOT NULL,
    effective_from       DATE        NOT NULL,
    effective_to         DATE,
    ctc_annual_minor     BIGINT      NOT NULL CHECK (ctc_annual_minor >= 0),
    gross_monthly_minor  BIGINT      NOT NULL CHECK (gross_monthly_minor >= 0),
    basic_monthly_minor  BIGINT      NOT NULL CHECK (basic_monthly_minor >= 0),
    currency             TEXT        NOT NULL DEFAULT 'INR' CHECK (char_length(currency) = 3),
    revision_reason      TEXT,
    approved_by          UUID,
    approved_at          TIMESTAMPTZ,
    created_by           UUID,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT salary_structures_period_ordered
        CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT salary_structures_basic_within_gross
        CHECK (basic_monthly_minor <= gross_monthly_minor)
);

CREATE UNIQUE INDEX IF NOT EXISTS salary_structures_from_uidx
    ON salary_structures (employee_id, effective_from);

-- At most one open-ended structure per person. Two would make "what are they
-- paid today?" ambiguous, and a payroll run would pick between them at random.
CREATE UNIQUE INDEX IF NOT EXISTS salary_structures_open_uidx
    ON salary_structures (employee_id) WHERE effective_to IS NULL;

CREATE INDEX IF NOT EXISTS salary_structures_employee_idx
    ON salary_structures (employee_id, effective_from DESC);

CREATE TABLE IF NOT EXISTS salary_structure_lines (
    id                    UUID PRIMARY KEY,
    salary_structure_id   UUID         NOT NULL REFERENCES salary_structures (id) ON DELETE CASCADE,
    pay_component_id      UUID         NOT NULL REFERENCES pay_components (id),
    amount_monthly_minor  BIGINT       NOT NULL DEFAULT 0 CHECK (amount_monthly_minor >= 0),
    percentage            NUMERIC(6,3) CHECK (percentage IS NULL OR (percentage >= 0 AND percentage <= 100)),
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT salary_structure_lines_unique UNIQUE (salary_structure_id, pay_component_id)
);

CREATE INDEX IF NOT EXISTS salary_structure_lines_structure_idx
    ON salary_structure_lines (salary_structure_id);
