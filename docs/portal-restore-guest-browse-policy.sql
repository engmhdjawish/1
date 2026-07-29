-- استعادة سياسة الزائر «تصفح فقط» (بدون أسعار) بعد إعادة البذر الخاطئة
-- شغّل يدوياً على production فقط إذا تأكدت أن السياسة تغيّرت إلى guest_full أو show_price=true
--
-- التحقق قبل التعديل:
--   SELECT ap.code, ap.show_price, ap.name_ar
--   FROM store_guest_settings s
--   JOIN access_policies ap ON ap.id = s.access_policy_id
--   WHERE s.id = 1;

BEGIN;

UPDATE store_guest_settings s
SET access_policy_id = ap.id,
    updated_at = NOW()
FROM access_policies ap
WHERE s.id = 1
  AND ap.code = 'guest_browse';

UPDATE access_policies
SET show_price = FALSE,
    show_quantity = FALSE,
    allow_cart = FALSE,
    allow_order = FALSE,
    updated_at = NOW()
WHERE code = 'guest_browse';

COMMIT;

-- بعد التشغيل: امسح كاش الرئيسية من لوحة التحكم أو أعد تحميل php-fpm
