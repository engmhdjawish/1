-- About us and contact fields for company_settings
INSERT INTO company_settings (key, value_ar)
VALUES
    ('company_email', ''),
    ('about_us_title_ar', 'من نحن'),
    ('about_us_ar', '')
ON CONFLICT (key) DO NOTHING;
