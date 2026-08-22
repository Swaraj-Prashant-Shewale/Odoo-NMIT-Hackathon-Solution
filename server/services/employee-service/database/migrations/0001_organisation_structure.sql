-- ---------------------------------------------------------------------------
-- Organisation structure: departments, designations, locations and the
-- checklist templates that new joiners and leavers are measured against.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS departments (
    id                UUID PRIMARY KEY,
    name              TEXT        NOT NULL,
    code              TEXT        NOT NULL,
    description       TEXT,
    parent_id         UUID        REFERENCES departments (id) ON DELETE SET NULL,
    head_employee_id  UUID,
    cost_centre       TEXT,
    is_active         BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT departments_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)
);

-- Codes and names are compared case-insensitively so "ENG" and "eng" cannot
-- both exist and confuse a reader of the org chart.
CREATE UNIQUE INDEX IF NOT EXISTS departments_code_key   ON departments (LOWER(code));
CREATE UNIQUE INDEX IF NOT EXISTS departments_name_key   ON departments (LOWER(name));
CREATE INDEX        IF NOT EXISTS departments_parent_idx ON departments (parent_id);
CREATE INDEX        IF NOT EXISTS departments_active_idx ON departments (is_active);

CREATE TABLE IF NOT EXISTS designations (
    id             UUID PRIMARY KEY,
    title          TEXT        NOT NULL,
    code           TEXT        NOT NULL,
    level          INTEGER     NOT NULL DEFAULT 1,
    department_id  UUID        REFERENCES departments (id) ON DELETE SET NULL,
    description    TEXT,
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT designations_level_range CHECK (level BETWEEN 1 AND 20)
);

CREATE UNIQUE INDEX IF NOT EXISTS designations_code_key       ON designations (LOWER(code));
CREATE UNIQUE INDEX IF NOT EXISTS designations_title_key      ON designations (LOWER(title));
CREATE INDEX        IF NOT EXISTS designations_department_idx ON designations (department_id);
CREATE INDEX        IF NOT EXISTS designations_level_idx      ON designations (level DESC);

CREATE TABLE IF NOT EXISTS locations (
    id             UUID PRIMARY KEY,
    name           TEXT        NOT NULL,
    address_line1  TEXT,
    address_line2  TEXT,
    city           TEXT,
    state          TEXT,
    country        TEXT        NOT NULL DEFAULT 'India',
    postal_code    TEXT,
    timezone       TEXT        NOT NULL DEFAULT 'Asia/Kolkata',
    is_remote      BOOLEAN     NOT NULL DEFAULT FALSE,
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS locations_name_key   ON locations (LOWER(name));
CREATE INDEX        IF NOT EXISTS locations_active_idx ON locations (is_active);

-- The checklist a joiner or leaver is given is reference data rather than code,
-- so HR can extend it without a deployment and every employee created after the
-- change picks the new step up automatically.
CREATE TABLE IF NOT EXISTS checklist_templates (
    id               UUID PRIMARY KEY,
    kind             TEXT        NOT NULL,
    title            TEXT        NOT NULL,
    description      TEXT,
    category         TEXT        NOT NULL DEFAULT 'general',
    sequence         INTEGER     NOT NULL DEFAULT 0,
    owner_role       TEXT        NOT NULL DEFAULT 'hr_officer',
    due_offset_days  INTEGER     NOT NULL DEFAULT 0,
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT checklist_templates_kind CHECK (kind IN ('onboarding', 'offboarding')),
    CONSTRAINT checklist_templates_owner_role CHECK (
        owner_role IN ('super_admin', 'hr_admin', 'hr_officer', 'manager', 'finance', 'employee')
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS checklist_templates_kind_title_key
    ON checklist_templates (kind, LOWER(title));
CREATE INDEX IF NOT EXISTS checklist_templates_kind_idx
    ON checklist_templates (kind, sequence);
