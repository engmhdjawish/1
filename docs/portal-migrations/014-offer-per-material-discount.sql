-- Per-material offer discounts + fixed amount-off type
-- Safe on existing installs (IF NOT EXISTS / guarded checks).

ALTER TYPE special_offer_discount_type ADD VALUE IF NOT EXISTS 'fixed_amount';

ALTER TABLE special_offers
    ADD COLUMN IF NOT EXISTS pricing_scope VARCHAR(20) NOT NULL DEFAULT 'offer',
    ADD COLUMN IF NOT EXISTS fixed_amount_syp NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS fixed_amount_usd NUMERIC(18, 4);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_special_offers_pricing_scope'
    ) THEN
        ALTER TABLE special_offers
            ADD CONSTRAINT chk_special_offers_pricing_scope
            CHECK (pricing_scope IN ('offer', 'per_material'));
    END IF;
END $$;

ALTER TABLE special_offer_products
    ADD COLUMN IF NOT EXISTS discount_type special_offer_discount_type,
    ADD COLUMN IF NOT EXISTS discount_percent NUMERIC(6, 2),
    ADD COLUMN IF NOT EXISTS fixed_price_syp NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS fixed_price_usd NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS fixed_amount_syp NUMERIC(18, 4),
    ADD COLUMN IF NOT EXISTS fixed_amount_usd NUMERIC(18, 4);
