-- Short public codes for share links (WhatsApp-friendly URLs).
ALTER TABLE share_links
    ADD COLUMN IF NOT EXISTS short_code VARCHAR(32);

CREATE UNIQUE INDEX IF NOT EXISTS ux_share_links_short_code
    ON share_links (short_code)
    WHERE short_code IS NOT NULL AND btrim(short_code) <> '';
