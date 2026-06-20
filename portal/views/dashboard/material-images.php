<?php

declare(strict_types=1);

/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var array{pending: int, syncing: int, synced: int, failed: int, total: int} $syncStats */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var list<array<string, mixed>> $queue */
/** @var array{items: list<array<string, mixed>>, page: int, page_size: int, total_count: int, has_more: bool} $queuePage */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array<string, string> $settingsForm */

$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$syncStats = is_array($syncStats ?? null) ? $syncStats : ['pending' => 0, 'syncing' => 0, 'synced' => 0, 'failed' => 0, 'total' => 0];
$apiHealth = is_array($apiHealth ?? null) ? $apiHealth : ['ok' => false, 'message' => ''];
$queuePage = is_array($queuePage ?? null) ? $queuePage : ['items' => [], 'page' => 1, 'page_size' => 20, 'total_count' => 0, 'has_more' => false];
$queue = is_array($queue ?? null) ? $queue : ($queuePage['items'] ?? []);
$materialFilterOptions = is_array($materialFilterOptions ?? null) ? $materialFilterOptions : [];
$settingsForm = is_array($settingsForm ?? null) ? $settingsForm : [];
$statusLabels = [
    'pending' => ['label' => 'بانتظار الأمين', 'class' => 'bg-amber-100 text-amber-800'],
    'syncing' => ['label' => 'جاري المزامنة', 'class' => 'bg-blue-100 text-blue-800'],
    'synced' => ['label' => 'تمت على الأمين', 'class' => 'bg-green-100 text-green-800'],
    'failed' => ['label' => 'فشل', 'class' => 'bg-red-100 text-red-800'],
];
$materialTypeOptions = array_values($materialFilterOptions['materialTypes'] ?? []);
$ageCategoryOptions = array_values($materialFilterOptions['ageCategories'] ?? []);
$manufacturerOptions = array_values($materialFilterOptions['manufacturers'] ?? []);
$sizeRangeOptions = array_values($materialFilterOptions['sizeRanges'] ?? []);
$countryOriginOptions = array_values($materialFilterOptions['countryOfOrigins'] ?? []);
$groupOptions = array_values(array_filter($materialFilterOptions['groups'] ?? [], static fn ($row) => is_array($row)));
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد — رفع ومزامنة</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        <strong>①</strong> رفع على الموقع مع استئناف من المتصفح.
        <strong class="mr-1">②</strong> مزامنة الأمين صورة تلو الأخرى — يتوقف عند انقطاع الاتصال ويُستأنف لاحقاً.
        <strong class="mr-1">③</strong> تصفح مواد محددة للتحقق من توفر الصور.
      </p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs" id="statsPills">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        على الموقع: <strong id="statLocalCount"><?= (int) ($stats['local_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        ثامبنيل: <strong id="statThumbCount"><?= (int) ($stats['thumbnail_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        بانتظار الأمين: <strong id="statPendingCount"><?= (int) ($syncStats['pending'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        تمت: <strong id="statSyncedCount"><?= (int) ($syncStats['synced'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white" id="apiStatusPill">
        API الأمين:
        <?php if (!empty($apiHealth['ok'])): ?>
          <strong class="text-status-active">متصل</strong>
        <?php else: ?>
          <strong class="text-status-rejected">غير متصل</strong>
        <?php endif; ?>
      </span>
    </div>
  </div>
</section>

<?php if (!empty($flash)): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= ($flashType ?? 'success') === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h((string) $flash) ?>
  </p>
<?php endif; ?>

<section class="grid gap-4 lg:grid-cols-2 mb-6">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">① رفع على الموقع</h2>
    <p class="text-xs text-text-muted mb-3">اختر عدة صور — تُرفع واحدة تلو الأخرى مع شريط تقدم. عند انقطاع الاتصال أو إغلاق المتصفح يمكن الاستئناف من حيث توقفت.</p>

    <div id="uploadPickPanel" class="space-y-3">
      <input type="file" id="uploadPicker" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="block w-full text-sm">
      <button type="button" id="startUploadBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold" disabled>بدء الرفع</button>
    </div>

    <div id="uploadActivePanel" class="hidden space-y-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3">
      <p id="uploadActiveStatus" class="text-xs text-amber-900">جاري رفع الصور...</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" id="pauseUploadBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">إيقاف مؤقت</button>
        <button type="button" id="resumeUploadBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف</button>
        <button type="button" id="discardQueueBtn" class="h-9 px-4 rounded-lg border border-red-200 bg-white text-xs font-bold text-red-700">إلغاء</button>
      </div>
    </div>

    <div class="space-y-3 mt-3">
      <div id="overallProgressWrap" class="hidden">
        <div class="flex justify-between text-xs text-text-muted mb-1">
          <span id="overallProgressLabel">0 / 0</span>
          <span id="remainingLabel">متبقي: 0</span>
        </div>
        <div class="h-2 rounded-full bg-surface-low overflow-hidden">
          <div id="overallProgressBar" class="h-full bg-primary transition-all duration-300" style="width:0%"></div>
        </div>
      </div>
    </div>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-1">② مزامنة الأمين</h2>
    <p class="text-xs text-text-muted mb-3">يرسل الطابور صورة واحدة في كل مرة. «فحص الملفات المحلية» يقارن كل صورة مع الأمين عبر SHA256 والحجم — إن تطابقت تُعلَّم متزامنة دون رفع. عند فصل الاتصال يتوقف — اضغط «استئناف» عند عودة الأمين.</p>
    <div class="flex flex-wrap gap-2 mb-3">
      <button type="button" id="startSyncBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">بدء / استئناف المزامنة</button>
      <button type="button" id="pauseSyncBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">إيقاف مؤقت</button>
      <button type="button" id="retryFailedBtn" class="h-9 px-4 rounded-lg border border-amber-200 bg-amber-50 text-xs font-bold text-amber-900">إعادة المحاولة للفاشلة</button>
      <button type="button" id="scanLocalBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">فحص الملفات المحلية</button>
    </div>
    <div id="syncProgressWrap" class="hidden">
      <div class="flex justify-between text-xs text-text-muted mb-1">
        <span id="syncProgressLabel">مزامنة...</span>
      </div>
      <div class="h-2 rounded-full bg-surface-low overflow-hidden">
        <div id="syncProgressBar" class="h-full bg-emerald-600 transition-all" style="width:0%"></div>
      </div>
    </div>
    <p id="syncStatus" class="text-xs text-text-muted mt-2"><?= !empty($apiHealth['ok']) ? 'جاهز للمزامنة.' : h((string) ($apiHealth['message'] ?? 'الأمين غير متصل.')) ?></p>
  </article>
