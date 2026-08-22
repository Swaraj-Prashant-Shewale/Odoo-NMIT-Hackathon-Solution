-- ---------------------------------------------------------------------------
-- Bank details and expense claims.
--
-- Account and tax identifiers are stored as authenticated ciphertext. A keyed
-- blind index sits beside the account number so a duplicate can be detected
-- without the column ever being readable, and only the last four digits are
-- kept in the clear so a person can recognise their own account on screen.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS bank_accounts (
    id                        UUID PRIMARY KEY,
    employee_id               UUID        NOT NULL UNIQUE,
    account_number_encrypted  TEXT        NOT NULL,
    account_number_blind      TEXT        NOT NULL UNIQUE,
    account_last4             TEXT        NOT NULL CHECK (account_last4 ~ '^[0-9]{4}$'),
    bank_name                 TEXT        NOT NULL,
    ifsc_code                 TEXT        NOT NULL CHECK (ifsc_code ~ '^[A-Z]{4}0[A-Z0-9]{6}$'),
    account_holder_name       TEXT        NOT NULL,
    tax_identifier_encrypted  TEXT,
    tax_identifier_last4      TEXT,
    verified_at               TIMESTAMPTZ,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS expense_claims (
    id                    UUID PRIMARY KEY,
    employee_id           UUID        NOT NULL,
    claim_number          TEXT        NOT NULL UNIQUE,
    category              TEXT        NOT NULL
                          CHECK (category IN ('travel', 'meals', 'accommodation', 'equipment',
                                              'software', 'training', 'communication',
                                              'client_entertainment', 'medical', 'other')),
    title                 TEXT        NOT NULL,
    description           TEXT,
    incurred_on           DATE        NOT NULL,
    amount_minor          BIGINT      NOT NULL CHECK (amount_minor > 0),
    currency              TEXT        NOT NULL DEFAULT 'INR' CHECK (char_length(currency) = 3),
    receipt_document_id   UUID,
    status                TEXT        NOT NULL DEFAULT 'submitted'
                          CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'reimbursed')),
    approver_id           UUID,
    decided_by            UUID,
    decided_at            TIMESTAMPTZ,
    decision_note         TEXT,
    reimbursed_at         TIMESTAMPTZ,
    reimbursed_reference  TEXT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT expense_claims_decision_recorded
        CHECK (status NOT IN ('approved', 'rejected') OR decided_at IS NOT NULL),
    CONSTRAINT expense_claims_reimbursement_recorded
        CHECK (status <> 'reimbursed' OR reimbursed_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS expense_claims_employee_idx ON expense_claims (employee_id, incurred_on DESC);
CREATE INDEX IF NOT EXISTS expense_claims_status_idx   ON expense_claims (status, created_at DESC);
CREATE INDEX IF NOT EXISTS expense_claims_approver_idx ON expense_claims (approver_id, status);

-- The claim number is a human-facing document reference printed on receipts,
-- not an addressable identifier: claims are still fetched by UUID primary key.
CREATE SEQUENCE IF NOT EXISTS expense_claim_number_seq AS BIGINT START WITH 1;
