-- Per-material offer quantity caps + exhausted flag for auto-exit from offer.
-- Safe on existing installs (IF NOT EXISTS).

ALTER TABLE special_offer_products
    ADD COLUMN IF NOT EXISTS qty_mode VARCHAR(20) NOT NULL DEFAULT 'unlimited',
    ADD COLUMN IF NOT EXISTS qty_cap_packages NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS is_exhausted BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS exhausted_at TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_special_offer_products_qty_mode'
    ) THEN
        ALTER TABLE special_offer_products
            ADD CONSTRAINT chk_special_offer_products_qty_mode
            CHECK (qty_mode IN ('unlimited', 'stock', 'fixed'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_special_offer_products_exhausted
    ON special_offer_products (offer_id)
    WHERE is_exhausted = TRUE;

CREATE INDEX IF NOT EXISTS ix_order_items_special_offer_material
    ON order_items (special_offer_id, material_guid)
    WHERE special_offer_id IS NOT NULL;
