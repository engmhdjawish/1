-- Price checker slideshow: filters, manual materials, special offers.

ALTER TABLE price_checker_settings
    ADD COLUMN IF NOT EXISTS slideshow_mode VARCHAR(20) NOT NULL DEFAULT 'filter',
    ADD COLUMN IF NOT EXISTS slideshow_filter_rules JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS slideshow_offer_slug VARCHAR(120) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS slideshow_use_offer_prices BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS price_checker_slideshow_materials (
    material_guid UUID PRIMARY KEY,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Migrate legacy manufacturer list into filter rules when empty.
UPDATE price_checker_settings
SET slideshow_filter_rules = jsonb_build_object(
        'manufacturers',
        (
            SELECT COALESCE(jsonb_agg(trim(value)), '[]'::jsonb)
            FROM unnest(string_to_array(slideshow_manufacturers, E'\n')) AS value
            WHERE trim(value) <> ''
        ),
        'has_image', true,
        'is_available', true
    )
WHERE slideshow_filter_rules = '{}'::jsonb
  AND trim(slideshow_manufacturers) <> '';
