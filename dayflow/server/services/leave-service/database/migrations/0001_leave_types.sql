-- Leave types: the catalogue of things a person can be away for.
--
-- Every rule that decides whether a request is allowed lives here as a column
-- rather than in application code, so HR can change policy without a deploy.

CREATE TABLE IF NOT EXISTS leave_types (
    id                            UUID PRIMARY KEY,
    name                          TEXT         NOT NULL,
    code                          TEXT         NOT NULL,
    category                      TEXT         NOT NULL
                                  CHECK (category IN ('paid','sick','unpaid','casual','maternity','paternity','comp_off','bereavement')),
    -- Rendered as the block colour on the team leave calendar.
    colour                        TEXT         NOT NULL DEFAULT '#64748B'
                                  CHECK (colour ~ '^#[0-9A-Fa-f]{6}$'),
    annual_quota_days             NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (annual_quota_days >= 0),
    accrual_frequency             TEXT         NOT NULL DEFAULT 'none'
                                  CHECK (accrual_frequency IN ('none','monthly','quarterly','yearly')),
    accrual_days                  NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (accrual_days >= 0),
    max_carry_forward_days        NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (max_carry_forward_days >= 0),
    allows_half_day               BOOLEAN      NOT NULL DEFAULT TRUE,
    -- NULL means proof is never required, whatever the length of the absence.
    requires_document_after_days  INTEGER      CHECK (requires_document_after_days IS NULL OR requires_document_after_days >= 0),
    min_notice_days               INTEGER      NOT NULL DEFAULT 0 CHECK (min_notice_days >= 0),
    -- Counted in leave days actually charged, not calendar days: a weekend
    -- sitting inside a range is not a day the employee is charged for.
    max_consecutive_days          INTEGER      CHECK (max_consecutive_days IS NULL OR max_consecutive_days > 0),
    is_paid                       BOOLEAN      NOT NULL DEFAULT TRUE,
    applies_to_gender             TEXT         NOT NULL DEFAULT 'any'
                                  CHECK (applies_to_gender IN ('any','female','male')),
    is_active                     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at                    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at                    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT leave_types_accrual_consistent
        CHECK (accrual_frequency = 'none' OR accrual_days > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS leave_types_code_key   ON leave_types (code);
CREATE UNIQUE INDEX IF NOT EXISTS leave_types_name_key   ON leave_types (name);
CREATE INDEX        IF NOT EXISTS leave_types_active_idx ON leave_types (is_active, name);
