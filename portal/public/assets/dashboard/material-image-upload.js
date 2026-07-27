/**
 * صور المواد — تبويب رفع ومزامنة (يعاد تهيئته بعد AJAX).
 */
(function () {
  'use strict';

  const API_URL = '/dashboard/material-images-api.php';
  const QUEUE_STORAGE_KEY = 'materialImages.uploadQueue';
  const AUTO_SYNC_STORAGE_KEY = 'dash-mi-auto-sync-after-upload';
  const DB_NAME = 'materialImagesUploadDb';
  const DB_STORE = 'files';
  const DB_VERSION = 1;
  const UPLOAD_CONCURRENCY = 3;
  const SYNC_QUEUE_REFRESH_INTERVAL = 5;
  const SYNC_ERROR_DELAY_MS = 80;

  function readBootstrap(root) {
    const el = root.getElementById('material-images-upload-config')
      || document.getElementById('material-images-upload-config');
    if (!el || !el.textContent) {
      return {
        statusLabels: {},
        queue: [],
        queuePage: { page: 1, page_size: 20, total_count: 0, has_more: false },
        pendingDeletable: 0,
        apiOk: true,
        apiMessage: '',
      };
    }
    try {
      return JSON.parse(el.textContent);
    } catch {
      return {
        statusLabels: {},
        queue: [],
        queuePage: { page: 1, page_size: 20, total_count: 0, has_more: false },
        pendingDeletable: 0,
        apiOk: true,
        apiMessage: '',
      };
    }
  }

  function readAutoSyncPreference() {
    try {
      const stored = localStorage.getItem(AUTO_SYNC_STORAGE_KEY);
      if (stored === '0') return false;
      if (stored === '1') return true;
    } catch {
      /* ignore */
    }
    return true;
  }

  function writeAutoSyncPreference(checked) {
    try {
      localStorage.setItem(AUTO_SYNC_STORAGE_KEY, checked ? '1' : '0');
    } catch {
      /* ignore */
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatBytes(bytes) {
    if (!bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  function uid() {
    return crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}${Math.random().toString(16).slice(2)}`;
  }

  function delay(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
  }

  window.portalMaterialImagesUploadInit = function portalMaterialImagesUploadInit(root = document) {
    const panel = root.querySelector('[data-material-images-upload-panel]');
    if (!panel) return;

    if (window.__materialImagesUploadInstance) {
      window.__materialImagesUploadInstance.dispose();
    }

    const bootstrap = readBootstrap(root);
    const abort = new AbortController();
    const instance = {
      disposed: false,
      dispose() {
        this.disposed = true;
        abort.abort();
      },
    };
    window.__materialImagesUploadInstance = instance;
    const signal = abort.signal;

    const statusLabels = bootstrap.statusLabels || {};
    const picker = panel.querySelector('#uploadPicker');
    const uploadPickBtn = panel.querySelector('#uploadPickBtn');
    const uploadDropZone = panel.querySelector('#uploadDropZone');
    const uploadPickSummary = panel.querySelector('#uploadPickSummary');
    const startBtn = panel.querySelector('#startUploadBtn');
    const pauseBtn = panel.querySelector('#pauseUploadBtn');
    const queueSection = panel.querySelector('#uploadQueueSection');
    const queueList = panel.querySelector('#uploadQueueList');
    const uploadQueueSummary = panel.querySelector('#uploadQueueSummary');
    const overallWrap = panel.querySelector('#overallProgressWrap');
    const overallLabel = panel.querySelector('#overallProgressLabel');
    const remainingLabel = panel.querySelector('#remainingLabel');
    const overallBar = panel.querySelector('#overallProgressBar');
    const uploadPickPanel = panel.querySelector('#uploadPickPanel');
    const uploadActivePanel = panel.querySelector('#uploadActivePanel');
    const uploadActiveStatus = panel.querySelector('#uploadActiveStatus');
    const resumeBtn = panel.querySelector('#resumeUploadBtn');
    const discardBtn = panel.querySelector('#discardQueueBtn');
    const startSyncBtn = panel.querySelector('#startSyncBtn');
    const pauseSyncBtn = panel.querySelector('#pauseSyncBtn');
    const retryFailedBtn = panel.querySelector('#retryFailedBtn');
    const scanLocalBtn = panel.querySelector('#scanLocalBtn');
    const purgeOrphanQueueBtn = panel.querySelector('#purgeOrphanQueueBtn');
    const deleteSelectedPendingBtn = panel.querySelector('#deleteSelectedPendingBtn');
    const deleteAllPendingBtn = panel.querySelector('#deleteAllPendingBtn');
    const pauseDeletePendingBtn = panel.querySelector('#pauseDeletePendingBtn');
    const resumeDeletePendingBtn = panel.querySelector('#resumeDeletePendingBtn');
    const deletePendingProgressWrap = panel.querySelector('#deletePendingProgressWrap');
    const deletePendingProgressLabel = panel.querySelector('#deletePendingProgressLabel');
    const deletePendingStatusLabel = panel.querySelector('#deletePendingStatusLabel');
    const deletePendingProgressBar = panel.querySelector('#deletePendingProgressBar');
    const syncQueueSelectAll = panel.querySelector('#syncQueueSelectAll');
    const syncProgressWrap = panel.querySelector('#syncProgressWrap');
    const syncProgressLabel = panel.querySelector('#syncProgressLabel');
    const syncProgressBar = panel.querySelector('#syncProgressBar');
    const syncStatus = panel.querySelector('#syncStatus');
    const syncQueueBody = panel.querySelector('#syncQueueBody');
    const syncQueueCards = panel.querySelector('#syncQueueCards');
    const syncQueueSummary = panel.querySelector('#syncQueueSummary');
    const syncQueuePrevBtn = panel.querySelector('#syncQueuePrevBtn');
    const syncQueueNextBtn = panel.querySelector('#syncQueueNextBtn');
    const syncQueuePageLabel = panel.querySelector('#syncQueuePageLabel');
    const autoSyncCheckbox = panel.querySelector('#autoSyncAfterUpload');
    const queueFilterTabs = panel.querySelectorAll('.dash-mi-queue-tab');
    const uploadSteps = panel.querySelectorAll('.dash-mi-upload-step[data-upload-step]');

    let syncRunning = false;
    let syncPaused = false;
    let scanRunning = false;
    let autoSyncAfterUpload = readAutoSyncPreference();
    let syncQueuePage = Number(bootstrap.queuePage?.page || 1);
    let syncQueuePageSize = Number(bootstrap.queuePage?.page_size || 20);
    let syncQueueHasMore = !!bootstrap.queuePage?.has_more;
    let syncQueueTotalCount = Number(bootstrap.queuePage?.total_count || 0);
    let syncQueueStatusFilter = '';
    let pendingDeletableTotal = Number(bootstrap.pendingDeletable || 0);
    let deletePendingRunning = false;
    let deletePendingPaused = false;
    let deletePendingProcessed = 0;
    let deletePendingFailed = 0;
    let deletePendingInitialTotal = 0;
    let syncSuccessStreak = 0;

    let queue = null;
    let paused = false;
    let uploading = false;
    let uploadSessionStarted = false;
    let dbPromise = null;
    let lastQueueItemIds = '';

    function appendQueueFormParams(form, { includeStatus = false } = {}) {
      form.append('queue_page', String(syncQueuePage));
      form.append('queue_page_size', String(syncQueuePageSize));
      if (includeStatus && syncQueueStatusFilter) {
        form.append('queue_status', syncQueueStatusFilter);
      }
    }

    function buildOverviewUrl() {
      const params = new URLSearchParams({
        action: 'overview',
        queue_page: String(syncQueuePage),
        queue_page_size: String(syncQueuePageSize),
      });
      if (syncQueueStatusFilter) {
        params.set('queue_status', syncQueueStatusFilter);
      }
      return `${API_URL}?${params.toString()}`;
    }

    function buildQueueUrl(page) {
      const params = new URLSearchParams({
        action: 'queue',
        page: String(page),
        page_size: String(syncQueuePageSize),
      });
      if (syncQueueStatusFilter) {
        params.set('status', syncQueueStatusFilter);
      }
      return `${API_URL}?${params.toString()}`;
    }

    function hasPendingUploadItems() {
      return queue?.items.some((item) => ['pending', 'uploading', 'error', 'missing'].includes(item.status)) ?? false;
    }

    function updateUploadStepper() {
      const onUpload = uploading || (queue && hasPendingUploadItems());
      const onSync = syncRunning || scanRunning;
      uploadSteps.forEach((step) => {
        const name = step.getAttribute('data-upload-step');
        if (name === 'upload') {
          step.classList.toggle('is-active', onUpload || !onSync);
        } else if (name === 'sync') {
          step.classList.toggle('is-active', onSync);
        }
      });
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

      updateUploadStepper();
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
      await Promise.all(queue.items.map((item) => idbDelete(`${queueId}:${item.id}`)));
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
      try {
        const raw = localStorage.getItem(QUEUE_STORAGE_KEY);
        if (!raw) return null;
        return JSON.parse(raw);
      } catch {
        return null;
      }
    }

    function uploadStatusLabel(status) {
      return {
        pending: 'بالانتظار',
        uploading: 'جاري الرفع',
        done: 'مكتمل',
        error: 'فشل',
        missing: 'بحاجة إعادة اختيار',
      }[status] || status;
    }

    function buildQueueItemHtml(item) {
      const percentClass = item.status === 'done'
        ? 'text-status-active'
        : item.status === 'error'
          ? 'text-status-rejected'
          : 'text-text-muted';
      const barClass = item.status === 'error' ? 'bg-status-rejected' : 'bg-primary';
      return `
        <div class="p-3" data-item-id="${escapeHtml(item.id)}">
          <div class="flex items-center justify-between gap-2 mb-1">
            <div class="min-w-0">
              <div class="font-mono text-xs truncate" dir="ltr">${escapeHtml(item.name)}</div>
              <div class="text-[11px] text-text-muted upload-item-status">${formatBytes(item.size)} · ${uploadStatusLabel(item.status)}${item.error ? ` — ${escapeHtml(item.error)}` : ''}</div>
            </div>
            <span class="text-xs font-bold upload-item-percent ${percentClass}">${Math.round(item.progress)}%</span>
          </div>
          <div class="h-1.5 rounded-full bg-surface-low overflow-hidden">
            <div class="h-full upload-item-bar ${barClass} transition-all duration-200" style="width:${item.progress}%"></div>
          </div>
        </div>
      `;
    }

    function updateQueueItemDom(item) {
      if (!queueList) return false;
      const row = queueList.querySelector(`[data-item-id="${CSS.escape(item.id)}"]`);
      if (!row) return false;

      const statusEl = row.querySelector('.upload-item-status');
      const percentEl = row.querySelector('.upload-item-percent');
      const barEl = row.querySelector('.upload-item-bar');

      if (statusEl) {
        statusEl.textContent = `${formatBytes(item.size)} · ${uploadStatusLabel(item.status)}${item.error ? ` — ${item.error}` : ''}`;
      }
      if (percentEl) {
        percentEl.textContent = `${Math.round(item.progress)}%`;
        percentEl.classList.remove('text-status-active', 'text-status-rejected', 'text-text-muted');
        if (item.status === 'done') {
          percentEl.classList.add('text-status-active');
        } else if (item.status === 'error') {
          percentEl.classList.add('text-status-rejected');
        } else {
          percentEl.classList.add('text-text-muted');
        }
      }
      if (barEl) {
        barEl.style.width = `${item.progress}%`;
        barEl.classList.toggle('bg-status-rejected', item.status === 'error');
        barEl.classList.toggle('bg-primary', item.status !== 'error');
      }
      return true;
    }

    function updateQueueSummary() {
      if (!queue || queue.items.length === 0) return;

      const done = queue.items.filter((item) => item.status === 'done').length;
      const total = queue.items.length;
      const remaining = queue.items.filter((item) => ['pending', 'uploading', 'error'].includes(item.status)).length;

      if (uploadQueueSummary) {
        uploadQueueSummary.textContent = `${done} مكتمل من ${total}`;
      }
      overallWrap?.classList.remove('hidden');
      if (overallLabel) overallLabel.textContent = `${done} / ${total}`;
      if (remainingLabel) remainingLabel.textContent = `متبقي: ${remaining}`;
      if (overallBar) {
        overallBar.style.width = `${total ? Math.round((done / total) * 100) : 0}%`;
      }
    }

    function renderQueue({ force = false } = {}) {
      if (!queue || queue.items.length === 0) {
        queueSection?.classList.add('hidden');
        lastQueueItemIds = '';
        updateUploadControls();
        return;
      }

      queueSection?.classList.remove('hidden');
      const ids = queue.items.map((item) => item.id).join('|');
      const structureChanged = force || ids !== lastQueueItemIds;

      if (structureChanged) {
        lastQueueItemIds = ids;
        if (queueList) {
          queueList.innerHTML = queue.items.map((item) => buildQueueItemHtml(item)).join('');
        }
      } else {
        let missingDom = false;
        for (const item of queue.items) {
          if (!updateQueueItemDom(item)) {
            missingDom = true;
          }
        }
        if (missingDom && queueList) {
          queueList.innerHTML = queue.items.map((item) => buildQueueItemHtml(item)).join('');
        }
      }

      updateQueueSummary();
      updateUploadControls();
    }

    function updatePickSummary(fileCount) {
      if (!uploadPickSummary) return;
      uploadPickSummary.textContent = fileCount > 0 ? `${fileCount} ملف محدد` : '';
    }

    async function buildQueueFromFiles(fileList) {
      const files = Array.from(fileList || []);
      if (files.length === 0) return;

      if (queue && uploading) {
        window.alert('انتظر حتى ينتهي الرفع الحالي أو أوقفه مؤقتاً.');
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

      for (let i = 0; i < files.length; i += 1) {
        await idbPut(`${queue.id}:${queue.items[i].id}`, files[i]);
      }

      saveQueue();
      uploadSessionStarted = false;
      paused = false;
      updatePickSummary(files.length);
      renderQueue({ force: true });
    }

    function blobToBase64(blob) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
          const result = reader.result;
          const base64 = typeof result === 'string' ? (result.split(',')[1] || '') : '';
          resolve(base64);
        };
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
      });
    }

    async function uploadViaBase64(blob, item) {
      const encoded = await blobToBase64(blob);
      const form = new FormData();
      form.append('action', 'upload-data');
      form.append('file_name', item.name);
      form.append('file_data', encoded);
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      return res.json();
    }

    function claimNextUploadItem() {
      if (!queue) return null;
      for (const item of queue.items) {
        if (item.status === 'pending' || item.status === 'error') {
          item.status = 'uploading';
          item.progress = 0;
          item.error = '';
          saveQueue();
          renderQueue();
          return item;
        }
      }
      return null;
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

      const formData = new FormData();
      formData.append('action', 'upload');
      formData.append('file', blob, item.name);

      const xhrResult = await new Promise((resolve) => {
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
          resolve({
            ok: xhr.status >= 200 && xhr.status < 300 && payload.ok,
            payload,
            status: xhr.status,
            transportError: false,
          });
        };
        xhr.onerror = () => {
          resolve({
            ok: false,
            payload: { ok: false, message: 'انقطع الاتصال أثناء الرفع' },
            status: 0,
            transportError: true,
          });
        };
        xhr.onabort = () => {
          resolve({
            ok: false,
            payload: { ok: false, message: 'أُلغي الرفع' },
            status: 0,
            transportError: true,
          });
        };
        if (signal.aborted) {
          xhr.abort();
          return;
        }
        signal.addEventListener('abort', () => xhr.abort(), { once: true });
        xhr.send(formData);
      });

      let payload = xhrResult.payload;
      let success = xhrResult.ok;

      if (!success) {
        try {
          const fallbackPayload = await uploadViaBase64(blob, item);
          payload = fallbackPayload;
          success = !!fallbackPayload.ok;
          if (success) {
            item.progress = 100;
          }
        } catch {
          success = false;
        }
      }

      if (success) {
        item.status = 'done';
        item.progress = 100;
        item.replaced = !!payload.replaced;
        await idbDelete(`${queue.id}:${item.id}`);
      } else {
        item.status = 'error';
        item.error = payload?.message || `فشل الرفع (رمز ${xhrResult.status || '—'})`;
        item.progress = 0;
      }

      saveQueue();
      renderQueue();
      return success;
    }

    async function uploadWorker() {
      while (!paused && !signal.aborted) {
        const item = claimNextUploadItem();
        if (!item) break;
        await uploadItem(item);
      }
    }

    async function processQueue() {
      if (!queue || uploading) return;
      uploading = true;
      paused = false;
      uploadSessionStarted = true;
      updateUploadControls();

      const workers = Array.from({ length: UPLOAD_CONCURRENCY }, () => uploadWorker());
      await Promise.all(workers);

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
        window.setTimeout(() => {
          if (queue && queue.items.every((item) => item.status === 'done')) {
            queue = null;
            lastQueueItemIds = '';
            queueSection?.classList.add('hidden');
            overallWrap?.classList.add('hidden');
            if (picker) picker.value = '';
            updatePickSummary(0);
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
          const storedBlob = await idbGet(`${queue.id}:${item.id}`);
          if (!(storedBlob instanceof Blob)) {
            item.status = 'missing';
            item.error = 'أعد اختيار الملفات المفقودة';
          }
        }
      }

      if (hasPendingUploadItems()) {
        uploadSessionStarted = true;
      }
      saveQueue();
      renderQueue({ force: true });
    }

    async function discardQueue() {
      if (!window.confirm('إلغاء الرفع وحذف الطابور؟')) {
        return;
      }
      if (queue) {
        await idbClearQueue(queue.id);
      }
      queue = null;
      paused = false;
      uploading = false;
      uploadSessionStarted = false;
      lastQueueItemIds = '';
      localStorage.removeItem(QUEUE_STORAGE_KEY);
      queueSection?.classList.add('hidden');
      overallWrap?.classList.add('hidden');
      if (picker) picker.value = '';
      updatePickSummary(0);
      updateUploadControls();
    }

    async function refreshStats() {
      try {
        const response = await fetch(buildOverviewUrl(), { signal });
        const payload = await response.json();
        if (!payload.ok) return;
        const statLocal = root.getElementById('statLocalCount') || document.getElementById('statLocalCount');
        const statThumb = root.getElementById('statThumbCount') || document.getElementById('statThumbCount');
        if (statLocal) statLocal.textContent = String(payload.local?.local_count ?? 0);
        if (statThumb) statThumb.textContent = String(payload.local?.thumbnail_count ?? 0);
        renderSyncOverview(payload);
      } catch {
        /* ignore */
      }
    }

    function updateSyncProgress(sync) {
      const total = Math.max(1, (sync.synced ?? 0) + (sync.pending ?? 0) + (sync.failed ?? 0) + (sync.syncing ?? 0));
      const done = sync.synced ?? 0;
      if (syncProgressBar) {
        syncProgressBar.style.width = `${Math.round((done / total) * 100)}%`;
      }
      if (syncProgressLabel) {
        syncProgressLabel.textContent = `تم ${done} — متبقي ${(sync.pending ?? 0) + (sync.failed ?? 0)}`;
      }
    }

    function renderSyncOverview(data) {
      const pendingEl = root.getElementById('statPendingCount') || document.getElementById('statPendingCount');
      const syncedEl = root.getElementById('statSyncedCount') || document.getElementById('statSyncedCount');
      const failedEl = root.getElementById('statFailedCount') || document.getElementById('statFailedCount');
      if (pendingEl) pendingEl.textContent = String(data.sync?.pending ?? 0);
      if (syncedEl) syncedEl.textContent = String(data.sync?.synced ?? 0);
      if (failedEl) failedEl.textContent = String(data.sync?.failed ?? 0);

      const apiPill = root.getElementById('apiStatusPill') || document.getElementById('apiStatusPill');
      if (apiPill) {
        apiPill.innerHTML = data.api?.ok
          ? 'API الأمين: <strong class="text-status-active">متصل</strong>'
          : 'API الأمين: <strong class="text-status-rejected">غير متصل</strong>';
      }

      if (data.sync) {
        updateSyncProgress(data.sync);
      }

      if (data.queue) {
        renderSyncQueue(data.queue, data.sync || {});
      }
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

    function buildSyncPreviewCell(row) {
      const previewUrl = row.preview_url || '';
      if (!previewUrl) return '<td class="p-3"></td>';
      return `<td class="p-3"><img src="${escapeHtml(previewUrl)}" alt="" class="dash-mi-sync-thumb" loading="lazy" decoding="async"></td>`;
    }

    function buildSyncQueueRowHtml(row) {
      const status = row.sync_status || 'pending';
      const meta = statusLabels[status] || statusLabels.pending || { label: status, class: '' };
      const canDelete = status === 'pending' || status === 'failed';
      const checkCell = canDelete
        ? `<input type="checkbox" class="sync-queue-select rounded" data-queue-id="${escapeHtml(row.id || '')}">`
        : '';
      const actionCell = canDelete
        ? `<button type="button" class="delete-pending-queue-btn h-7 px-2 rounded border border-red-200 bg-red-50 text-red-700 font-bold" data-queue-id="${escapeHtml(row.id || '')}" data-file-name="${escapeHtml(row.file_name || '')}">حذف محلي</button>`
        : '<span class="text-text-muted">—</span>';
      return `<tr data-queue-id="${escapeHtml(row.id || '')}">
        <td class="p-3 text-center">${checkCell}</td>
        ${buildSyncPreviewCell(row)}
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.file_name || '')}</td>
        <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full ${escapeHtml(meta.class || '')}">${escapeHtml(meta.label || status)}</span></td>
        <td class="p-3 font-mono text-xs" dir="ltr">${escapeHtml(row.amine_image_guid || '—')}</td>
        <td class="p-3 text-xs text-text-muted">${escapeHtml(row.amine_sync_error_ar || '')}</td>
        <td class="p-3 text-xs">${actionCell}</td>
      </tr>`;
    }

    function buildSyncQueueCardHtml(row) {
      const status = row.sync_status || 'pending';
      const meta = statusLabels[status] || statusLabels.pending || { label: status, class: '' };
      const previewUrl = row.preview_url || '';
      const previewHtml = previewUrl
        ? `<img src="${escapeHtml(previewUrl)}" alt="" class="dash-mi-sync-thumb" loading="lazy" decoding="async">`
        : '';
      return `<article class="dash-mi-sync-card" data-queue-id="${escapeHtml(row.id || '')}">
        <div class="dash-mi-sync-card__head">
          ${previewHtml}
          <p class="dash-mi-sync-card__file" dir="ltr">${escapeHtml(row.file_name || '')}</p>
          <span class="text-xs px-2 py-0.5 rounded-full ${escapeHtml(meta.class || '')}">${escapeHtml(meta.label || status)}</span>
        </div>
        <div class="dash-mi-sync-card__meta">
          <div dir="ltr">${escapeHtml(row.amine_image_guid || '—')}</div>
          <div>${escapeHtml(row.amine_sync_error_ar || '')}</div>
        </div>
      </article>`;
    }

    function renderSyncQueue(queuePayload) {
      if (!syncQueueSummary || !syncQueueBody) return;
      const meta = normalizeQueuePayload(queuePayload);
      const items = meta.items;
      updateSyncQueuePagination(meta);
      syncQueueSummary.textContent = `${syncQueueTotalCount} عنصر`;

      if (!items.length) {
        syncQueueBody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-text-muted">الطابور فارغ.</td></tr>';
        if (syncQueueCards) syncQueueCards.innerHTML = '';
        if (syncQueueSelectAll) syncQueueSelectAll.checked = false;
        return;
      }

      syncQueueBody.innerHTML = items.map((row) => buildSyncQueueRowHtml(row)).join('');
      if (syncQueueCards) {
        syncQueueCards.innerHTML = items.map((row) => buildSyncQueueCardHtml(row)).join('');
      }
      if (syncQueueSelectAll) syncQueueSelectAll.checked = false;
    }

    function updateDeletePendingControls() {
      const busy = deletePendingRunning || syncRunning || scanRunning;
      if (deleteSelectedPendingBtn) deleteSelectedPendingBtn.disabled = busy;
      if (deleteAllPendingBtn) deleteAllPendingBtn.disabled = busy;
      deleteAllPendingBtn?.classList.toggle('hidden', deletePendingRunning);
      pauseDeletePendingBtn?.classList.toggle('hidden', !deletePendingRunning || deletePendingPaused);
      resumeDeletePendingBtn?.classList.toggle('hidden', !deletePendingRunning || !deletePendingPaused);
      if (!deletePendingRunning) {
        deletePendingProgressWrap?.classList.add('hidden');
      }
    }

    function getSelectedPendingQueueIds() {
      return Array.from(panel.querySelectorAll('.sync-queue-select:checked'))
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
          appendQueueFormParams(form);
          const res = await fetch(API_URL, { method: 'POST', body: form, signal });
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
        if (payload.queue) renderSyncQueue(payload.queue);
        if (payload.sync) {
          renderSyncOverview({ sync: payload.sync, api: { ok: true }, queue: payload.queue });
        }

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
      if (!window.confirm('حذف كل الصور غير المزامنة من مجلد الموقع والطابور؟ (لن يُمس bm000)')) return;
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
      if (!window.confirm(`حذف ${ids.length} صورة محددة من الموقع والطابور؟`)) return;

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
          appendQueueFormParams(form);
          try {
            const res = await fetch(API_URL, { method: 'POST', body: form, signal });
            const payload = await res.json();
            if (payload.ok) deletePendingProcessed += 1;
            else deletePendingFailed += 1;
            renderDeletePendingProgress();
            if (payload.queue) renderSyncQueue(payload.queue);
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
      appendQueueFormParams(form);
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      const payload = await res.json();
      if (syncStatus) syncStatus.textContent = payload.message || '';
      if (payload.queue) renderSyncQueue(payload.queue);
      if (syncQueueSelectAll) syncQueueSelectAll.checked = false;
    }

    async function deletePendingQueueItem(queueId, fileName) {
      const label = fileName || 'هذه الصورة';
      if (!window.confirm(`حذف «${label}» من مجلد الموقع وطابور المزامنة؟\n(لن يُمس سجل الأمين bm000 إن وُجد — استخدم تبويب الربط للحذف الكامل.)`)) {
        return;
      }
      const form = new FormData();
      form.append('action', 'delete-pending-queue-item');
      form.append('queue_id', queueId);
      appendQueueFormParams(form);
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      const payload = await res.json();
      if (syncStatus) {
        syncStatus.textContent = payload.message || (payload.ok ? 'تم الحذف.' : 'تعذر الحذف.');
      }
      if (payload.queue) {
        renderSyncQueue(payload.queue);
      }
      if (payload.sync) {
        renderSyncOverview({ sync: payload.sync, api: { ok: true }, queue: payload.queue });
      }
    }

    async function purgeOrphanQueue() {
      if (!window.confirm('إزالة سجلات الطابور التي فقدت ملفاتها على مجلد الموقع؟')) return;
      const form = new FormData();
      form.append('action', 'purge-orphan-queue');
      appendQueueFormParams(form);
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      const payload = await res.json();
      if (syncStatus) syncStatus.textContent = payload.message || 'تم التنظيف.';
      if (payload.queue) renderSyncQueue(payload.queue);
    }

    async function refreshOverview() {
      try {
        const response = await fetch(buildOverviewUrl(), { signal });
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
        const response = await fetch(buildQueueUrl(syncQueuePage), { signal });
        const payload = await response.json();
        if (payload.ok) {
          renderSyncQueue(payload);
        }
        return payload;
      } catch {
        return { ok: false };
      }
    }

    async function syncNextOnce({ light = false } = {}) {
      const form = new FormData();
      form.append('action', 'sync-next');
      appendQueueFormParams(form, { includeStatus: !light });
      if (light) {
        form.append('light', '1');
      }
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      return res.json();
    }

    async function processSyncQueue() {
      if (syncRunning) return;
      syncRunning = true;
      syncPaused = false;
      syncSuccessStreak = 0;
      syncProgressWrap?.classList.remove('hidden');
      if (syncStatus) syncStatus.textContent = 'جاري مزامنة الأمين...';
      updateUploadStepper();

      while (!syncPaused && !signal.aborted) {
        let result;
        try {
          result = await syncNextOnce({ light: true });
        } catch {
          if (syncStatus) syncStatus.textContent = 'انقطع الاتصال — سيتم الاستئناف عند الضغط على «استئناف».';
          break;
        }

        if (result.sync) {
          updateSyncProgress(result.sync);
          renderSyncOverview({
            sync: result.sync,
            api: { ok: !result.offline },
          });
        }

        if (result.queue && !result.light) {
          renderSyncQueue(result.queue);
        }

        if (result.done) {
          if (syncStatus) syncStatus.textContent = result.message || 'اكتملت المزامنة.';
          break;
        }

        if (result.offline || !result.ok) {
          if (syncStatus) syncStatus.textContent = result.message || 'توقف بسبب انقطاع الأمين — اضغط «استئناف» لاحقاً.';
          await delay(SYNC_ERROR_DELAY_MS);
          break;
        }

        if (syncStatus) syncStatus.textContent = result.message || 'تمت مزامنة صورة.';
        syncSuccessStreak += 1;
        if (syncSuccessStreak % SYNC_QUEUE_REFRESH_INTERVAL === 0) {
          await refreshOverview();
        }
      }

      syncRunning = false;
      updateUploadStepper();
      await refreshOverview();
    }

    function setQueueFilter(filter) {
      syncQueueStatusFilter = filter || '';
      syncQueuePage = 1;
      queueFilterTabs.forEach((tab) => {
        const tabFilter = tab.getAttribute('data-queue-filter') || '';
        tab.classList.toggle('is-active', tabFilter === syncQueueStatusFilter);
      });
      loadSyncQueuePage(1);
    }

    if (autoSyncCheckbox instanceof HTMLInputElement) {
      autoSyncCheckbox.checked = autoSyncAfterUpload;
      autoSyncCheckbox.addEventListener('change', () => {
        autoSyncAfterUpload = autoSyncCheckbox.checked;
        writeAutoSyncPreference(autoSyncAfterUpload);
      }, { signal });
    }

    uploadPickBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      picker?.click();
    }, { signal });

    uploadDropZone?.addEventListener('click', (event) => {
      if (event.target === uploadPickBtn || uploadPickBtn?.contains(event.target)) return;
      picker?.click();
    }, { signal });

    uploadDropZone?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        picker?.click();
      }
    }, { signal });

    uploadDropZone?.addEventListener('dragover', (event) => {
      event.preventDefault();
      uploadDropZone.classList.add('is-dragover');
    }, { signal });

    uploadDropZone?.addEventListener('dragleave', () => {
      uploadDropZone.classList.remove('is-dragover');
    }, { signal });

    uploadDropZone?.addEventListener('drop', async (event) => {
      event.preventDefault();
      uploadDropZone.classList.remove('is-dragover');
      await buildQueueFromFiles(event.dataTransfer?.files);
    }, { signal });

    picker?.addEventListener('change', async () => {
      const count = picker.files?.length || 0;
      updatePickSummary(count);
      await buildQueueFromFiles(picker.files);
    }, { signal });

    startBtn?.addEventListener('click', () => processQueue(), { signal });
    pauseBtn?.addEventListener('click', () => {
      paused = true;
      updateUploadControls();
    }, { signal });
    resumeBtn?.addEventListener('click', () => processQueue(), { signal });
    discardBtn?.addEventListener('click', () => discardQueue(), { signal });

    startSyncBtn?.addEventListener('click', () => {
      syncPaused = false;
      processSyncQueue();
    }, { signal });

    pauseSyncBtn?.addEventListener('click', () => {
      syncPaused = true;
      if (syncStatus) syncStatus.textContent = 'مزامنة الأمين متوقفة مؤقتاً.';
      updateUploadStepper();
    }, { signal });

    retryFailedBtn?.addEventListener('click', async () => {
      const form = new FormData();
      form.append('action', 'retry-failed');
      appendQueueFormParams(form);
      const res = await fetch(API_URL, { method: 'POST', body: form, signal });
      const data = await res.json();
      if (syncStatus) syncStatus.textContent = data.message || '';
      await refreshOverview();
    }, { signal });

    scanLocalBtn?.addEventListener('click', async () => {
      if (scanRunning) return;

      scanRunning = true;
      scanLocalBtn.disabled = true;
      if (startSyncBtn) startSyncBtn.disabled = true;
      syncProgressWrap?.classList.remove('hidden');
      if (syncProgressLabel) syncProgressLabel.textContent = 'فحص محلي: 0%';
      if (syncProgressBar) syncProgressBar.style.width = '1%';
      if (syncStatus) syncStatus.textContent = 'جاري تهيئة الفحص...';
      updateUploadStepper();

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
        const initRes = await fetch(API_URL, { method: 'POST', body: initForm, signal });
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
            appendQueueFormParams(reconcileForm);
            const reconcileRes = await fetch(API_URL, { method: 'POST', body: reconcileForm, signal });
            const reconcileData = await reconcileRes.json();
            if (!reconcileData.ok) {
              if (syncStatus) syncStatus.textContent = reconcileData.message || 'توقّفت مطابقة الطابور.';
              return;
            }
            const reconcile = reconcileData.reconcile || {};
            totals.reconciled += Number(reconcile.reconciled || 0);
            totals.content_changed += Number(reconcile.content_changed || 0);
            reconcileOffset = Number(reconcile.offset || (reconcileOffset + chunkSize));
            if (reconcile.done) break;
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
          appendQueueFormParams(form);
          const res = await fetch(API_URL, { method: 'POST', body: form, signal });
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

          if (scan.done) break;
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
        await fetch(API_URL, { method: 'POST', body: finishForm, signal });
        await refreshOverview();
      } catch {
        if (syncStatus) syncStatus.textContent = 'تعذّر إكمال الفحص المحلي.';
        const failForm = new FormData();
        failForm.append('action', 'scan-local-finish');
        await fetch(API_URL, { method: 'POST', body: failForm, signal });
      } finally {
        scanRunning = false;
        scanLocalBtn.disabled = false;
        if (startSyncBtn) startSyncBtn.disabled = false;
        updateUploadStepper();
      }
    }, { signal });

    syncQueuePrevBtn?.addEventListener('click', () => {
      if (syncQueuePage > 1) loadSyncQueuePage(syncQueuePage - 1);
    }, { signal });

    syncQueueNextBtn?.addEventListener('click', () => {
      if (syncQueueHasMore) loadSyncQueuePage(syncQueuePage + 1);
    }, { signal });

    syncQueueBody?.addEventListener('click', (event) => {
      const btn = event.target instanceof HTMLElement ? event.target.closest('.delete-pending-queue-btn') : null;
      if (!btn) return;
      const queueId = btn.getAttribute('data-queue-id') || '';
      const fileName = btn.getAttribute('data-file-name') || '';
      if (queueId) deletePendingQueueItem(queueId, fileName);
    }, { signal });

    purgeOrphanQueueBtn?.addEventListener('click', purgeOrphanQueue, { signal });
    deleteSelectedPendingBtn?.addEventListener('click', deleteSelectedPending, { signal });
    deleteAllPendingBtn?.addEventListener('click', deleteAllPending, { signal });

    pauseDeletePendingBtn?.addEventListener('click', () => {
      deletePendingPaused = true;
      updateDeletePendingControls();
      if (syncStatus) syncStatus.textContent = 'متوقف مؤقتاً — اضغط «استئناف».';
    }, { signal });

    resumeDeletePendingBtn?.addEventListener('click', async () => {
      if (!deletePendingRunning || !deletePendingPaused) return;
      deletePendingPaused = false;
      if (syncStatus) syncStatus.textContent = 'جاري الحذف...';
      await runDeletePendingLoop();
    }, { signal });

    syncQueueSelectAll?.addEventListener('change', () => {
      const checked = !!syncQueueSelectAll.checked;
      panel.querySelectorAll('.sync-queue-select').forEach((el) => {
        el.checked = checked;
      });
    }, { signal });

    queueFilterTabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        setQueueFilter(tab.getAttribute('data-queue-filter') || '');
      }, { signal });
    });

    updateSyncQueuePagination(normalizeQueuePayload({
      items: bootstrap.queue || [],
      page: bootstrap.queuePage?.page || 1,
      page_size: bootstrap.queuePage?.page_size || 20,
      total_count: bootstrap.queuePage?.total_count || 0,
      has_more: !!bootstrap.queuePage?.has_more,
    }));

    if (syncStatus && bootstrap.apiMessage && !bootstrap.apiOk) {
      syncStatus.textContent = bootstrap.apiMessage;
    } else if (syncStatus && bootstrap.apiOk) {
      syncStatus.textContent = 'جاهز للمزامنة.';
    }

    restoreQueueFromStorage();
    refreshOverview();
  };
}());
