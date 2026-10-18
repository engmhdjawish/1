-- "Shop by" blocks on the homepage: one facet, two facets combined, or custom tiles.

CREATE TABLE IF NOT EXISTS home_shop_blocks (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title_ar         VARCHAR(200) NOT NULL,
    block_type       VARCHAR(20) NOT NULL DEFAULT 'facet' CHECK (block_type IN ('facet', 'combo', 'custom')),
    primary_param    VARCHAR(40),
    secondary_param  VARCHAR(40),
    primary_values   JSONB NOT NULL DEFAULT '[]'::jsonb,
    secondary_values JSONB NOT NULL DEFAULT '[]'::jsonb,
    tiles            JSONB NOT NULL DEFAULT '[]'::jsonb,
    style            VARCHAR(20) NOT NULL DEFAULT 'icons' CHECK (style IN ('icons', 'chips', 'cards')),
    placement        VARCHAR(20) NOT NULL DEFAULT 'tabs' CHECK (placement IN ('tabs', 'band')),
    max_items        INT NOT NULL DEFAULT 12 CHECK (max_items BETWEEN 2 AND 60),
    sort_order       INT NOT NULL DEFAULT 0,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS ix_home_shop_blocks_sort ON home_shop_blocks (sort_order) WHERE is_active = TRUE;
