-- Persist per-reader dismissal separately from the read timestamp.

ALTER TABLE portal_notification_reads
    ADD COLUMN IF NOT EXISTS dismissed_at TIMESTAMPTZ;
