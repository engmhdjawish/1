-- Local material image path settings (files live on disk; metadata stays on Amine API)

INSERT INTO company_settings (key, value_ar)
VALUES
    ('material_images_dir', ''),
    ('material_thumbnails_dir', '')
ON CONFLICT (key) DO NOTHING;
