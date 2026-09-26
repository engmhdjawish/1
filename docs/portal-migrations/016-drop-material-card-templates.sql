-- Rollback material card templates (014/015) if they were applied.
-- Safe to run when those migrations were never applied.

DROP TABLE IF EXISTS material_card_fonts;
DROP TABLE IF EXISTS material_card_template_fields;
DROP TABLE IF EXISTS material_card_templates;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_card_field_kind') THEN
        DROP TYPE material_card_field_kind;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_card_text_align') THEN
        DROP TYPE material_card_text_align;
    END IF;
END $$;

DELETE FROM web_role_permissions rp
USING web_permissions p
WHERE rp.permission_id = p.id
  AND p.code = 'images.templates.manage';

DELETE FROM web_permissions
WHERE code = 'images.templates.manage';
