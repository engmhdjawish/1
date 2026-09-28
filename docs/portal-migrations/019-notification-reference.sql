-- Link staff alerts to orders/customers and index for fast resolution.

ALTER TABLE portal_notifications
    ADD COLUMN IF NOT EXISTS reference_type VARCHAR(32),
    ADD COLUMN IF NOT EXISTS reference_id VARCHAR(64);

CREATE INDEX IF NOT EXISTS ix_portal_notifications_reference
    ON portal_notifications (source, reference_type, reference_id)
    WHERE reference_id IS NOT NULL;
