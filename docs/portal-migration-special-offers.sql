-- Special offers (website-only pricing overlay) + store quantity limits
-- Run on portal PostgreSQL after portal-db-schema.sql

CREATE TYPE special_offer_discount_type AS ENUM ('percent', 'fixed_price');
CREATE TYPE special_offer_selection_mode AS ENUM ('manual', 'filter');

CREATE TABLE special_offers (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug                VARCHAR(80) NOT NULL UNIQUE,
    title_ar            VARCHAR(200) NOT NULL,
    subtitle_ar         VARCHAR(500),
    badge_text_ar       VARCHAR(80),
    banner_image_url    VARCHAR(1000),
    selection_mode      special_offer_selection_mode NOT NULL DEFAULT 'filter',
    discount_type       special_offer_discount_type NOT NULL DEFAULT 'percent',
    discount_percent    NUMERIC(6, 2),
    fixed_price_syp     NUMERIC(18, 4),
    fixed_price_usd     NUMERIC(18, 4),
    starts_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ends_at             TIMESTAMPTZ,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    priority            INT NOT NULL DEFAULT 0,
    min_packages        NUMERIC(18, 4),
    max_packages        NUMERIC(18, 4),
    max_products        INT NOT NULL DEFAULT 12 CHECK (max_products BETWEEN 1 AND 48),
    show_on_home        BOOLEAN NOT NULL DEFAULT TRUE,
    home_sort_order     INT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_web_user_id UUID REFERENCES web_users (id)
);

CREATE INDEX ix_special_offers_active_window
    ON special_offers (is_active, starts_at, ends_at)
    WHERE is_active = TRUE;

CREATE TABLE special_offer_filters (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    offer_id        UUID NOT NULL REFERENCES special_offers (id) ON DELETE CASCADE,
    filter_type     VARCHAR(50) NOT NULL,
    value_ar        VARCHAR(500) NOT NULL,
    CONSTRAINT uq_special_offer_filter UNIQUE (offer_id, filter_type, value_ar)
);

CREATE TABLE special_offer_products (
    offer_id        UUID NOT NULL REFERENCES special_offers (id) ON DELETE CASCADE,
    material_guid   UUID NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    PRIMARY KEY (offer_id, material_guid)
);

ALTER TABLE store_guest_settings
    ADD COLUMN IF NOT EXISTS max_packages_per_material NUMERIC(18, 4);

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS special_offer_id UUID REFERENCES special_offers (id),
    ADD COLUMN IF NOT EXISTS original_sale_price_sp NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS original_sale_price_usd NUMERIC(18, 4);

CREATE TRIGGER tr_special_offers_updated
    BEFORE UPDATE ON special_offers
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Permission (run portal-db-seed updates or insert manually)
INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES ('special_offers.manage', 'إدارة العروض الخاصة', 'محتوى', 'عروض الموقع والحسومات')
ON CONFLICT (code) DO UPDATE SET name_ar = EXCLUDED.name_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'special_offers.manage'
WHERE r.code IN ('super_admin', 'content')
ON CONFLICT DO NOTHING;
