-- ---------------------------------------------------------------------------
-- The competency framework a review is scored against, and the review cycles
-- that appraisals are grouped into.
--
-- applies_to_designation_id narrows a competency to one job title. It is left
-- NULL for the company-wide framework, which is the usual case.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS competencies (
    id                        UUID PRIMARY KEY,
    name                      TEXT        NOT NULL,
    description               TEXT,
    category                  TEXT        NOT NULL DEFAULT 'core',
    applies_to_designation_id UUID,
    display_order             INTEGER     NOT NULL DEFAULT 0,
    is_active                 BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS competencies_name_idx
    ON competencies (lower(name));

CREATE INDEX IF NOT EXISTS competencies_active_order_idx
    ON competencies (is_active, display_order, name);

CREATE TABLE IF NOT EXISTS review_cycles (
    id                     UUID PRIMARY KEY,
    name                   TEXT        NOT NULL,
    period_start           DATE        NOT NULL,
    period_end             DATE        NOT NULL,
    self_review_due_on     DATE,
    manager_review_due_on  DATE,
    status                 TEXT        NOT NULL DEFAULT 'draft'
                           CHECK (status IN ('draft', 'open', 'in_review', 'closed')),
    rating_scale_max       INTEGER     NOT NULL DEFAULT 5
                           CHECK (rating_scale_max BETWEEN 2 AND 10),
    instructions           TEXT,
    created_by             UUID,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT review_cycle_period_ordered CHECK (period_end >= period_start)
);

CREATE INDEX IF NOT EXISTS review_cycles_status_idx
    ON review_cycles (status, period_start DESC);

CREATE UNIQUE INDEX IF NOT EXISTS review_cycles_name_period_idx
    ON review_cycles (lower(name), period_start);
