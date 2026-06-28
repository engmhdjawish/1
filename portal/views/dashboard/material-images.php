<?php

declare(strict_types=1);

/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var array{pending: int, syncing: int, synced: int, failed: int, total: int} $syncStats */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var list<array<string, mixed>> $queue */
/** @var array{items: list<array<string, mixed>>, page: int, page_size: int, total_count: int, has_more: bool} $queuePage */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array<string, string> $settingsForm */

$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$syncStats = is_array($syncStats ?? null) ? $syncStats : ['pending' => 0, 'syncing' => 0, 'synced' => 0, 'failed' => 0, 'total' => 0];
$apiHealth = is_array($apiHealth ?? null) ? $apiHealth : ['ok' => false, 'message' => ''];
$queuePage = is_array($queuePage ?? null) ? $queuePage : ['items' => [], 'page' => 1, 'page_size' => 20, 'total_count' => 0, 'has_more' => false];
$queue = is_array($queue ?? null) ? $queue : ($queuePage['items'] ?? []);
$settingsForm = is_array($settingsForm ?? null) ? $settingsForm : [];
$statusLabels = [
    'pending' => ['label' => 'بانتظار الأمين', 'class' => 'bg-amber-100 text-amber-800'],
    'syncing' => ['label' => 'جاري المزامنة', 'class' => 'bg-blue-100 text-blue-800'],
    'synced' => ['label' => 'تمت على الأمين', 'class' => 'bg-green-100 text-green-800'],
    'failed' => ['label' => 'فشل', 'class' => 'bg-red-100 text-red-800'],
];
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد — رفع ومزامنة</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        <strong>①</strong> رفع على الموقع مع استئناف من المتصفح.
        <strong class="mr-1">②</strong> مزامنة الأمين صورة تلو الأخرى — يتوقف عند انقطاع الاتصال ويُستأنف لاحقاً.
        لربط الصور بالمواد استخدم
        <a href="/dashboard/material-image-links.php" class="text-primary font-bold hover:underline">صفحة الربط المستقلة</a>.
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
    <p class="text-xs text-text-muted mb-3">مجلد الموقع هو الأصل. «فحص الملفات المحلية» يقرأ أسماء الملفات من مجلد الموقع ويبحث عنها في سجل الأمين (API) بالاسم — عند التطابق يُربط GUID للعرض. إن اختلف المحتوى تُعلَّم بانتظار الرفع للأمين. الطابور يرسل صورة واحدة في كل مرة.</p>
    <div class="flex flex-wrap gap-2 mb-3">
      <button type="button" id="startSyncBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">بدء / استئناف المزامنة</button>
      <button type="button" id="pauseSyncBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">إيقاف مؤقت</button>
      <button type="button" id="retryFailedBtn" class="h-9 px-4 rounded-lg border border-amber-200 bg-amber-50 text-xs font-bold text-amber-900">إعادة المحاولة للفاشلة</button>
      <button type="button" id="scanLocalBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">فحص الملفات المحلية</button>
      <button type="button" id="purgeOrphanQueueBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">تنظيف الطابور</button>
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
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold">طابور المزامنة مع الأمين</h2>
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" id="deleteSelectedPendingBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold">حذف المحدد</button>
      <button type="button" id="deleteAllPendingBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold">حذف كل غير المزامنة</button>
      <button type="button" id="pauseDeletePendingBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hidden">إيقاف</button>
      <button type="button" id="resumeDeletePendingBtn" class="h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف</button>
      <span class="text-xs text-text-muted" id="syncQueueSummary"><?= (int) ($queuePage['total_count'] ?? $syncStats['total'] ?? 0) ?> عنصر</span>
    </div>
  </div>
  <div id="deletePendingProgressWrap" class="hidden px-4 pt-3">
    <div class="flex justify-between text-xs text-text-muted mb-1">
      <span id="deletePendingProgressLabel">0 / 0</span>
      <span id="deletePendingStatusLabel">جاري الحذف...</span>
    </div>
    <div class="h-2 rounded-full bg-surface-low overflow-hidden mb-3">
      <div id="deletePendingProgressBar" class="h-full bg-red-500 transition-all" style="width:0%"></div>
    </div>
  </div>
  <div class="overflow-auto">
    <table class="w-full text-sm min-w-[800px]">
      <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
        <tr>
          <th class="p-3 w-10"><input type="checkbox" id="syncQueueSelectAll" class="rounded" title="تحديد الكل في هذه الصفحة"></th>
          <th class="text-right p-3">الملف</th>
          <th class="text-right p-3">الحالة</th>
          <th class="text-right p-3">معرف الأمين</th>
          <th class="text-right p-3">ملاحظة</th>
          <th class="text-right p-3">إجراء</th>
        </tr>
      </thead>
      <tbody id="syncQueueBody" class="divide-y divide-border-subtle">
        <?php if ($queue === []): ?>
          <tr><td colspan="6" class="p-6 text-center text-text-muted">لا توجد عناصر في الطابور بعد. ارفع صوراً أو اضغط «فحص الملفات المحلية».</td></tr>
        <?php endif; ?>
        <?php foreach ($queue as $row): ?>
          <?php
            $status = (string) ($row['sync_status'] ?? 'pending');
            $meta = $statusLabels[$status] ?? $statusLabels['pending'];
            $canDeletePending = in_array($status, ['pending', 'failed'], true);
          ?>
          <tr>
            <td class="p-3 text-center">
              <?php if ($canDeletePending): ?>
                <input type="checkbox" class="sync-queue-select rounded" data-queue-id="<?= h((string) ($row['id'] ?? '')) ?>">
              <?php endif; ?>
            </td>
            <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($row['file_name'] ?? '')) ?></td>
            <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full <?= h($meta['class']) ?>"><?= h($meta['label']) ?></span></td>
            <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($row['amine_image_guid'] ?? '—')) ?></td>
            <td class="p-3 text-xs text-text-muted"><?= h((string) ($row['amine_sync_error_ar'] ?? '')) ?></td>
            <td class="p-3 text-xs">
              <?php if ($canDeletePending): ?>
                <button type="button" class="delete-pending-queue-btn h-7 px-2 rounded border border-red-200 bg-red-50 text-red-700 font-bold" data-queue-id="<?= h((string) ($row['id'] ?? '')) ?>" data-file-name="<?= h((string) ($row['file_name'] ?? '')) ?>">حذف محلي</button>
              <?php else: ?>
                <span class="text-text-muted">—</span>
              <?php endif; ?>
            </td>
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

  const startSyncBtn = document.getElementById('startSyncBtn');
  const pauseSyncBtn = document.getElementById('pauseSyncBtn');
  const retryFailedBtn = document.getElementById('retryFailedBtn');
  const scanLocalBtn = document.getElementById('scanLocalBtn');
  const purgeOrphanQueueBtn = document.getElementById('purgeOrphanQueueBtn');
  const deleteSelectedPendingBtn = document.getElementById('deleteSelectedPendingBtn');
  const deleteAllPendingBtn = document.getElementById('deleteAllPendingBtn');
  const pauseDeletePendingBtn = document.getElementById('pauseDeletePendingBtn');
  const resumeDeletePendingBtn = document.getElementById('resumeDeletePendingBtn');
  const deletePendingProgressWrap = document.getElementById('deletePendingProgressWrap');
  const deletePendingProgressLabel = document.getElementById('deletePendingProgressLabel');
  const deletePendingStatusLabel = document.getElementById('deletePendingStatusLabel');
  const deletePendingProgressBar = document.getElementById('deletePendingProgressBar');
  const syncQueueSelectAll = document.getElementById('syncQueueSelectAll');
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
  let pendingDeletableTotal = <?= (int) ($pendingDeletable ?? 0) ?>;
  let deletePendingRunning = false;
  let deletePendingPaused = false;
  let deletePendingProcessed = 0;
  let deletePendingFailed = 0;
  let deletePendingInitialTotal = 0;

  let queue = null;
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
    formData.append('action', 'upload');
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

  function updateDeletePendingControls() {
    const busy = deletePendingRunning || syncRunning || scanRunning;
    deleteSelectedPendingBtn && (deleteSelectedPendingBtn.disabled = busy);
    deleteAllPendingBtn && (deleteAllPendingBtn.disabled = busy);
    deleteAllPendingBtn?.classList.toggle('hidden', deletePendingRunning);
    pauseDeletePendingBtn?.classList.toggle('hidden', !deletePendingRunning || deletePendingPaused);
    resumeDeletePendingBtn?.classList.toggle('hidden', !deletePendingRunning || !deletePendingPaused);
    if (!deletePendingRunning) {
      deletePendingProgressWrap?.classList.add('hidden');
    }
  }

  function getSelectedPendingQueueIds() {
    return Array.from(document.querySelectorAll('.sync-queue-select:checked'))
      .map((el) => el.getAttribute('data-queue-id') || '')
      .filter((id) => id !== '');
  }

  function renderDeletePendingProgress() {
    const total = Math.max(1, deletePendingInitialTotal || 1);
    const done = deletePendingProcessed + deletePendingFailed;
    deletePendingProgressWrap?.classList.remove('hidden');
    if (deletePendingProgressLabel) {
      deletePendingProgressLabel.textContent = `${done} / ${deletePendingInitialTotal}`;
    }
    if (deletePendingProgressBar) {
      deletePendingProgressBar.style.width = `${Math.min(100, Math.round((done / total) * 100))}%`;
    }
    if (deletePendingStatusLabel) {
      deletePendingStatusLabel.textContent = deletePendingPaused
        ? 'متوقف مؤقتاً'
        : `تم ${deletePendingProcessed}${deletePendingFailed > 0 ? ` — فشل ${deletePendingFailed}` : ''}`;
    }
  }

  async function runDeletePendingLoop() {
    while (!deletePendingPaused) {
      let payload;
      try {
        const form = new FormData();
        form.append('action', 'delete-pending-next');
        form.append('queue_page', String(syncQueuePage));
        form.append('queue_page_size', String(syncQueuePageSize));
        const res = await fetch(API_URL, { method: 'POST', body: form });
        payload = await res.json();
      } catch {
        if (syncStatus) syncStatus.textContent = 'انقطع الاتصال — اضغط «استئناف».';
        deletePendingPaused = true;
        updateDeletePendingControls();
        return;
      }

      if (payload.deleted) deletePendingProcessed += 1;
      else if (!payload.done) deletePendingFailed += 1;

      if (typeof payload.pending_deletable === 'number') {
        pendingDeletableTotal = payload.pending_deletable;
      }
      if (deletePendingInitialTotal === 0 && pendingDeletableTotal > 0) {
        deletePendingInitialTotal = pendingDeletableTotal + deletePendingProcessed;
      }

      renderDeletePendingProgress();
      if (syncStatus) syncStatus.textContent = payload.message || syncStatus.textContent;
      if (payload.queue) renderSyncQueue(payload.queue, payload.sync || {});

      if (payload.done) {
        if (syncStatus) {
          syncStatus.textContent = `اكتمل الحذف — نجح ${deletePendingProcessed}${deletePendingFailed > 0 ? `، فشل ${deletePendingFailed}` : ''}.`;
        }
        deletePendingRunning = false;
        updateDeletePendingControls();
        return;
      }
    }
    updateDeletePendingControls();
  }

  async function deleteAllPending() {
    if (deletePendingRunning) return;
    if (!confirm('حذف كل الصور غير المزامنة من مجلد الموقع والطابور؟ (لن يُمس bm000)')) return;
    deletePendingRunning = true;
    deletePendingPaused = false;
    deletePendingProcessed = 0;
    deletePendingFailed = 0;
    deletePendingInitialTotal = pendingDeletableTotal;
    updateDeletePendingControls();
    renderDeletePendingProgress();
    await runDeletePendingLoop();
  }

  async function deleteSelectedPending() {
    const ids = getSelectedPendingQueueIds();
    if (ids.length === 0) {
      if (syncStatus) syncStatus.textContent = 'حدّد صوراً من الطابور أولاً.';
      return;
    }
    if (!confirm(`حذف ${ids.length} صورة محددة من الموقع والطابور؟`)) return;

    if (ids.length > 15) {
      deletePendingRunning = true;
      deletePendingPaused = false;
      deletePendingProcessed = 0;
      deletePendingFailed = 0;
      deletePendingInitialTotal = ids.length;
      updateDeletePendingControls();
      renderDeletePendingProgress();
      for (const id of ids) {
        if (deletePendingPaused) break;
        const form = new FormData();
        form.append('action', 'delete-pending-queue-item');
        form.append('queue_id', id);
        form.append('queue_page', String(syncQueuePage));
        form.append('queue_page_size', String(syncQueuePageSize));
        try {
          const res = await fetch(API_URL, { method: 'POST', body: form });
          const payload = await res.json();
          if (payload.ok) deletePendingProcessed += 1;
          else deletePendingFailed += 1;
          renderDeletePendingProgress();
          if (payload.queue) renderSyncQueue(payload.queue, payload.sync || {});
        } catch {
          deletePendingFailed += 1;
        }
      }
      deletePendingRunning = false;
      updateDeletePendingControls();
      if (syncStatus) syncStatus.textContent = `تم حذف ${deletePendingProcessed} صورة.`;
      return;
    }

    const form = new FormData();
    form.append('action', 'delete-pending-batch');
    ids.forEach((id) => form.append('queue_ids[]', id));
    form.append('queue_page', String(syncQueuePage));
    form.append('queue_page_size', String(syncQueuePageSize));
    const res = await fetch(API_URL, { method: 'POST', body: form });
    const payload = await res.json();
    if (syncStatus) syncStatus.textContent = payload.message || '';
    if (payload.queue) renderSyncQueue(payload.queue, payload.sync || {});
    if (syncQueueSelectAll) syncQueueSelectAll.checked = false;
  }

  function renderSyncQueue(queuePayload, sync) {
    if (!syncQueueSummary || !syncQueueBody) return;
    const meta = normalizeQueuePayload(queuePayload);
    const items = meta.items;
    updateSyncQueuePagination(meta);
    syncQueueSummary.textContent = `${syncQueueTotalCount} عنصر`;
    if (!items.length) {
      syncQueueBody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-text-muted">الطابور فارغ.</td></tr>';
      return;
    }
    syncQueueBody.innerHTML = items.map((row) => {
      const status = row.sync_status || 'pending';
      const meta = statusLabels[status] || statusLabels.pending;
      const canDelete = status === 'pending' || status === 'failed';
      const checkCell = canDelete
        ? `<input type="checkbox" class="sync-queue-select rounded" data-queue-id="${escapeHtml(row.id || '')}">`
        : '';
      const actionCell = canDelete
        ? `<button type="button" class="delete-pending-queue-btn h-7 px-2 rounded border border-red-200 bg-red-50 text-red-700 font-bold" data-queue-id="${escapeHtml(row.id || '')}" data-file-name="${escapeHtml(row.file_name || '')}">حذف محلي</button>`
        : '<span class="text-text-muted">—</span>';
      return `<tr>
        <td class="p-3 text-center">${checkCell}</td>
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.file_name || '')}</td>
        <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full ${meta.class}">${meta.label}</span></td>
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.amine_image_guid || '—')}</td>
        <td class="p-3 text-xs text-text-muted">${escapeHtml(row.amine_sync_error_ar || '')}</td>
        <td class="p-3 text-xs">${actionCell}</td>
      </tr>`;
    }).join('');
    if (syncQueueSelectAll) syncQueueSelectAll.checked = false;
  }

  async function deletePendingQueueItem(queueId, fileName) {
    const label = fileName || 'هذه الصورة';
    if (!confirm(`حذف «${label}» من مجلد الموقع وطابور المزامنة؟\n(لن يُمس سجل الأمين bm000 إن وُجد — استخدم تبويب الربط للحذف الكامل.)`)) {
      return;
    }
    const form = new FormData();
    form.append('action', 'delete-pending-queue-item');
    form.append('queue_id', queueId);
    form.append('queue_page', String(syncQueuePage));
    form.append('queue_page_size', String(syncQueuePageSize));
    const res = await fetch(API_URL, { method: 'POST', body: form });
    const payload = await res.json();
    if (syncStatus) {
      syncStatus.textContent = payload.message || (payload.ok ? 'تم الحذف.' : 'تعذر الحذف.');
    }
    if (payload.queue) {
      renderSyncQueue(payload.queue, payload.sync || {});
    }
    if (payload.sync) {
      renderSyncOverview({ sync: payload.sync, api: { ok: true }, queue: payload.queue });
    }
  }

  async function purgeOrphanQueue() {
    if (!confirm('إزالة سجلات الطابور التي فقدت ملفاتها على مجلد الموقع؟')) return;
    const form = new FormData();
    form.append('action', 'purge-orphan-queue');
    form.append('queue_page', String(syncQueuePage));
    form.append('queue_page_size', String(syncQueuePageSize));
    const res = await fetch(API_URL, { method: 'POST', body: form });
    const payload = await res.json();
    if (syncStatus) syncStatus.textContent = payload.message || 'تم التنظيف.';
    if (payload.queue) renderSyncQueue(payload.queue, payload.sync || {});
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
    if (syncProgressLabel) syncProgressLabel.textContent = 'فحص محلي: 0%';
    if (syncProgressBar) syncProgressBar.style.width = '1%';
    if (syncStatus) syncStatus.textContent = 'جاري تهيئة الفحص...';

    const totals = { added: 0, skipped: 0, reconciled: 0, content_changed: 0 };
    let offset = 0;
    let totalFiles = 0;
    let chunkSize = 15;

    const updateScanProgress = (current, total, label) => {
      const safeTotal = Math.max(1, total);
      const pct = Math.min(100, Math.round((current / safeTotal) * 100));
      if (syncProgressLabel) syncProgressLabel.textContent = label || `فحص محلي: ${current} / ${total}`;
      if (syncProgressBar) syncProgressBar.style.width = `${Math.max(1, pct)}%`;
    };

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
      chunkSize = Number(initData.init?.chunk_size || 15);
      const pendingQueue = Number(initData.init?.pending_queue_count || 0);

      if (pendingQueue > 0) {
        let reconcileOffset = 0;
        if (syncStatus) syncStatus.textContent = `مطابقة الطابور المعلّق (0 / ${pendingQueue})...`;
        while (reconcileOffset < pendingQueue) {
          updateScanProgress(reconcileOffset, pendingQueue, `مطابقة الطابور: ${reconcileOffset} / ${pendingQueue}`);
          const reconcileForm = new FormData();
          reconcileForm.append('action', 'reconcile-queue-chunk');
          reconcileForm.append('offset', String(reconcileOffset));
          reconcileForm.append('chunk_size', String(chunkSize));
          reconcileForm.append('queue_page', String(syncQueuePage));
          reconcileForm.append('queue_page_size', String(syncQueuePageSize));
          const reconcileRes = await fetch(API_URL, { method: 'POST', body: reconcileForm });
          const reconcileData = await reconcileRes.json();
          if (!reconcileData.ok) {
            if (syncStatus) syncStatus.textContent = reconcileData.message || 'توقّفت مطابقة الطابور.';
            return;
          }
          const reconcile = reconcileData.reconcile || {};
          totals.reconciled += Number(reconcile.reconciled || 0);
          totals.content_changed += Number(reconcile.content_changed || 0);
          reconcileOffset = Number(reconcile.offset || (reconcileOffset + chunkSize));
          if (reconcile.done) {
            break;
          }
        }
      }

      if (totalFiles === 0) {
        if (syncStatus) {
          syncStatus.textContent = initData.message || 'لا توجد ملفات محلية للفحص.';
        }
        await refreshOverview();
        return;
      }

      offset = 0;
      while (offset < totalFiles) {
        updateScanProgress(offset, totalFiles);
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
      updateScanProgress(totalFiles, totalFiles, `فحص محلي: ${totalFiles} / ${totalFiles}`);

      const finishForm = new FormData();
      finishForm.append('action', 'scan-local-finish');
      await fetch(API_URL, { method: 'POST', body: finishForm });

      await refreshOverview();
    } catch (error) {
      if (syncStatus) syncStatus.textContent = 'تعذّر إكمال الفحص المحلي.';
      const failForm = new FormData();
      failForm.append('action', 'scan-local-finish');
      await fetch(API_URL, { method: 'POST', body: failForm });
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
  syncQueueBody?.addEventListener('click', (event) => {
    const btn = event.target instanceof HTMLElement ? event.target.closest('.delete-pending-queue-btn') : null;
    if (!btn) return;
    const queueId = btn.getAttribute('data-queue-id') || '';
    const fileName = btn.getAttribute('data-file-name') || '';
    if (queueId) deletePendingQueueItem(queueId, fileName);
  });
  purgeOrphanQueueBtn?.addEventListener('click', purgeOrphanQueue);
  deleteSelectedPendingBtn?.addEventListener('click', deleteSelectedPending);
  deleteAllPendingBtn?.addEventListener('click', deleteAllPending);
  pauseDeletePendingBtn?.addEventListener('click', () => {
    deletePendingPaused = true;
    updateDeletePendingControls();
    if (syncStatus) syncStatus.textContent = 'متوقف مؤقتاً — اضغط «استئناف».';
  });
  resumeDeletePendingBtn?.addEventListener('click', async () => {
    if (!deletePendingRunning || !deletePendingPaused) return;
    deletePendingPaused = false;
    if (syncStatus) syncStatus.textContent = 'جاري الحذف...';
    await runDeletePendingLoop();
  });
  syncQueueSelectAll?.addEventListener('change', () => {
    const checked = !!syncQueueSelectAll.checked;
    document.querySelectorAll('.sync-queue-select').forEach((el) => {
      el.checked = checked;
    });
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
