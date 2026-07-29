-- Link store orders to anonymous visitor sessions (jawish_vid) for visitor log identity
-- Safe to re-run

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS visitor_session_id VARCHAR(120);

CREATE INDEX IF NOT EXISTS ix_orders_visitor_session
    ON orders (visitor_session_id, created_at DESC)
    WHERE visitor_session_id IS NOT NULL AND visitor_session_id <> '';

CREATE INDEX IF NOT EXISTS ix_visitor_logs_session
    ON visitor_logs (session_id, created_at DESC);

CREATE INDEX IF NOT EXISTS ix_visitor_logs_customer
    ON visitor_logs (web_customer_id, created_at DESC)
    WHERE web_customer_id IS NOT NULL;
