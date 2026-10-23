-- Wholesale customers register with their shop address instead of an e-mail.

ALTER TABLE web_customers ADD COLUMN IF NOT EXISTS shop_address_ar VARCHAR(300);
