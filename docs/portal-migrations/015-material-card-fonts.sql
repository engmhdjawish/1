-- Fonts for material card templates + per-field font selection
CREATE TABLE IF NOT EXISTS material_card_fonts (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name_ar                 VARCHAR(200) NOT NULL,
    file_name               VARCHAR(255) NOT NULL,
    storage_path            VARCHAR(1000) NOT NULL,
    file_size_bytes         INT NOT NULL DEFAULT 0 CHECK (file_size_bytes >= 0),
    uploaded_by_web_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_material_card_fonts_created
    ON material_card_fonts (created_at DESC);

ALTER TABLE material_card_template_fields
    ADD COLUMN IF NOT EXISTS font_file VARCHAR(255);

COMMENT ON COLUMN material_card_template_fields.font_file IS
    'Relative storage path under PORTAL_STORAGE_PATH, e.g. fonts/custom/<id>.ttf';
