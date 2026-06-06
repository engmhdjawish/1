<?php

declare(strict_types=1);

/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var list<array<string, mixed>> $files */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array<string, string> $settingsForm */

$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$files = is_array($files ?? null) ? $files : [];
$settingsForm = is_array($settingsForm ?? null) ? $settingsForm : [];
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد على الموقع</h1>
      <p class="text-sm text-text-muted mt-1">مخزون الملفات المحلية التي يعرضها الموقع للزوار — يجب أن تطابق أسماء ملفات الأمين.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs" id="statsPills">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        على الموقع: <strong id="statLocalCount"><?= (int) ($stats['local_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        ثامبنيل: <strong id="statThumbCount"><?= (int) ($stats['thumbnail_count'] ?? 0) ?></strong>
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
    <h2 class="font-bold mb-2">مسارات التخزين</h2>
    <p class="text-xs text-text-muted mb-3">اترك الحقول فارغة لاستخدام <code dir="ltr">portal/storage/material-images</code>.</p>
    <form method="post" class="space-y-3">
      <input type="hidden" name="action" value="save_settings">
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الصور الأصلية</span>
        <input name="material_images_dir" value="<?= h((string) ($settingsForm['material_images_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
      </label>
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الثامبنيل</span>
        <input name="material_thumbnails_dir" value="<?= h((string) ($settingsForm['material_thumbnails_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
      </label>
      <button class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">حفظ المسارات</button>
    </form>
    <dl class="mt-4 text-[11px] text-text-muted space-y-1">
      <div><dt class="inline font-bold">الصور:</dt> <dd class="inline font-mono" dir="ltr"><?= h((string) ($paths['images_dir'] ?? '')) ?></dd></div>
      <div><dt class="inline font-bold">الثامبنيل:</dt> <dd class="inline font-mono" dir="ltr"><?= h((string) ($paths['thumbnails_dir'] ?? '')) ?></dd></div>
    </dl>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">رفع متسلسل مع استئناف</h2>
    <p class="text-xs text-text-muted mb-3">اختر عدة صور — تُرفع واحدة تلو الأخرى مع شريط تقدم. عند انقطاع الاتصال أو إغلاق المتصفح يمكن الاستئناف من حيث توقفت.</p>

    <div id="resumeBanner" class="hidden mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span id="resumeBannerText">يوجد رفع غير مكتمل.</span>
        <div class="flex gap-2">
          <button type="button" id="resumeUploadBtn" class="rounded-lg bg-primary text-white px-3 py-1.5 font-bold">استئناف</button>
          <button type="button" id="discardQueueBtn" class="rounded-lg border border-border-subtle bg-white px-3 py-1.5 font-bold">إلغاء الطابور</button>
        </div>
      </div>
    </div>

    <div class="space-y-3">
      <input type="file" id="uploadPicker" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="block w-full text-sm">
      <div class="flex flex-wrap gap-2">
        <button type="button" id="startUploadBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold" disabled>بدء الرفع</button>
        <button type="button" id="pauseUploadBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold hidden">إيقاف مؤقت</button>
      </div>
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
</section>

<section id="uploadQueueSection" class="hidden rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">طابور الرفع</h2>
    <span id="queueSummary" class="text-xs text-text-muted"></span>
  </div>
  <div id="uploadQueueList" class="divide-y divide-border-subtle max-h-[420px] overflow-auto"></div>
