-- ---------------------------------------------------------------------------
-- Records the day a document's expiry reminder was announced.
--
-- The expiring-documents endpoint is what publishes employee.document.expiring,
-- and the notification service turns every one of those into an in-app message
-- and an email. Without a marker, an HR screen that is opened five times in an
-- afternoon reminds the same person five times. One reminder per document per
-- day is what a nightly job would produce, and the column makes the endpoint
-- behave that way no matter how often it is called.
-- ---------------------------------------------------------------------------

ALTER TABLE employee_documents
    ADD COLUMN IF NOT EXISTS expiry_notified_on DATE;

CREATE INDEX IF NOT EXISTS employee_documents_expiry_notified_idx
    ON employee_documents (expiry_notified_on) WHERE expires_on IS NOT NULL;
