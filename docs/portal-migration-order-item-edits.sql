-- Order item staff edits + customer-visible audit trail
-- Run on portal PostgreSQL after portal-db-schema.sql

DO $$ BEGIN
    CREATE TYPE order_item_status AS ENUM ('active', 'cancelled');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS status order_item_status NOT NULL DEFAULT 'active';

DO $$ BEGIN
    CREATE TYPE order_item_change_type AS ENUM ('quantity', 'price_sp', 'price_usd', 'cancel');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS order_item_changes (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id                UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    order_item_id           UUID NOT NULL REFERENCES order_items (id) ON DELETE CASCADE,
    change_type             order_item_change_type NOT NULL,
    old_value               VARCHAR(100),
    new_value               VARCHAR(100),
    reason_ar               VARCHAR(500) NOT NULL,
    changed_by_web_user_id  UUID REFERENCES web_users (id),
    visible_to_customer     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_order_item_changes_order
    ON order_item_changes (order_id, created_at DESC);

CREATE INDEX IF NOT EXISTS ix_order_items_active
    ON order_items (order_id, sort_order)
    WHERE status = 'active';
