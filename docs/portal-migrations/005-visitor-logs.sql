-- Visitor analytics (run on existing portal_db — safe to re-run)
-- Use this instead of re-running the full docs/portal-db-schema.sql

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS visitor_logs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    share_link_id   UUID REFERENCES share_links (id) ON DELETE SET NULL,
    web_customer_id UUID REFERENCES web_customers (id) ON DELETE SET NULL,
    session_id      VARCHAR(120) NOT NULL,
    action          VARCHAR(80) NOT NULL,
    visitor_ip      VARCHAR(45),
    country_ar      VARCHAR(100),
    city_ar         VARCHAR(100),
    latitude        NUMERIC(10, 7),
    longitude       NUMERIC(10, 7),
    user_agent      VARCHAR(500),
    referer         VARCHAR(1000),
    details_ar      VARCHAR(2000),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_visitor_logs_created ON visitor_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS ix_visitor_logs_link ON visitor_logs (share_link_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_visitor_logs_action ON visitor_logs (action, created_at DESC);
