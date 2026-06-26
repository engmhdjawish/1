<div id="productQuickView" class="fixed inset-0 z-[60] hidden" aria-hidden="true">
  <div id="productQuickViewBackdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
  <div class="absolute inset-0 flex items-end sm:items-center justify-center p-0 sm:p-4 pointer-events-none">
    <div
      id="productQuickViewPanel"
      class="pointer-events-auto relative w-full sm:max-w-2xl max-h-[92vh] sm:max-h-[88vh] bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl overflow-hidden flex flex-col touch-pan-y"
      role="dialog"
      aria-modal="true"
      aria-labelledby="productQuickViewTitle"
    >
      <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 shrink-0">
        <button type="button" id="productQuickViewPrev" class="w-10 h-10 rounded-full border border-gray-200 inline-flex items-center justify-center hover:border-primary disabled:opacity-30" aria-label="المادة السابقة">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>
        <div class="text-xs text-gray-500 font-bold" id="productQuickViewCounter"></div>
        <button type="button" id="productQuickViewNext" class="w-10 h-10 rounded-full border border-gray-200 inline-flex items-center justify-center hover:border-primary disabled:opacity-30" aria-label="المادة التالية">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>
        <button type="button" id="productQuickViewClose" class="absolute left-4 top-3 w-10 h-10 rounded-full bg-gray-100 inline-flex items-center justify-center hover:bg-gray-200" aria-label="إغلاق">
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
      </div>
      <div id="productQuickViewBody" class="overflow-y-auto flex-1 p-4 sm:p-6">
        <div class="text-center text-gray-500 py-16">جاري التحميل...</div>
      </div>
      <div class="shrink-0 border-t border-gray-100 px-4 py-3 flex flex-wrap gap-2 justify-between items-center bg-white">
        <a id="productQuickViewFullLink" href="/store.php" class="text-sm font-bold text-primary">فتح صفحة كاملة</a>
        <span class="text-xs text-gray-400 hidden sm:inline">اسحب يميناً/يساراً للتنقل</span>
      </div>
    </div>
  </div>
</div>
