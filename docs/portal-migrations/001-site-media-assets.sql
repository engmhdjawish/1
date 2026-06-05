-- Add site media library for banners, ads, logos (run on existing portal_db)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'site_media_category') THEN
        CREATE TYPE site_media_category AS ENUM ('banner', 'ad', 'logo', 'other');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS site_media_assets (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title_ar                VARCHAR(200),
    category                site_media_category NOT NULL DEFAULT 'banner',
    file_name               VARCHAR(255) NOT NULL,
    storage_path            VARCHAR(1000) NOT NULL,
    mime_type               VARCHAR(100) NOT NULL,
    file_size_bytes         INT NOT NULL DEFAULT 0 CHECK (file_size_bytes >= 0),
    uploaded_by_web_user_id UUID REFERENCES web_users (id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_site_media_category ON site_media_assets (category, created_at DESC);

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES ('site_media.manage', 'مكتبة صور الموقع', 'محتوى', 'رفع وإدارة بنرات وإعلانات وشعارات الموقع')
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'site_media.manage'
WHERE r.code IN ('super_admin', 'content')
ON CONFLICT DO NOTHING;
