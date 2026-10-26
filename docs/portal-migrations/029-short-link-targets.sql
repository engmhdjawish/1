-- Generic /s/{code} targets (products, order tracking, scoped share filters).
ALTER TABLE catalog_filter_views ADD COLUMN IF NOT EXISTS target_path TEXT;
