<div
  data-material-images-link-panel
  data-can-add-details="<?= !empty($detailsBanner['ok']) ? '1' : '0' ?>"
  data-templates-api="/dashboard/material-card-templates-api.php"
>
<?php if (!empty($materialFilterOptionsError)): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-sm"><?= h((string) $materialFilterOptionsError) ?></p>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between gap-2">
    <h2 class="font-bold text-sm">صور الأمين</h2>
    <div class="flex items-center gap-2 shrink-0">
      <a href="/dashboard/material-card-templates.php" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-base" aria-hidden="true">dashboard_customize</span>
        القوالب
      </a>
      <button type="button" id="reloadSourcesBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تحديث</button>
      <span id="sourcePageLabel" class="text-xs text-text-muted whitespace-nowrap">صفحة 1</span>
    </div>
  </div>
  <div class="p-4">
    <div class="dash-mi-toolbar mb-3">
      <div class="dash-mi-filter-tabs">
        <button type="button" class="link-filter-btn dash-mi-filter-tab" data-filter="all">كل الصور</button>
        <button type="button" class="link-filter-btn dash-mi-filter-tab" data-filter="linked">المرتبطة</button>
        <button type="button" class="link-filter-btn dash-mi-filter-tab is-active" data-filter="unlinked">غير المرتبطة</button>
      </div>
      <?php if (!empty($detailsBanner['ok'])): ?>
        <label class="dash-mi-template-note inline-flex items-start gap-2 cursor-pointer">
          <input type="checkbox" id="globalUseTemplate" class="mt-0.5 shrink-0">
          <span>
            <strong>تفعيل القالب عند الربط</strong>
            — بعد اختيار المواد تُفتح نافذة لتكبير/تصغير وتحريك الصورة داخل منطقة القالب قبل التأكيد.
          </span>
        </label>
      <?php else: ?>
        <p class="dash-mi-template-note">قالب البطاقة غير جاهز حالياً. <?= h((string) ($detailsBanner['message'] ?? '')) ?>
          <a href="/dashboard/material-card-templates.php" class="text-primary font-bold underline">إدارة القوالب</a>
        </p>
      <?php endif; ?>
      <div class="flex flex-col sm:flex-row gap-2">
        <input type="search" id="sourceMaterialSearch" class="h-9 flex-1 min-w-0 rounded-lg border border-border-subtle px-3 text-sm" placeholder="بحث مادة بالاسم أو الرمز">
        <button type="button" id="applySourceFiltersBtn" class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold shrink-0">بحث</button>
      </div>
      <details class="dash-mi-danger-zone">
        <summary class="dash-mi-danger-zone__toggle">حذف جماعي (خطير)</summary>
        <div class="dash-mi-danger-zone__body">
          <p class="dash-mi-danger-zone__hint">يحذف من bm000 والموقع نهائياً. لا يمكن التراجع.</p>
          <div class="dash-mi-toolbar__actions">
            <button type="button" id="deleteAllUnlinkedBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold hidden">حذف كل غير المرتبطة</button>
            <button type="button" id="deleteSelectedUnlinkedBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold hidden">حذف المحدد</button>
            <button type="button" id="pauseDeleteUnlinkedBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hidden">إيقاف</button>
            <button type="button" id="resumeDeleteUnlinkedBtn" class="h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف</button>
            <button type="button" id="cancelDeleteUnlinkedBtn" class="h-8 px-3 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold hidden">إلغاء</button>
          </div>
          <label id="selectAllUnlinkedWrap" class="hidden inline-flex items-center gap-2 text-xs font-bold text-text-muted">
            <input type="checkbox" id="selectAllUnlinked" class="rounded border-border-subtle">
            تحديد الكل في الصفحة
          </label>
          <div id="deleteUnlinkedProgressWrap" class="hidden">
            <div class="flex justify-between text-xs text-text-muted mb-1">
              <span id="deleteUnlinkedProgressLabel">0 / 0</span>
              <span id="deleteUnlinkedStatusLabel">جاري الحذف...</span>
            </div>
            <div class="h-2 rounded-full bg-surface-low overflow-hidden">
              <div id="deleteUnlinkedProgressBar" class="h-full bg-red-500 transition-all" style="width:0%"></div>
            </div>
          </div>
        </div>
      </details>
    </div>
    <div id="sourceCards" class="dash-mi-cards"></div>
    <div class="mt-3 flex items-center justify-between">
      <button type="button" id="sourcePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
      <button type="button" id="sourceNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
    </div>
  </div>
</section>

<p id="linkStatus" class="text-sm text-text-muted"></p>

<div id="imageLightbox" class="dash-mi-lightbox" role="dialog" aria-modal="true" hidden>
  <button type="button" id="lightboxCloseBtn" class="dash-mi-lightbox__close" aria-label="إغلاق">×</button>
  <div class="dash-mi-lightbox__stage">
    <img id="lightboxImg" src="" alt="">
  </div>
  <p id="lightboxCaption" class="dash-mi-lightbox__caption"></p>
  <p class="dash-mi-lightbox__hint">انقر على الصورة للتكبير — انقر مجدداً للعودة</p>
</div>

<div id="templateFitModal" class="dash-mi-fit-modal" hidden>
  <div class="dash-mi-fit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="templateFitTitle">
    <div class="dash-mi-fit-modal__head">
      <h3 id="templateFitTitle">ضبط الصورة على القالب</h3>
      <button type="button" id="templateFitCloseBtn" class="dash-mi-fit-modal__close" aria-label="إغلاق">×</button>
    </div>
    <p class="dash-mi-fit-modal__hint">اسحب للتحريك · عجلة الفأرة أو الأزرار للتكبير/التصغير · ثم أكّد الربط</p>
    <div class="dash-mi-fit-modal__stage-wrap">
      <div id="templateFitStage" class="dash-mi-fit-modal__stage">
        <img id="templateFitBg" alt="" class="dash-mi-fit-modal__bg">
        <div id="templateFitPhotoClip" class="dash-mi-fit-modal__photo-clip">
          <img id="templateFitPhoto" alt="" draggable="false">
        </div>
      </div>
    </div>
    <div class="dash-mi-fit-modal__tools">
      <button type="button" id="templateFitZoomOut" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تصغير</button>
      <button type="button" id="templateFitZoomIn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تكبير</button>
      <button type="button" id="templateFitReset" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">إعادة</button>
      <span id="templateFitZoomLabel" class="text-xs text-text-muted">100%</span>
      <div class="flex-1"></div>
      <button type="button" id="templateFitCancel" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">إلغاء</button>
      <button type="button" id="templateFitConfirm" class="h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold">تأكيد الربط</button>
    </div>
  </div>
</div>
</div>
