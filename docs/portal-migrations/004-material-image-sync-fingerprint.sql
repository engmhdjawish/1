-- Fingerprint columns for accurate portal ↔ Amine image matching.

ALTER TABLE material_image_sync_queue
    ADD COLUMN IF NOT EXISTS local_size_bytes BIGINT,
    ADD COLUMN IF NOT EXISTS local_sha256 VARCHAR(64);

CREATE INDEX IF NOT EXISTS ix_material_image_sync_sha256
    ON material_image_sync_queue (local_sha256)
    WHERE local_sha256 IS NOT NULL;
