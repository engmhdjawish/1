<?php

declare(strict_types=1);

/** @var array<string, mixed> $template */
/** @var array<string, list<array{key: string, label: string, type: string}>> $fieldCatalog */
/** @var list<array{key: string, label: string}> $qrTargetCatalog */
/** @var array<string, string> $sampleFields */
/** @var string $companyLogoUrl */

require __DIR__ . '/partials/media-picker.php';

$templateJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$fieldCatalogJson = json_encode($fieldCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$qrTargetCatalogJson = json_encode($qrTargetCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$sampleFieldsJson = json_encode($sampleFields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="space-y-4" id="materialImageTemplateEditor"
     data-api="/dashboard/material-image-template-api.php"
     data-template="<?= h((string) $templateJson) ?>"
     data-field-catalog="<?= h((string) $fieldCatalogJson) ?>"
     data-qr-targets="<?= h((string) $qrTargetCatalogJson) ?>"
     data-sample-fields="<?= h((string) $sampleFieldsJson) ?>"
     data-company-logo="<?= h($companyLogoUrl) ?>">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h1 class="text-xl font-extrabold text-slate-900">محرر قالب عرض صور المواد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">
        صمّم إطاراً واحداً يُطبَّق على جميع صور المواد في الموقع عند العرض فقط — بدون تعديل ملف الصورة الأصلي.
        يمكنك إضافة أكثر من شعار وربط حقول المادة وبيانات الشركة.
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="button" id="mitResetBtn" class="h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hover:bg-slate-50">استعادة الافتراضي</button>
      <button type="button" id="mitSaveBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold hover:bg-primary/90">حفظ القالب</button>
    </div>
  </div>

  <div id="mitFlash" class="hidden rounded-xl border px-4 py-3 text-sm font-semibold"></div>

  <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4">
    <section class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="font-bold text-sm">معاينة القالب</h2>
        <label class="inline-flex items-center gap-2 text-xs font-bold text-text-muted">
          <input type="checkbox" id="mitEnabled" class="rounded border-border-subtle" <?= !empty($template['enabled']) ? 'checked' : '' ?>>
          تفعيل القالب على الموقع
        </label>
      </div>

      <div class="mit-canvas-wrap">
        <div id="mitCanvas" class="material-image-frame material-image-frame--detail mit-editor-canvas material-image-frame--template">
          <div class="material-image-frame__stack">
            <div class="material-image-frame__photo mit-region mit-region--photo" data-region="photo">
              <div class="mit-sample-photo" aria-hidden="true"></div>
              <div class="mit-region-grid" aria-hidden="true"></div>
              <div class="mit-elements-layer" data-layer="photo"></div>
            </div>
            <div class="material-image-frame__footer mit-region mit-region--footer" data-region="footer">
              <div class="mit-region-grid" aria-hidden="true"></div>
              <div class="mit-elements-layer" data-layer="footer"></div>
            </div>
            <div class="mit-elements-layer mit-elements-layer--frame" data-region="frame" data-layer="frame"></div>
          </div>
        </div>
      </div>
    </section>

    <aside class="space-y-4">
      <section class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm space-y-3">
        <h2 class="font-bold text-sm">إضافة عنصر</h2>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" id="mitAddTextBtn" class="h-9 rounded-lg border border-border-subtle bg-surface-low text-xs font-bold hover:bg-white">نص</button>
          <button type="button" id="mitAddLogoBtn" class="h-9 rounded-lg border border-border-subtle bg-surface-low text-xs font-bold hover:bg-white">شعار / صورة</button>
          <button type="button" id="mitAddBarcodeBtn" class="h-9 rounded-lg border border-border-subtle bg-surface-low text-xs font-bold hover:bg-white">باركود</button>
          <button type="button" id="mitAddQrBtn" class="h-9 rounded-lg border border-border-subtle bg-surface-low text-xs font-bold hover:bg-white">QR</button>
        </div>
        <p class="text-[11px] text-text-muted leading-relaxed">
          اسحب العناصر داخل الصورة أو الشريط أو <strong>الإطار الكامل</strong> لوضع الشعار فوق الاثنين معاً.
        </p>
      </section>

      <section class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm space-y-3">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-bold text-sm">العناصر</h2>
          <span id="mitElementCount" class="text-[11px] text-text-muted">0</span>
        </div>
        <div id="mitElementsList" class="space-y-1 max-h-44 overflow-y-auto"></div>
      </section>

      <section id="mitInspector" class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm space-y-3 hidden">
        <h2 class="font-bold text-sm">خصائص العنصر</h2>

        <label class="block text-xs">
          <span class="text-text-muted">المنطقة</span>
          <select id="mitFieldRegion" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
            <option value="frame">الإطار الكامل (صورة + شريط)</option>
            <option value="footer">الشريط السفلي</option>
            <option value="photo">فوق الصورة</option>
          </select>
        </label>

        <label class="block text-xs">
          <span class="text-text-muted">الحقل / المصدر</span>
          <select id="mitFieldSource" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm"></select>
        </label>

        <div id="mitFixedImageWrap" class="hidden">
          <?php $renderMediaPickerField('صورة ثابتة', 'mit_fixed_image', '', 'mit-fixed-image', 'logo'); ?>
        </div>

        <div id="mitQrWrap" class="space-y-2 hidden">
          <label class="block text-xs">
            <span class="text-text-muted">وجهة QR</span>
            <select id="mitQrTarget" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm"></select>
          </label>
          <label class="block text-xs hidden" id="mitQrCustomWrap">
            <span class="text-text-muted">رابط مخصص</span>
            <input type="url" id="mitQrCustomUrl" placeholder="https://..." class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
          </label>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <label class="block text-xs">
            <span class="text-text-muted">العرض %</span>
            <input type="number" id="mitWidthPct" min="1" max="100" step="0.5" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
          </label>
          <label class="block text-xs">
            <span class="text-text-muted">الارتفاع %</span>
            <input type="number" id="mitHeightPct" min="1" max="100" step="0.5" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
          </label>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <label class="block text-xs">
            <span class="text-text-muted">محاذاة أفقية</span>
            <select id="mitAlign" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
              <option value="start">بداية (يمين في عربي)</option>
              <option value="center">وسط</option>
              <option value="end">نهاية (يسار في عربي)</option>
            </select>
          </label>
          <label class="block text-xs">
            <span class="text-text-muted">محاذاة عمودية</span>
            <select id="mitValign" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
              <option value="start">أعلى</option>
              <option value="center">وسط</option>
              <option value="end">أسفل</option>
            </select>
          </label>
        </div>

        <div id="mitTextStyleWrap" class="space-y-2">
          <label class="block text-xs">
            <span class="text-text-muted">لون النص</span>
            <input type="color" id="mitTextColor" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-1">
          </label>
          <label class="block text-xs">
            <span class="text-text-muted">اتجاه النص</span>
            <select id="mitTextDirection" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
              <option value="rtl">يمين ← يسار (عربي)</option>
              <option value="ltr">يسار → يمين (إنجليزي/أرقام)</option>
            </select>
          </label>
          <div class="grid grid-cols-2 gap-2">
            <label class="block text-xs">
              <span class="text-text-muted">حجم الخط (نسبة)</span>
              <input type="number" id="mitFontSize" min="0.4" max="2" step="0.02" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="block text-xs">
              <span class="text-text-muted">سُمك الخط</span>
              <input type="number" id="mitFontWeight" min="100" max="900" step="100" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
            </label>
          </div>
          <label class="inline-flex items-center gap-2 text-xs font-bold text-text-muted">
            <input type="checkbox" id="mitNowrap" class="rounded border-border-subtle">
            سطر واحد مع نقاط(...)
          </label>
        </div>

        <div id="mitImageStyleWrap" class="space-y-2 hidden">
          <label class="block text-xs">
            <span class="text-text-muted">تكبير الصورة</span>
            <input type="range" id="mitImageScale" min="0.5" max="3" step="0.05" class="mt-1 w-full">
          </label>
          <div class="grid grid-cols-2 gap-2">
            <label class="block text-xs">
              <span class="text-text-muted">قص أفقي %</span>
              <input type="range" id="mitCropX" min="0" max="100" step="1" class="mt-1 w-full">
            </label>
            <label class="block text-xs">
              <span class="text-text-muted">قص عمودي %</span>
              <input type="range" id="mitCropY" min="0" max="100" step="1" class="mt-1 w-full">
            </label>
          </div>
          <label class="block text-xs">
            <span class="text-text-muted">شفافية</span>
            <input type="range" id="mitOpacity" min="0" max="1" step="0.05" class="mt-1 w-full">
          </label>
          <label class="block text-xs">
            <span class="text-text-muted">خلفية الصورة</span>
            <input type="text" id="mitImageBg" placeholder="rgba(255,255,255,0.9)" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
          </label>
        </div>

        <button type="button" id="mitDeleteElementBtn" class="w-full h-9 rounded-lg border border-red-200 text-red-700 text-xs font-bold hover:bg-red-50">حذف العنصر</button>
      </section>

      <section class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm space-y-3">
        <h2 class="font-bold text-sm">الشريط السفلي</h2>
        <label class="inline-flex items-center gap-2 text-xs font-bold text-text-muted">
          <input type="checkbox" id="mitFooterEnabled" class="rounded border-border-subtle" <?= !empty($template['footer']['enabled']) ? 'checked' : '' ?>>
          إظهار الشريط السفلي
        </label>
        <label class="block text-xs">
          <span class="text-text-muted">ارتفاع الشريط (rem)</span>
          <input type="number" id="mitFooterHeight" min="2" max="8" step="0.1" value="<?= h((string) ($template['footer']['min_height_rem'] ?? 3.2)) ?>" class="mt-1 w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
        </label>
        <label class="block text-xs">
          <span class="text-text-muted">لون الشريط الجانبي</span>
          <input type="color" id="mitAccentColor" value="<?= h((string) ($template['footer']['accent_color'] ?? '#d81921')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-1">
        </label>
      </section>
    </aside>
  </div>
</div>

<?php portal_render_media_picker_modal(); ?>
