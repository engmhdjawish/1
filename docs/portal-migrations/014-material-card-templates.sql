-- Configurable material card templates (photo + text/barcode slots)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_card_field_kind') THEN
        CREATE TYPE material_card_field_kind AS ENUM (
            'photo',
            'product_name',
            'packaging',
            'barcode'
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_card_text_align') THEN
        CREATE TYPE material_card_text_align AS ENUM ('right', 'center', 'left');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS material_card_templates (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name_ar                 VARCHAR(200) NOT NULL,
    file_name               VARCHAR(255) NOT NULL,
    storage_path            VARCHAR(1000) NOT NULL,
    mime_type               VARCHAR(100) NOT NULL DEFAULT 'image/png',
    file_size_bytes         INT NOT NULL DEFAULT 0 CHECK (file_size_bytes >= 0),
    canvas_width            INT NOT NULL CHECK (canvas_width > 0),
    canvas_height           INT NOT NULL CHECK (canvas_height > 0),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    is_default              BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_by_web_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_material_card_templates_default
    ON material_card_templates ((is_default))
    WHERE is_default = TRUE;

CREATE INDEX IF NOT EXISTS ix_material_card_templates_active
    ON material_card_templates (is_active, updated_at DESC);

CREATE TABLE IF NOT EXISTS material_card_template_fields (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    template_id     UUID NOT NULL REFERENCES material_card_templates (id) ON DELETE CASCADE,
    field_kind      material_card_field_kind NOT NULL,
    x               INT NOT NULL CHECK (x >= 0),
    y               INT NOT NULL CHECK (y >= 0),
    w               INT NOT NULL CHECK (w > 0),
    h               INT NOT NULL CHECK (h > 0),
    font_size       NUMERIC(6,2),
    color_hex       VARCHAR(7),
    align           material_card_text_align,
    z_index         SMALLINT NOT NULL DEFAULT 0,
    meta_json       JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (template_id, field_kind)
);

CREATE INDEX IF NOT EXISTS ix_material_card_template_fields_template
    ON material_card_template_fields (template_id);

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES (
    'images.templates.manage',
    'قوالب بطاقة صور المواد',
    'مواد',
    'رفع وضبط قوالب بطاقة المنتج وحقولها'
)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'images.templates.manage'
WHERE r.code IN ('super_admin', 'catalog_media')
ON CONFLICT DO NOTHING;
