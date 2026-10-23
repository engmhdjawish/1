-- Fast lookup of a customer's latest orders, used to recognise a repeated order submit.

CREATE INDEX IF NOT EXISTS ix_orders_phone_recent ON orders (guest_phone, created_at DESC)
    WHERE guest_phone IS NOT NULL;
