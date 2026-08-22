-- ---------------------------------------------------------------------------
-- Payroll runs, payslips and payslip lines.
--
-- One run per calendar month, one payslip per employee per run, and a line
-- for every component that contributed to it. Component names and types are
-- copied onto the line so a payslip issued years ago still reads correctly
-- after the component behind it has been renamed or retired.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payroll_runs (
    id                      UUID PRIMARY KEY,
    period                  TEXT        NOT NULL UNIQUE
                            CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    run_label               TEXT        NOT NULL,
    status                  TEXT        NOT NULL DEFAULT 'draft'
                            CHECK (status IN ('draft', 'processing', 'approved', 'paid', 'cancelled')),
    employee_count          INTEGER     NOT NULL DEFAULT 0 CHECK (employee_count >= 0),
    total_gross_minor       BIGINT      NOT NULL DEFAULT 0 CHECK (total_gross_minor >= 0),
    total_deductions_minor  BIGINT      NOT NULL DEFAULT 0 CHECK (total_deductions_minor >= 0),
    total_net_minor         BIGINT      NOT NULL DEFAULT 0,
    processed_by            UUID,
    processed_at            TIMESTAMPTZ,
    approved_by             UUID,
    approved_at             TIMESTAMPTZ,
    paid_at                 TIMESTAMPTZ,
    notes                   TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- Separation of duties: whoever ran the numbers may not also sign them off.
    CONSTRAINT payroll_runs_approver_differs
        CHECK (approved_by IS NULL OR processed_by IS NULL OR approved_by <> processed_by)
);

CREATE INDEX IF NOT EXISTS payroll_runs_period_idx ON payroll_runs (period DESC);
CREATE INDEX IF NOT EXISTS payroll_runs_status_idx ON payroll_runs (status, period DESC);

CREATE TABLE IF NOT EXISTS payslips (
    id                      UUID PRIMARY KEY,
    payroll_run_id          UUID         NOT NULL REFERENCES payroll_runs (id) ON DELETE CASCADE,
    employee_id             UUID         NOT NULL,
    period                  TEXT         NOT NULL
                            CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    payable_days            NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (payable_days >= 0),
    present_days            NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (present_days >= 0),
    leave_days              NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (leave_days >= 0),
    lop_days                NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (lop_days >= 0),
    gross_minor             BIGINT       NOT NULL DEFAULT 0 CHECK (gross_minor >= 0),
    total_deductions_minor  BIGINT       NOT NULL DEFAULT 0 CHECK (total_deductions_minor >= 0),
    net_minor               BIGINT       NOT NULL DEFAULT 0,
    tax_minor               BIGINT       NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    published_at            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT payslips_run_employee_unique UNIQUE (payroll_run_id, employee_id)
);

CREATE INDEX IF NOT EXISTS payslips_employee_idx  ON payslips (employee_id, period DESC);
CREATE INDEX IF NOT EXISTS payslips_period_idx    ON payslips (period);
CREATE INDEX IF NOT EXISTS payslips_published_idx ON payslips (employee_id, published_at DESC);

CREATE TABLE IF NOT EXISTS payslip_lines (
    id                UUID PRIMARY KEY,
    payslip_id        UUID        NOT NULL REFERENCES payslips (id) ON DELETE CASCADE,
    pay_component_id  UUID        NOT NULL REFERENCES pay_components (id),
    component_name    TEXT        NOT NULL,
    component_type    TEXT        NOT NULL
                      CHECK (component_type IN ('earning', 'deduction', 'employer_contribution')),
    amount_minor      BIGINT      NOT NULL DEFAULT 0 CHECK (amount_minor >= 0),
    display_order     INTEGER     NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS payslip_lines_payslip_idx ON payslip_lines (payslip_id, display_order);
CREATE INDEX IF NOT EXISTS payslip_lines_type_idx    ON payslip_lines (component_type);
