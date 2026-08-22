-- ---------------------------------------------------------------------------
-- Uploaded paperwork. Only metadata lives here; the bytes are written under
-- STORAGE_PATH/uploads/documents, outside the web root, under a generated
-- filename that carries no information about the person it belongs to.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS employee_documents (
    id                 UUID PRIMARY KEY,
    employee_id        UUID        NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
    category           TEXT        NOT NULL,
    title              TEXT        NOT NULL,
    original_filename  TEXT        NOT NULL,
    stored_filename    TEXT        NOT NULL,
    mime_type          TEXT        NOT NULL,
    size_bytes         BIGINT      NOT NULL,
    checksum           TEXT        NOT NULL,
    issued_on          DATE,
    expires_on         DATE,
    status             TEXT        NOT NULL DEFAULT 'pending',
    verified_by        UUID,
    verified_at        TIMESTAMPTZ,
    uploaded_by        UUID,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT employee_documents_category CHECK (
        category IN ('identity', 'address', 'education', 'employment', 'contract',
                     'payroll', 'medical', 'photo', 'other')
    ),
    CONSTRAINT employee_documents_status CHECK (
        status IN ('pending', 'verified', 'rejected', 'expired')
    ),
    CONSTRAINT employee_documents_size CHECK (size_bytes >= 0),
    CONSTRAINT employee_documents_expiry_after_issue CHECK (
        expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on
    ),
    CONSTRAINT employee_documents_verification_consistent CHECK (
        (status IN ('verified', 'rejected')) = (verified_at IS NOT NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS employee_documents_stored_filename_key
    ON employee_documents (stored_filename);
CREATE INDEX IF NOT EXISTS employee_documents_employee_idx
    ON employee_documents (employee_id, created_at DESC);
CREATE INDEX IF NOT EXISTS employee_documents_expiry_idx
    ON employee_documents (expires_on) WHERE expires_on IS NOT NULL;
CREATE INDEX IF NOT EXISTS employee_documents_status_idx   ON employee_documents (status);
CREATE INDEX IF NOT EXISTS employee_documents_category_idx ON employee_documents (category);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t     ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE c.conname = 'employees_photo_document_fk'
          AND t.relname = 'employees'
          AND n.nspname = current_schema()
    ) THEN
        ALTER TABLE employees
            ADD CONSTRAINT employees_photo_document_fk
            FOREIGN KEY (photo_document_id) REFERENCES employee_documents (id) ON DELETE SET NULL;
    END IF;
END
$$;
