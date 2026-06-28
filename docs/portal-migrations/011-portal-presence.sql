-- Lightweight online presence for guests and logged-in users (heartbeat upsert).

CREATE TABLE IF NOT EXISTS portal_presence (
    presence_key    VARCHAR(128) PRIMARY KEY,
    kind            VARCHAR(20) NOT NULL DEFAULT 'guest',
    subject_id      UUID,
    label_ar        VARCHAR(250),
    visitor_ip      VARCHAR(45),
    country_ar      VARCHAR(120),
    city_ar         VARCHAR(120),
    user_agent      VARCHAR(500),
    last_seen_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_portal_presence_last_seen
    ON portal_presence (last_seen_at DESC);

CREATE INDEX IF NOT EXISTS ix_portal_presence_kind_seen
    ON portal_presence (kind, last_seen_at DESC);
