-- Procurement: material policies, factory purchase drafts, and staff permission.

CREATE TABLE IF NOT EXISTS procurement_settings (
    id                      SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    store_guids             JSONB NOT NULL DEFAULT '[]'::jsonb,
    limit_to_current_season BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id      UUID REFERENCES web_users (id) ON DELETE SET NULL
);

INSERT INTO procurement_settings (id)
VALUES (1)
ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS procurement_materials (
    material_guid           UUID PRIMARY KEY,
    material_code           VARCHAR(80) NOT NULL DEFAULT '',
    material_name_ar        VARCHAR(500) NOT NULL DEFAULT '',
    manufacturer            VARCHAR(200) NOT NULL DEFAULT '',
    group_guid              UUID,
    lifecycle               VARCHAR(20) NOT NULL DEFAULT 'trial'
        CHECK (lifecycle IN ('trial', 'season_active', 'discontinued')),
    min_cartons             NUMERIC(12, 3),
    max_cartons             NUMERIC(12, 3),
    first_seen_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    configured_at           TIMESTAMPTZ,
    configured_by_user_id   UUID REFERENCES web_users (id) ON DELETE SET NULL,
    notes_ar                TEXT NOT NULL DEFAULT '',
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_procurement_materials_lifecycle
    ON procurement_materials (lifecycle);

CREATE INDEX IF NOT EXISTS idx_procurement_materials_configured
    ON procurement_materials (configured_at NULLS FIRST, first_seen_at DESC);

CREATE TABLE IF NOT EXISTS procurement_sync_runs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at     TIMESTAMPTZ,
    materials_seen  INT NOT NULL DEFAULT 0,
    materials_new   INT NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'running'
        CHECK (status IN ('running', 'ok', 'failed')),
    error_ar        TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS procurement_orders (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    manufacturer        VARCHAR(200) NOT NULL,
    status              VARCHAR(24) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'sent', 'partially_received', 'closed', 'cancelled')),
    season_id           UUID REFERENCES store_seasons (id) ON DELETE SET NULL,
    notes_ar            TEXT NOT NULL DEFAULT '',
    sent_at             TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by_user_id  UUID REFERENCES web_users (id) ON DELETE SET NULL,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_procurement_orders_manufacturer
    ON procurement_orders (manufacturer, created_at DESC);

CREATE TABLE IF NOT EXISTS procurement_order_lines (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id            UUID NOT NULL REFERENCES procurement_orders (id) ON DELETE CASCADE,
    material_guid       UUID NOT NULL,
    material_code       VARCHAR(80) NOT NULL DEFAULT '',
    material_name_ar    VARCHAR(500) NOT NULL DEFAULT '',
    suggested_cartons   NUMERIC(12, 3) NOT NULL DEFAULT 0,
    approved_cartons    NUMERIC(12, 3) NOT NULL DEFAULT 0,
    available_cartons   NUMERIC(12, 3) NOT NULL DEFAULT 0,
    line_status         VARCHAR(16) NOT NULL DEFAULT 'open'
        CHECK (line_status IN ('open', 'closed', 'cancelled')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_procurement_order_lines_material
    ON procurement_order_lines (material_guid);

CREATE UNIQUE INDEX IF NOT EXISTS uq_procurement_open_line_per_material
    ON procurement_order_lines (material_guid)
    WHERE line_status = 'open';

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES (
    'procurement.manage',
    'المشتريات',
    'مشتريات',
    'اكتشاف مواد الأمين، سياسات إعادة الطلب، ومسودات طلبات المصانع'
)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'procurement.manage'
WHERE r.code = 'super_admin'
ON CONFLICT DO NOTHING;

INSERT INTO web_roles (code, name_ar, description_ar, is_system)
VALUES ('procurement', 'المشتريات', 'نواقص المخزون وطلبات المصانع', TRUE)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    description_ar = EXCLUDED.description_ar,
    is_system = TRUE;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN ('dashboard.view', 'procurement.manage', 'store.view_stock')
WHERE r.code = 'procurement'
ON CONFLICT DO NOTHING;
