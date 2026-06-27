-- Track live portal sessions (staff + customers) for online presence and remote logout.

ALTER TABLE web_sessions
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMPTZ;

ALTER TABLE web_customer_sessions
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMPTZ;

UPDATE web_sessions SET last_seen_at = COALESCE(last_seen_at, created_at) WHERE last_seen_at IS NULL;
UPDATE web_customer_sessions SET last_seen_at = COALESCE(last_seen_at, created_at) WHERE last_seen_at IS NULL;

CREATE INDEX IF NOT EXISTS ix_web_sessions_online
    ON web_sessions (last_seen_at DESC)
    WHERE revoked_at IS NULL;

CREATE INDEX IF NOT EXISTS ix_web_customer_sessions_online
    ON web_customer_sessions (last_seen_at DESC)
    WHERE revoked_at IS NULL;
