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

const API_URL = '/dashboard/material-images-api.php';
  const API_HEADERS = {
    Accept: 'application/json',
    'X-Dashboard-Ajax': '1',
    'X-Requested-With': 'XMLHttpRequest',
  };

  async function fetchJson(url, options = {}, attempt = 0) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      signal: options.signal ?? signal,
      headers: { ...API_HEADERS, ...(options.headers || {}) },
    });
    const text = await response.text();
    if (!text.trim()) {
      throw new Error('استجابة فارغة من الخادم. تحقق من سجل أخطاء PHP.');
    }
    try {
      const payload = JSON.parse(text);
      if (payload?.login && attempt < 1) {
        await new Promise((resolve) => window.setTimeout(resolve, 300));
        return fetchJson(url, options, attempt + 1);
      }
      return payload;
    } catch {
      if (attempt < 1) {
        await new Promise((resolve) => window.setTimeout(resolve, 350));
        return fetchJson(url, options, attempt + 1);
      }
      throw new Error(`استجابة غير صالحة من الخادم: ${text.slice(0, 180)}`);
    }
  }

  const sourceCards = panel.querySelector('#sourceCards');
  const assignInProgressZone = panel.querySelector('#assignInProgressZone');
  const assignInProgressList = panel.querySelector('#assignInProgressList');
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
  const orphanGhostScanBtn = panel.querySelector('#orphanGhostScanBtn');
  const orphanGhostDryRunBtn = panel.querySelector('#orphanGhostDryRunBtn');
  const orphanGhostPurgeBtn = panel.querySelector('#orphanGhostPurgeBtn');
  const orphanGhostProgressWrap = panel.querySelector('#orphanGhostProgressWrap');
  const orphanGhostProgressLabel = panel.querySelector('#orphanGhostProgressLabel');
  const orphanGhostProgressBar = panel.querySelector('#orphanGhostProgressBar');
  const orphanGhostReport = panel.querySelector('#orphanGhostReport');
  const linkFilterButtons = panel.querySelectorAll('.link-filter-btn');
  const linkStatus = panel.querySelector('#linkStatus');
  
  const imageLightbox = panel.querySelector('#imageLightbox');
  const lightboxImg = panel.querySelector('#lightboxImg');
  const lightboxCaption = panel.querySelector('#lightboxCaption');
  const lightboxCloseBtn = panel.querySelector('#lightboxCloseBtn');
  const lightboxLinkCard = panel.querySelector('#lightboxLinkCard');
  const lightboxMaterialInput = panel.querySelector('#lightboxMaterialInput');
  const lightboxSuggestions = panel.querySelector('#lightboxSuggestions');
  const lightboxChips = panel.querySelector('#lightboxChips');
  const lightboxAssignBtn = panel.querySelector('#lightboxAssignBtn');
  const lightboxSkipBtn = panel.querySelector('#lightboxSkipBtn');
  const lightboxStatus = panel.querySelector('#lightboxStatus');
  let sourceItems = [];
  let lightboxItem = null;
  let lightboxSelected = [];
  let lightboxSearchTimer = null;
  let lightboxSearchRequest = 0;
  let lightboxActiveResultIndex = -1;
  let openNextUnlinkedAfterLoad = false;
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
  const recentlyLinkedKeys = new Set();
  const MIN_MATERIAL_SEARCH_LEN = 2;
  const SOURCE_FILTER_DEBOUNCE_MS = 500;
  const MATERIAL_SEARCH_DEBOUNCE_MS = 400;
  let sourceSearchTimer = null;
  let orphanGhostScanId = '';
  let orphanGhostScanning = false;

  function clearSourceMaterialSearch() {
    if (sourceMaterialSearch) {
      sourceMaterialSearch.value = '';
    }
  }

  function applySourceMaterialSearch() {
    loadSources(1);
  }

  function currentLinkFilter() {
    const active = panel.querySelector('.link-filter-btn.is-active');
    return active?.getAttribute('data-filter') || 'unlinked';
  }

  function setLinkFilter(filter) {
    linkFilterButtons.forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-filter') === filter);
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
    sourceCards.innerHTML = '<div class="dash-mi-cards__status" aria-busy="true">'
      + '<div class="dash-spinner" role="status" aria-label="جاري تحميل الصور"></div>'
      + '<p class="text-sm font-medium">جاري تحميل الصور...</p>'
      + '</div>';
    if (sourcePageLabel) sourcePageLabel.textContent = 'جاري التحميل...';
    if (sourcePrevBtn) sourcePrevBtn.disabled = true;
    if (sourceNextBtn) sourceNextBtn.disabled = true;
    if (linkStatus) linkStatus.textContent = '';
  }

  function visibleSourceCardCount() {
    return sourceCards?.querySelectorAll('article').length ?? 0;
  }

  function syncPaginationAfterCountChange() {
    hasMore = page * pageSize < totalCount;
    updatePageLabel();
    if (sourceNextBtn) sourceNextBtn.disabled = !hasMore;
    if (sourcePrevBtn) sourcePrevBtn.disabled = page <= 1;
  }

  function maybeRefillSourcePageAfterLink() {
    if (currentLinkFilter() !== 'unlinked') return;
    if (totalCount <= 0) return;
    const inGrid = visibleSourceCardCount();
    const inAssign = assignInProgressList?.querySelectorAll('article').length ?? 0;
    if (inGrid > 0 || inAssign > 0) return;
    loadSources(1);
  }

  function removeCard(card, item) {
    if (!card) return;
    sourceMaterialMap.delete(cardKey(item));
    card.remove();
    totalCount = Math.max(0, totalCount - 1);
    syncPaginationAfterCountChange();
    syncAssignInProgressZone();
    ensureCardsPlaceholder();
    maybeRefillSourcePageAfterLink();
  }

  function syncAssignInProgressZone() {
    if (!assignInProgressZone || !assignInProgressList) return;
    const busy = assignInProgressList.querySelectorAll('article.dash-mi-card').length > 0;
    assignInProgressZone.hidden = !busy;
  }

  function materialLabelsForAssign(items) {
    return (items || [])
      .map((row) => String(row?.code || row?.name || '').trim())
      .filter(Boolean)
      .slice(0, 4);
  }

  function flyCardToAssignZone(card, items) {
    if (!card || !assignInProgressList || !assignInProgressZone) {
      setCardAssigning(card, true);
      return;
    }
    const first = card.getBoundingClientRect();
    assignInProgressZone.hidden = false;
    card.classList.add('is-assigning-flight');
    card.classList.remove('is-assign-done', 'is-assign-failed');
    setCardAssigning(card, true);
    const labels = materialLabelsForAssign(items);
    const statusEl = card.querySelector('.card-status');
    if (statusEl) {
      statusEl.textContent = labels.length
        ? `جاري الربط: ${labels.join('، ')}${items.length > labels.length ? '…' : ''}`
        : 'جاري الربط وحفظ النسخ…';
    }
    const cardInput = card.querySelector('.material-input');
    if (cardInput instanceof HTMLElement) {
      cardInput.blur();
    }
    assignInProgressList.appendChild(card);
    if (imageLightbox && !imageLightbox.hidden) {
      window.requestAnimationFrame(() => scheduleLightboxSearchFocus());
    }
    const last = card.getBoundingClientRect();
    const dx = first.left - last.left;
    const dy = first.top - last.top;
    if (typeof card.animate === 'function' && (Math.abs(dx) > 1 || Math.abs(dy) > 1)) {
      card.animate(
        [
          { transform: `translate(${dx}px, ${dy}px)`, opacity: 0.92 },
          { transform: 'translate(0, 0)', opacity: 1 },
        ],
        { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
      );
    }
    syncAssignInProgressZone();
  }

  function returnCardFromAssignZone(card) {
    if (!card) return;
    card.classList.remove('is-assigning-flight', 'is-assign-done');
    card.classList.add('is-assign-failed');
    setCardAssigning(card, false);
    if (sourceCards && card.parentElement === assignInProgressList) {
      const first = card.getBoundingClientRect();
      sourceCards.prepend(card);
      const last = card.getBoundingClientRect();
      const dx = first.left - last.left;
      const dy = first.top - last.top;
      if (typeof card.animate === 'function' && (Math.abs(dx) > 1 || Math.abs(dy) > 1)) {
        card.animate(
          [
            { transform: `translate(${dx}px, ${dy}px)` },
            { transform: 'translate(0, 0)' },
          ],
          { duration: 320, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
        );
      }
    }
    window.setTimeout(() => card.classList.remove('is-assign-failed'), 1800);
    syncAssignInProgressZone();
  }

  async function finishAssignCardSuccess(card, item) {
    if (!card) return;
    card.classList.add('is-assign-done');
    card.classList.remove('is-assign-failed');
    const statusEl = card.querySelector('.card-status');
    if (statusEl) statusEl.textContent = 'تم الربط وحفظ النسخ ✓';
    try {
      if (typeof card.animate === 'function') {
        await card.animate(
          [
            { opacity: 1, transform: 'scale(1)' },
            { opacity: 0, transform: 'scale(0.94) translateY(-10px)' },
          ],
          { duration: 380, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', fill: 'forwards' }
        ).finished;
      }
    } catch {
      // Animation may be interrupted if the panel reloads.
    }
    removeCard(card, item);
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
        const card = img.closest('article');
        if (card?.dataset.assigning === '1') return;
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

  function cssAttrEscape(value) {
    return String(value ?? '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function findCardForItem(item, card = null) {
    if (card?.isConnected) return card;
    const scopes = [assignInProgressList, sourceCards].filter(Boolean);
    const guid = itemImageGuid(item);
    const fileName = String(item?.file_name || '').trim();
    for (const scope of scopes) {
      if (guid) {
        const byGuid = scope.querySelector(`article[data-image-guid="${cssAttrEscape(guid)}"]`);
        if (byGuid) return byGuid;
      }
      if (fileName) {
        const byFile = scope.querySelector(`article[data-file-name="${cssAttrEscape(fileName)}"]`);
        if (byFile) return byFile;
      }
      const key = cardKey(item);
      if (key) {
        const byKey = scope.querySelector(`article[data-key="${cssAttrEscape(key)}"]`);
        if (byKey) return byKey;
      }
    }
    return null;
  }

  function rememberLinkedItem(item) {
    const key = cardKey(item);
    if (key) recentlyLinkedKeys.add(key);
    const guid = itemImageGuid(item);
    if (guid) recentlyLinkedKeys.add(guid);
    const fileName = String(item?.file_name || '').trim();
    if (fileName) recentlyLinkedKeys.add(fileName);
  }

  function isRecentlyLinkedItem(item) {
    const key = cardKey(item);
    if (key && recentlyLinkedKeys.has(key)) return true;
    const guid = String(item?.amine_image_guid || '').trim();
    if (guid && recentlyLinkedKeys.has(guid)) return true;
    const fileName = String(item?.file_name || '').trim();
    return fileName !== '' && recentlyLinkedKeys.has(fileName);
  }

  function removeCardByItem(item, card = null) {
    const target = findCardForItem(item, card);
    if (target) removeCard(target, item);
  }

  function setCardAssigning(card, assigning) {
    if (!card) return;
    card.dataset.assigning = assigning ? '1' : '0';
    // Keep preview visible — the card flies into «جاري الربط» with its image.
    const previewBtn = card.querySelector('.preview-btn');
    if (previewBtn instanceof HTMLElement) {
      previewBtn.style.visibility = '';
      previewBtn.style.pointerEvents = assigning ? 'none' : '';
    }
  }

  function assignSucceeded(payload) {
    if (!payload || typeof payload !== 'object') return false;
    const linked = Number(payload.linked || 0);
    const failed = Number(payload.failed || 0);
    if (linked <= 0 || failed > 0) return false;
    if (payload.staging_kept === true) return false;
    if (payload.ok === true) return true;
    if (Array.isArray(payload.items) && payload.items.length) {
      return payload.items.every((row) => row && row.ok === true && row.local_synced !== false);
    }
    return false;
  }

  function assignProgressMessage(materialCount, elapsedSec = 0) {
    const countLabel = materialCount === 1 ? 'مادة واحدة' : `${materialCount} مواد`;
    if (elapsedSec <= 0) {
      return `جاري الربط وحفظ النسخ على الموقع… قد يستغرق بضع ثوانٍ لكل مادة (${countLabel}).`;
    }
    return `جاري الربط وحفظ النسخ على الموقع… مضى ${elapsedSec}ث — ${countLabel}. لا تغلق الصفحة.`;
  }

  function formatAssignFailureDetail(payload) {
    const base = String(payload?.message || '').trim();
    if (Array.isArray(payload?.items) && payload.items.length) {
      const firstFail = payload.items.find((row) => row && row.ok === false);
      if (firstFail?.message) {
        const extra = `${firstFail.material_code || ''} ${firstFail.material_name || ''}`.trim();
        const detail = extra ? `${extra}: ${firstFail.message}` : firstFail.message;
        return base && !base.includes(detail) ? `${base} — ${detail}` : detail;
      }
    }
    return base;
  }

  function showAssignOutcome(payload, statusEl, fallbackSuccess) {
    const linked = Number(payload?.linked || 0);
    const failed = Number(payload?.failed || 0);
    const message = String(payload?.message || fallbackSuccess || '').trim();

    if (assignSucceeded(payload)) {
      linkStatus.textContent = message || fallbackSuccess;
      if (statusEl) statusEl.textContent = '';
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(message || fallbackSuccess, 'success');
      }
      return 'success';
    }

    const detail = formatAssignFailureDetail(payload) || 'تعذر إكمال الربط مع النسخ المحلية.';
    linkStatus.textContent = detail;
    if (statusEl) statusEl.textContent = detail;

    if (linked > 0 && failed > 0) {
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(detail, 'info');
      }
      return 'partial';
    }

    if (payload?.staging_kept === true && linked > 0) {
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(detail, 'info');
      }
      return 'partial';
    }

    if (window.dashboardApp?.showToast) {
      window.dashboardApp.showToast(detail, 'error');
    }
    return 'error';
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
    if (card?.dataset) card.dataset.suggestionIndex = '';
  }

  function highlightSuggestionButtons(container, activeIndex) {
    if (!container) return;
    const buttons = container.querySelectorAll('button[data-guid]');
    buttons.forEach((button, index) => {
      button.classList.toggle('bg-primary/10', index === activeIndex);
      button.classList.toggle('font-bold', index === activeIndex);
    });
    const active = buttons[activeIndex];
    if (active instanceof HTMLElement) {
      active.scrollIntoView({ block: 'nearest' });
    }
  }

  function suggestionListOpen(container) {
    return !!(container && !container.classList.contains('hidden')
      && container.querySelector('button[data-guid]'));
  }

  function materialFromSuggestionButton(button) {
    if (!(button instanceof HTMLButtonElement)) return null;
    const guid = button.getAttribute('data-guid') || '';
    if (!guid) return null;
    const label = button.getAttribute('data-label') || '';
    const code = button.getAttribute('data-code') || '';
    const name = button.getAttribute('data-name') || label;
    return { guid, label, code, name };
  }

  async function loadNextUnlinkedSources() {
    if (currentLinkFilter() === 'unlinked' && totalCount > 0) {
      openNextUnlinkedAfterLoad = true;
      await loadSources(1);
      return true;
    }
    if (hasMore) {
      openNextUnlinkedAfterLoad = true;
      await loadSources(page + 1);
      return true;
    }
    return false;
  }

  let lightboxSearchFocusToken = 0;
  let lightboxFocusGuardTimer = null;
  let lightboxFocusGuardDepth = 0;

  function scheduleLightboxSearchFocus() {
    if (!lightboxMaterialInput || !imageLightbox || imageLightbox.hidden) return;
    const token = ++lightboxSearchFocusToken;
    const focusInput = () => {
      if (token !== lightboxSearchFocusToken) return;
      if (!lightboxMaterialInput || imageLightbox?.hidden) return;
      try {
        lightboxMaterialInput.focus({ preventScroll: false });
      } catch {
        lightboxMaterialInput.focus();
      }
    };
    focusInput();
    window.setTimeout(focusInput, 0);
    window.setTimeout(focusInput, 80);
    window.setTimeout(focusInput, 220);
  }

  function focusFirstUnlinkedCardInput() {
    if (imageLightbox && !imageLightbox.hidden) {
      scheduleLightboxSearchFocus();
      return;
    }
    const input = sourceCards?.querySelector('article[data-linked="0"] .material-input');
    if (input instanceof HTMLInputElement) {
      input.focus();
    }
  }

  function activeInLightboxSearchUi() {
    const active = document.activeElement;
    if (!(active instanceof Node) || !lightboxMaterialInput) return false;
    if (active === lightboxMaterialInput) return true;
    if (lightboxSuggestions && lightboxSuggestions.contains(active)) return true;
    return !!(lightboxLinkCard?.contains(active)
      && active.closest('#lightboxChips, #lightboxAssignBtn, #lightboxSkipBtn'));
  }

  function tickLightboxFocusGuard() {
    if (!imageLightbox || imageLightbox.hidden || !lightboxMaterialInput) return;
    const busy = (assignInProgressList?.querySelectorAll('article').length ?? 0) > 0;
    if (!busy) return;
    if (activeInLightboxSearchUi()) return;
    scheduleLightboxSearchFocus();
  }

  function startLightboxFocusGuard() {
    if (!imageLightbox || imageLightbox.hidden) return;
    lightboxFocusGuardDepth += 1;
    if (lightboxFocusGuardTimer) return;
    lightboxFocusGuardTimer = window.setInterval(tickLightboxFocusGuard, 120);
  }

  function stopLightboxFocusGuard() {
    lightboxFocusGuardDepth = Math.max(0, lightboxFocusGuardDepth - 1);
    if (lightboxFocusGuardDepth > 0) return;
    if (lightboxFocusGuardTimer) {
      window.clearInterval(lightboxFocusGuardTimer);
      lightboxFocusGuardTimer = null;
    }
  }

  function renderLightboxChips() {
    if (!lightboxChips) return;
    if (!lightboxSelected.length) {
      lightboxChips.innerHTML = '<span class="text-[11px] text-text-muted">لم تُختر مادة بعد.</span>';
      return;
    }
    lightboxChips.innerHTML = lightboxSelected.map((row, index) => (
      `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-surface-low text-[11px]">`
      + `${escapeHtml(row.label)}`
      + `<button type="button" data-lightbox-remove="${index}" class="text-red-600" title="إزالة">×</button>`
      + `</span>`
    )).join('');
  }

  function openLightbox(url, caption) {
    if (!url || !imageLightbox || !lightboxImg) return;
    lightboxItem = null;
    lightboxLinkCard?.setAttribute('hidden', '');
    lightboxImg.src = url;
    lightboxImg.alt = caption || '';
    lightboxImg.style.transform = '';
    lightboxImg.style.transformOrigin = 'center center';
    lightboxImg.style.cursor = 'zoom-in';
    lightboxImg.dataset.zoomed = '0';
    if (lightboxCaption) lightboxCaption.textContent = caption || '';
    imageLightbox.hidden = false;
    imageLightbox.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function openLinkDesk(item) {
    const url = item?.preview_full_url || item?.preview_url || '';
    if (!url || !imageLightbox || !lightboxImg) return;
    lightboxItem = item;
    lightboxSelected = [];
    renderLightboxChips();
    if (lightboxSuggestions) {
      lightboxSuggestions.classList.add('hidden');
      lightboxSuggestions.innerHTML = '';
    }
    if (lightboxMaterialInput) lightboxMaterialInput.value = '';
    if (lightboxStatus) lightboxStatus.textContent = '';
    lightboxLinkCard?.removeAttribute('hidden');
    lightboxImg.src = url;
    lightboxImg.alt = item?.file_name || '';
    lightboxImg.style.transform = '';
    lightboxImg.style.transformOrigin = 'center center';
    lightboxImg.style.cursor = 'zoom-in';
    lightboxImg.dataset.zoomed = '0';
    if (lightboxCaption) lightboxCaption.textContent = item?.file_name || '';
    imageLightbox.hidden = false;
    imageLightbox.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    scheduleLightboxSearchFocus();
    if (lightboxImg) {
      const onReady = () => {
        lightboxImg.removeEventListener('load', onReady);
        scheduleLightboxSearchFocus();
      };
      lightboxImg.addEventListener('load', onReady);
    }
  }

  async function openNextUnlinked(current) {
    const currentKey = cardKey(current);
    let passed = currentKey === '';
    for (const candidate of sourceItems) {
      if (!passed) {
        if (cardKey(candidate) === currentKey) passed = true;
        continue;
      }
      if (candidate.is_linked_to_material || isRecentlyLinkedItem(candidate)) continue;
      openLinkDesk(candidate);
      return;
    }
    if (await loadNextUnlinkedSources()) {
      return;
    }
    closeLightbox();
    if (linkStatus) linkStatus.textContent = 'تم. لا توجد صور أخرى غير مرتبطة في هذه القائمة.';
  }

  function assignFromLightbox() {
    if (!lightboxItem) return;
    if (!lightboxSelected.length) {
      if (lightboxStatus) lightboxStatus.textContent = 'اختر المادة المطابقة لرقم الموديل في الصورة.';
      return;
    }
    const item = lightboxItem;
    const materials = lightboxSelected.map((row) => ({ ...row }));
    const card = findCardForItem(item);
    const assignBtn = card?.querySelector('.assign-btn') || lightboxAssignBtn;
    const cardStatus = card?.querySelector('.card-status');

    lightboxSelected = [];
    renderLightboxChips();
    if (lightboxMaterialInput) lightboxMaterialInput.value = '';
    if (lightboxStatus) lightboxStatus.textContent = '';

    void openNextUnlinked(item);
    void assign(item, materials, card, assignBtn, cardStatus);
    scheduleLightboxSearchFocus();
  }

  function closeLightbox() {
    if (!imageLightbox || !lightboxImg) return;
    imageLightbox.hidden = true;
    imageLightbox.classList.remove('is-open');
    document.body.style.overflow = '';
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

    if (action === 'reassign-materials') {
      form.append('image_guid', item.amine_image_guid || '');
      form.append('material_guid', item.linked_material_guid || '');
    }

    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  function itemStillInUnlinkedList(items, item) {
    const key = cardKey(item);
    const guid = String(item?.amine_image_guid || '').trim();
    const fileName = String(item?.file_name || '').trim();
    return (items || []).some((row) => {
      if (key && cardKey(row) === key) return true;
      if (guid && String(row?.amine_image_guid || '').trim() === guid) return true;
      return fileName !== '' && String(row?.file_name || '').trim() === fileName;
    });
  }

  async function recoverCardIfAssignSucceededOnServer(item, card) {
    if (currentLinkFilter() !== 'unlinked') return false;
    try {
      const payload = await fetchJson(`${API_URL}?action=link-sources-page&page=1&page_size=${Math.max(pageSize, 24)}&link_filter=unlinked`);
      if (!payload.ok || itemStillInUnlinkedList(payload.items, item)) return false;
      const successMessage = 'تم الربط وحفظ النسخ على الموقع (تأكيد من القائمة).';
      linkStatus.textContent = successMessage;
      const cardStatus = card?.querySelector('.card-status');
      if (cardStatus) cardStatus.textContent = '';
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(successMessage, 'success');
      }
      rememberLinkedItem(item);
      await finishAssignCardSuccess(card, item);
      syncSelectAllUnlinked();
      updateDeleteUnlinkedControls();
      ensureCardsPlaceholder();
      return true;
    } catch {
      return false;
    }
  }

  function handleCardAfterAssign(card, item, payload) {
    sourceMaterialMap.delete(cardKey(item));
    if (currentLinkFilter() !== 'unlinked' && item.is_linked_to_material) {
      loadSources(page);
      return;
    }
    if (!assignSucceeded(payload)) return;
    rememberLinkedItem(item);
    finishAssignCardSuccess(card, item);
    syncSelectAllUnlinked();
    updateDeleteUnlinkedControls();
    ensureCardsPlaceholder();
    window.setTimeout(() => focusFirstUnlinkedCardInput(), 0);
  }

  async function assign(item, items, card, button, statusEl = null) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
      return;
    }
    const lightboxOpen = !!(imageLightbox && !imageLightbox.hidden);
    if (lightboxOpen) {
      startLightboxFocusGuard();
    }
    if (button && button !== lightboxAssignBtn) {
      button.disabled = true;
    }
    flyCardToAssignZone(card, items);
    if (lightboxOpen) {
      scheduleLightboxSearchFocus();
    }
    const progressStart = assignProgressMessage(items.length);
    linkStatus.textContent = progressStart;
    if (statusEl) statusEl.textContent = progressStart;
    let elapsed = 0;
    const progressTimer = window.setInterval(() => {
      elapsed += 1;
      const msg = assignProgressMessage(items.length, elapsed);
      linkStatus.textContent = msg;
      const liveStatus = card?.querySelector('.card-status');
      if (liveStatus) liveStatus.textContent = msg;
      if (lightboxOpen) {
        tickLightboxFocusGuard();
      }
    }, 1000);
    try {
      const payload = await postAssignForm('assign-materials', item, items, card);
      const outcome = showAssignOutcome(payload, statusEl, 'تم الربط مع حفظ النسخ على الموقع.');
      if (outcome === 'success') {
        handleCardAfterAssign(card, item, payload);
        return;
      }
      if (outcome === 'partial') {
        returnCardFromAssignZone(card);
        return;
      }
      if (await recoverCardIfAssignSucceededOnServer(item, card)) return;
      returnCardFromAssignZone(card);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الربط.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(message, 'error');
      }
      returnCardFromAssignZone(card);
    } finally {
      window.clearInterval(progressTimer);
      if (button && button.disabled) {
        button.disabled = false;
      }
      if (lightboxOpen) {
        stopLightboxFocusGuard();
        scheduleLightboxSearchFocus();
      }
      syncAssignInProgressZone();
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
    flyCardToAssignZone(card, items);
    const progressStart = assignProgressMessage(items.length);
    if (statusEl) statusEl.textContent = progressStart;
    linkStatus.textContent = progressStart;
    let elapsed = 0;
    const progressTimer = window.setInterval(() => {
      elapsed += 1;
      const msg = assignProgressMessage(items.length, elapsed);
      linkStatus.textContent = msg;
      const liveStatus = card?.querySelector('.card-status');
      if (liveStatus) liveStatus.textContent = msg;
    }, 1000);
    try {
      const payload = await postAssignForm('reassign-materials', item, items, card);
      const outcome = showAssignOutcome(payload, statusEl, 'تم الاستبدال مع حفظ النسخ على الموقع.');
      if (outcome === 'success') {
        handleCardAfterAssign(card, item, payload);
      } else {
        returnCardFromAssignZone(card);
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الاستبدال.';
      if (statusEl) statusEl.textContent = message;
      linkStatus.textContent = message;
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(message, 'error');
      }
      returnCardFromAssignZone(card);
    } finally {
      window.clearInterval(progressTimer);
      button.disabled = false;
      syncAssignInProgressZone();
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
    if (!confirm(`حذف الصورة من المحاسبة والموقع نهائياً؟${linkedNote}`)) return;
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

  async function startDeleteUnlinked(queue, confirmMessage, options = {}) {
    if (deleteUnlinkedRunning) return;
    if (currentLinkFilter() !== 'unlinked') return;
    const requireTypedConfirm = options.requireTypedConfirm === true;
    if (requireTypedConfirm) {
      const confirmFn = window.dashboardApp?.confirmDestructive;
      if (typeof confirmFn === 'function') {
        if (!confirmFn(confirmMessage, 'حذف')) return;
      } else if (!confirm(confirmMessage)) {
        return;
      }
    } else if (!confirm(confirmMessage)) {
      return;
    }

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
    await startDeleteUnlinked(
      null,
      'حذف جميع الصور غير المرتبطة من المحاسبة والموقع؟ لا يمكن التراجع.',
      { requireTypedConfirm: true }
    );
  }

  async function processDeleteSelectedUnlinked() {
    const selected = collectSelectedUnlinked();
    if (!selected.length) {
      linkStatus.textContent = 'حدّد صورة واحدة على الأقل للحذف.';
      return;
    }
    await startDeleteUnlinked(
      [...selected],
      `حذف ${selected.length} صورة محددة من المحاسبة والموقع؟ لا يمكن التراجع.`,
      { requireTypedConfirm: selected.length >= 5 }
    );
  }

  async function deleteAllUnlinked() {
    await processDeleteAllUnlinked();
  }

  async function deleteSelectedUnlinked() {
    await processDeleteSelectedUnlinked();
  }

  async function postOrphanCleanupAction(action, fields = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(fields).forEach(([key, value]) => {
      form.append(key, value === undefined || value === null ? '' : String(value));
    });
    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  function setOrphanGhostProgress(percent, label) {
    if (orphanGhostProgressWrap) {
      orphanGhostProgressWrap.hidden = false;
      orphanGhostProgressWrap.classList.remove('hidden');
    }
    if (orphanGhostProgressLabel && label) orphanGhostProgressLabel.textContent = label;
    if (orphanGhostProgressBar) {
      orphanGhostProgressBar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    }
    if (linkStatus && label) linkStatus.textContent = label;
  }

  function orphanCleanupTimeoutHint(error) {
    const text = error instanceof Error ? error.message : String(error || '');
    if (/504|Gateway Time-out|timeout/i.test(text)) {
      return 'انتهت مهلة nginx — أعد الفحص (تم تقسيمه لدفعات أصغر). إن استمر الخطأ زِد proxy_read_timeout في nginx.';
    }
    return text;
  }

  function renderOrphanGhostReport(report) {
    if (!orphanGhostReport) return;
    const portal = Number(report.portal_orphan_count ?? 0);
    const amine = Number(report.amine_orphan_count ?? 0);
    const queue = Number(report.stale_queue_count ?? 0);
    const lines = [
      `موقع (ملف بلا bm000): ${portal}`,
      `أمين (قرص بلا bm000): ${amine}`,
      `طابور (سجل بلا صورة مسجّلة): ${queue}`,
      '',
      'عيّنة موقع:',
      ...(report.samples?.portal || []).slice(0, 8).map((row) => `  - ${row.file_name} (${row.reason || ''})`),
      '',
      'عيّنة أمين:',
      ...(report.samples?.amine || []).slice(0, 8).map((row) => `  - ${row.file_name}`),
    ];
    orphanGhostReport.textContent = lines.join('\n');
    orphanGhostReport.classList.remove('hidden');
  }

  async function runOrphanGhostScan() {
    if (orphanGhostScanning) return;
    orphanGhostScanning = true;
    orphanGhostScanId = '';
    orphanGhostDryRunBtn?.classList.add('hidden');
    orphanGhostPurgeBtn?.classList.add('hidden');
    if (orphanGhostScanBtn) orphanGhostScanBtn.disabled = true;
    if (orphanGhostReport) {
      orphanGhostReport.classList.add('hidden');
      orphanGhostReport.textContent = '';
    }
    setOrphanGhostProgress(1, 'جاري الفحص الآمن (معاينة فقط) — لا حذف بعد...');
    try {
      const init = await postOrphanCleanupAction('orphan-cleanup-init');
      if (!init.ok) {
        if (linkStatus) linkStatus.textContent = init.message || 'تعذر بدء الفحص.';
        return;
      }
      orphanGhostScanId = String(init.scan_id || '');
      if (!orphanGhostScanId) {
        if (linkStatus) linkStatus.textContent = 'تعذر بدء الفحص.';
        return;
      }
      setOrphanGhostProgress(3, init.message || 'تهيئة الفحص...');

      let registryDone = false;
      while (!registryDone) {
        const reg = await postOrphanCleanupAction('orphan-cleanup-registry-chunk', { scan_id: orphanGhostScanId });
        if (!reg.ok) {
          if (linkStatus) linkStatus.textContent = reg.message || 'توقّف تحميل سجل الأمين.';
          return;
        }
        registryDone = !!reg.done;
        const total = Math.max(1, Number(reg.registry_total || reg.registered_count || 1));
        const loaded = Number(reg.registered_count || 0);
        setOrphanGhostProgress(
          5 + Math.min(28, (loaded / total) * 28),
          reg.message || 'تحميل سجل الأمين...'
        );
      }

      const indexLocal = await postOrphanCleanupAction('orphan-cleanup-index-local', { scan_id: orphanGhostScanId });
      if (!indexLocal.ok) {
        if (linkStatus) linkStatus.textContent = indexLocal.message || 'تعذر فهرسة ملفات الموقع.';
        return;
      }
      setOrphanGhostProgress(36, indexLocal.message || 'فهرسة الموقع...');

      let portalDone = false;
      while (!portalDone) {
        const chunk = await postOrphanCleanupAction('orphan-cleanup-scan-portal', { scan_id: orphanGhostScanId });
        if (!chunk.ok) {
          if (linkStatus) linkStatus.textContent = chunk.message || 'توقّف فحص الموقع.';
          return;
        }
        portalDone = !!chunk.done;
        const total = Number(chunk.total || 1);
        const offset = Number(chunk.offset || 0);
        setOrphanGhostProgress(
          38 + Math.min(24, (offset / total) * 24),
          chunk.message || 'فحص الموقع...'
        );
      }

      let amineDone = false;
      while (!amineDone) {
        const chunk = await postOrphanCleanupAction('orphan-cleanup-scan-amine', { scan_id: orphanGhostScanId });
        if (!chunk.ok) {
          if (linkStatus) linkStatus.textContent = chunk.message || 'توقّف فحص الأمين.';
          return;
        }
        amineDone = !!chunk.done;
        const total = Math.max(1, Number(chunk.total || 1));
        const offset = Number(chunk.offset || 0);
        setOrphanGhostProgress(
          64 + Math.min(26, (offset / total) * 26),
          chunk.message || 'فحص قرص الأمين...'
        );
      }

      const queueScan = await postOrphanCleanupAction('orphan-cleanup-scan-queue', { scan_id: orphanGhostScanId });
      if (!queueScan.ok) {
        if (linkStatus) linkStatus.textContent = queueScan.message || 'توقّف فحص الطابور.';
        return;
      }
      setOrphanGhostProgress(92, queueScan.message || 'فحص الطابور...');

      const report = await postOrphanCleanupAction('orphan-cleanup-report', { scan_id: orphanGhostScanId });
      if (!report.ok) {
        if (linkStatus) linkStatus.textContent = report.message || 'تعذر عرض التقرير.';
        return;
      }

      renderOrphanGhostReport(report);
      setOrphanGhostProgress(100, 'اكتمل الفحص — راجع المعاينة قبل الحذف.');
      const totalOrphans = Number(report.portal_orphan_count ?? 0)
        + Number(report.amine_orphan_count ?? 0)
        + Number(report.stale_queue_count ?? 0);
      if (linkStatus) {
        linkStatus.textContent = totalOrphans > 0
          ? `معاينة: ${totalOrphans} عنصر يتيم — يمكن «تجربة حذف» ثم «تنفيذ الحذف».`
          : 'لا وُجدت صور يتيمة في هذا الفحص.';
      }
      if (totalOrphans > 0) {
        orphanGhostDryRunBtn?.classList.remove('hidden');
        orphanGhostPurgeBtn?.classList.remove('hidden');
      }
    } catch (error) {
      if (linkStatus) {
        linkStatus.textContent = orphanCleanupTimeoutHint(error) || 'تعذر إكمال الفحص.';
      }
    } finally {
      orphanGhostScanning = false;
      if (orphanGhostScanBtn) orphanGhostScanBtn.disabled = false;
    }
  }

  async function runOrphanGhostPurge(dryRun) {
    if (!orphanGhostScanId) {
      if (linkStatus) linkStatus.textContent = 'نفّذ «فحص آمن» أولاً.';
      return;
    }
    if (!dryRun) {
      const confirmFn = window.dashboardApp?.confirmDestructive;
      const promptText = 'حذف الملفات اليتيمة من الموقع وقرص الأمين والطابور؟ لا يمكن التراجع.';
      if (typeof confirmFn === 'function') {
        if (!confirmFn(promptText, 'حذف')) return;
      } else if (!window.confirm(promptText)) {
        return;
      }
    }
    if (linkStatus) {
      linkStatus.textContent = dryRun ? 'تجربة حذف (بدون تنفيذ)...' : 'جاري حذف اليتيمات...';
    }
    try {
      const result = await postOrphanCleanupAction('orphan-cleanup-purge', {
        scan_id: orphanGhostScanId,
        purge_portal: '1',
        purge_amine: '1',
        purge_queue: '1',
        dry_run: dryRun ? '1' : '0',
      });
      if (!result.ok) {
        if (linkStatus) linkStatus.textContent = result.message || 'تعذر الحذف.';
        return;
      }
      if (linkStatus) linkStatus.textContent = result.message || 'تم.';
      if (window.dashboardApp?.showToast) {
        window.dashboardApp.showToast(result.message || 'تم.', dryRun ? 'info' : 'success');
      }
      if (!dryRun) {
        orphanGhostDryRunBtn?.classList.add('hidden');
        orphanGhostPurgeBtn?.classList.add('hidden');
        orphanGhostScanId = '';
        await loadSources(page || 1);
      }
    } catch (error) {
      if (linkStatus) {
        linkStatus.textContent = error instanceof Error ? error.message : 'تعذر الحذف.';
      }
    }
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

    let searchTimer = null;
    let searchRequestId = 0;

    let activeSuggestionIndex = -1;

    const addMaterialFromSuggestion = (material) => {
      if (!material?.guid) return;
      const selected = sourceMaterialMap.get(key) || [];
      if (!selected.some((row) => row.guid === material.guid)) {
        selected.push(material);
      }
      sourceMaterialMap.set(key, selected);
      chips.innerHTML = chipsHtml(key);
      closeSuggestions(card);
      input.value = '';
      activeSuggestionIndex = -1;
      input.focus();
    };

    const renderSuggestionRows = (rows) => {
      activeSuggestionIndex = -1;
      if (!rows.length) {
        sug.innerHTML = '<div class="p-2 text-xs text-text-muted">لا نتائج</div>';
        sug.classList.remove('hidden');
        return;
      }
      sug.innerHTML = rows.map((row, index) => {
        const guid = row.material_guid || '';
        const code = row.material_code || '';
        const name = row.name || '';
        const label = `${code} ${name}`.trim();
        return `<button type="button" class="block w-full text-right px-3 py-2 text-xs hover:bg-surface-low add-mat" data-result-index="${index}" data-guid="${escapeHtml(guid)}" data-label="${escapeHtml(label)}" data-code="${escapeHtml(code)}" data-name="${escapeHtml(name)}">${escapeHtml(label)}</button>`;
      }).join('');
      sug.classList.remove('hidden');
      activeSuggestionIndex = 0;
      highlightSuggestionButtons(sug, activeSuggestionIndex);
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

    const scheduleMaterialLookup = () => {
      if (searchTimer) clearTimeout(searchTimer);
      const q = input.value.trim();
      if (q.length < MIN_MATERIAL_SEARCH_LEN) {
        searchRequestId += 1;
        closeSuggestions(card);
        return;
      }
      searchTimer = setTimeout(() => {
        searchTimer = null;
        runMaterialLookup(q);
      }, MATERIAL_SEARCH_DEBOUNCE_MS);
    };

    previewBtn?.addEventListener('click', () => {
      if (item.is_linked_to_material) {
        openLightbox(item.preview_full_url || item.preview_url, item.linked_material_name || '');
        return;
      }
      openLinkDesk(item);
    });

    input.addEventListener('input', scheduleMaterialLookup);

    input.addEventListener('keydown', (event) => {
      const listOpen = suggestionListOpen(sug);
      const buttons = listOpen ? sug.querySelectorAll('button[data-guid]') : [];
      if (event.key === 'ArrowDown' && listOpen && buttons.length) {
        event.preventDefault();
        activeSuggestionIndex = Math.min(activeSuggestionIndex + 1, buttons.length - 1);
        highlightSuggestionButtons(sug, activeSuggestionIndex);
        return;
      }
      if (event.key === 'ArrowUp' && listOpen && buttons.length) {
        event.preventDefault();
        activeSuggestionIndex = Math.max(activeSuggestionIndex - 1, 0);
        highlightSuggestionButtons(sug, activeSuggestionIndex);
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        if (searchTimer) clearTimeout(searchTimer);
        if (listOpen && activeSuggestionIndex >= 0 && buttons[activeSuggestionIndex]) {
          const material = materialFromSuggestionButton(buttons[activeSuggestionIndex]);
          if (material) addMaterialFromSuggestion(material);
          return;
        }
        const selected = sourceMaterialMap.get(key) || [];
        if (selected.length) {
          assign(item, selected, card, assignBtn, cardStatus);
          return;
        }
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
        if (searchTimer) clearTimeout(searchTimer);
        if (listOpen) {
          event.preventDefault();
          searchRequestId += 1;
          closeSuggestions(card);
          activeSuggestionIndex = -1;
          return;
        }
        const selected = sourceMaterialMap.get(key) || [];
        if (selected.length) {
          event.preventDefault();
          sourceMaterialMap.set(key, []);
          chips.innerHTML = chipsHtml(key);
          return;
        }
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
      const material = materialFromSuggestionButton(btn);
      if (material) addMaterialFromSuggestion(material);
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
    const items = (payload.items || []).filter((item) => !isRecentlyLinkedItem(item));
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
        const previewFullSrc = item.preview_full_url || item.preview_url;
        const previewSrc = previewFullSrc;
        const previewTitle = isLinked ? 'تكبير الصورة' : 'افتح الصورة واقرأ رقم الموديل ثم اربطها';
        const previewHint = isLinked ? 'تكبير' : 'افتح للربط';
        const preview = previewSrc
          ? `<button type="button" class="preview-btn dash-mi-card__preview" title="${previewTitle}">
              <img src="${escapeHtml(previewSrc)}" data-full-src="${escapeHtml(previewFullSrc)}" alt="" decoding="async">
              <span class="dash-mi-card__zoom-hint">${previewHint}</span>
            </button>`
          : '<div class="dash-mi-card__preview bg-surface-low"></div>';
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

        return `<article class="dash-mi-card" data-key="${escapeHtml(key)}" data-image-guid="${escapeHtml(item.amine_image_guid || '')}" data-file-name="${escapeHtml(item.file_name || '')}" data-linked="${isLinked ? '1' : '0'}">
          <div class="flex items-center justify-between gap-2 min-h-[1.25rem]">${selectBlock}${selectBlock ? '' : '<span></span>'}</div>
          ${preview}
          <div class="dash-mi-card__head">
            <div class="min-w-0">
              ${linkBadge}
              <p class="dash-mi-card__title material-title">${materialTitle}</p>
              ${materialCode}
            </div>
          </div>
          <div class="relative">
            <input class="material-input h-9 w-full rounded-lg border border-border-subtle px-3 text-xs" placeholder="ابحث بالاسم أو الرمز">
            <div class="suggestions hidden absolute z-20 mt-1 w-full bg-white border border-border-subtle rounded-lg shadow max-h-48 overflow-auto"></div>
          </div>
          <div class="chips flex flex-wrap gap-1">${chipsHtml(key)}</div>
          <div class="dash-mi-card__actions">
            <button type="button" class="assign-btn h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold w-full">ربط المواد المضافة</button>
            ${reassignBlock}
            ${unlinkBlock}
            <button type="button" class="delete-btn h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold w-full">حذف الصورة</button>
          </div>
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

    sourceItems = items;
    page = Number(payload.page || 1);
    hasMore = !!payload.has_more;
    totalCount = Number(payload.total_count || 0);
    if (openNextUnlinkedAfterLoad) {
      openNextUnlinkedAfterLoad = false;
      const next = items.find((row) => !row.is_linked_to_material && !isRecentlyLinkedItem(row));
      if (next) {
        openLinkDesk(next);
      } else if (totalCount > 0 && currentLinkFilter() === 'unlinked') {
        openNextUnlinkedAfterLoad = true;
        window.setTimeout(() => loadSources(1), 0);
      } else if (hasMore) {
        openNextUnlinkedAfterLoad = true;
        window.setTimeout(() => loadSources(page + 1), 0);
      } else {
        closeLightbox();
      }
    }
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
  lightboxAssignBtn?.addEventListener('click', () => {
    assignFromLightbox();
  }, { signal });
  lightboxSkipBtn?.addEventListener('click', () => {
    if (lightboxItem) openNextUnlinked(lightboxItem);
  }, { signal });
  const addLightboxMaterialFromButton = (button) => {
    const material = materialFromSuggestionButton(button);
    if (!material?.guid) return;
    if (!lightboxSelected.some((row) => row.guid === material.guid)) {
      lightboxSelected = [material];
    }
    renderLightboxChips();
    lightboxSuggestions?.classList.add('hidden');
    if (lightboxMaterialInput) lightboxMaterialInput.value = '';
    lightboxActiveResultIndex = -1;
    scheduleLightboxSearchFocus();
  };

  lightboxMaterialInput?.addEventListener('keydown', (event) => {
    const listOpen = suggestionListOpen(lightboxSuggestions);
    const buttons = listOpen ? lightboxSuggestions.querySelectorAll('button[data-guid]') : [];
    if (event.key === 'ArrowDown' && listOpen && buttons.length) {
      event.preventDefault();
      lightboxActiveResultIndex = Math.min(lightboxActiveResultIndex + 1, buttons.length - 1);
      highlightSuggestionButtons(lightboxSuggestions, lightboxActiveResultIndex);
      return;
    }
    if (event.key === 'ArrowUp' && listOpen && buttons.length) {
      event.preventDefault();
      lightboxActiveResultIndex = Math.max(lightboxActiveResultIndex - 1, 0);
      highlightSuggestionButtons(lightboxSuggestions, lightboxActiveResultIndex);
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      if (listOpen && lightboxActiveResultIndex >= 0 && buttons[lightboxActiveResultIndex]) {
        addLightboxMaterialFromButton(buttons[lightboxActiveResultIndex]);
        return;
      }
      if (lightboxSelected.length) {
        assignFromLightbox();
        return;
      }
      const query = lightboxMaterialInput?.value.trim() || '';
      if (query.length >= MIN_MATERIAL_SEARCH_LEN && lightboxSearchTimer) {
        clearTimeout(lightboxSearchTimer);
      }
    }
    if (event.key === 'Escape') {
      if (listOpen) {
        event.preventDefault();
        event.stopPropagation();
        lightboxSearchRequest += 1;
        lightboxSuggestions?.classList.add('hidden');
        if (lightboxSuggestions) lightboxSuggestions.innerHTML = '';
        lightboxActiveResultIndex = -1;
        return;
      }
      if (lightboxSelected.length) {
        event.preventDefault();
        event.stopPropagation();
        lightboxSelected = [];
        renderLightboxChips();
      }
    }
  }, { signal });
  lightboxMaterialInput?.addEventListener('input', () => {
    if (lightboxSearchTimer) clearTimeout(lightboxSearchTimer);
    const query = lightboxMaterialInput.value.trim();
    if (query.length < MIN_MATERIAL_SEARCH_LEN) {
      lightboxSearchRequest += 1;
      lightboxActiveResultIndex = -1;
      lightboxSuggestions?.classList.add('hidden');
      return;
    }
    lightboxSearchTimer = window.setTimeout(async () => {
      const requestId = ++lightboxSearchRequest;
      if (lightboxSuggestions) {
        lightboxSuggestions.innerHTML = '<div class="p-2 text-xs text-text-muted">جاري البحث...</div>';
        lightboxSuggestions.classList.remove('hidden');
      }
      try {
        const rows = await searchMaterials(query);
        if (requestId !== lightboxSearchRequest || !lightboxSuggestions) return;
        if (!rows.length) {
          lightboxActiveResultIndex = -1;
          lightboxSuggestions.innerHTML = '<div class="p-2 text-xs text-text-muted">لا نتائج</div>';
          return;
        }
        lightboxSuggestions.innerHTML = rows.map((row, index) => {
          const guid = row.material_guid || '';
          const code = row.material_code || '';
          const name = row.name || '';
          const label = `${code} ${name}`.trim();
          return `<button type="button" class="block w-full text-right px-3 py-2 text-xs hover:bg-surface-low" data-result-index="${index}" data-guid="${escapeHtml(guid)}" data-label="${escapeHtml(label)}" data-code="${escapeHtml(code)}" data-name="${escapeHtml(name)}">${escapeHtml(label)}</button>`;
        }).join('');
        lightboxSuggestions.classList.remove('hidden');
        lightboxActiveResultIndex = 0;
        highlightSuggestionButtons(lightboxSuggestions, lightboxActiveResultIndex);
      } catch (error) {
        if (requestId !== lightboxSearchRequest || !lightboxSuggestions) return;
        const message = error instanceof Error ? error.message : 'تعذر البحث.';
        lightboxSuggestions.innerHTML = `<div class="p-2 text-xs text-red-600">${escapeHtml(message)}</div>`;
      }
    }, MATERIAL_SEARCH_DEBOUNCE_MS);
  }, { signal });
  lightboxSuggestions?.addEventListener('click', (event) => {
    const target = event.target;
    const button = target instanceof HTMLElement ? target.closest('button[data-guid]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    addLightboxMaterialFromButton(button);
  }, { signal });
  lightboxChips?.addEventListener('click', (event) => {
    const target = event.target;
    const button = target instanceof HTMLElement ? target.closest('button[data-lightbox-remove]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    lightboxSelected = [];
    renderLightboxChips();
  }, { signal });
  lightboxImg?.addEventListener('click', (event) => {
    event.stopPropagation();
    toggleLightboxZoom(event);
  }, { signal });
  imageLightbox?.addEventListener('click', (event) => {
    if (event.target === imageLightbox) closeLightbox();
  }, { signal });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!imageLightbox || imageLightbox.hidden) return;
    if (suggestionListOpen(lightboxSuggestions)) {
      event.preventDefault();
      lightboxSearchRequest += 1;
      lightboxSuggestions?.classList.add('hidden');
      if (lightboxSuggestions) lightboxSuggestions.innerHTML = '';
      lightboxActiveResultIndex = -1;
      return;
    }
    if (lightboxSelected.length) {
      event.preventDefault();
      lightboxSelected = [];
      renderLightboxChips();
      return;
    }
    closeLightbox();
  }, { signal });

  sourcePrevBtn?.addEventListener('click', () => { if (page > 1) loadSources(page - 1); }, { signal });
  sourceNextBtn?.addEventListener('click', () => { if (hasMore) loadSources(page + 1); }, { signal });
  reloadSourcesBtn?.addEventListener('click', () => loadSources(page), { signal });
  applySourceFiltersBtn?.addEventListener('click', applySourceMaterialSearch, { signal });
  deleteAllUnlinkedBtn?.addEventListener('click', deleteAllUnlinked, { signal });
  deleteSelectedUnlinkedBtn?.addEventListener('click', deleteSelectedUnlinked, { signal });
  orphanGhostScanBtn?.addEventListener('click', () => { void runOrphanGhostScan(); }, { signal });
  orphanGhostDryRunBtn?.addEventListener('click', () => { void runOrphanGhostPurge(true); }, { signal });
  orphanGhostPurgeBtn?.addEventListener('click', () => { void runOrphanGhostPurge(false); }, { signal });
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
      recentlyLinkedKeys.clear();
      clearSourceMaterialSearch();
      setLinkFilter(filter);
      loadSources(1);
    }, { signal });
  });

  sourceMaterialSearch?.addEventListener('input', () => {
    if (sourceSearchTimer) clearTimeout(sourceSearchTimer);
    sourceSearchTimer = setTimeout(() => {
      sourceSearchTimer = null;
      applySourceMaterialSearch();
    }, SOURCE_FILTER_DEBOUNCE_MS);
  }, { signal });

  sourceMaterialSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (sourceSearchTimer) clearTimeout(sourceSearchTimer);
      sourceSearchTimer = null;
      applySourceMaterialSearch();
    }
  }, { signal });
  setLinkFilter('unlinked');
  queueMicrotask(() => {
    if (!signal.aborted && panel.isConnected) {
      loadSources(1);
    }
  });

  window.addEventListener('pageshow', (event) => {
    if (!event.persisted || signal.aborted) return;
    if (!panel.isConnected) return;
    loadSources(page || 1);
  }, { signal });
  };
})();
