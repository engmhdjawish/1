-- Store seasons: material groups shown first in the trial store while their season is current.

CREATE TABLE IF NOT EXISTS store_seasons (
    id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name_ar            VARCHAR(120) NOT NULL,
    customer_title_ar  VARCHAR(160) NOT NULL DEFAULT '',
    icon               VARCHAR(40) NOT NULL DEFAULT 'wb_sunny',
    group_guids        JSONB NOT NULL DEFAULT '[]'::jsonb,
    starts_month       SMALLINT CHECK (starts_month BETWEEN 1 AND 12),
    starts_day         SMALLINT CHECK (starts_day BETWEEN 1 AND 31),
    sort_order         INT NOT NULL DEFAULT 0,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS store_season_settings (
    id                 SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    mode               VARCHAR(10) NOT NULL DEFAULT 'off' CHECK (mode IN ('off', 'manual', 'auto')),
    manual_season_id   UUID REFERENCES store_seasons (id) ON DELETE SET NULL,
    show_to_customers  BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL
);

INSERT INTO store_season_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