</section>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
    <div>
      <h2 class="font-bold">مخزون الموقع</h2>
      <p class="text-xs text-text-muted mt-0.5">هذه قائمة الملفات الموجودة فعلياً على سيرفر الموقع — ما يظهر هنا هو ما يمكن للموقع عرضه محلياً.</p>
    </div>
    <div class="flex gap-2 items-center">
      <input type="search" id="localFileSearch" placeholder="بحث بالاسم..." class="h-8 rounded-lg border border-border-subtle px-3 text-xs">
      <span class="text-xs text-text-muted whitespace-nowrap" id="localFileCount"><?= count($files) ?> ملف</span>
    </div>
  </div>
  <div class="overflow-auto">
    <table class="w-full text-sm min-w-[920px]" id="localFilesTable">
      <thead class="text-text-muted border-b border-border-subtle">
        <tr>
          <th class="text-right p-3">معاينة</th>
          <th class="text-right p-3">اسم الملف</th>
          <th class="text-right p-3">الحجم</th>
          <th class="text-right p-3">آخر تعديل</th>
          <th class="text-right p-3">الحالة</th>
        </tr>
      </thead>
      <tbody id="localFilesBody">
        <?php if ($files === []): ?>
          <tr id="localFilesEmpty"><td colspan="5" class="p-4 text-sm text-text-muted">لا توجد صور على سيرفر الموقع بعد.</td></tr>
        <?php else: ?>
          <?php foreach ($files as $file): ?>
            <tr class="border-b border-border-subtle last:border-0 local-file-row" data-name="<?= h(strtolower((string) ($file['file_name'] ?? ''))) ?>">
              <td class="p-3">
                <img
                  src="<?= h((string) ($file['preview_url'] ?? '')) ?>"
                  data-thumb="<?= h((string) ($file['preview_thumb_url'] ?? '')) ?>"
                  alt=""
                  class="local-preview w-12 h-12 rounded-lg object-cover bg-surface-low border border-border-subtle"
                  loading="lazy"
                >
              </td>
              <td class="p-3 font-mono text-xs" dir="ltr">
                <a href="<?= h((string) ($file['preview_url'] ?? '')) ?>" target="_blank" class="text-primary hover:underline"><?= h((string) ($file['file_name'] ?? '')) ?></a>
              </td>
              <td class="p-3"><?= h(number_format(((int) ($file['size_bytes'] ?? 0)) / 1024, 1)) ?> KB</td>
              <td class="p-3 text-text-muted"><?= h((string) ($file['modified_at'] ?? '')) ?></td>
              <td class="p-3">
                <?php if (!empty($file['has_thumbnail'])): ?>
                  <span class="text-status-active text-xs font-bold">جاهزة للعرض</span>
                <?php else: ?>
                  <span class="text-status-pending text-xs font-bold">بدون ثامبنيل</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
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
  const queueSummary = document.getElementById('queueSummary');
  const overallWrap = document.getElementById('overallProgressWrap');
  const overallLabel = document.getElementById('overallProgressLabel');
  const remainingLabel = document.getElementById('remainingLabel');
  const overallBar = document.getElementById('overallProgressBar');
  const resumeBanner = document.getElementById('resumeBanner');
  const resumeBannerText = document.getElementById('resumeBannerText');
  const resumeBtn = document.getElementById('resumeUploadBtn');
  const discardBtn = document.getElementById('discardQueueBtn');
  const localSearch = document.getElementById('localFileSearch');

  let queue = null;
  let paused = false;
  let uploading = false;
  let dbPromise = null;

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
      startBtn.disabled = true;
      return;
    }

    queueSection.classList.remove('hidden');
    startBtn.disabled = uploading;
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
    queueSummary.textContent = `${done} مكتمل من ${total}`;
    overallWrap.classList.remove('hidden');
    overallLabel.textContent = `${done} / ${total}`;
    remainingLabel.textContent = `متبقي: ${remaining}`;
    overallBar.style.width = `${total ? Math.round((done / total) * 100) : 0}%`;
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
    renderQueue();
    paused = false;
    pauseBtn.classList.add('hidden');
    startBtn.disabled = false;
    resumeBanner.classList.add('hidden');
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
    startBtn.disabled = true;
    pauseBtn.classList.remove('hidden');

    for (const item of queue.items) {
      if (paused) break;
      if (item.status === 'done') continue;
      await uploadItem(item);
    }

    uploading = false;
    startBtn.disabled = false;
    pauseBtn.classList.add('hidden');

    const pending = queue.items.some((item) => ['pending', 'uploading', 'error', 'missing'].includes(item.status));
    if (!pending) {
      await idbClearQueue(queue.id);
      localStorage.removeItem(QUEUE_STORAGE_KEY);
      resumeBanner.classList.add('hidden');
      await refreshLocalFiles();
      setTimeout(() => {
        if (queue && queue.items.every((item) => item.status === 'done')) {
          queue = null;
          queueSection.classList.add('hidden');
          overallWrap.classList.add('hidden');
          picker.value = '';
        }
      }, 1500);
    } else {
      saveQueue();
      showResumeBanner();
    }
  }

  function showResumeBanner() {
    if (!queue) return;
    const remaining = queue.items.filter((item) => item.status !== 'done').length;
    if (remaining <= 0) {
      resumeBanner.classList.add('hidden');
      return;
    }
    resumeBannerText.textContent = `يوجد ${remaining} صورة لم يكتمل رفعها. يمكنك الاستئناف من حيث توقفت.`;
    resumeBanner.classList.remove('hidden');
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

    saveQueue();
    renderQueue();
    showResumeBanner();
  }

  async function discardQueue() {
    if (queue) {
      await idbClearQueue(queue.id);
    }
    queue = null;
    paused = false;
    uploading = false;
    localStorage.removeItem(QUEUE_STORAGE_KEY);
    resumeBanner.classList.add('hidden');
    queueSection.classList.add('hidden');
    overallWrap.classList.add('hidden');
    picker.value = '';
    startBtn.disabled = true;
  }

  async function refreshLocalFiles() {
    try {
      const response = await fetch(`${API_URL}?action=list`);
      const payload = await response.json();
      if (!payload.ok) return;

      const statLocal = document.getElementById('statLocalCount');
      const statThumb = document.getElementById('statThumbCount');
      const countEl = document.getElementById('localFileCount');
      if (statLocal) statLocal.textContent = String(payload.stats?.local_count ?? 0);
      if (statThumb) statThumb.textContent = String(payload.stats?.thumbnail_count ?? 0);
      if (countEl) countEl.textContent = `${payload.files?.length ?? 0} ملف`;

      const tbody = document.getElementById('localFilesBody');
      if (!tbody) return;
      const files = payload.files || [];
      if (files.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-sm text-text-muted">لا توجد صور على سيرفر الموقع بعد.</td></tr>';
        return;
      }

      tbody.innerHTML = files.map((file) => `
        <tr class="border-b border-border-subtle last:border-0 local-file-row" data-name="${escapeHtml(String(file.file_name || '').toLowerCase())}">
          <td class="p-3">
            <img src="${escapeHtml(file.preview_url || '')}" data-thumb="${escapeHtml(file.preview_thumb_url || '')}" alt="" class="local-preview w-12 h-12 rounded-lg object-cover bg-surface-low border border-border-subtle" loading="lazy">
          </td>
          <td class="p-3 font-mono text-xs" dir="ltr">
            <a href="${escapeHtml(file.preview_url || '')}" target="_blank" class="text-primary hover:underline">${escapeHtml(file.file_name || '')}</a>
          </td>
          <td class="p-3">${(Number(file.size_bytes || 0) / 1024).toFixed(1)} KB</td>
          <td class="p-3 text-text-muted">${escapeHtml(file.modified_at || '')}</td>
          <td class="p-3"><span class="text-xs font-bold ${file.has_thumbnail ? 'text-status-active' : 'text-status-pending'}">${file.has_thumbnail ? 'جاهزة للعرض' : 'بدون ثامبنيل'}</span></td>
        </tr>
      `).join('');
      bindPreviewFallbacks();
    } catch {
      // ignore
    }
  }

  function bindPreviewFallbacks() {
    document.querySelectorAll('.local-preview').forEach((img) => {
      img.addEventListener('error', () => {
        const thumb = img.getAttribute('data-thumb');
        if (thumb && img.src !== thumb) {
          img.src = thumb;
          return;
        }
        img.replaceWith(Object.assign(document.createElement('div'), {
          className: 'w-12 h-12 rounded-lg bg-surface-low border border-border-subtle flex items-center justify-center text-[10px] text-text-muted',
          textContent: 'لا معاينة',
        }));
      }, { once: true });
    });
  }

  picker?.addEventListener('change', async () => {
    await buildQueueFromFiles(picker.files);
  });

  startBtn?.addEventListener('click', () => processQueue());
  pauseBtn?.addEventListener('click', () => {
    paused = true;
    uploading = false;
    pauseBtn.classList.add('hidden');
    startBtn.disabled = false;
  });
  resumeBtn?.addEventListener('click', () => processQueue());
  discardBtn?.addEventListener('click', () => discardQueue());

  localSearch?.addEventListener('input', () => {
    const term = localSearch.value.trim().toLowerCase();
    document.querySelectorAll('.local-file-row').forEach((row) => {
      const name = row.getAttribute('data-name') || '';
      row.classList.toggle('hidden', term !== '' && !name.includes(term));
    });
  });

  bindPreviewFallbacks();
  restoreQueueFromStorage();
})();
</script>
