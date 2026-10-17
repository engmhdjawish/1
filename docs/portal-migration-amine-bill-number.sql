-- Stores the Ameen reservation bill number returned by the API.
-- The portal also adds this column on demand (ADD COLUMN IF NOT EXISTS).

ALTER TABLE orders ADD COLUMN IF NOT EXISTS amine_bill_number INTEGER;
