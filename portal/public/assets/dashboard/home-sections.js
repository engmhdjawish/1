/**
 * Home sections editor — display mode toggle and manual material search.
 */
(function () {
  'use strict';

  const boundRoots = new WeakSet();

  window.portalHomeSectionsInit = (root = document) => {
    const form = root.querySelector('#home-section-form');
    if (!form || boundRoots.has(form)) {
      return;
    }
    boundRoots.add(form);

    const modeSelect = root.querySelector('#display_mode');
    const filterPanel = root.querySelector('#filter-mode-panel');
    const manualPanel = root.querySelector('#manual-mode-panel');
    const syncPanels = () => {
      const isManual = modeSelect?.value === 'manual';
      filterPanel?.classList.toggle('hidden', isManual);
      manualPanel?.classList.toggle('hidden', !isManual);
    };
    modeSelect?.addEventListener('change', syncPanels);
    syncPanels();

    const searchInput = root.querySelector('#hs-material-search');
    const resultsEl = root.querySelector('#hs-material-search-results');
    const searchWrap = root.querySelector('#hs-material-search-wrap');
    const statusEl = root.querySelector('#hs-material-search-status');
    if (!searchInput) {
      return;
    }

    const MANUAL_PICKER_ID = 'hs-manual-materials';
    const PAGE_SIZE = 24;

    let searchItems = [];
    let activeResultIndex = -1;
    let searchPage = 1;
    let searchTotal = 0;
    let searchHasMore = false;
    let searchLoading = false;
    let searchTimer = null;
    let searchRequestId = 0;

    const getSelectedMaterialIds = () => {
      if (typeof window.portalTokenPickerGetSelected === 'function') {
        return new Set(window.portalTokenPickerGetSelected(MANUAL_PICKER_ID));
      }
      return new Set();
    };

    const hideResults = () => {
      if (!resultsEl) return;
      resultsEl.classList.add('hidden');
      resultsEl.innerHTML = '';
      searchInput.setAttribute('aria-expanded', 'false');
      activeResultIndex = -1;
      searchItems = [];
      searchPage = 1;
      searchTotal = 0;
      searchHasMore = false;
      searchLoading = false;
    };

    const updateStatus = () => {
      if (!statusEl) return;
      if (searchItems.length === 0) {
        statusEl.textContent = 'لا توجد نتائج.';
        return;
      }
      const shown = searchItems.length;
      const totalText = searchTotal > 0 ? ' من ' + searchTotal : '';
      statusEl.textContent = shown + totalText + ' — اختر من القائمة (↑↓ Enter) — مرّر للأسفل للمزيد';
    };

    const highlightResult = () => {
      if (!resultsEl) return;
      const activeNode = resultsEl.querySelector('[data-result-index="' + activeResultIndex + '"]');
      Array.from(resultsEl.querySelectorAll('[data-result-index]')).forEach((node) => {
        const index = Number(node.getAttribute('data-result-index'));
        node.classList.toggle('bg-primary/10', index === activeResultIndex);
        node.classList.toggle('font-bold', index === activeResultIndex);
      });
      activeNode?.scrollIntoView({ block: 'nearest' });
    };

    const appendResultRow = (item, index) => {
      if (!resultsEl || !item) return;
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('data-result-index', String(index));
      li.className = 'px-3 py-2.5 cursor-pointer hover:bg-surface-low text-right';
      li.textContent = item.label || item.value || '';
      li.addEventListener('mousedown', (event) => {
        event.preventDefault();
        addMaterialItem(item);
      });
      resultsEl.appendChild(li);
    };

    const addMaterialItem = (item) => {
      if (!item || typeof window.portalTokenPickerAdd !== 'function') return;
      const added = window.portalTokenPickerAdd(MANUAL_PICKER_ID, [item]);
      if (added > 0 && statusEl) {
        statusEl.textContent = 'تمت إضافة: ' + (item.label || item.value);
      }
      searchInput.value = '';
      hideResults();
    };

    const renderResultList = (items, append) => {
      if (!resultsEl) return;
      if (!append) {
        resultsEl.innerHTML = '';
        searchItems = [];
      }
      const selected = getSelectedMaterialIds();
      items.forEach((item) => {
        if (!item || !item.value || selected.has(item.value)) return;
        if (searchItems.some((row) => row.value === item.value)) return;
        searchItems.push(item);
        appendResultRow(item, searchItems.length - 1);
      });

      if (searchItems.length === 0) {
        hideResults();
        if (statusEl) statusEl.textContent = 'لا توجد نتائج جديدة.';
        return;
      }

      resultsEl.classList.remove('hidden');
      searchInput.setAttribute('aria-expanded', 'true');
      if (activeResultIndex < 0) activeResultIndex = 0;
      highlightResult();
      updateStatus();

      const sentinel = resultsEl.querySelector('[data-role="load-sentinel"]');
      if (sentinel) sentinel.remove();
      if (searchHasMore) {
        const loadingRow = document.createElement('li');
        loadingRow.setAttribute('data-role', 'load-sentinel');
        loadingRow.className = 'px-3 py-2 text-center text-xs text-text-muted';
        loadingRow.textContent = searchLoading ? 'جاري تحميل المزيد...' : 'مرّر للأسفل للمزيد';
        resultsEl.appendChild(loadingRow);
      }
    };

    const fetchMaterialPage = async (page, append) => {
      const q = (searchInput.value || '').trim();
      if (q === '') {
        hideResults();
        if (statusEl) statusEl.textContent = '';
        return;
      }
      if (searchLoading) return;

      const requestId = ++searchRequestId;
      searchLoading = true;
      if (!append && statusEl) statusEl.textContent = 'جاري البحث...';

      try {
        const url =
          '/dashboard/home-sections-api.php?q=' +
          encodeURIComponent(q) +
          '&page=' +
          encodeURIComponent(String(page)) +
          '&pageSize=' +
          encodeURIComponent(String(PAGE_SIZE));
        const response = await fetch(url, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (requestId !== searchRequestId) return;
        const data = await response.json();
        if (!data.ok) {
          if (!append) hideResults();
          if (statusEl) statusEl.textContent = data.message || 'تعذر البحث.';
          return;
        }

        searchPage = Number(data.page) || page;
        searchTotal = Number(data.total) || 0;
        searchHasMore = !!data.hasMore;
        const items = Array.isArray(data.items) ? data.items : [];
        renderResultList(items, append);
      } catch (_) {
        if (requestId !== searchRequestId) return;
        if (!append) hideResults();
        if (statusEl) statusEl.textContent = 'تعذر الاتصال بالخادم.';
      } finally {
        if (requestId === searchRequestId) {
          searchLoading = false;
        }
      }
    };

    const runMaterialSearch = (append = false) => {
      if (append) {
        if (!searchHasMore || searchLoading) return;
        fetchMaterialPage(searchPage + 1, true);
        return;
      }
      searchPage = 1;
      searchHasMore = false;
      searchTotal = 0;
      fetchMaterialPage(1, false);
    };

    const scheduleMaterialSearch = (delayMs = 280) => {
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        searchTimer = null;
        runMaterialSearch(false);
      }, delayMs);
    };

    searchInput.addEventListener('input', () => {
      const q = (searchInput.value || '').trim();
      if (q === '') {
        if (searchTimer) clearTimeout(searchTimer);
        hideResults();
        if (statusEl) statusEl.textContent = '';
        return;
      }
      scheduleMaterialSearch();
    });

    resultsEl?.addEventListener('scroll', () => {
      if (!searchHasMore || searchLoading) return;
      const nearBottom = resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 48;
      if (nearBottom) {
        runMaterialSearch(true);
      }
    });

    searchInput.addEventListener('keydown', (event) => {
      const hasList = resultsEl && !resultsEl.classList.contains('hidden') && searchItems.length > 0;
      if (event.key === 'ArrowDown' && hasList) {
        event.preventDefault();
        activeResultIndex = Math.min(activeResultIndex + 1, searchItems.length - 1);
        highlightResult();
        return;
      }
      if (event.key === 'ArrowUp' && hasList) {
        event.preventDefault();
        activeResultIndex = Math.max(activeResultIndex - 1, 0);
        highlightResult();
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        event.stopPropagation();
        if (searchTimer) clearTimeout(searchTimer);
        if (hasList && activeResultIndex >= 0 && searchItems[activeResultIndex]) {
          addMaterialItem(searchItems[activeResultIndex]);
          return;
        }
        runMaterialSearch(false);
        return;
      }
      if (event.key === 'Escape') {
        hideResults();
      }
    });

    document.addEventListener('click', (event) => {
      if (!searchWrap || searchWrap.contains(event.target)) return;
      hideResults();
    });
  };
})();
