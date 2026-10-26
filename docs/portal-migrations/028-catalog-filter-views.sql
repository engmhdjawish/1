-- Durable short codes for filtered catalog / share browse URLs.
CREATE TABLE IF NOT EXISTS catalog_filter_views (
    short_code   VARCHAR(16) PRIMARY KEY,
    payload_json JSONB NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_catalog_filter_views_payload_hash
    ON catalog_filter_views (payload_hash);

CREATE INDEX IF NOT EXISTS ix_catalog_filter_views_last_used
    ON catalog_filter_views (last_used_at DESC);
