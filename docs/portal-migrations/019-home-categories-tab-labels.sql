-- Home page categories (quick links) and optional tab labels for sections/offers.

ALTER TABLE home_sections
    ADD COLUMN IF NOT EXISTS tab_label_ar VARCHAR(80);

ALTER TABLE special_offers
    ADD COLUMN IF NOT EXISTS tab_label_ar VARCHAR(80);

CREATE TABLE IF NOT EXISTS home_categories (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    label_ar        VARCHAR(100) NOT NULL,
    icon_key        VARCHAR(80) NOT NULL DEFAULT 'category',
    link_url        VARCHAR(500) NOT NULL DEFAULT '/store.php',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_home_categories_sort
    ON home_categories (sort_order)
    WHERE is_active = TRUE;

COMMENT ON COLUMN home_sections.tab_label_ar IS 'Short label for homepage section tab; falls back to title_ar when empty.';
COMMENT ON COLUMN special_offers.tab_label_ar IS 'Short label for homepage offer tab; falls back to title_ar when empty.';
COMMENT ON TABLE home_categories IS 'Quick category shortcuts on the homepage (icon + link).';
