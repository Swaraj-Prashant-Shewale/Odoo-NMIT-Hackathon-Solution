-- ---------------------------------------------------------------------------
-- Company property issued to staff. Assignment is held on the row itself
-- rather than in a history table because an asset has exactly one holder at a
-- time and the offboarding checklist only ever asks whether it is back yet.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS company_assets (
    id             UUID PRIMARY KEY,
    asset_tag      TEXT        NOT NULL,
    category       TEXT        NOT NULL,
    name           TEXT        NOT NULL,
    serial_number  TEXT,
    purchased_on   DATE,
    value_minor    BIGINT      NOT NULL DEFAULT 0,
    "condition"    TEXT        NOT NULL DEFAULT 'good',
    assigned_to    UUID        REFERENCES employees (id) ON DELETE SET NULL,
    assigned_on    DATE,
    returned_on    DATE,
    status         TEXT        NOT NULL DEFAULT 'available',
    notes          TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- Tag, name and serial number folded into one column so the inventory
    -- search is a single bound comparison rather than three OR-ed ones.
    search_text    TEXT GENERATED ALWAYS AS (
        LOWER(
            COALESCE(asset_tag, '') || ' ' || COALESCE(name, '') || ' ' ||
            COALESCE(serial_number, '')
        )
    ) STORED,

    CONSTRAINT company_assets_category CHECK (
        category IN ('laptop', 'desktop', 'monitor', 'phone', 'tablet', 'peripheral',
                     'furniture', 'access_card', 'software_licence', 'vehicle', 'other')
    ),
    CONSTRAINT company_assets_condition CHECK (
        "condition" IN ('new', 'good', 'fair', 'poor', 'damaged')
    ),
    CONSTRAINT company_assets_status CHECK (
        status IN ('available', 'assigned', 'in_repair', 'retired', 'lost')
    ),
    CONSTRAINT company_assets_value_positive CHECK (value_minor >= 0),
    CONSTRAINT company_assets_assignment_consistent CHECK (
        status <> 'assigned' OR (assigned_to IS NOT NULL AND assigned_on IS NOT NULL)
    ),
    CONSTRAINT company_assets_return_after_assign CHECK (
        returned_on IS NULL OR assigned_on IS NULL OR returned_on >= assigned_on
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS company_assets_tag_key ON company_assets (UPPER(asset_tag));
CREATE INDEX IF NOT EXISTS company_assets_assigned_idx ON company_assets (assigned_to) WHERE assigned_to IS NOT NULL;
CREATE INDEX IF NOT EXISTS company_assets_status_idx   ON company_assets (status);
CREATE INDEX IF NOT EXISTS company_assets_category_idx ON company_assets (category);
