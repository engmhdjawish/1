-- Material lifecycle: جديد / فعال / ملغي (replaces trial / season_active / discontinued).

ALTER TABLE procurement_materials DROP CONSTRAINT IF EXISTS procurement_materials_lifecycle_check;

UPDATE procurement_materials SET lifecycle = CASE lifecycle
    WHEN 'trial' THEN 'new'
    WHEN 'season_active' THEN 'active'
    WHEN 'discontinued' THEN 'cancelled'
    ELSE lifecycle
END;

ALTER TABLE procurement_materials
    ALTER COLUMN lifecycle SET DEFAULT 'new';

ALTER TABLE procurement_materials ADD CONSTRAINT procurement_materials_lifecycle_check
    CHECK (lifecycle IN ('new', 'active', 'cancelled'));
