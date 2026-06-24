<div
  data-material-images-link-panel
  data-can-add-details="<?= !empty($detailsBanner['ok']) ? '1' : '0' ?>"
>
<?php if (!empty($materialFilterOptionsError)): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-sm"><?= h((string) $materialFilterOptionsError) ?></p>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">صور الأمين (صفحات)</h2>
    <div class="flex items-center gap-2">
      <button type="button" id="reloadSourcesBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تحديث</button>
      <span id="sourcePageLabel" class="text-xs text-text-muted">صفحة 1</span>
    </div>
  </div>
  <div class="p-4">
    <div class="flex flex-col gap-3 mb-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-text-muted font-bold">عرض:</span>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-filter="all">كل الصور</button>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-filter="linked">المرتبطة</button>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-primary bg-primary text-white text-xs font-bold" data-filter="unlinked">غير المرتبطة</button>
      </div>
      <div class="flex flex-col sm:flex-row gap-2">
        <input type="search" id="sourceMaterialSearch" class="h-9 flex-1 rounded-lg border border-border-subtle px-3 text-sm" placeholder="بحث مادة بالاسم أو الرمز — Enter أو زر بحث">
        <button type="button" id="applySourceFiltersBtn" class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold">بحث</button>
        <button type="button" id="deleteAllUnlinkedBtn" class="h-9 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold hidden">حذف كل غير المرتبطة</button>
        <button type="button" id="pauseDeleteUnlinkedBtn" class="h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hidden">إيقاف الحذف</button>
        <button type="button" id="resumeDeleteUnlinkedBtn" class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف الحذف</button>
      </div>
      <div id="deleteUnlinkedProgressWrap" class="hidden mb-3">
        <div class="flex justify-between text-xs text-text-muted mb-1">
          <span id="deleteUnlinkedProgressLabel">0 / 0</span>
          <span id="deleteUnlinkedStatusLabel">جاري الحذف...</span>
        </div>
        <div class="h-2 rounded-full bg-surface-low overflow-hidden">
          <div id="deleteUnlinkedProgressBar" class="h-full bg-red-500 transition-all" style="width:0%"></div>
        </div>
      </div>
    </div>
    <div id="sourceCards" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"></div>
    <div class="mt-3 flex items-center justify-between">
      <button type="button" id="sourcePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
      <button type="button" id="sourceNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
    </div>
  </div>
</section>

<p id="linkStatus" class="text-sm text-text-muted"></p>

<div id="imageLightbox" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/85 p-4" role="dialog" aria-modal="true">
  <button type="button" id="lightboxCloseBtn" class="absolute top-4 left-4 h-10 w-10 rounded-full bg-white/90 text-lg font-bold" aria-label="إغلاق">×</button>
  <div class="max-w-[96vw] max-h-[92vh] flex flex-col items-center gap-3">
    <img id="lightboxImg" src="" alt="" class="max-w-full max-h-[78vh] object-contain rounded-lg shadow-2xl bg-white transition-transform duration-150">
    <p id="lightboxCaption" class="text-white text-sm text-center max-w-2xl"></p>
    <p class="text-white/70 text-xs text-center">انقر على الصورة للتكبير عند النقطة — انقر مجدداً للعودة</p>
  </div>
</div>
</div>
