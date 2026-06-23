-- Material image sync queue: local upload first, then push to Amine one-by-one.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_image_sync_status') THEN
        CREATE TYPE material_image_sync_status AS ENUM ('pending', 'syncing', 'synced', 'failed');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS material_image_sync_queue (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    file_name               VARCHAR(255) NOT NULL,
    local_file_path         VARCHAR(1000) NOT NULL,
    local_thumb_path        VARCHAR(1000),
    amine_image_guid        UUID,
    sync_status             material_image_sync_status NOT NULL DEFAULT 'pending',
    amine_sync_error_ar     VARCHAR(500),
    synced_to_amine_at      TIMESTAMPTZ,
    uploaded_by_web_user_id UUID REFERENCES web_users (id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_material_image_sync_file
    ON material_image_sync_queue (file_name);

CREATE INDEX IF NOT EXISTS ix_material_image_sync_status
    ON material_image_sync_queue (sync_status, created_at);
