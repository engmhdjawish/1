-- Web Push subscriptions for device notifications (PWA / mobile browser).

CREATE TABLE IF NOT EXISTS portal_push_subscriptions (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    reader_type VARCHAR(16) NOT NULL CHECK (reader_type IN ('guest', 'customer', 'staff')),
    reader_id   VARCHAR(64) NOT NULL,
    endpoint    TEXT NOT NULL,
    p256dh      TEXT NOT NULL,
    auth        TEXT NOT NULL,
    user_agent  VARCHAR(500),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (endpoint)
);

CREATE INDEX IF NOT EXISTS ix_portal_push_subscriptions_reader
    ON portal_push_subscriptions (reader_type, reader_id);
