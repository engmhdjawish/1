-- Price checker kiosk (in-store barcode display) settings.

CREATE TABLE IF NOT EXISTS price_checker_settings (
    id                      SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    enabled                 BOOLEAN NOT NULL DEFAULT TRUE,
    allowed_ips             TEXT NOT NULL DEFAULT '',
    page_title_ar           VARCHAR(200) NOT NULL DEFAULT 'فاحص الأسعار',
    display_seconds         INT NOT NULL DEFAULT 5 CHECK (display_seconds BETWEEN 2 AND 120),
    error_display_seconds   INT NOT NULL DEFAULT 5 CHECK (error_display_seconds BETWEEN 2 AND 60),
    slideshow_enabled       BOOLEAN NOT NULL DEFAULT TRUE,
    slideshow_count         INT NOT NULL DEFAULT 5 CHECK (slideshow_count BETWEEN 1 AND 20),
    slideshow_interval_ms   INT NOT NULL DEFAULT 20000 CHECK (slideshow_interval_ms BETWEEN 3000 AND 120000),
    slideshow_cache_seconds INT NOT NULL DEFAULT 300 CHECK (slideshow_cache_seconds BETWEEN 30 AND 3600),
    slideshow_show_price    BOOLEAN NOT NULL DEFAULT TRUE,
    slideshow_manufacturers TEXT NOT NULL DEFAULT '',
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id      UUID REFERENCES web_users (id)
);

INSERT INTO price_checker_settings (id)
VALUES (1)
ON CONFLICT (id) DO NOTHING;

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES (
    'price_checker.manage',
    'فاحص الأسعار في المحل',
    'محتوى',
    'إعداد شاشة مسح الباركود والإعلانات في المحل'
)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'price_checker.manage'
WHERE r.code IN ('super_admin', 'content')
ON CONFLICT DO NOTHING;
