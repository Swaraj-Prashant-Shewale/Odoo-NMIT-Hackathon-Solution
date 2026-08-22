-- ---------------------------------------------------------------------------
-- Company announcements and their per-person read receipts.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS announcements (
    id                   UUID PRIMARY KEY,
    title                TEXT        NOT NULL,
    body                 TEXT        NOT NULL,
    category             TEXT        NOT NULL DEFAULT 'general',
    severity             TEXT        NOT NULL DEFAULT 'info'
                         CHECK (severity IN ('info', 'success', 'warning', 'critical')),
    published_by         UUID        NOT NULL,
    published_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_on           DATE,
    pinned               BOOLEAN     NOT NULL DEFAULT FALSE,
    -- Targeting columns are plain UUID / TEXT with no foreign key: departments
    -- live in the employee schema, which this database role cannot see.
    target_department_id UUID,
    target_role          TEXT
                         CHECK (target_role IS NULL OR target_role IN
                             ('super_admin', 'hr_admin', 'hr_officer', 'finance', 'manager', 'employee')),
    is_active            BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS announcements_feed_idx
    ON announcements (is_active, pinned DESC, published_at DESC);

CREATE INDEX IF NOT EXISTS announcements_department_idx
    ON announcements (target_department_id);

CREATE INDEX IF NOT EXISTS announcements_expiry_idx
    ON announcements (expires_on);

CREATE TABLE IF NOT EXISTS announcement_reads (
    id              UUID PRIMARY KEY,
    announcement_id UUID        NOT NULL REFERENCES announcements (id) ON DELETE CASCADE,
    employee_id     UUID        NOT NULL,
    read_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT announcement_reads_unique UNIQUE (announcement_id, employee_id)
);

CREATE INDEX IF NOT EXISTS announcement_reads_employee_idx
    ON announcement_reads (employee_id);
