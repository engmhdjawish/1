-- Add "guests" audience for public notifications (visitors only).

DO $$ BEGIN
    ALTER TYPE notification_audience ADD VALUE IF NOT EXISTS 'guests';
EXCEPTION
    WHEN undefined_object THEN NULL;
END $$;

ALTER TABLE portal_notifications DROP CONSTRAINT IF EXISTS portal_notifications_audience_check;
ALTER TABLE portal_notifications
    ADD CONSTRAINT portal_notifications_audience_check
    CHECK (audience::text IN ('all', 'guests', 'customers', 'staff'));
