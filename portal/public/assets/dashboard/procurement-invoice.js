/**
 * Procurement — invoice grid: live material search with multi-select (like store / special offers).
 */
(function () {
  'use strict';

  const root = document.getElementById('procurement-invoice-root');
  if (!root) return;

  const searchInput = document.getElementById('proc-invoice-search');
  const resultsEl = document.getElementById('proc-invoice-search-results');
  const searchWrap = document.getElementById('proc-invoice-search-wrap');
  const statusEl = document.getElementById('proc-invoice-search-status');
  const manufacturerSelect = document.getElementById('proc-invoice-manufacturer');
  const addSelectedBtn = document.getElementById('proc-invoice-add-selected');
  const selectedCountEl = document.getElementById('proc-invoice-selected-count');
  const batchForm = document.getElementById('proc-invoice-batch-add');

  if (!searchInput || !resultsEl || !batchForm) return;

  const readGridGuids = () => {
    const node = document.getElementById('proc-invoice-grid-guids');
    if (!node) return new Set();
    try {
      const parsed = JSON.parse(node.textContent || '[]');
      return new Set(Array.isArray(parsed) ? parsed.map(String) : []);
    } catch (_) {
      return new Set();
    }
  };

  let gridGuids = readGridGuids();
  let searchItems = [];
  const selectedInList = new Set();
  let activeResultIndex = -1;
  let searchPage = 1;
  let searchTotal = 0;
  let searchHasMore = false;
  let searchLoading = false;
  let searchTimer = null;
  let searchRequestId = 0;
  const PAGE_SIZE = 24;

  const manufacturerFilter = () => (manufacturerSelect?.value || root.dataset.manufacturer || '').trim();

  const updateAddSelectedButton = () => {
    const count = selectedInList.size;
    if (!addSelectedBtn || !selectedCountEl) return;
    selectedCountEl.textContent = String(count);
    addSelectedBtn.classList.toggle('hidden', count === 0);
    addSelectedBtn.disabled = count === 0;
  };

  const hideResults = () => {
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
    searchInput.setAttribute('aria-expanded', 'false');
    activeResultIndex = -1;
    searchItems = [];
    searchPage = 1;
    searchTotal = 0;
    searchHasMore = false;
    searchLoading = false;
    selectedInList.clear();
    updateAddSelectedButton();
  };

  const toggleSelected = (value, checked) => {
    if (!value) return;
    if (checked) selectedInList.add(value);
    else selectedInList.delete(value);
    updateAddSelectedButton();
    resultsEl.querySelectorAll('[data-pick-value]').forEach((row) => {
      const guid = row.getAttribute('data-pick-value');
      const cb = row.querySelector('input[type="checkbox"]');
      if (cb && guid) cb.checked = selectedInList.has(guid);
    });
  };

  const submitBatchAdd = (guids) => {
    const unique = Array.from(new Set(guids.filter(Boolean)));
    if (unique.length === 0) return;
    batchForm.querySelectorAll('input[name="material_guids[]"]').forEach((n) => n.remove());
    unique.forEach((guid) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'material_guids[]';
      input.value = guid;
      batchForm.appendChild(input);
    });
    batchForm.submit();
  };

  const appendResultRow = (item, index) => {
    const li = document.createElement('li');
    li.setAttribute('role', 'option');
    li.setAttribute('data-result-index', String(index));
    li.setAttribute('data-pick-value', item.value);
    li.className =
      'flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-surface-low text-right border-b border-border-subtle last:border-0';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'shrink-0';
    cb.checked = selectedInList.has(item.value);
    cb.addEventListener('click', (event) => {
      event.stopPropagation();
      toggleSelected(item.value, cb.checked);
    });

    const thumb = document.createElement('span');
    thumb.className = 'so-invoice__thumb so-invoice__thumb--sm shrink-0';
    if (item.image) {
      const img = document.createElement('img');
      img.src = item.image;
      img.alt = '';
      img.loading = 'lazy';
      thumb.appendChild(img);
    } else {
      thumb.classList.add('is-empty');
    }

    const label = document.createElement('span');
    label.className = 'flex-1 text-sm';
    label.textContent = item.label || item.value || '';

    li.append(cb, thumb, label);

    li.addEventListener('mousedown', (event) => {
      if (event.target instanceof HTMLInputElement) return;
      event.preventDefault();
      const next = !selectedInList.has(item.value);
      toggleSelected(item.value, next);
      cb.checked = next;
    });

    li.addEventListener('dblclick', (event) => {
      event.preventDefault();
      submitBatchAdd([item.value]);
    });

    resultsEl.appendChild(li);
  };

  const highlightResult = () => {
    resultsEl.querySelectorAll('[data-result-index]').forEach((node) => {
      const index = Number(node.getAttribute('data-result-index'));
      node.classList.toggle('bg-primary/10', index === activeResultIndex);
      node.classList.toggle('font-bold', index === activeResultIndex);
    });
    const activeNode = resultsEl.querySelector('[data-result-index="' + activeResultIndex + '"]');
    activeNode?.scrollIntoView({ block: 'nearest' });
  };

  const renderResultList = (items, append) => {
    if (!append) {
      resultsEl.innerHTML = '';
      searchItems = [];
    }
    items.forEach((item) => {
      if (!item?.value || gridGuids.has(item.value)) return;
      if (searchItems.some((row) => row.value === item.value)) return;
      searchItems.push(item);
      appendResultRow(item, searchItems.length - 1);
    });

    if (searchItems.length === 0 && !append) {
      hideResults();
      if (statusEl) statusEl.textContent = 'لا توجد نتائج جديدة.';
      return;
    }

    resultsEl.classList.remove('hidden');
    searchInput.setAttribute('aria-expanded', 'true');
    if (activeResultIndex < 0 && searchItems.length > 0) activeResultIndex = 0;
    highlightResult();

    if (statusEl) {
      const shown = searchItems.length;
      const totalText = searchTotal > 0 ? ' من ' + searchTotal : '';
      statusEl.textContent =
        shown + totalText + ' — حدّد أكثر من مادة ثم «إضافة المحدّد» — Enter يبدّل تحديد السطر';
    }

    const sentinel = resultsEl.querySelector('[data-role="load-sentinel"]');
    if (sentinel) sentinel.remove();
    if (searchHasMore) {
      const loadingRow = document.createElement('li');
      loadingRow.setAttribute('data-role', 'load-sentinel');
      loadingRow.className = 'px-3 py-2 text-center text-xs text-text-muted list-none';
      loadingRow.textContent = searchLoading ? 'جاري تحميل المزيد...' : 'مرّر للأسفل للمزيد';
      resultsEl.appendChild(loadingRow);
    }
  };

  const fetchMaterialPage = async (page, append) => {
    const q = (searchInput.value || '').trim();
    if (q.length < 2) {
      hideResults();
      if (statusEl) statusEl.textContent = 'اكتب حرفين على الأقل.';
      return;
    }
    if (searchLoading) return;

    const requestId = ++searchRequestId;
    searchLoading = true;
    if (!append && statusEl) statusEl.textContent = 'جاري البحث...';

    try {
      const params = new URLSearchParams({
        q,
        page: String(page),
        pageSize: String(PAGE_SIZE),
      });
      const mfr = manufacturerFilter();
      if (mfr !== '') params.set('manufacturer', mfr);

      const response = await fetch('/dashboard/procurement-api.php?' + params.toString(), {
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
      renderResultList(Array.isArray(data.items) ? data.items : [], append);
    } catch (_) {
      if (requestId !== searchRequestId) return;
      if (!append) hideResults();
      if (statusEl) statusEl.textContent = 'تعذر الاتصال بالخادم.';
    } finally {
      if (requestId === searchRequestId) searchLoading = false;
    }
  };

  const runSearch = (append = false) => {
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

  searchInput.addEventListener('input', () => {
    const q = (searchInput.value || '').trim();
    if (q.length < 2) {
      if (searchTimer) clearTimeout(searchTimer);
      hideResults();
      if (statusEl) statusEl.textContent = '';
      return;
    }
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(false), 280);
  });

  manufacturerSelect?.addEventListener('change', () => {
    root.dataset.manufacturer = manufacturerSelect.value;
    const q = (searchInput.value || '').trim();
    if (q.length >= 2) runSearch(false);
  });

  resultsEl.addEventListener('scroll', () => {
    if (!searchHasMore || searchLoading) return;
    if (resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 48) {
      runSearch(true);
    }
  });

  searchInput.addEventListener('keydown', (event) => {
    const hasList = !resultsEl.classList.contains('hidden') && searchItems.length > 0;
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
      if (searchTimer) clearTimeout(searchTimer);
      if (hasList && activeResultIndex >= 0 && searchItems[activeResultIndex]) {
        const item = searchItems[activeResultIndex];
        const next = !selectedInList.has(item.value);
        toggleSelected(item.value, next);
        const row = resultsEl.querySelector('[data-pick-value="' + CSS.escape(item.value) + '"]');
        const cb = row?.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = next;
        return;
      }
      runSearch(false);
      return;
    }
    if (event.key === 'Escape') hideResults();
  });

  addSelectedBtn?.addEventListener('click', () => {
    submitBatchAdd(Array.from(selectedInList));
  });

  document.addEventListener('click', (event) => {
    if (searchWrap && !searchWrap.contains(event.target)) hideResults();
  });
})();
