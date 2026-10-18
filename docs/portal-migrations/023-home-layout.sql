-- Layout of the trial homepage (/home-next.php): block order, visibility, titles and options.

CREATE TABLE IF NOT EXISTS home_layout_settings (
    id                 SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    config             JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL
);

INSERT INTO home_layout_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
