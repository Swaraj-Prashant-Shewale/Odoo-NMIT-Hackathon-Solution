-- How a leave type applies to a particular kind of employment.
--
-- An intern and a permanent engineer share the leave type but not the
-- entitlement, so the quota override and the qualifying period are held here
-- and dated, which keeps last year's accruals reproducible after a change.

CREATE TABLE IF NOT EXISTS leave_policies (
    id                   UUID PRIMARY KEY,
    leave_type_id        UUID         NOT NULL REFERENCES leave_types (id) ON DELETE CASCADE,
    employment_type      TEXT         NOT NULL
                         CHECK (employment_type IN ('full_time','part_time','contract','intern','consultant')),
    applies_after_months INTEGER      NOT NULL DEFAULT 0 CHECK (applies_after_months >= 0),
    -- NULL falls back to the leave type's own annual quota.
    quota_override_days  NUMERIC(5,2) CHECK (quota_override_days IS NULL OR quota_override_days >= 0),
    effective_from       DATE         NOT NULL,
    effective_to         DATE,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT leave_policies_dates_ordered
        CHECK (effective_to IS NULL OR effective_to >= effective_from)
);

CREATE UNIQUE INDEX IF NOT EXISTS leave_policies_unique_window
    ON leave_policies (leave_type_id, employment_type, effective_from);

CREATE INDEX IF NOT EXISTS leave_policies_lookup_idx
    ON leave_policies (employment_type, leave_type_id, effective_from DESC);
