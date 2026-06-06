-- Local material image paths (company_settings) + guid→filename index for fast serving

INSERT INTO company_settings (key, value_ar)
VALUES
    ('material_images_dir', ''),
    ('material_thumbnails_dir', '')
ON CONFLICT (key) DO NOTHING;

CREATE TABLE IF NOT EXISTS material_image_index (
    image_guid              UUID PRIMARY KEY,
    file_name               TEXT NOT NULL,
    api_updated_at          TIMESTAMPTZ NULL,
    indexed_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_material_image_index_file_name
    ON material_image_index (file_name);
