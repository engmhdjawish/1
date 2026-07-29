CREATE TABLE IF NOT EXISTS material_image_zip_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    requested_by_web_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL,
    mode VARCHAR(32) NOT NULL,
    params JSONB NOT NULL DEFAULT '{}'::jsonb,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    progress_pct SMALLINT NOT NULL DEFAULT 0 CHECK (progress_pct >= 0 AND progress_pct <= 100),
    progress_message VARCHAR(500),
    file_path VARCHAR(1000),
    file_name VARCHAR(255),
    image_count INT,
    error_message VARCHAR(500),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    downloaded_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS ix_material_image_zip_jobs_status
    ON material_image_zip_jobs (status, created_at);

CREATE INDEX IF NOT EXISTS ix_material_image_zip_jobs_user
    ON material_image_zip_jobs (requested_by_web_user_id, created_at DESC);
