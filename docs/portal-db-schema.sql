-- Portal website database (PostgreSQL)
-- Separate from Amine SQL Server and ApiManagementDb
-- Run on the external web server

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ---------------------------------------------------------------------------
-- Access policies (reusable visibility rules; prices always from Amine API)
-- ---------------------------------------------------------------------------
CREATE TABLE access_policies (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code            VARCHAR(80) NOT NULL UNIQUE,
    name_ar         VARCHAR(200) NOT NULL,
    description_ar  VARCHAR(500),
    show_price      BOOLEAN NOT NULL DEFAULT TRUE,
    show_quantity   BOOLEAN NOT NULL DEFAULT FALSE,
    allow_cart      BOOLEAN NOT NULL DEFAULT TRUE,
    allow_order     BOOLEAN NOT NULL DEFAULT TRUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX ix_access_policies_active ON access_policies (is_active) WHERE is_active = TRUE;

-- ---------------------------------------------------------------------------
-- Website staff users (NOT ApiUsers)
-- ---------------------------------------------------------------------------
CREATE TABLE web_roles (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code            VARCHAR(80) NOT NULL UNIQUE,
    name_ar         VARCHAR(150) NOT NULL,
    description_ar  VARCHAR(500),
    is_system       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE web_permissions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code            VARCHAR(120) NOT NULL UNIQUE,
    name_ar         VARCHAR(150) NOT NULL,
    category_ar     VARCHAR(80) NOT NULL,
    description_ar  VARCHAR(500)
);

CREATE TABLE web_role_permissions (
    role_id         UUID NOT NULL REFERENCES web_roles (id) ON DELETE CASCADE,
    permission_id   UUID NOT NULL REFERENCES web_permissions (id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE web_users (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(255),
    display_name_ar VARCHAR(200) NOT NULL,
    password_hash   VARCHAR(500) NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at   TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_web_users_user_name UNIQUE (user_name)
);

CREATE TABLE web_user_roles (
    user_id         UUID NOT NULL REFERENCES web_users (id) ON DELETE CASCADE,
    role_id         UUID NOT NULL REFERENCES web_roles (id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE web_sessions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES web_users (id) ON DELETE CASCADE,
    token_hash      VARCHAR(128) NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_ip      VARCHAR(45),
    user_agent      VARCHAR(500)
);

CREATE INDEX ix_web_sessions_user ON web_sessions (user_id, expires_at);

-- ---------------------------------------------------------------------------
-- Web customers (separate from Amine cu000)
-- ---------------------------------------------------------------------------
CREATE TYPE web_customer_status AS ENUM ('pending', 'active', 'rejected', 'suspended');
CREATE TYPE web_customer_registration_source AS ENUM ('self_register', 'admin_created');

CREATE TABLE web_customers (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name_ar                 VARCHAR(250) NOT NULL,
    phone                   VARCHAR(30) NOT NULL,
    email                   VARCHAR(255),
    password_hash           VARCHAR(500),
    status                  web_customer_status NOT NULL DEFAULT 'pending',
    registration_source     web_customer_registration_source NOT NULL,
    access_policy_id        UUID REFERENCES access_policies (id),
    notes_ar                VARCHAR(1000),
    rejection_reason_ar     VARCHAR(500),
    approved_by_web_user_id UUID REFERENCES web_users (id),
    approved_at             TIMESTAMPTZ,
    created_by_web_user_id  UUID REFERENCES web_users (id),
    legacy_customer_guid    UUID,
    is_active               BOOLEAN NOT NULL DEFAULT FALSE,
    last_login_at           TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_web_customers_phone UNIQUE (phone)
);

CREATE INDEX ix_web_customers_status ON web_customers (status);
CREATE INDEX ix_web_customers_pending ON web_customers (created_at DESC) WHERE status = 'pending';

CREATE TABLE web_customer_sessions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    customer_id     UUID NOT NULL REFERENCES web_customers (id) ON DELETE CASCADE,
    token_hash      VARCHAR(128) NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_ip      VARCHAR(45),
    user_agent      VARCHAR(500)
);

-- ---------------------------------------------------------------------------
-- Store guest (anonymous) + company settings
-- ---------------------------------------------------------------------------
CREATE TABLE store_guest_settings (
    id                  SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    access_policy_id    UUID NOT NULL REFERENCES access_policies (id),
    max_packages_per_material NUMERIC(18, 4),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id  UUID REFERENCES web_users (id)
);

CREATE TABLE company_settings (
    key             VARCHAR(100) PRIMARY KEY,
    value_ar        TEXT NOT NULL,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id)
);

-- ---------------------------------------------------------------------------
-- Homepage sections (banners: عروض، نسواني، رجالي، وصلنا حديثاً…)
-- ---------------------------------------------------------------------------
CREATE TYPE home_section_display_mode AS ENUM ('manual', 'filter');

CREATE TABLE home_sections (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug            VARCHAR(80) NOT NULL UNIQUE,
    title_ar        VARCHAR(200) NOT NULL,
    subtitle_ar     VARCHAR(500),
    banner_image_url VARCHAR(1000),
    display_mode    home_section_display_mode NOT NULL DEFAULT 'filter',
    max_products    INT NOT NULL DEFAULT 12 CHECK (max_products BETWEEN 1 AND 48),
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id)
);

CREATE INDEX ix_home_sections_sort ON home_sections (sort_order) WHERE is_active = TRUE;

CREATE TABLE home_section_filters (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    section_id      UUID NOT NULL REFERENCES home_sections (id) ON DELETE CASCADE,
    filter_type     VARCHAR(50) NOT NULL,
    value_ar        VARCHAR(500) NOT NULL,
    CONSTRAINT uq_home_section_filter UNIQUE (section_id, filter_type, value_ar)
);

-- filter_type: keyword | material_type | target_category | manufacturer | min_quantity

-- Site media library (banners, ads, logos — not material photos)
CREATE TYPE site_media_category AS ENUM ('banner', 'ad', 'logo', 'other');

CREATE TABLE site_media_assets (
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

CREATE INDEX ix_site_media_category ON site_media_assets (category, created_at DESC);

CREATE TABLE home_section_products (
    section_id      UUID NOT NULL REFERENCES home_sections (id) ON DELETE CASCADE,
    material_guid   UUID NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    PRIMARY KEY (section_id, material_guid)
);

-- ---------------------------------------------------------------------------
-- Special offers (website-only pricing overlay)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Share links (improved viewer links)
-- ---------------------------------------------------------------------------
CREATE TABLE share_links (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    public_token            VARCHAR(64) NOT NULL UNIQUE,
    name_ar                 VARCHAR(250) NOT NULL,
    access_policy_id        UUID NOT NULL REFERENCES access_policies (id),
    require_password        BOOLEAN NOT NULL DEFAULT FALSE,
    access_username         VARCHAR(100),
    password_hash           VARCHAR(500),
    keyword                 VARCHAR(250),
    min_quantity            NUMERIC(18, 6) DEFAULT 0,
    expires_at              TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_web_user_id  UUID REFERENCES web_users (id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX ix_share_links_active ON share_links (is_active, created_at DESC);

CREATE TABLE share_link_filters (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    link_id         UUID NOT NULL REFERENCES share_links (id) ON DELETE CASCADE,
    filter_type     VARCHAR(50) NOT NULL,
    value_ar        VARCHAR(500) NOT NULL,
    CONSTRAINT uq_share_link_filter UNIQUE (link_id, filter_type, value_ar)
);

-- ---------------------------------------------------------------------------
-- Orders (future Amine bill/reservation sync)
-- ---------------------------------------------------------------------------
CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'completed', 'cancelled');
CREATE TYPE amine_sync_status AS ENUM ('none', 'pending', 'synced', 'failed');

CREATE TABLE orders (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_number            VARCHAR(30) NOT NULL UNIQUE,
    share_link_id           UUID REFERENCES share_links (id),
    web_customer_id         UUID REFERENCES web_customers (id),
    guest_name_ar           VARCHAR(250),
    guest_phone             VARCHAR(30),
    status                  order_status NOT NULL DEFAULT 'pending',
    total_sp                NUMERIC(18, 2) NOT NULL DEFAULT 0,
    total_usd               NUMERIC(18, 4) NOT NULL DEFAULT 0,
    notes_ar                VARCHAR(1000),
    quote_access_token      VARCHAR(128) NOT NULL UNIQUE,
    amine_bill_guid         UUID,
    amine_sync_status       amine_sync_status NOT NULL DEFAULT 'none',
    amine_synced_at         TIMESTAMPTZ,
    amine_sync_error_ar     VARCHAR(1000),
    reservation_notes_ar    VARCHAR(1000),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX ix_orders_created ON orders (created_at DESC);
CREATE INDEX ix_orders_customer ON orders (web_customer_id, created_at DESC);
CREATE INDEX ix_orders_share_link ON orders (share_link_id, created_at DESC);
CREATE INDEX ix_orders_amine_sync ON orders (amine_sync_status) WHERE amine_sync_status <> 'none';

CREATE TABLE order_items (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id        UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    material_guid   UUID NOT NULL,
    material_code   VARCHAR(100),
    material_name_ar VARCHAR(500) NOT NULL,
    quantity        NUMERIC(18, 6) NOT NULL DEFAULT 1,
    pcs_per_box     INT NOT NULL DEFAULT 1,
    sale_price_sp   NUMERIC(18, 2) NOT NULL DEFAULT 0,
    sale_price_usd  NUMERIC(18, 4) NOT NULL DEFAULT 0,
    original_sale_price_sp NUMERIC(18, 4),
    original_sale_price_usd NUMERIC(18, 4),
    special_offer_id UUID REFERENCES special_offers (id),
    image_url       VARCHAR(1000),
    sort_order      INT NOT NULL DEFAULT 0
);

CREATE INDEX ix_order_items_order ON order_items (order_id, sort_order);

-- ---------------------------------------------------------------------------
-- Visitor analytics
-- ---------------------------------------------------------------------------
CREATE TABLE visitor_logs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    share_link_id   UUID REFERENCES share_links (id) ON DELETE SET NULL,
    web_customer_id UUID REFERENCES web_customers (id) ON DELETE SET NULL,
    session_id      VARCHAR(120) NOT NULL,
    action          VARCHAR(80) NOT NULL,
    visitor_ip      VARCHAR(45),
    country_ar      VARCHAR(100),
    city_ar         VARCHAR(100),
    latitude        NUMERIC(10, 7),
    longitude       NUMERIC(10, 7),
    user_agent      VARCHAR(500),
    referer         VARCHAR(1000),
    details_ar      VARCHAR(2000),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX ix_visitor_logs_created ON visitor_logs (created_at DESC);
CREATE INDEX ix_visitor_logs_link ON visitor_logs (share_link_id, created_at DESC);
CREATE INDEX ix_visitor_logs_action ON visitor_logs (action, created_at DESC);

-- ---------------------------------------------------------------------------
-- Dual-hosted images (local copy + Amine API reference)
-- ---------------------------------------------------------------------------
CREATE TABLE portal_images (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    material_guid           UUID NOT NULL,
    amine_image_guid        UUID,
    local_file_path         VARCHAR(1000) NOT NULL,
    local_thumb_path        VARCHAR(1000),
    uploaded_by_web_user_id UUID REFERENCES web_users (id),
    synced_to_amine_at      TIMESTAMPTZ,
    amine_sync_error_ar     VARCHAR(500),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX ix_portal_images_material ON portal_images (material_guid, created_at DESC);

-- ---------------------------------------------------------------------------
-- updated_at trigger helper
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER tr_access_policies_updated BEFORE UPDATE ON access_policies
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_web_users_updated BEFORE UPDATE ON web_users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_web_customers_updated BEFORE UPDATE ON web_customers
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_home_sections_updated BEFORE UPDATE ON home_sections
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_special_offers_updated BEFORE UPDATE ON special_offers
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_share_links_updated BEFORE UPDATE ON share_links
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER tr_orders_updated BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
