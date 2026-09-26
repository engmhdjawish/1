<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $templates */
/** @var list<array<string, mixed>> $fonts */
/** @var array{ok: bool, message: string} $requirements */
/** @var string|null $loadError */

$templates = is_array($templates ?? null) ? $templates : [];
$fonts = is_array($fonts ?? null) ? $fonts : [];
$requirements = is_array($requirements ?? null) ? $requirements : ['ok' => false, 'message' => ''];
$selectedId = trim((string) ($_GET['id'] ?? ''));
if ($selectedId === '' && $templates !== []) {
    foreach ($templates as $row) {
        if (!empty($row['is_default'])) {
            $selectedId = (string) ($row['id'] ?? '');
            break;
        }
    }
    if ($selectedId === '') {
        $selectedId = (string) ($templates[0]['id'] ?? '');
    }
}
?>
<section class="mb-6" data-material-card-templates>
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">قوالب بطاقة صور المواد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        ارفع صورة القالب وحدد أبعادها إن رغبت (مثل 1080×900)، ثم اضبط الحقول والـ z-index (الصورة بقيمة أقل تظهر خلف القالب)،
        واختر خط كل حقل أو ارفع خطوطاً جديدة.
      </p>
      <?php if (empty($requirements['ok'])): ?>
        <p class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 max-w-3xl">
          <?= h((string) ($requirements['message'] ?? 'متطلبات الرسم غير مكتملة.')) ?>
        </p>
      <?php endif; ?>
      <?php if ($loadError !== null && $loadError !== ''): ?>
        <p class="mt-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 max-w-3xl"><?= h($loadError) ?></p>
      <?php endif; ?>
    </div>
    <a href="/dashboard/material-images.php?tab=link" class="h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold inline-flex items-center gap-1">
      <span class="material-symbols-outlined text-base" aria-hidden="true">link</span>
      العودة لربط الصور
    </a>
  </div>
</section>

<div class="grid gap-4 xl:grid-cols-[18rem_minmax(0,1fr)]" data-mct-root data-selected-id="<?= h($selectedId) ?>">
  <aside class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-3 py-2.5 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between gap-2">
      <h2 class="font-bold text-sm">القوالب</h2>
      <button type="button" id="mctReloadBtn" class="h-8 px-2 rounded-lg border border-border-subtle bg-white text-[11px] font-bold">تحديث</button>
    </div>
    <div class="p-3 space-y-3">
      <form id="mctUploadForm" class="space-y-2 rounded-lg border border-dashed border-border-subtle p-2.5 bg-surface-low/40">
        <label class="block text-xs font-bold">رفع قالب جديد
          <input type="file" name="file" accept="image/png,image/jpeg,image/webp" required class="mt-1 block w-full text-xs">
        </label>
        <label class="block text-xs font-bold">الاسم
          <input type="text" name="name_ar" class="mt-1 h-8 w-full rounded-lg border border-border-subtle px-2 text-xs" placeholder="قالب جاويش">
        </label>
        <div class="grid grid-cols-2 gap-2">
          <label class="block text-[11px] font-bold">العرض (px)
            <input type="number" name="target_width" min="100" max="4000" placeholder="1080" class="mt-0.5 h-8 w-full rounded-lg border border-border-subtle px-2 text-xs">
          </label>
          <label class="block text-[11px] font-bold">الارتفاع (px)
            <input type="number" name="target_height" min="100" max="4000" placeholder="900" class="mt-0.5 h-8 w-full rounded-lg border border-border-subtle px-2 text-xs">
          </label>
        </div>
        <p class="text-[10px] text-text-muted leading-relaxed m-0">اترك الأبعاد فارغة للإبقاء على حجم الملف الأصلي. مثال: 1080×900</p>
        <label class="inline-flex items-center gap-2 text-[11px] font-bold">
          <input type="checkbox" name="make_default" value="1" checked>
          جعله الافتراضي
        </label>
        <button type="submit" class="h-8 w-full rounded-lg bg-primary text-white text-xs font-bold">رفع</button>
      </form>

      <form id="mctFontUploadForm" class="space-y-2 rounded-lg border border-border-subtle p-2.5 bg-white">
        <p class="text-xs font-bold m-0">خطوط مخصصة</p>
        <label class="block text-[11px] font-bold">رفع خط (.ttf / .otf)
          <input type="file" name="file" accept=".ttf,.otf,font/ttf,font/otf" required class="mt-1 block w-full text-xs">
        </label>
        <label class="block text-[11px] font-bold">اسم الخط
          <input type="text" name="name_ar" class="mt-0.5 h-8 w-full rounded-lg border border-border-subtle px-2 text-xs" placeholder="Tahoma Bold">
        </label>
        <button type="submit" class="h-8 w-full rounded-lg border border-border-subtle bg-surface-low text-xs font-bold">رفع الخط</button>
        <div id="mctFontList" class="space-y-1 max-h-40 overflow-auto"></div>
      </form>

      <div id="mctList" class="space-y-2"></div>
    </div>
  </aside>

  <section class="rounded-xl border border-border-subtle bg-white overflow-hidden min-w-0">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-bold text-sm">ضبط الحقول</h2>
      <div class="flex flex-wrap gap-2">
        <button type="button" id="mctSaveBtn" class="h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold" disabled>حفظ الإعدادات</button>
        <button type="button" id="mctDeleteBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold" disabled>حذف</button>
      </div>
    </div>
    <div class="p-4 grid gap-4 2xl:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="min-w-0">
        <div id="mctStageWrap" class="relative w-full overflow-auto rounded-xl border border-border-subtle bg-[#111] max-h-[70vh]">
          <div id="mctStage" class="relative mx-auto" style="width:min(100%,920px)">
            <img id="mctTemplateImg" alt="" class="block w-full h-auto select-none pointer-events-none">
            <div id="mctOverlays" class="absolute inset-0"></div>
          </div>
        </div>
        <p class="mt-2 text-[11px] text-text-muted leading-relaxed">اسحب المربعات لتغيير المكان، ومن الزاوية لتغيير الحجم. اختر حقلاً من القائمة لتعديل الخط واللون.</p>
      </div>
      <div class="space-y-3">
        <label class="block text-xs font-bold">اسم القالب
          <input type="text" id="mctName" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" disabled>
        </label>
        <label class="inline-flex items-center gap-2 text-xs font-bold">
          <input type="checkbox" id="mctActive" disabled> مفعّل
        </label>
        <label class="inline-flex items-center gap-2 text-xs font-bold">
          <input type="checkbox" id="mctDefault" disabled> افتراضي عند الربط
        </label>
        <div>
          <p class="text-xs font-bold mb-1">الحقل المحدد</p>
          <div id="mctFieldTabs" class="flex flex-wrap gap-1 mb-2"></div>
          <div id="mctFieldForm" class="space-y-2 rounded-lg border border-border-subtle p-2.5 bg-surface-low/40">
            <p class="text-[11px] text-text-muted">اختر قالباً ثم حقلاً.</p>
          </div>
        </div>
        <p id="mctStatus" class="text-xs text-text-muted"></p>
      </div>
    </div>
  </section>
</div>

<script type="application/json" id="mctBootstrap"><?= json_encode([
    'api' => '/dashboard/material-card-templates-api.php',
    'selectedId' => $selectedId,
    'fieldLabels' => is_array($fieldLabels ?? null) ? $fieldLabels : [],
    'items' => $templates,
    'fonts' => $fonts,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
