-- In-app notifications (public + private). Safe to run on existing portal_db.

DO $$ BEGIN
    CREATE TYPE notification_scope AS ENUM ('public', 'private');
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

DO $$ BEGIN
    CREATE TYPE notification_audience AS ENUM ('all', 'guests', 'customers', 'staff');
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS portal_notifications (
    id                          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    scope                       notification_scope NOT NULL,
    audience                    notification_audience NOT NULL DEFAULT 'all',
    title_ar                    VARCHAR(200) NOT NULL,
    body_ar                     TEXT NOT NULL,
    link_url                    VARCHAR(500),
    icon                        VARCHAR(50) NOT NULL DEFAULT 'notifications',
    recipient_web_customer_id   UUID REFERENCES web_customers (id) ON DELETE CASCADE,
    recipient_web_user_id       UUID REFERENCES web_users (id) ON DELETE CASCADE,
    source                      VARCHAR(50) NOT NULL DEFAULT 'manual',
    created_by_web_user_id      UUID REFERENCES web_users (id) ON DELETE SET NULL,
    expires_at                  TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_portal_notifications_created
    ON portal_notifications (created_at DESC);

CREATE INDEX IF NOT EXISTS ix_portal_notifications_public
    ON portal_notifications (scope, audience, created_at DESC)
    WHERE scope = 'public';

CREATE INDEX IF NOT EXISTS ix_portal_notifications_customer
    ON portal_notifications (recipient_web_customer_id, created_at DESC)
    WHERE recipient_web_customer_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_portal_notifications_staff
    ON portal_notifications (recipient_web_user_id, created_at DESC)
    WHERE recipient_web_user_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS portal_notification_reads (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    notification_id     UUID NOT NULL REFERENCES portal_notifications (id) ON DELETE CASCADE,
    reader_type         VARCHAR(16) NOT NULL CHECK (reader_type IN ('guest', 'customer', 'staff')),
    reader_id           VARCHAR(64) NOT NULL,
    read_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_portal_notification_read UNIQUE (notification_id, reader_type, reader_id)
);

CREATE INDEX IF NOT EXISTS ix_portal_notification_reads_reader
    ON portal_notification_reads (reader_type, reader_id, read_at DESC);

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES ('notifications.manage', 'إدارة الإشعارات', 'إدارة', 'إرسال إشعارات عامة وخاصة للعملاء والموظفين')
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'notifications.manage'
WHERE r.code IN ('super_admin', 'content')
ON CONFLICT DO NOTHING;
