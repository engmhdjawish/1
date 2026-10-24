-- Whether materials that are out of stock still show their price (hidden by default).

ALTER TABLE store_guest_settings ADD COLUMN IF NOT EXISTS show_unavailable_prices BOOLEAN NOT NULL DEFAULT FALSE;