</section>

<section id="uploadQueueSection" class="hidden rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">طابور الرفع</h2>
    <span id="uploadQueueSummary" class="text-xs text-text-muted"></span>
  </div>
  <div id="uploadQueueList" class="divide-y divide-border-subtle max-h-[420px] overflow-auto"></div>
</section>

<article class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">طابور المزامنة مع الأمين</h2>
    <span class="text-xs text-text-muted" id="syncQueueSummary"><?= (int) ($queuePage['total_count'] ?? $syncStats['total'] ?? 0) ?> عنصر</span>
  </div>
  <div class="overflow-auto">
    <table class="w-full text-sm min-w-[720px]">
      <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
        <tr>
          <th class="text-right p-3">الملف</th>
          <th class="text-right p-3">الحالة</th>
          <th class="text-right p-3">معرف الأمين</th>
          <th class="text-right p-3">ملاحظة</th>
        </tr>
      </thead>
      <tbody id="syncQueueBody" class="divide-y divide-border-subtle">
        <?php if ($queue === []): ?>
          <tr><td colspan="4" class="p-6 text-center text-text-muted">لا توجد عناصر في الطابور بعد. ارفع صوراً أو اضغط «فحص الملفات المحلية».</td></tr>
        <?php endif; ?>
        <?php foreach ($queue as $row): ?>
          <?php $status = (string) ($row['sync_status'] ?? 'pending'); $meta = $statusLabels[$status] ?? $statusLabels['pending']; ?>
          <tr>
            <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($row['file_name'] ?? '')) ?></td>
            <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full <?= h($meta['class']) ?>"><?= h($meta['label']) ?></span></td>
            <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($row['amine_image_guid'] ?? '—')) ?></td>
            <td class="p-3 text-xs text-text-muted"><?= h((string) ($row['amine_sync_error_ar'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div id="syncQueuePagination" class="px-4 py-3 border-t border-border-subtle bg-surface-low/40 flex items-center justify-between gap-2">
    <button type="button" id="syncQueuePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
    <span class="text-xs text-text-muted" id="syncQueuePageLabel">صفحة 1</span>
    <button type="button" id="syncQueueNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
  </div>
</article>

<details class="rounded-xl border border-border-subtle bg-white p-4 mb-6">
  <summary class="font-bold cursor-pointer">مسارات التخزين (متقدم)</summary>
  <form method="post" class="grid gap-3 mt-4 lg:grid-cols-2">
    <input type="hidden" name="action" value="save_settings">
    <label class="text-xs block">
      <span class="text-text-muted">مجلد الصور على الموقع</span>
      <input name="material_images_dir" value="<?= h((string) ($settingsForm['material_images_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="اتركه فارغاً للافتراضي">
    </label>
    <label class="text-xs block">
      <span class="text-text-muted">مجلد الثامبنيل</span>
      <input name="material_thumbnails_dir" value="<?= h((string) ($settingsForm['material_thumbnails_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
    </label>
    <div class="lg:col-span-2 text-[11px] text-text-muted font-mono" dir="ltr">
      images: <?= h((string) ($paths['images_dir'] ?? '')) ?> · thumbs: <?= h((string) ($paths['thumbnails_dir'] ?? '')) ?>
    </div>
    <button class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold lg:col-span-2 lg:justify-self-start">حفظ المسارات</button>
  </form>
</details>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
    <h2 class="font-bold">تصفح صور المواد</h2>
    <p class="text-xs text-text-muted mt-0.5">ابحث عن مواد محددة وتحقق هل صورتها موجودة على سيرفر الموقع أم لا.</p>
  </div>

  <form id="browseFiltersForm" class="p-4 border-b border-border-subtle space-y-3">
    <?php if (!empty($materialFilterOptionsError)): ?>
      <p class="rounded-lg border border-amber-200 bg-amber-50 text-amber-700 px-3 py-2 text-xs"><?= h($materialFilterOptionsError) ?></p>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
      <label class="text-xs lg:col-span-2">
        <span class="text-text-muted block mb-1">بحث بالاسم أو الكود</span>
        <input type="search" id="browseSearch" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: صيف 2026">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">حالة الصورة على الموقع</span>
        <select id="browseLocalStatus" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="all">الكل</option>
          <option value="missing" selected>ناقصة على الموقع</option>
          <option value="on_site">موجودة على الموقع</option>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">صورة في الأمين</span>
        <select id="browseHasImage" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="1" selected>مع صورة فقط</option>
          <option value="">بدون قيد</option>
          <option value="0">بدون صورة</option>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">نوع المادة</span>
        <select id="browseMaterialTypes" multiple class="h-20 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <?php foreach ($materialTypeOptions as $option): ?>
            <option value="<?= h($option) ?>"><?= h($option) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">الفئة العمرية</span>
        <select id="browseAgeCategories" multiple class="h-20 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <?php foreach ($ageCategoryOptions as $option): ?>
            <option value="<?= h($option) ?>"><?= h($option) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">الشركة</span>
        <select id="browseManufacturers" multiple class="h-20 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <?php foreach ($manufacturerOptions as $option): ?>
            <option value="<?= h($option) ?>"><?= h($option) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">المجموعة</span>
        <select id="browseGroupGuids" multiple class="h-20 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <?php foreach ($groupOptions as $group): ?>
            <?php
              $groupGuid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
              $groupName = trim((string) ($group['name'] ?? $group['Name'] ?? $groupGuid));
            ?>
            <?php if ($groupGuid !== ''): ?>
              <option value="<?= h($groupGuid) ?>"><?= h($groupName) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button type="submit" id="browseSubmitBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">عرض النتائج</button>
      <button type="button" id="browseResetBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">مسح الفلاتر</button>
      <span class="text-xs text-text-muted" id="browseSummary">اختر الفلاتر ثم اضغط «عرض النتائج».</span>
    </div>
  </form>

  <div id="browseLoading" class="hidden px-4 py-8 text-center text-sm text-text-muted">جاري التحميل...</div>
  <div id="browseError" class="hidden px-4 py-3 text-sm text-red-700 bg-red-50 border-b border-red-100"></div>
  <div id="browseEmpty" class="hidden px-4 py-8 text-center text-sm text-text-muted">لا توجد مواد مطابقة للفلاتر.</div>

  <div id="browseResults" class="hidden divide-y divide-border-subtle"></div>

  <div id="browsePagination" class="hidden px-4 py-3 border-t border-border-subtle bg-surface-low/40 flex items-center justify-between gap-2">
    <button type="button" id="browsePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
    <span class="text-xs text-text-muted" id="browsePageLabel">صفحة 1</span>
    <button type="button" id="browseNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
  </div>
</section>

<script>
(() => {
  const API_URL = '/dashboard/material-images-api.php';
  const QUEUE_STORAGE_KEY = 'materialImages.uploadQueue';
  const DB_NAME = 'materialImagesUploadDb';
  const DB_STORE = 'files';
  const DB_VERSION = 1;

  const picker = document.getElementById('uploadPicker');
  const startBtn = document.getElementById('startUploadBtn');
  const pauseBtn = document.getElementById('pauseUploadBtn');
  const queueSection = document.getElementById('uploadQueueSection');
  const queueList = document.getElementById('uploadQueueList');
  const uploadQueueSummary = document.getElementById('uploadQueueSummary');
  const overallWrap = document.getElementById('overallProgressWrap');
  const overallLabel = document.getElementById('overallProgressLabel');
  const remainingLabel = document.getElementById('remainingLabel');
  const overallBar = document.getElementById('overallProgressBar');
  const uploadPickPanel = document.getElementById('uploadPickPanel');
  const uploadActivePanel = document.getElementById('uploadActivePanel');
  const uploadActiveStatus = document.getElementById('uploadActiveStatus');
  const resumeBtn = document.getElementById('resumeUploadBtn');
  const discardBtn = document.getElementById('discardQueueBtn');
  const browseForm = document.getElementById('browseFiltersForm');
  const browseSearch = document.getElementById('browseSearch');
  const browseLocalStatus = document.getElementById('browseLocalStatus');
  const browseHasImage = document.getElementById('browseHasImage');
  const browseMaterialTypes = document.getElementById('browseMaterialTypes');
  const browseAgeCategories = document.getElementById('browseAgeCategories');
  const browseManufacturers = document.getElementById('browseManufacturers');
  const browseGroupGuids = document.getElementById('browseGroupGuids');
  const browseResetBtn = document.getElementById('browseResetBtn');
  const browseSummary = document.getElementById('browseSummary');
  const browseLoading = document.getElementById('browseLoading');
  const browseError = document.getElementById('browseError');
  const browseEmpty = document.getElementById('browseEmpty');
  const browseResults = document.getElementById('browseResults');
  const browsePagination = document.getElementById('browsePagination');
  const browsePrevBtn = document.getElementById('browsePrevBtn');
  const browseNextBtn = document.getElementById('browseNextBtn');
  const browsePageLabel = document.getElementById('browsePageLabel');

  const startSyncBtn = document.getElementById('startSyncBtn');
  const pauseSyncBtn = document.getElementById('pauseSyncBtn');
  const retryFailedBtn = document.getElementById('retryFailedBtn');
  const scanLocalBtn = document.getElementById('scanLocalBtn');
  const syncProgressWrap = document.getElementById('syncProgressWrap');
  const syncProgressLabel = document.getElementById('syncProgressLabel');
  const syncProgressBar = document.getElementById('syncProgressBar');
  const syncStatus = document.getElementById('syncStatus');
  const syncQueueBody = document.getElementById('syncQueueBody');
  const syncQueueSummary = document.getElementById('syncQueueSummary');
  const syncQueuePagination = document.getElementById('syncQueuePagination');
  const syncQueuePrevBtn = document.getElementById('syncQueuePrevBtn');
  const syncQueueNextBtn = document.getElementById('syncQueueNextBtn');
  const syncQueuePageLabel = document.getElementById('syncQueuePageLabel');
  const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;

  let syncRunning = false;
  let syncPaused = false;
  let scanRunning = false;
  let autoSyncAfterUpload = true;
  let syncQueuePage = <?= (int) ($queuePage['page'] ?? 1) ?>;
  let syncQueuePageSize = <?= (int) ($queuePage['page_size'] ?? 20) ?>;
  let syncQueueHasMore = <?= !empty($queuePage['has_more']) ? 'true' : 'false' ?>;
  let syncQueueTotalCount = <?= (int) ($queuePage['total_count'] ?? 0) ?>;

  let queue = null;
  let browsePage = 1;
  let browseHasMore = false;
  let browseTotalCount = null;
  let paused = false;
  let uploading = false;
  let uploadSessionStarted = false;
  let dbPromise = null;

  function hasPendingUploadItems() {
    return queue?.items.some((item) => ['pending', 'uploading', 'error', 'missing'].includes(item.status)) ?? false;
  }

  function updateUploadControls() {
    const hasQueue = !!(queue && queue.items.length > 0);
    const pending = hasPendingUploadItems();
    const sessionActive = hasQueue && pending && (uploadSessionStarted || uploading);

    uploadPickPanel?.classList.toggle('hidden', sessionActive || uploading);
    uploadActivePanel?.classList.toggle('hidden', !(sessionActive || uploading));

    if (startBtn) {
      startBtn.disabled = !hasQueue || sessionActive || uploading;
    }

    pauseBtn?.classList.toggle('hidden', !uploading);
    resumeBtn?.classList.toggle('hidden', uploading || !pending);

    if (uploadActiveStatus) {
      if (uploading) {
        uploadActiveStatus.textContent = 'جاري رفع الصور...';
      } else if (paused) {
        uploadActiveStatus.textContent = 'متوقف مؤقتاً — يمكنك الاستئناف أو الإلغاء.';
      } else if (pending) {
        uploadActiveStatus.textContent = 'يوجد رفع غير مكتمل — يمكنك الاستئناف أو الإلغاء.';
      } else {
        uploadActiveStatus.textContent = '';
      }
    }
  }

  function uid() {
    return crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
  }

  function openDb() {
    if (!dbPromise) {
      dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
          const db = request.result;
          if (!db.objectStoreNames.contains(DB_STORE)) {
            db.createObjectStore(DB_STORE);
          }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
    }
    return dbPromise;
  }

  async function idbPut(key, blob) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(DB_STORE, 'readwrite');
      tx.objectStore(DB_STORE).put(blob, key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  async function idbGet(key) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(DB_STORE, 'readonly');
      const req = tx.objectStore(DB_STORE).get(key);
      req.onsuccess = () => resolve(req.result ?? null);
      req.onerror = () => reject(req.error);
    });
  }

  async function idbDelete(key) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(DB_STORE, 'readwrite');
      tx.objectStore(DB_STORE).delete(key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  async function idbClearQueue(queueId) {
    if (!queue) return;
    await Promise.all(queue.items.map((item) => idbDelete(`${queue.id}:${item.id}`)));
  }

  function saveQueue() {
    if (!queue) {
      localStorage.removeItem(QUEUE_STORAGE_KEY);
      return;
    }
    localStorage.setItem(QUEUE_STORAGE_KEY, JSON.stringify({
      id: queue.id,
      createdAt: queue.createdAt,
      items: queue.items.map((item) => ({
        id: item.id,
        name: item.name,
        size: item.size,
        status: item.status,
        progress: item.progress,
        error: item.error || '',
        replaced: !!item.replaced,
      })),
    }));
  }

  function loadQueueMeta() {
    const raw = localStorage.getItem(QUEUE_STORAGE_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  function statusLabel(status) {
    return {
      pending: 'بالانتظار',
      uploading: 'جاري الرفع',
      done: 'مكتمل',
      error: 'فشل',
      missing: 'بحاجة إعادة اختيار',
    }[status] || status;
  }

  function renderQueue() {
    if (!queue || queue.items.length === 0) {
      queueSection.classList.add('hidden');
      updateUploadControls();
      return;
    }

    queueSection.classList.remove('hidden');
    queueList.innerHTML = queue.items.map((item) => `
      <div class="p-3" data-item-id="${item.id}">
        <div class="flex items-center justify-between gap-2 mb-1">
          <div class="min-w-0">
            <div class="font-mono text-xs truncate" dir="ltr">${escapeHtml(item.name)}</div>
            <div class="text-[11px] text-text-muted">${formatBytes(item.size)} · ${statusLabel(item.status)}${item.error ? ' — ' + escapeHtml(item.error) : ''}</div>
          </div>
          <span class="text-xs font-bold ${item.status === 'done' ? 'text-status-active' : item.status === 'error' ? 'text-status-rejected' : 'text-text-muted'}">${Math.round(item.progress)}%</span>
        </div>
        <div class="h-1.5 rounded-full bg-surface-low overflow-hidden">
          <div class="h-full ${item.status === 'error' ? 'bg-status-rejected' : 'bg-primary'} transition-all duration-200" style="width:${item.progress}%"></div>
        </div>
      </div>
    `).join('');

    const done = queue.items.filter((item) => item.status === 'done').length;
    const total = queue.items.length;
    const remaining = queue.items.filter((item) => item.status === 'pending' || item.status === 'uploading' || item.status === 'error').length;
    uploadQueueSummary.textContent = `${done} مكتمل من ${total}`;
    overallWrap.classList.remove('hidden');
    overallLabel.textContent = `${done} / ${total}`;
    remainingLabel.textContent = `متبقي: ${remaining}`;
    overallBar.style.width = `${total ? Math.round((done / total) * 100) : 0}%`;
    updateUploadControls();
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatBytes(bytes) {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  async function buildQueueFromFiles(fileList) {
    const files = Array.from(fileList || []);
    if (files.length === 0) return;

    if (queue && uploading) {
      alert('انتظر حتى ينتهي الرفع الحالي أو أوقفه مؤقتاً.');
      return;
    }

    queue = {
      id: uid(),
      createdAt: new Date().toISOString(),
      items: files.map((file) => ({
        id: uid(),
        name: file.name,
        size: file.size,
        status: 'pending',
        progress: 0,
        error: '',
        replaced: false,
      })),
    };

    for (let i = 0; i < files.length; i++) {
      await idbPut(`${queue.id}:${queue.items[i].id}`, files[i]);
    }

    saveQueue();
    uploadSessionStarted = false;
    paused = false;
    renderQueue();
  }

  async function uploadItem(item) {
    const blob = await idbGet(`${queue.id}:${item.id}`);
    if (!(blob instanceof Blob)) {
      item.status = 'missing';
      item.error = 'الملف غير محفوظ في المتصفح — أعد اختياره';
      item.progress = 0;
      saveQueue();
      renderQueue();
      return false;
    }

    item.status = 'uploading';
    item.progress = 0;
    item.error = '';
    saveQueue();
    renderQueue();

    const formData = new FormData();
    formData.append('file', blob, item.name);

    return new Promise((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL);
      xhr.upload.onprogress = (event) => {
        if (!event.lengthComputable) return;
        item.progress = Math.max(1, Math.round((event.loaded / event.total) * 100));
        renderQueue();
      };
      xhr.onreadystatechange = () => {
        if (xhr.readyState !== 4) return;
        let payload = null;
        try {
          payload = JSON.parse(xhr.responseText || '{}');
        } catch {
          payload = { ok: false, message: 'استجابة غير صالحة من الخادم' };
        }

        if (xhr.status >= 200 && xhr.status < 300 && payload.ok) {
          item.status = 'done';
          item.progress = 100;
          item.replaced = !!payload.replaced;
          idbDelete(`${queue.id}:${item.id}`);
          resolve(true);
        } else {
          item.status = 'error';
          item.error = payload.message || `فشل الرفع (رمز ${xhr.status})`;
          item.progress = 0;
          resolve(false);
        }
        saveQueue();
        renderQueue();
      };
      xhr.onerror = () => {
        item.status = 'error';
        item.error = 'انقطع الاتصال أثناء الرفع';
        item.progress = 0;
        saveQueue();
        renderQueue();
        resolve(false);
      };
      xhr.send(formData);
    });
  }

  async function processQueue() {
    if (!queue || uploading) return;
    uploading = true;
    paused = false;
    uploadSessionStarted = true;
    updateUploadControls();

    for (const item of queue.items) {
      if (paused) break;
      if (item.status === 'done') continue;
      await uploadItem(item);
    }

    uploading = false;

    if (paused) {
      saveQueue();
      updateUploadControls();
      return;
    }

    const pending = hasPendingUploadItems();
    if (!pending) {
      await idbClearQueue(queue.id);
      localStorage.removeItem(QUEUE_STORAGE_KEY);
      uploadSessionStarted = false;
      updateUploadControls();
      await refreshStats();
      if (autoSyncAfterUpload) {
        syncPaused = false;
        processSyncQueue();
      }
      if (browseResults && !browseResults.classList.contains('hidden')) {
        await loadBrowseResults(browsePage);
      }
      setTimeout(() => {
        if (queue && queue.items.every((item) => item.status === 'done')) {
          queue = null;
          queueSection.classList.add('hidden');
          overallWrap.classList.add('hidden');
          picker.value = '';
          updateUploadControls();
        }
      }, 1500);
    } else {
      saveQueue();
      updateUploadControls();
    }
  }

  async function restoreQueueFromStorage() {
    const meta = loadQueueMeta();
    if (!meta || !Array.isArray(meta.items) || meta.items.length === 0) return;

    queue = {
      id: meta.id,
      createdAt: meta.createdAt,
      items: meta.items.map((item) => ({
        id: item.id,
        name: item.name,
        size: item.size,
        status: item.status === 'uploading' ? 'pending' : item.status,
        progress: item.status === 'done' ? 100 : 0,
        error: item.error || '',
        replaced: !!item.replaced,
      })),
    };

    for (const item of queue.items) {
      if (item.status === 'pending' || item.status === 'error') {
        const blob = await idbGet(`${queue.id}:${item.id}`);
        if (!(blob instanceof Blob)) {
          item.status = 'missing';
          item.error = 'أعد اختيار الملفات المفقودة';
        }
      }
    }

    const pending = hasPendingUploadItems();
    if (pending) {
      uploadSessionStarted = true;
    }
    saveQueue();
    renderQueue();
  }

  async function discardQueue() {
    if (!confirm('إلغاء الرفع وحذف الطابور؟')) {
      return;
    }
    if (queue) {
      await idbClearQueue(queue.id);
    }
    queue = null;
    paused = false;
    uploading = false;
    uploadSessionStarted = false;
    localStorage.removeItem(QUEUE_STORAGE_KEY);
    queueSection.classList.add('hidden');
    overallWrap.classList.add('hidden');
    picker.value = '';
    updateUploadControls();
  }

  async function refreshStats() {
    try {
      const response = await fetch(`${API_URL}?action=overview`);
      const payload = await response.json();
      if (!payload.ok) return;

      const statLocal = document.getElementById('statLocalCount');
      const statThumb = document.getElementById('statThumbCount');
      if (statLocal) statLocal.textContent = String(payload.local?.local_count ?? 0);
      if (statThumb) statThumb.textContent = String(payload.local?.thumbnail_count ?? 0);
      renderSyncOverview(payload);
    } catch {
      // ignore
    }
  }

  function renderSyncOverview(data) {
    const pendingEl = document.getElementById('statPendingCount');
    const syncedEl = document.getElementById('statSyncedCount');
    if (pendingEl) pendingEl.textContent = String(data.sync?.pending ?? 0);
    if (syncedEl) syncedEl.textContent = String(data.sync?.synced ?? 0);
    const apiPill = document.getElementById('apiStatusPill');
    if (apiPill) {
      apiPill.innerHTML = data.api?.ok
        ? 'API الأمين: <strong class="text-status-active">متصل</strong>'
        : 'API الأمين: <strong class="text-status-rejected">غير متصل</strong>';
    }
    renderSyncQueue(data.queue || {}, data.sync || {});
  }

  function normalizeQueuePayload(queuePayload) {
    if (Array.isArray(queuePayload)) {
      return {
        items: queuePayload,
        page: syncQueuePage,
        page_size: syncQueuePageSize,
        total_count: queuePayload.length,
        has_more: false,
      };
    }
    return {
      items: queuePayload?.items || [],
      page: queuePayload?.page || syncQueuePage,
      page_size: queuePayload?.page_size || syncQueuePageSize,
      total_count: queuePayload?.total_count ?? (queuePayload?.items?.length || 0),
      has_more: !!queuePayload?.has_more,
    };
  }

  function updateSyncQueuePagination(meta) {
    syncQueuePage = meta.page;
    syncQueuePageSize = meta.page_size;
    syncQueueTotalCount = meta.total_count;
    syncQueueHasMore = meta.has_more;
    if (syncQueuePageLabel) {
      syncQueuePageLabel.textContent = `صفحة ${syncQueuePage} — ${syncQueueTotalCount} عنصر`;
    }
    if (syncQueuePrevBtn) syncQueuePrevBtn.disabled = syncQueuePage <= 1;
    if (syncQueueNextBtn) syncQueueNextBtn.disabled = !syncQueueHasMore;
  }

  function renderSyncQueue(queuePayload, sync) {
    if (!syncQueueSummary || !syncQueueBody) return;
    const meta = normalizeQueuePayload(queuePayload);
    const items = meta.items;
    updateSyncQueuePagination(meta);
    syncQueueSummary.textContent = `${syncQueueTotalCount} عنصر`;
    if (!items.length) {
      syncQueueBody.innerHTML = '<tr><td colspan="4" class="p-6 text-center text-text-muted">الطابور فارغ.</td></tr>';
      return;
    }
    syncQueueBody.innerHTML = items.map((row) => {
      const status = row.sync_status || 'pending';
      const meta = statusLabels[status] || statusLabels.pending;
      return `<tr>
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.file_name || '')}</td>
        <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full ${meta.class}">${meta.label}</span></td>
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.amine_image_guid || '—')}</td>
        <td class="p-3 text-xs text-text-muted">${escapeHtml(row.amine_sync_error_ar || '')}</td>
      </tr>`;
    }).join('');
  }

  async function refreshOverview() {
    try {
      const response = await fetch(`${API_URL}?action=overview&queue_page=${syncQueuePage}&queue_page_size=${syncQueuePageSize}`);
      const payload = await response.json();
      if (payload.ok) {
        renderSyncOverview(payload);
      }
      return payload;
    } catch {
      return { ok: false };
    }
  }

  async function loadSyncQueuePage(page) {
    syncQueuePage = Math.max(1, page);
    try {
      const response = await fetch(`${API_URL}?action=queue&page=${syncQueuePage}&page_size=${syncQueuePageSize}`);
      const payload = await response.json();
      if (payload.ok) {
        renderSyncQueue(payload, payload.sync || {});
      }
      return payload;
    } catch {
      return { ok: false };
    }
  }

  async function syncNextOnce() {
    const form = new FormData();
    form.append('action', 'sync-next');
    form.append('queue_page', String(syncQueuePage));
    form.append('queue_page_size', String(syncQueuePageSize));
    const res = await fetch(API_URL, { method: 'POST', body: form });
    return res.json();
  }

  async function processSyncQueue() {
    if (syncRunning) return;
    syncRunning = true;
    syncPaused = false;
    syncProgressWrap?.classList.remove('hidden');
    if (syncStatus) syncStatus.textContent = 'جاري مزامنة الأمين...';

    while (!syncPaused) {
      let result;
      try {
        result = await syncNextOnce();
      } catch {
        if (syncStatus) syncStatus.textContent = 'انقطع الاتصال — سيتم الاستئناف عند الضغط على «استئناف».';
        break;
      }

      if (result.sync) {
        const total = Math.max(1, (result.sync.synced ?? 0) + (result.sync.pending ?? 0) + (result.sync.failed ?? 0));
        const done = result.sync.synced ?? 0;
        if (syncProgressBar) syncProgressBar.style.width = `${Math.round((done / total) * 100)}%`;
        if (syncProgressLabel) syncProgressLabel.textContent = `تم ${done} — متبقي ${(result.sync.pending ?? 0) + (result.sync.failed ?? 0)}`;
      }

      if (result.queue || result.sync) {
        renderSyncQueue(result.queue || {}, result.sync || {});
        renderSyncOverview({ sync: result.sync, api: { ok: !result.offline }, queue: result.queue });
      }

      if (result.done) {
        if (syncStatus) syncStatus.textContent = result.message || 'اكتملت المزامنة.';
        break;
      }

      if (result.offline || !result.ok) {
        if (syncStatus) syncStatus.textContent = result.message || 'توقف بسبب انقطاع الأمين — اضغط «استئناف» لاحقاً.';
        break;
      }

      if (syncStatus) syncStatus.textContent = result.message || 'تمت مزامنة صورة.';
      await new Promise((resolve) => setTimeout(resolve, 400));
    }

    syncRunning = false;
    await refreshOverview();
  }

  function selectedValues(selectEl) {
    if (!selectEl) return [];
    return Array.from(selectEl.selectedOptions).map((option) => option.value).filter(Boolean);
  }

  function buildBrowseParams(page) {
    const params = new URLSearchParams();
    params.set('action', 'browse');
    params.set('page', String(page));
    params.set('page_size', '24');
    const search = browseSearch?.value.trim() || '';
    if (search) params.set('search', search);
    if (browseLocalStatus?.value) params.set('local_status', browseLocalStatus.value);
    if (browseHasImage?.value !== '') params.set('has_image', browseHasImage.value);
    selectedValues(browseMaterialTypes).forEach((value) => params.append('material_types[]', value));
    selectedValues(browseAgeCategories).forEach((value) => params.append('age_categories[]', value));
    selectedValues(browseManufacturers).forEach((value) => params.append('manufacturers[]', value));
    selectedValues(browseGroupGuids).forEach((value) => params.append('group_guids[]', value));
    return params;
  }

  function renderBrowseItem(item) {
    const name = item.name || 'بدون اسم';
    const code = item.material_code ? ` (${item.material_code})` : '';
    const meta = [item.material_type, item.manufacturer, item.age_category].filter(Boolean).join(' · ');
    const statusClass = item.has_local ? 'text-status-active' : 'text-status-pending';
    const statusLabel = item.has_local ? 'موجودة على الموقع' : (item.image_guid ? 'ناقصة على الموقع' : 'بدون صورة في الأمين');
    const preview = item.preview_url || '';
    const fileName = item.stored_file_name || '';

    return `
      <article class="p-4 flex flex-col sm:flex-row gap-3 sm:items-center">
        <div class="shrink-0">
          ${preview
            ? `<img src="${escapeHtml(preview)}" alt="" class="browse-preview w-20 h-20 rounded-xl object-cover bg-surface-low border border-border-subtle" loading="lazy">`
            : '<div class="w-20 h-20 rounded-xl bg-surface-low border border-border-subtle flex items-center justify-center text-[11px] text-text-muted">لا صورة</div>'}
        </div>
        <div class="flex-1 min-w-0">
          <h3 class="font-bold text-sm truncate">${escapeHtml(name)}${escapeHtml(code)}</h3>
          ${meta ? `<p class="text-xs text-text-muted mt-0.5">${escapeHtml(meta)}</p>` : ''}
          ${fileName ? `<p class="text-[11px] font-mono text-text-muted mt-1 truncate" dir="ltr">${escapeHtml(fileName)}</p>` : ''}
        </div>
        <div class="text-left sm:text-center shrink-0">
          <span class="text-xs font-bold ${statusClass}">${escapeHtml(statusLabel)}</span>
        </div>
      </article>
    `;
  }

  function updateBrowsePagination() {
    if (!browsePagination || !browsePageLabel || !browsePrevBtn || !browseNextBtn) return;
    const totalText = browseTotalCount === null ? '' : ` من ${browseTotalCount}`;
    browsePageLabel.textContent = `صفحة ${browsePage}${totalText}`;
    browsePrevBtn.disabled = browsePage <= 1;
    browseNextBtn.disabled = !browseHasMore;
    browsePagination.classList.toggle('hidden', browseResults?.classList.contains('hidden'));
  }

  async function loadBrowseResults(page = 1) {
    browsePage = Math.max(1, page);
    browseLoading?.classList.remove('hidden');
    browseError?.classList.add('hidden');
    browseEmpty?.classList.add('hidden');
    browseResults?.classList.add('hidden');
    browsePagination?.classList.add('hidden');

    try {
      const response = await fetch(`${API_URL}?${buildBrowseParams(browsePage).toString()}`);
      const payload = await response.json();
      browseLoading?.classList.add('hidden');

      if (!payload.ok) {
        if (browseError) {
          browseError.textContent = payload.message || 'تعذر تحميل النتائج.';
          browseError.classList.remove('hidden');
        }
        return;
      }

      const items = payload.items || [];
      browseHasMore = !!payload.has_more;
      browseTotalCount = payload.total_count ?? null;

      if (items.length === 0) {
        browseEmpty?.classList.remove('hidden');
        if (browseSummary) browseSummary.textContent = 'لا توجد مواد مطابقة.';
        return;
      }

      if (browseResults) {
        browseResults.innerHTML = items.map(renderBrowseItem).join('');
        browseResults.classList.remove('hidden');
      }
      if (browseSummary) {
        const countLabel = browseTotalCount === null
          ? `${items.length} مادة في هذه الصفحة`
          : `${items.length} من ${browseTotalCount} مادة`;
        browseSummary.textContent = countLabel;
      }
      updateBrowsePagination();
    } catch {
      browseLoading?.classList.add('hidden');
      if (browseError) {
        browseError.textContent = 'تعذر الاتصال بالخادم.';
        browseError.classList.remove('hidden');
      }
    }
  }

  function resetBrowseFilters() {
    if (browseSearch) browseSearch.value = '';
    if (browseLocalStatus) browseLocalStatus.value = 'missing';
    if (browseHasImage) browseHasImage.value = '1';
    [browseMaterialTypes, browseAgeCategories, browseManufacturers, browseGroupGuids].forEach((selectEl) => {
      if (!selectEl) return;
      Array.from(selectEl.options).forEach((option) => { option.selected = false; });
    });
    browseResults?.classList.add('hidden');
    browsePagination?.classList.add('hidden');
    browseEmpty?.classList.add('hidden');
    browseError?.classList.add('hidden');
    if (browseSummary) browseSummary.textContent = 'اختر الفلاتر ثم اضغط «عرض النتائج».';
  }

  picker?.addEventListener('change', async () => {
    await buildQueueFromFiles(picker.files);
  });

  startBtn?.addEventListener('click', () => processQueue());
  pauseBtn?.addEventListener('click', () => {
    paused = true;
    updateUploadControls();
  });
  resumeBtn?.addEventListener('click', () => processQueue());
  discardBtn?.addEventListener('click', () => discardQueue());

  browseForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    loadBrowseResults(1);
  });
  browseResetBtn?.addEventListener('click', () => resetBrowseFilters());
  browsePrevBtn?.addEventListener('click', () => {
    if (browsePage > 1) loadBrowseResults(browsePage - 1);
  });
  browseNextBtn?.addEventListener('click', () => {
    if (browseHasMore) loadBrowseResults(browsePage + 1);
  });

  startSyncBtn?.addEventListener('click', () => {
    syncPaused = false;
    processSyncQueue();
  });

  pauseSyncBtn?.addEventListener('click', () => {
    syncPaused = true;
    if (syncStatus) syncStatus.textContent = 'مزامنة الأمين متوقفة مؤقتاً.';
  });

  retryFailedBtn?.addEventListener('click', async () => {
    const form = new FormData();
    form.append('action', 'retry-failed');
    form.append('queue_page', String(syncQueuePage));
    form.append('queue_page_size', String(syncQueuePageSize));
    const res = await fetch(API_URL, { method: 'POST', body: form });
    const data = await res.json();
    if (syncStatus) syncStatus.textContent = data.message || '';
    await refreshOverview();
  });

  scanLocalBtn?.addEventListener('click', async () => {
    if (scanRunning) {
      return;
    }

    scanRunning = true;
    scanLocalBtn.disabled = true;
    startSyncBtn && (startSyncBtn.disabled = true);
    syncProgressWrap?.classList.remove('hidden');

    const totals = { added: 0, skipped: 0, reconciled: 0, content_changed: 0 };
    let offset = 0;
    let totalFiles = 0;
    let chunkSize = 100;

    try {
      const initForm = new FormData();
      initForm.append('action', 'scan-local-init');
      const initRes = await fetch(API_URL, { method: 'POST', body: initForm });
      const initData = await initRes.json();
      if (!initData.ok) {
        if (syncStatus) syncStatus.textContent = initData.message || 'تعذّر بدء الفحص.';
        return;
      }

      totalFiles = Number(initData.init?.total_files || 0);
      chunkSize = Number(initData.init?.chunk_size || 100);
      totals.reconciled += Number(initData.init?.reconcile?.reconciled || 0);
      totals.content_changed += Number(initData.init?.reconcile?.content_changed || 0);

      if (totalFiles === 0) {
        if (syncStatus) {
          syncStatus.textContent = initData.message || 'لا توجد ملفات محلية للفحص.';
        }
        await refreshOverview();
        return;
      }

      while (offset < totalFiles) {
        const pct = Math.min(100, Math.round((offset / totalFiles) * 100));
        if (syncProgressLabel) {
          syncProgressLabel.textContent = `فحص محلي: ${offset} / ${totalFiles}`;
        }
        if (syncProgressBar) {
          syncProgressBar.style.width = `${pct}%`;
        }
        if (syncStatus) {
          syncStatus.textContent = `جاري فحص الملفات المحلية (${offset} / ${totalFiles})...`;
        }

        const form = new FormData();
        form.append('action', 'scan-local-chunk');
        form.append('offset', String(offset));
        form.append('chunk_size', String(chunkSize));
        form.append('queue_page', String(syncQueuePage));
        form.append('queue_page_size', String(syncQueuePageSize));

        const res = await fetch(API_URL, { method: 'POST', body: form });
        const data = await res.json();
        if (!data.ok) {
          if (syncStatus) syncStatus.textContent = data.message || 'توقّف الفحص بسبب خطأ.';
          break;
        }

        const scan = data.scan || {};
        totals.added += Number(scan.added || 0);
        totals.skipped += Number(scan.skipped || 0);
        totals.reconciled += Number(scan.reconciled || 0);
        totals.content_changed += Number(scan.content_changed || 0);
        offset = Number(scan.offset || (offset + chunkSize));

        if (scan.done) {
          break;
        }
      }

      const parts = [];
      if (totals.reconciled > 0) parts.push(`تطابقت ${totals.reconciled} مع الأمين`);
      if (totals.added > 0) parts.push(`أُضيف ${totals.added} للطابور`);
      if (totals.content_changed > 0) parts.push(`${totals.content_changed} بمحتوى مختلف`);
      if (totals.skipped > 0) parts.push(`تُخطّى ${totals.skipped}`);
      if (syncStatus) {
        syncStatus.textContent = parts.length > 0
          ? `اكتمل الفحص: ${parts.join('، ')}.`
          : 'اكتمل الفحص — لا تغييرات.';
      }
      if (syncProgressLabel) syncProgressLabel.textContent = `فحص محلي: ${totalFiles} / ${totalFiles}`;
      if (syncProgressBar) syncProgressBar.style.width = '100%';

      await refreshOverview();
    } catch (error) {
      if (syncStatus) syncStatus.textContent = 'تعذّر إكمال الفحص المحلي.';
    } finally {
      scanRunning = false;
      scanLocalBtn.disabled = false;
      startSyncBtn && (startSyncBtn.disabled = false);
    }
  });

  syncQueuePrevBtn?.addEventListener('click', () => {
    if (syncQueuePage > 1) loadSyncQueuePage(syncQueuePage - 1);
  });
  syncQueueNextBtn?.addEventListener('click', () => {
    if (syncQueueHasMore) loadSyncQueuePage(syncQueuePage + 1);
  });

  updateSyncQueuePagination(normalizeQueuePayload({
    items: <?= json_encode($queue, JSON_UNESCAPED_UNICODE) ?>,
    page: <?= (int) ($queuePage['page'] ?? 1) ?>,
    page_size: <?= (int) ($queuePage['page_size'] ?? 20) ?>,
    total_count: <?= (int) ($queuePage['total_count'] ?? 0) ?>,
    has_more: <?= !empty($queuePage['has_more']) ? 'true' : 'false' ?>,
  }));

  restoreQueueFromStorage();
  refreshOverview();
})();
</script>
