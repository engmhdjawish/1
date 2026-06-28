/**
 * صور المواد — تبويب ربط بالمواد (يعاد تهيئته بعد AJAX).
 */
(function () {
  'use strict';

  window.portalMaterialImagesLinkInit = function portalMaterialImagesLinkInit(root = document) {
    const panel = root.querySelector('[data-material-images-link-panel]');
    if (!panel) return;

    if (window.__materialImagesLinkInstance) {
      window.__materialImagesLinkInstance.dispose();
    }

    const abort = new AbortController();
    const instance = {
      disposed: false,
      loadId: 0,
      dispose() {
        this.disposed = true;
        abort.abort();
      },
    };
    window.__materialImagesLinkInstance = instance;
    window.__materialImagesLinkAbort = abort;
    const signal = abort.signal;

    const CAN_ADD_DETAILS = panel.dataset.canAddDetails === '1';

const API_URL = '/dashboard/material-images-api.php';
  const API_HEADERS = {
    Accept: 'application/json',
    'X-Dashboard-Ajax': '1',
    'X-Requested-With': 'XMLHttpRequest',
  };

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      signal: options.signal ?? signal,
      headers: { ...API_HEADERS, ...(options.headers || {}) },
    });
    const text = await response.text();
    if (!text.trim()) {
      throw new Error('استجابة فارغة من الخادم. تحقق من GD والخط (Tahoma) أو سجل أخطاء PHP.');
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(`استجابة غير صالحة من الخادم: ${text.slice(0, 180)}`);
    }
  }

  const sourceCards = panel.querySelector('#sourceCards');
  const sourcePageLabel = panel.querySelector('#sourcePageLabel');
  const sourcePrevBtn = panel.querySelector('#sourcePrevBtn');
  const sourceNextBtn = panel.querySelector('#sourceNextBtn');
  const reloadSourcesBtn = panel.querySelector('#reloadSourcesBtn');
  const sourceMaterialSearch = panel.querySelector('#sourceMaterialSearch');
  const applySourceFiltersBtn = panel.querySelector('#applySourceFiltersBtn');
  const deleteAllUnlinkedBtn = panel.querySelector('#deleteAllUnlinkedBtn');
  const deleteSelectedUnlinkedBtn = panel.querySelector('#deleteSelectedUnlinkedBtn');
  const pauseDeleteUnlinkedBtn = panel.querySelector('#pauseDeleteUnlinkedBtn');
  const resumeDeleteUnlinkedBtn = panel.querySelector('#resumeDeleteUnlinkedBtn');
  const cancelDeleteUnlinkedBtn = panel.querySelector('#cancelDeleteUnlinkedBtn');
  const selectAllUnlinkedWrap = panel.querySelector('#selectAllUnlinkedWrap');
  const selectAllUnlinked = panel.querySelector('#selectAllUnlinked');
  const deleteUnlinkedProgressWrap = panel.querySelector('#deleteUnlinkedProgressWrap');
  const deleteUnlinkedProgressLabel = panel.querySelector('#deleteUnlinkedProgressLabel');
  const deleteUnlinkedStatusLabel = panel.querySelector('#deleteUnlinkedStatusLabel');
  const deleteUnlinkedProgressBar = panel.querySelector('#deleteUnlinkedProgressBar');
  const linkFilterButtons = panel.querySelectorAll('.link-filter-btn');
  const linkStatus = panel.querySelector('#linkStatus');
  
  const imageLightbox = panel.querySelector('#imageLightbox');
  const lightboxImg = panel.querySelector('#lightboxImg');
  const lightboxCaption = panel.querySelector('#lightboxCaption');
  const lightboxCloseBtn = panel.querySelector('#lightboxCloseBtn');
  let page = 1;
  let hasMore = false;
  let totalCount = 0;
  let deleteUnlinkedRunning = false;
  let deleteUnlinkedPaused = false;
  let deleteUnlinkedCancelled = false;
  let deleteUnlinkedInitialTotal = 0;
  let deleteUnlinkedProcessed = 0;
  let deleteUnlinkedFailed = 0;
  /** @type {Array<{image_guid: string, file_name: string}>|null} */
  let deleteUnlinkedQueue = null;
  const pageSize = 12;
  const sourceMaterialMap = new Map();
  const MIN_MATERIAL_SEARCH_LEN = 2;

  function clearSourceMaterialSearch() {
    if (sourceMaterialSearch) {
      sourceMaterialSearch.value = '';
    }
  }

  function applySourceMaterialSearch() {
    loadSources(1);
  }

  function currentLinkFilter() {
    const active = panel.querySelector('.link-filter-btn.border-primary.bg-primary');
    return active?.getAttribute('data-filter') || 'unlinked';
  }

  function setLinkFilter(filter) {
    linkFilterButtons.forEach((btn) => {
      const isActive = btn.getAttribute('data-filter') === filter;
      btn.classList.toggle('border-primary', isActive);
      btn.classList.toggle('bg-primary', isActive);
      btn.classList.toggle('text-white', isActive);
      btn.classList.toggle('border-border-subtle', !isActive);
      btn.classList.toggle('bg-white', !isActive);
    });
    updateDeleteUnlinkedControls();
  }

  function updatePageLabel() {
    if (!sourcePageLabel) return;
    sourcePageLabel.textContent = `صفحة ${page} — ${totalCount} صورة`;
  }

  function ensureCardsPlaceholder() {
    if (!sourceCards) return;
    if (!sourceCards.querySelector('article')) {
      sourceCards.innerHTML = '<div class="text-xs text-text-muted">لا توجد صور في هذه الصفحة.</div>';
    }
  }

  function showSourcesLoading() {
    if (!sourceCards) return;
    sourceCards.innerHTML = '<div class="col-span-full flex flex-col items-center justify-center gap-3 py-16 text-text-muted" aria-busy="true">'
      + '<div class="dash-spinner" role="status" aria-label="جاري تحميل الصور"></div>'
      + '<p class="text-sm font-medium">جاري تحميل الصور...</p>'
      + '</div>';
    if (sourcePageLabel) sourcePageLabel.textContent = 'جاري التحميل...';
    if (sourcePrevBtn) sourcePrevBtn.disabled = true;
    if (sourceNextBtn) sourceNextBtn.disabled = true;
    if (linkStatus) linkStatus.textContent = '';
  }

  function removeCard(card, item) {
    if (!card) return;
    sourceMaterialMap.delete(cardKey(item));
    card.remove();
    totalCount = Math.max(0, totalCount - 1);
    updatePageLabel();
    ensureCardsPlaceholder();
  }

  function updateCardToUnlinked(card, item) {
    item.is_linked_to_material = false;
    item.linked_material_guid = '';
    item.linked_material_name = '';
    item.linked_material_code = '';

    const badge = card.querySelector('.link-badge');
    if (badge) {
      badge.className = 'link-badge text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full';
      badge.textContent = 'غير مرتبطة';
    }
    const title = card.querySelector('.material-title');
    if (title) {
      title.innerHTML = '<span class="text-text-muted">غير مرتبطة بمادة</span>';
    }
    card.querySelector('.material-code')?.remove();
    card.querySelector('.reassign-btn')?.remove();
    card.querySelector('.unlink-btn')?.remove();
  }

  function handleCardAfterDelete(card, item) {
    removeCard(card, item);
  }

  function handleCardAfterUnlink(card, item) {
    const filter = currentLinkFilter();
    if (filter === 'linked') {
      removeCard(card, item);
      return;
    }
    updateCardToUnlinked(card, item);
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c]));
  }

  function previewFallbackUrl(url) {
    const value = String(url ?? '').trim();
    if (value === '') return '';
    if (value.includes('thumb=1')) {
      return value.replace('thumb=1', 'thumb=0');
    }
    if (!value.includes('thumb=')) {
      return value.includes('?') ? `${value}&thumb=0` : `${value}?thumb=0`;
    }
    return '';
  }

  function bindPreviewImages() {
    sourceCards?.querySelectorAll('.preview-btn img').forEach((img) => {
      if (!(img instanceof HTMLImageElement)) return;
      img.addEventListener('error', () => {
        const fallback = img.dataset.fullSrc || previewFallbackUrl(img.src);
        if (fallback && img.src !== fallback) {
          img.src = fallback;
        }
      }, { once: true });
    });
  }

  function cardKey(item) {
    return item.amine_image_guid || item.file_name || '';
  }

  function itemImageGuid(item, card = null) {
    const fromItem = String(item?.amine_image_guid || '').trim();
    if (fromItem) return fromItem;
    const fromCard = String(card?.dataset?.imageGuid || '').trim();
    return fromCard;
  }

  function chipsHtml(key) {
    const items = sourceMaterialMap.get(key) || [];
    if (!items.length) {
      return '<span class="text-[11px] text-text-muted">لم تُضف مواد بعد — يمكن اختيار أكثر من مادة.</span>';
    }
    return items.map((item, index) => (
      `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-surface-low text-[11px]">`
      + `${escapeHtml(item.label)}`
      + `<button type="button" data-remove="${index}" class="text-red-600" title="إزالة">×</button>`
      + `</span>`
    )).join('');
  }

  function closeSuggestions(card) {
    const sug = card?.querySelector('.suggestions');
    if (!sug) return;
    sug.classList.add('hidden');
    sug.innerHTML = '';
  }

  function openLightbox(url, caption) {
    if (!url || !imageLightbox || !lightboxImg) return;
    lightboxImg.src = url;
    lightboxImg.alt = caption || '';
    lightboxImg.style.transform = '';
    lightboxImg.style.transformOrigin = 'center center';
    lightboxImg.style.cursor = 'zoom-in';
    lightboxImg.dataset.zoomed = '0';
    if (lightboxCaption) lightboxCaption.textContent = caption || '';
    imageLightbox.classList.remove('hidden');
    imageLightbox.classList.add('flex');
  }

  function closeLightbox() {
    if (!imageLightbox || !lightboxImg) return;
    imageLightbox.classList.add('hidden');
    imageLightbox.classList.remove('flex');
    lightboxImg.src = '';
    lightboxImg.style.transform = '';
    lightboxImg.dataset.zoomed = '0';
  }

  function toggleLightboxZoom(event) {
    if (!lightboxImg || lightboxImg.src === '') return;
    const rect = lightboxImg.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 100;
    const y = ((event.clientY - rect.top) / rect.height) * 100;
    if (lightboxImg.dataset.zoomed === '1') {
      lightboxImg.style.transform = '';
      lightboxImg.style.transformOrigin = 'center center';
      lightboxImg.style.cursor = 'zoom-in';
      lightboxImg.dataset.zoomed = '0';
      return;
    }
    lightboxImg.style.transformOrigin = `${x}% ${y}%`;
    lightboxImg.style.transform = 'scale(2.5)';
    lightboxImg.style.cursor = 'zoom-out';
    lightboxImg.dataset.zoomed = '1';
  }

  async function searchMaterials(keyword) {
    const payload = await fetchJson(`${API_URL}?action=material-search&q=${encodeURIComponent(keyword)}&page_size=40`);
    return payload.items || [];
  }

  async function postAction(action, fields) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(fields).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((entry) => form.append(`${key}[]`, entry));
      } else if (value !== undefined && value !== null && value !== '') {
        form.append(key, value);
      }
    });
    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  async function postAssignForm(action, item, items, card) {
    const form = new FormData();
    form.append('action', action);
    form.append('source_file_name', item.file_name || '');
    form.append('amine_image_guid', item.amine_image_guid || '');
    items.forEach((row) => form.append('material_guids[]', row.guid));
    const detailsCheck = card?.querySelector('.add-details-check');
    if (detailsCheck instanceof HTMLInputElement && detailsCheck.checked && !detailsCheck.disabled) {
      form.append('add_details', '1');
    }

    if (action === 'reassign-materials') {
      form.append('image_guid', item.amine_image_guid || '');
      form.append('material_guid', item.linked_material_guid || '');
    }

    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  function handleCardAfterAssign(card, item) {
    sourceMaterialMap.set(cardKey(item), []);
    if (currentLinkFilter() === 'unlinked' || !item.is_linked_to_material) {
      removeCard(card, item);
      return;
    }
    loadSources(page);
  }

  async function assign(item, items, card, button, statusEl = null) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
      return;
    }
    button.disabled = true;
    linkStatus.textContent = 'جاري الربط...';
    if (statusEl) statusEl.textContent = 'جاري الربط...';
    try {
      const payload = await postAssignForm('assign-materials', item, items, card);
      linkStatus.textContent = payload.message || '';
      if (statusEl) statusEl.textContent = payload.message || '';
      if (payload.items && payload.items.length && !payload.ok) {
        const firstFail = payload.items.find((row) => row && row.ok === false);
        if (firstFail?.message) {
          const extra = `${firstFail.material_code || ''} ${firstFail.material_name || ''}`.trim();
          const detail = extra ? `${extra}: ${firstFail.message}` : firstFail.message;
          linkStatus.textContent = detail;
          if (statusEl) statusEl.textContent = detail;
        }
      }
      if (payload.ok) handleCardAfterAssign(card, item);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الربط.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
    } finally {
      button.disabled = false;
    }
  }

  async function reassign(item, items, card, button, statusEl) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل للاستبدال.';
      if (statusEl) statusEl.textContent = message;
      return;
    }
    if (!confirm('سيتم فك الربط الحالي ثم ربط الصورة بالمواد المختارة. متابعة؟')) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري الاستبدال...';
    try {
      const payload = await postAssignForm('reassign-materials', item, items, card);
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterAssign(card, item);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الاستبدال.';
      if (statusEl) statusEl.textContent = message;
    } finally {
      button.disabled = false;
    }
  }

  async function unlink(item, card, button, statusEl) {
    if (!confirm('فك ربط هذه الصورة بالمادة الحالية؟')) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري فك الربط...';
    try {
      const payload = await postAction('unlink-image', {
        image_guid: item.amine_image_guid,
        material_guid: item.linked_material_guid,
      });
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterUnlink(card, item);
    } catch {
      if (statusEl) statusEl.textContent = 'تعذر فك الربط.';
    } finally {
      button.disabled = false;
    }
  }

  async function deleteImage(item, card, button, statusEl) {
    const imageGuid = itemImageGuid(item, card);
    const linkedNote = item.is_linked_to_material
      ? ' سيتم أيضاً فك ربطها بالمادة وحذف سجلها من قواعد البيانات.'
      : ' سيتم أيضاً حذف سجلها من قواعد البيانات.';
    if (!confirm(`حذف الصورة من bm000 والموقع نهائياً؟${linkedNote}`)) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري الحذف...';
    try {
      const payload = await postAction('delete-image', {
        image_guid: imageGuid,
        file_name: item.file_name,
        material_guid: item.linked_material_guid,
      });
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterDelete(card, item);
    } catch {
      if (statusEl) statusEl.textContent = 'تعذر حذف الصورة.';
    } finally {
      button.disabled = false;
    }
  }

  function selectedUnlinkedCheckboxes() {
    return Array.from(sourceCards?.querySelectorAll('.unlinked-select-check:checked') || []);
  }

  function collectSelectedUnlinked() {
    return selectedUnlinkedCheckboxes().map((input) => ({
      image_guid: String(input.dataset.imageGuid || '').trim(),
      file_name: String(input.dataset.fileName || '').trim(),
    })).filter((row) => row.image_guid !== '');
  }

  function syncSelectAllUnlinked() {
    if (!selectAllUnlinked) return;
    const boxes = Array.from(sourceCards?.querySelectorAll('.unlinked-select-check') || []);
    if (boxes.length === 0) {
      selectAllUnlinked.checked = false;
      selectAllUnlinked.indeterminate = false;
      return;
    }
    const checkedCount = boxes.filter((box) => box.checked).length;
    selectAllUnlinked.checked = checkedCount === boxes.length;
    selectAllUnlinked.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
  }

  function updateDeleteUnlinkedControls() {
    const showBulk = currentLinkFilter() === 'unlinked';
    const selectedCount = collectSelectedUnlinked().length;
    deleteAllUnlinkedBtn?.classList.toggle('hidden', !showBulk || deleteUnlinkedRunning);
    deleteSelectedUnlinkedBtn?.classList.toggle('hidden', !showBulk || deleteUnlinkedRunning);
    selectAllUnlinkedWrap?.classList.toggle('hidden', !showBulk || deleteUnlinkedRunning);
    pauseDeleteUnlinkedBtn?.classList.toggle('hidden', !showBulk || !deleteUnlinkedRunning || deleteUnlinkedPaused);
    resumeDeleteUnlinkedBtn?.classList.toggle('hidden', !showBulk || !deleteUnlinkedRunning || !deleteUnlinkedPaused);
    cancelDeleteUnlinkedBtn?.classList.toggle('hidden', !showBulk || !deleteUnlinkedRunning);
    if (deleteSelectedUnlinkedBtn) {
      deleteSelectedUnlinkedBtn.disabled = selectedCount === 0;
      deleteSelectedUnlinkedBtn.textContent = selectedCount > 0
        ? `حذف المحدد (${selectedCount})`
        : 'حذف المحدد';
    }
    if (!deleteUnlinkedRunning) {
      deleteUnlinkedProgressWrap?.classList.add('hidden');
    }
  }

  /** @deprecated kept for cached script compatibility */
  function syncBulkDeleteButton() {
    updateDeleteUnlinkedControls();
  }

  function renderDeleteUnlinkedProgress() {
    const total = Math.max(1, deleteUnlinkedInitialTotal || 1);
    const done = deleteUnlinkedProcessed + deleteUnlinkedFailed;
    const pct = Math.min(100, Math.round((done / total) * 100));
    deleteUnlinkedProgressWrap?.classList.remove('hidden');
    if (deleteUnlinkedProgressLabel) {
      deleteUnlinkedProgressLabel.textContent = `${done} / ${deleteUnlinkedInitialTotal}`;
    }
    if (deleteUnlinkedProgressBar) {
      deleteUnlinkedProgressBar.style.width = `${pct}%`;
    }
    if (deleteUnlinkedStatusLabel) {
      deleteUnlinkedStatusLabel.textContent = deleteUnlinkedPaused
        ? 'متوقف مؤقتاً'
        : `تم ${deleteUnlinkedProcessed}${deleteUnlinkedFailed > 0 ? ` — فشل ${deleteUnlinkedFailed}` : ''}`;
    }
  }

  async function runDeleteUnlinkedLoop() {
    while (!deleteUnlinkedPaused && !deleteUnlinkedCancelled) {
      let payload;
      try {
        if (Array.isArray(deleteUnlinkedQueue)) {
          const next = deleteUnlinkedQueue.shift();
          if (!next) {
            break;
          }
          payload = await postAction('delete-unlinked-next', {
            image_guid: next.image_guid,
            file_name: next.file_name,
          });
          payload.done = deleteUnlinkedQueue.length === 0;
        } else {
          payload = await postAction('delete-unlinked-next', {});
        }
      } catch {
        linkStatus.textContent = 'انقطع الاتصال — اضغط «استئناف الحذف».';
        deleteUnlinkedPaused = true;
        updateDeleteUnlinkedControls();
        return;
      }

      if (deleteUnlinkedCancelled) {
        break;
      }

      if (payload.deleted) {
        deleteUnlinkedProcessed += 1;
        const deletedGuid = String(payload.image_guid || '');
        const deletedCard = deletedGuid
          ? sourceCards?.querySelector(`article[data-image-guid="${deletedGuid}"]`)
          : null;
        deletedCard?.remove();
      } else if (!payload.done) {
        deleteUnlinkedFailed += 1;
      }

      if (typeof payload.remaining === 'number' && deleteUnlinkedInitialTotal === 0 && !Array.isArray(deleteUnlinkedQueue)) {
        deleteUnlinkedInitialTotal = deleteUnlinkedProcessed + deleteUnlinkedFailed + payload.remaining;
      }

      renderDeleteUnlinkedProgress();
      linkStatus.textContent = payload.message || linkStatus.textContent;
      syncSelectAllUnlinked();
      updateDeleteUnlinkedControls();

      if (payload.done) {
        if (!deleteUnlinkedCancelled) {
          linkStatus.textContent = `اكتمل الحذف — نجح ${deleteUnlinkedProcessed}${deleteUnlinkedFailed > 0 ? `، فشل ${deleteUnlinkedFailed}` : ''}.`;
        }
        deleteUnlinkedRunning = false;
        deleteUnlinkedQueue = null;
        updateDeleteUnlinkedControls();
        ensureCardsPlaceholder();
        await loadSources(page || 1);
        return;
      }
    }
    updateDeleteUnlinkedControls();
  }

  function resetDeleteUnlinkedState() {
    deleteUnlinkedRunning = false;
    deleteUnlinkedPaused = false;
    deleteUnlinkedCancelled = false;
    deleteUnlinkedQueue = null;
    deleteUnlinkedProcessed = 0;
    deleteUnlinkedFailed = 0;
    deleteUnlinkedInitialTotal = 0;
    updateDeleteUnlinkedControls();
  }

  function cancelDeleteUnlinked() {
    if (!deleteUnlinkedRunning) return;
    deleteUnlinkedCancelled = true;
    deleteUnlinkedPaused = false;
    linkStatus.textContent = `تم الإلغاء — نجح ${deleteUnlinkedProcessed}${deleteUnlinkedFailed > 0 ? `، فشل ${deleteUnlinkedFailed}` : ''}.`;
    resetDeleteUnlinkedState();
  }

  async function startDeleteUnlinked(queue, confirmMessage) {
    if (deleteUnlinkedRunning) return;
    if (currentLinkFilter() !== 'unlinked') return;
    if (!confirm(confirmMessage)) return;

    deleteUnlinkedRunning = true;
    deleteUnlinkedPaused = false;
    deleteUnlinkedCancelled = false;
    deleteUnlinkedProcessed = 0;
    deleteUnlinkedFailed = 0;
    deleteUnlinkedQueue = queue;
    deleteUnlinkedInitialTotal = Array.isArray(queue)
      ? queue.length
      : (totalCount > 0 ? totalCount : 0);
    updateDeleteUnlinkedControls();
    renderDeleteUnlinkedProgress();
    linkStatus.textContent = Array.isArray(queue)
      ? `جاري حذف ${queue.length} صورة محددة...`
      : 'جاري حذف الصور غير المرتبطة...';
    await runDeleteUnlinkedLoop();
  }

  async function processDeleteAllUnlinked() {
    await startDeleteUnlinked(null, 'حذف جميع الصور غير المرتبطة من bm000 والموقع؟ لا يمكن التراجع.');
  }

  async function processDeleteSelectedUnlinked() {
    const selected = collectSelectedUnlinked();
    if (!selected.length) {
      linkStatus.textContent = 'حدّد صورة واحدة على الأقل للحذف.';
      return;
    }
    await startDeleteUnlinked(
      [...selected],
      `حذف ${selected.length} صورة محددة من bm000 والموقع؟ لا يمكن التراجع.`
    );
  }

  async function deleteAllUnlinked() {
    await processDeleteAllUnlinked();
  }

  async function deleteSelectedUnlinked() {
    await processDeleteSelectedUnlinked();
  }

  function bindCard(card, item) {
    const key = cardKey(item);
    const input = card.querySelector('.material-input');
    const sug = card.querySelector('.suggestions');
    const chips = card.querySelector('.chips');
    const assignBtn = card.querySelector('.assign-btn');
    const reassignBtn = card.querySelector('.reassign-btn');
    const unlinkBtn = card.querySelector('.unlink-btn');
    const deleteBtn = card.querySelector('.delete-btn');
    const cardStatus = card.querySelector('.card-status');
    const previewBtn = card.querySelector('.preview-btn');
    if (!input || !sug || !chips || !assignBtn || !cardStatus) return;

    let searchRequestId = 0;

    const renderSuggestionRows = (rows) => {
      if (!rows.length) {
        sug.innerHTML = '<div class="p-2 text-xs text-text-muted">لا نتائج</div>';
        sug.classList.remove('hidden');
        return;
      }
      sug.innerHTML = rows.map((row) => {
        const guid = row.material_guid || '';
        const code = row.material_code || '';
        const name = row.name || '';
        const label = `${code} ${name}`.trim();
        return `<button type="button" class="block w-full text-right px-3 py-2 text-xs hover:bg-surface-low add-mat" data-guid="${escapeHtml(guid)}" data-label="${escapeHtml(label)}" data-code="${escapeHtml(code)}" data-name="${escapeHtml(name)}">${escapeHtml(label)}</button>`;
      }).join('');
      sug.classList.remove('hidden');
    };

    const runMaterialLookup = async (query) => {
      const requestId = ++searchRequestId;
      sug.innerHTML = '<div class="p-2 text-xs text-text-muted">جاري البحث...</div>';
      sug.classList.remove('hidden');
      try {
        const rows = await searchMaterials(query);
        if (requestId !== searchRequestId) return;
        renderSuggestionRows(rows);
      } catch (error) {
        if (requestId !== searchRequestId) return;
        const message = error instanceof Error ? error.message : 'تعذر البحث.';
        sug.innerHTML = `<div class="p-2 text-xs text-red-600">${escapeHtml(message)}</div>`;
        sug.classList.remove('hidden');
      }
    };

    previewBtn?.addEventListener('click', () => {
      openLightbox(item.preview_full_url || item.preview_url, item.linked_material_name || '');
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        const q = input.value.trim();
        if (q.length >= MIN_MATERIAL_SEARCH_LEN) {
          runMaterialLookup(q);
        } else {
          searchRequestId += 1;
          closeSuggestions(card);
        }
        return;
      }
      if (event.key === 'Escape') {
        searchRequestId += 1;
        closeSuggestions(card);
      }
    });

    sug.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('.add-mat') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      event.preventDefault();
      event.stopPropagation();
      const guid = btn.getAttribute('data-guid') || '';
      const label = btn.getAttribute('data-label') || '';
      const code = btn.getAttribute('data-code') || '';
      const name = btn.getAttribute('data-name') || '';
      if (!guid) return;
      const selected = sourceMaterialMap.get(key) || [];
      if (!selected.some((row) => row.guid === guid)) {
        selected.push({ guid, label, code, name });
      }
      sourceMaterialMap.set(key, selected);
      chips.innerHTML = chipsHtml(key);
      closeSuggestions(card);
      input.value = '';
      input.blur();
    });

    chips.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('button[data-remove]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      event.stopPropagation();
      closeSuggestions(card);
      const idx = Number(btn.getAttribute('data-remove') || -1);
      const selected = sourceMaterialMap.get(key) || [];
      if (idx >= 0) selected.splice(idx, 1);
      sourceMaterialMap.set(key, selected);
      chips.innerHTML = chipsHtml(key);
    });

    assignBtn.addEventListener('click', async () => {
      closeSuggestions(card);
      const selected = sourceMaterialMap.get(key) || [];
      if (!selected.length) {
        const message = 'أضف مادة واحدة على الأقل قبل الربط.';
        linkStatus.textContent = message;
        cardStatus.textContent = message;
        return;
      }
      await assign(item, selected, card, assignBtn, cardStatus);
    });

    reassignBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      const selected = sourceMaterialMap.get(key) || [];
      await reassign(item, selected, card, reassignBtn, cardStatus);
    });

    unlinkBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      await unlink(item, card, unlinkBtn, cardStatus);
    });

    deleteBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      await deleteImage(item, card, deleteBtn, cardStatus);
    });
  }

  function renderSources(payload) {
    const items = payload.items || [];
    if (!items.length) {
      sourceCards.innerHTML = '<div class="text-xs text-text-muted">لا توجد صور في هذه الصفحة.</div>';
    } else {
      sourceCards.innerHTML = items.map((item) => {
        const key = cardKey(item);
        const isLinked = !!item.is_linked_to_material;
        const materialTitle = isLinked
          ? escapeHtml(item.linked_material_name || 'مادة مرتبطة')
          : '<span class="text-text-muted">غير مرتبطة بمادة</span>';
        const materialCode = item.linked_material_code
          ? `<span class="material-code text-xs text-text-muted font-mono" dir="ltr">${escapeHtml(item.linked_material_code)}</span>`
          : '';
        const linkBadge = isLinked
          ? '<span class="link-badge text-[10px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">مرتبطة</span>'
          : '<span class="link-badge text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full">غير مرتبطة</span>';
        const previewSrc = item.preview_url || item.preview_full_url;
        const previewFullSrc = item.preview_full_url || item.preview_url;
        const preview = previewSrc
          ? `<button type="button" class="preview-btn group relative w-full h-48 rounded-lg border border-border-subtle bg-surface-low overflow-hidden" title="تكبير الصورة">
              <img src="${escapeHtml(previewSrc)}" data-full-src="${escapeHtml(previewFullSrc)}" class="w-full h-full object-contain bg-surface-low" alt="" decoding="async">
              <span class="absolute bottom-2 left-2 rounded-md bg-black/60 text-white text-[10px] px-2 py-1 opacity-90 group-hover:bg-black/80">🔍 تكبير</span>
            </button>`
          : '<div class="w-full h-48 rounded-lg border border-border-subtle bg-surface-low"></div>';
        const reassignBlock = isLinked
          ? `<button type="button" class="reassign-btn h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold w-full">استبدال الربط بمواد جديدة</button>`
          : '';
        const unlinkBlock = isLinked
          ? `<button type="button" class="unlink-btn h-8 px-3 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold w-full">فك الربط</button>`
          : '';

        const showSelect = currentLinkFilter() === 'unlinked' && !isLinked;
        const selectBlock = showSelect
          ? `<label class="inline-flex items-center gap-2 text-[11px] text-text-muted select-none">
              <input type="checkbox" class="unlinked-select-check rounded border-border-subtle" data-image-guid="${escapeHtml(item.amine_image_guid || '')}" data-file-name="${escapeHtml(item.file_name || '')}">
              تحديد للحذف
            </label>`
          : '';

        return `<article class="rounded-xl border border-border-subtle p-3 bg-white space-y-2" data-key="${escapeHtml(key)}" data-image-guid="${escapeHtml(item.amine_image_guid || '')}" data-file-name="${escapeHtml(item.file_name || '')}" data-linked="${isLinked ? '1' : '0'}">
          <div class="flex items-center justify-between gap-2">${selectBlock}${selectBlock ? '' : '<span></span>'}</div>
          ${preview}
          <div class="space-y-1">
            <div class="flex items-center justify-between gap-2">${linkBadge}</div>
            <p class="material-title text-sm font-bold leading-snug">${materialTitle}</p>
            ${materialCode}
          </div>
          <div class="relative">
            <input class="material-input h-9 w-full rounded-lg border border-border-subtle px-3 text-xs" placeholder="ابحث بالاسم أو الرمز — Enter للبحث">
            <div class="suggestions hidden absolute z-20 mt-1 w-full bg-white border border-border-subtle rounded-lg shadow max-h-48 overflow-auto"></div>
          </div>
          <div class="chips flex flex-wrap gap-1">${chipsHtml(key)}</div>
          <label class="add-details-wrap flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-low/50 px-2.5 py-2 text-[11px] leading-relaxed cursor-pointer select-none">
            <input type="checkbox" class="add-details-check mt-0.5 shrink-0" ${CAN_ADD_DETAILS ? 'checked' : 'disabled'}>
            <span>
              هامش سفلي في الصورة:
              <strong dir="ltr">رمز - اسم</strong>،
              <strong>التعبئة : الكمية الوحدة</strong>،
              و<strong>اسم الشركة + الموبايل</strong> في الزاوية اليسار
            </span>
          </label>
          <button type="button" class="assign-btn h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold w-full">ربط المواد المضافة</button>
          ${reassignBlock}
          ${unlinkBlock}
          <button type="button" class="delete-btn h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold w-full">حذف الصورة</button>
          <p class="card-status text-[11px] text-text-muted"></p>
        </article>`;
      }).join('');

      items.forEach((item, index) => {
        const card = sourceCards.children[index];
        if (card) bindCard(card, item);
      });
      bindPreviewImages();
      syncSelectAllUnlinked();
      updateDeleteUnlinkedControls();
    }

    page = Number(payload.page || 1);
    hasMore = !!payload.has_more;
    totalCount = Number(payload.total_count || 0);
    updatePageLabel();
    sourcePrevBtn.disabled = page <= 1;
    sourceNextBtn.disabled = !hasMore;
    updateDeleteUnlinkedControls();
  }

  async function loadSources(targetPage = 1) {
    if (instance.disposed) return;
    const requestId = ++instance.loadId;
    showSourcesLoading();
    const linkFilter = currentLinkFilter();
    const materialQuery = sourceMaterialSearch?.value.trim() || '';
    try {
      const payload = await fetchJson(`${API_URL}?action=link-sources-page&page=${targetPage}&page_size=${pageSize}&link_filter=${encodeURIComponent(linkFilter)}&material_query=${encodeURIComponent(materialQuery)}`);
      if (instance.disposed || signal.aborted || requestId !== instance.loadId) return;
      if (!payload.ok) {
        if (sourceCards) {
          sourceCards.innerHTML = '<div class="text-xs text-red-600">تعذر تحميل الصور.</div>';
        }
        if (linkStatus) {
          linkStatus.textContent = payload.message || 'تعذر تحميل الصور.';
        }
        return;
      }
      if (linkStatus) linkStatus.textContent = '';
      renderSources(payload);
    } catch (error) {
      if (instance.disposed || signal.aborted || error?.name === 'AbortError' || requestId !== instance.loadId) return;
      if (sourceCards) {
        sourceCards.innerHTML = '<div class="text-xs text-red-600">تعذر تحميل الصور.</div>';
      }
      if (linkStatus) {
        linkStatus.textContent = error instanceof Error ? error.message : 'تعذر تحميل الصور.';
      }
    }
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (!target.closest('.material-input') && !target.closest('.suggestions')) {
      sourceCards?.querySelectorAll('article').forEach((card) => closeSuggestions(card));
    }
  }, { signal });

  lightboxCloseBtn?.addEventListener('click', closeLightbox, { signal });
  lightboxImg?.addEventListener('click', (event) => {
    event.stopPropagation();
    toggleLightboxZoom(event);
  }, { signal });
  imageLightbox?.addEventListener('click', (event) => {
    if (event.target === imageLightbox) closeLightbox();
  }, { signal });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeLightbox();
  }, { signal });

  sourcePrevBtn?.addEventListener('click', () => { if (page > 1) loadSources(page - 1); }, { signal });
  sourceNextBtn?.addEventListener('click', () => { if (hasMore) loadSources(page + 1); }, { signal });
  reloadSourcesBtn?.addEventListener('click', () => loadSources(page), { signal });
  applySourceFiltersBtn?.addEventListener('click', applySourceMaterialSearch, { signal });
  deleteAllUnlinkedBtn?.addEventListener('click', deleteAllUnlinked, { signal });
  deleteSelectedUnlinkedBtn?.addEventListener('click', deleteSelectedUnlinked, { signal });
  cancelDeleteUnlinkedBtn?.addEventListener('click', cancelDeleteUnlinked, { signal });
  selectAllUnlinked?.addEventListener('change', () => {
    const checked = !!selectAllUnlinked.checked;
    sourceCards?.querySelectorAll('.unlinked-select-check').forEach((box) => {
      box.checked = checked;
    });
    updateDeleteUnlinkedControls();
  }, { signal });
  sourceCards?.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement && target.classList.contains('unlinked-select-check')) {
      syncSelectAllUnlinked();
      updateDeleteUnlinkedControls();
    }
  }, { signal });
  pauseDeleteUnlinkedBtn?.addEventListener('click', () => {
    if (!deleteUnlinkedRunning) return;
    deleteUnlinkedPaused = true;
    updateDeleteUnlinkedControls();
    if (linkStatus) linkStatus.textContent = 'متوقف مؤقتاً — اضغط «استئناف الحذف».';
  }, { signal });
  resumeDeleteUnlinkedBtn?.addEventListener('click', async () => {
    if (!deleteUnlinkedRunning || !deleteUnlinkedPaused) return;
    deleteUnlinkedPaused = false;
    if (linkStatus) linkStatus.textContent = 'جاري حذف الصور غير المرتبطة...';
    await runDeleteUnlinkedLoop();
  }, { signal });
  linkFilterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const filter = btn.getAttribute('data-filter') || 'all';
      clearSourceMaterialSearch();
      setLinkFilter(filter);
      loadSources(1);
    }, { signal });
  });

  sourceMaterialSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      applySourceMaterialSearch();
    }
  }, { signal });
  setLinkFilter('unlinked');
  loadSources(1);

  window.addEventListener('pageshow', (event) => {
    if (!event.persisted || signal.aborted) return;
    if (!panel.isConnected) return;
    loadSources(page || 1);
  }, { signal });
  };
})();
