/**
 * Procurement — invoice grid: inline material search (Excel-style) with multi-select + AJAX mutations.
 */
(function () {
  'use strict';

  const boundRoots = new WeakSet();
  const MIN_QUERY_LEN = 1;
  const API_URL = '/dashboard/procurement-api.php';
  const AJAX_HEADER = 'X-Dashboard-Ajax';

  const toast = (message, type = 'success') => {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type);
    }
  };

  window.portalProcurementInvoiceInit = (root = document) => {
    const invoiceRoot = root.querySelector('#procurement-invoice-root');
    if (!invoiceRoot || boundRoots.has(invoiceRoot)) {
      return;
    }
    boundRoots.add(invoiceRoot);

    const searchInput = invoiceRoot.querySelector('#proc-invoice-grid-input');
    const resultsEl = invoiceRoot.querySelector('#proc-invoice-grid-dropdown');
    const dropdownLayer = invoiceRoot.querySelector('#proc-invoice-dropdown-layer');
    const statusEl = invoiceRoot.querySelector('#proc-invoice-search-status');
    const manufacturerSelect = invoiceRoot.querySelector('#proc-invoice-manufacturer');
    const addSelectedBtn = invoiceRoot.querySelector('#proc-invoice-add-selected');
    const selectedCountEl = invoiceRoot.querySelector('#proc-invoice-selected-count');
    const gridBody = invoiceRoot.querySelector('#proc-invoice-grid-body');
    const composerRow = gridBody?.querySelector('[data-role="composer"]');
    const saveBtn = invoiceRoot.querySelector('#proc-invoice-save-btn');
    const clearBtn = invoiceRoot.querySelector('#proc-invoice-clear-btn');
    const emptyHint = invoiceRoot.querySelector('#proc-invoice-empty-hint');
    const countEl = invoiceRoot.querySelector('#proc-invoice-grid-count');
    const guidsNode = invoiceRoot.querySelector('#proc-invoice-grid-guids');
    const subtabBadge = document.querySelector('#proc-invoice-subtab-badge');

    if (!searchInput || !resultsEl || !gridBody || !composerRow) {
      return;
    }

    const readLifecycleLabels = () => {
      const node = invoiceRoot.querySelector('#proc-invoice-lifecycle-labels');
      if (!node) return { new: 'جديد', active: 'فعال', cancelled: 'ملغي' };
      try {
        const parsed = JSON.parse(node.textContent || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (_) {
        return {};
      }
    };

    const lifecycleLabels = readLifecycleLabels();

    const readGridGuids = () => {
      if (!guidsNode) return new Set();
      try {
        const parsed = JSON.parse(guidsNode.textContent || '[]');
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
    let selectAllCheckbox = null;
    let invoiceBusy = false;
    const PAGE_SIZE = 24;

    const manufacturerFilter = () =>
      (manufacturerSelect?.value || invoiceRoot.dataset.manufacturer || '').trim();

    const setBusy = (busy) => {
      invoiceBusy = busy;
      if (addSelectedBtn) addSelectedBtn.disabled = busy || selectedInList.size === 0;
      if (saveBtn) saveBtn.disabled = busy;
      if (clearBtn) clearBtn.disabled = busy;
    };

    const positionDropdown = () => {
      if (resultsEl.classList.contains('hidden')) return;
      const rect = searchInput.getBoundingClientRect();
      const width = Math.min(window.innerWidth - 16, Math.max(rect.width, 280));
      let left = rect.left;
      if (left + width > window.innerWidth - 8) {
        left = window.innerWidth - width - 8;
      }
      left = Math.max(8, left);
      resultsEl.style.position = 'fixed';
      resultsEl.style.top = `${Math.min(window.innerHeight - 120, rect.bottom + 4)}px`;
      resultsEl.style.left = `${left}px`;
      resultsEl.style.width = `${width}px`;
      resultsEl.style.maxHeight = `${Math.min(320, window.innerHeight - rect.bottom - 24)}px`;
      resultsEl.style.zIndex = '2000';
    };

    const selectableSearchValues = () =>
      searchItems.map((item) => item.value).filter((value) => value && !gridGuids.has(value));

    const updateSelectAllCheckbox = () => {
      if (!selectAllCheckbox) return;
      const selectable = selectableSearchValues();
      if (selectable.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.disabled = true;
        return;
      }
      selectAllCheckbox.disabled = false;
      const selectedCount = selectable.filter((v) => selectedInList.has(v)).length;
      selectAllCheckbox.checked = selectedCount === selectable.length;
      selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < selectable.length;
    };

    const updateAddSelectedButton = () => {
      const count = selectedInList.size;
      if (!addSelectedBtn || !selectedCountEl) return;
      selectedCountEl.textContent = String(count);
      addSelectedBtn.classList.toggle('hidden', count === 0);
      addSelectedBtn.disabled = invoiceBusy || count === 0;
      updateSelectAllCheckbox();
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
      selectAllCheckbox = null;
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

    const toggleSelectAll = (checked) => {
      selectableSearchValues().forEach((value) => {
        if (checked) selectedInList.add(value);
        else selectedInList.delete(value);
      });
      updateAddSelectedButton();
      resultsEl.querySelectorAll('[data-pick-value]').forEach((row) => {
        const guid = row.getAttribute('data-pick-value');
        const cb = row.querySelector('input[type="checkbox"]');
        if (cb && guid) cb.checked = selectedInList.has(guid);
      });
    };

    const ensureSelectAllHeader = () => {
      if (resultsEl.querySelector('[data-role="select-all-header"]')) {
        selectAllCheckbox = resultsEl.querySelector('[data-role="select-all-cb"]');
        updateSelectAllCheckbox();
        return;
      }
      const header = document.createElement('li');
      header.setAttribute('data-role', 'select-all-header');
      header.className =
        'sticky top-0 z-10 flex items-center gap-2 px-3 py-2 bg-surface-low border-b border-border-subtle list-none text-right';

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'shrink-0';
      cb.setAttribute('data-role', 'select-all-cb');
      cb.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleSelectAll(cb.checked);
      });

      const label = document.createElement('span');
      label.className = 'flex-1 text-xs font-bold text-text-muted';
      label.textContent = 'تحديد الكل (النتائج المعروضة)';

      header.append(cb, label);
      resultsEl.prepend(header);
      selectAllCheckbox = cb;
      updateSelectAllCheckbox();
    };

    async function invoicePost(action, fields) {
      const body = new FormData();
      body.set('action', action);
      Object.entries(fields || {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
          value.forEach((entry) => body.append(key, entry));
        } else if (value !== undefined && value !== null) {
          body.set(key, value);
        }
      });

      const response = await fetch(API_URL, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          [AJAX_HEADER]: '1',
        },
      });
      const data = await response.json();
      if (!response.ok && data?.ok !== false) {
        throw new Error('تعذر تنفيذ العملية.');
      }
      return data;
    }

    const formatCountLabel = (count) => (count === 1 ? '1 مادة' : `${count} مواد`);

    const buildDataRow = (row, index) => {
      const guid = String(row.material_guid || '');
      const tr = document.createElement('tr');
      tr.className = 'so-invoice__row';
      tr.setAttribute('data-material-guid', guid);

      const numTd = document.createElement('td');
      numTd.className = 'so-invoice__num';
      numTd.textContent = String(index + 1);

      const matTd = document.createElement('td');
      matTd.className = 'so-invoice__material';
      const inner = document.createElement('div');
      inner.className = 'so-invoice__material-inner';
      const text = document.createElement('div');
      text.className = 'so-invoice__material-text';
      const nameSpan = document.createElement('span');
      nameSpan.className = 'so-invoice__name';
      nameSpan.textContent = String(row.material_name_ar || '');
      const codeSpan = document.createElement('span');
      codeSpan.className = 'so-invoice__code';
      codeSpan.textContent = String(row.material_code || '');
      text.append(nameSpan, codeSpan);
      inner.append(text);
      matTd.append(inner);

      const availTd = document.createElement('td');
      availTd.className = 'text-xs';
      availTd.textContent =
        row.available_cartons !== null && row.available_cartons !== undefined
          ? String(row.available_cartons)
          : '—';

      const lcTd = document.createElement('td');
      lcTd.className = 'so-invoice__type';
      const lcSelect = document.createElement('select');
      lcSelect.className = 'so-invoice__select';
      lcSelect.name = `lifecycle[${guid}]`;
      const lc = String(row.lifecycle || 'new');
      Object.entries(lifecycleLabels).forEach(([code, label]) => {
        const opt = document.createElement('option');
        opt.value = code;
        opt.textContent = String(label);
        if (code === lc) opt.selected = true;
        lcSelect.append(opt);
      });
      lcTd.append(lcSelect);

      const minTd = document.createElement('td');
      const minInput = document.createElement('input');
      minInput.type = 'number';
      minInput.step = '0.001';
      minInput.min = '0';
      minInput.name = `min_cartons[${guid}]`;
      minInput.className = 'so-invoice__input w-20';
      minInput.value = row.min_cartons !== null && row.min_cartons !== undefined ? String(row.min_cartons) : '';
      minTd.append(minInput);

      const maxTd = document.createElement('td');
      const maxInput = document.createElement('input');
      maxInput.type = 'number';
      maxInput.step = '0.001';
      maxInput.min = '0';
      maxInput.name = `max_cartons[${guid}]`;
      maxInput.className = 'so-invoice__input w-20';
      maxInput.value = row.max_cartons !== null && row.max_cartons !== undefined ? String(row.max_cartons) : '';
      maxTd.append(maxInput);

      const notesTd = document.createElement('td');
      const notesInput = document.createElement('input');
      notesInput.type = 'text';
      notesInput.name = `notes_ar[${guid}]`;
      notesInput.className = 'so-invoice__input min-w-[6rem]';
      notesInput.value = String(row.notes_ar || '');
      notesTd.append(notesInput);

      const actionTd = document.createElement('td');
      actionTd.className = 'so-invoice__action';
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'so-invoice__remove proc-invoice-remove';
      removeBtn.setAttribute('data-material-guid', guid);
      removeBtn.title = 'حذف من الفاتورة';
      removeBtn.textContent = '×';
      actionTd.append(removeBtn);

      tr.append(numTd, matTd, availTd, lcTd, minTd, maxTd, notesTd, actionTd);
      return tr;
    };

    const bindRemoveButtons = () => {
      gridBody.querySelectorAll('.proc-invoice-remove').forEach((btn) => {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
          const guid = btn.getAttribute('data-material-guid');
          if (!guid || invoiceBusy) return;
          void removeMaterial(guid);
        });
      });
    };

    const applyInvoiceState = (invoice) => {
      if (!invoice || typeof invoice !== 'object') return;
      const rows = Array.isArray(invoice.rows) ? invoice.rows : [];
      const guids = Array.isArray(invoice.gridGuids) ? invoice.gridGuids.map(String) : [];

      if (guidsNode) {
        guidsNode.textContent = JSON.stringify(guids);
      }
      gridGuids = new Set(guids);

      gridBody.querySelectorAll('tr.so-invoice__row:not([data-role="composer"])').forEach((tr) => tr.remove());
      rows.forEach((row, index) => {
        gridBody.insertBefore(buildDataRow(row, index), composerRow);
      });
      bindRemoveButtons();

      const count = rows.length;
      if (countEl) countEl.textContent = formatCountLabel(count);
      clearBtn?.classList.toggle('hidden', count === 0);
      saveBtn?.classList.toggle('hidden', count === 0);
      emptyHint?.classList.toggle('hidden', count > 0);

      if (subtabBadge) {
        subtabBadge.textContent = String(count);
        subtabBadge.classList.toggle('hidden', count === 0);
      }

      selectedInList.clear();
      updateAddSelectedButton();
    };

    async function runInvoiceMutation(action, fields, successMessage) {
      if (invoiceBusy) return null;
      setBusy(true);
      try {
        const data = await invoicePost(action, fields);
        toast(data.message || successMessage || 'تم.', data.ok === false ? 'error' : 'success');
        if (data.ok && data.invoice) {
          applyInvoiceState(data.invoice);
        }
        return data;
      } catch (_) {
        toast('تعذر الاتصال بالخادم.', 'error');
        return null;
      } finally {
        setBusy(false);
      }
    }

    const submitBatchAdd = (guids) => {
      const unique = Array.from(new Set(guids.filter(Boolean)));
      if (unique.length === 0) return;
      void runInvoiceMutation('invoice_add_materials', { 'material_guids[]': unique }).then((data) => {
        if (data?.ok) {
          searchInput.value = '';
          hideResults();
          if (statusEl) statusEl.textContent = '';
          searchInput.focus();
        }
      });
    };

    const removeMaterial = (guid) =>
      runInvoiceMutation('invoice_remove_material', { material_guid: guid });

    const collectSaveFields = () => {
      const fields = {};
      gridBody.querySelectorAll('tr.so-invoice__row:not([data-role="composer"])').forEach((tr) => {
        const guid = tr.getAttribute('data-material-guid');
        if (!guid) return;
        const lc = tr.querySelector(`select[name="lifecycle[${CSS.escape(guid)}]"]`);
        const min = tr.querySelector(`input[name="min_cartons[${CSS.escape(guid)}]"]`);
        const max = tr.querySelector(`input[name="max_cartons[${CSS.escape(guid)}]"]`);
        const notes = tr.querySelector(`input[name="notes_ar[${CSS.escape(guid)}]"]`);
        if (lc) fields[`lifecycle[${guid}]`] = lc.value;
        if (min) fields[`min_cartons[${guid}]`] = min.value;
        if (max) fields[`max_cartons[${guid}]`] = max.value;
        if (notes) fields[`notes_ar[${guid}]`] = notes.value;
      });
      return fields;
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
        selectAllCheckbox = null;
      }
      items.forEach((item) => {
        if (!item?.value || gridGuids.has(item.value)) return;
        if (searchItems.some((row) => row.value === item.value)) return;
        searchItems.push(item);
        appendResultRow(item, searchItems.length - 1);
      });

      if (searchItems.length === 0 && !append) {
        hideResults();
        if (statusEl) statusEl.textContent = 'لا توجد نتائج — جرّب جزءاً من الاسم أو الكود.';
        return;
      }

      ensureSelectAllHeader();
      resultsEl.classList.remove('hidden');
      searchInput.setAttribute('aria-expanded', 'true');
      positionDropdown();
      if (activeResultIndex < 0 && searchItems.length > 0) activeResultIndex = 0;
      highlightResult();
      updateSelectAllCheckbox();

      if (statusEl) {
        const shown = searchItems.length;
        const totalText = searchTotal > 0 ? ' من ' + searchTotal : '';
        statusEl.textContent =
          shown +
          totalText +
          ' — حدّد أو «تحديد الكل» ثم «إضافة المحدّد»';
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
      if (q.length < MIN_QUERY_LEN) {
        hideResults();
        if (statusEl) statusEl.textContent = '';
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

        const response = await fetch(API_URL + '?' + params.toString(), {
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

    let lastEnterAt = 0;

    searchInput.addEventListener('input', () => {
      gridGuids = readGridGuids();
      const q = (searchInput.value || '').trim();
      if (q.length < MIN_QUERY_LEN) {
        if (searchTimer) clearTimeout(searchTimer);
        hideResults();
        if (statusEl) statusEl.textContent = '';
        return;
      }
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(() => runSearch(false), 260);
    });

    searchInput.addEventListener('focus', () => {
      const q = (searchInput.value || '').trim();
      if (q.length >= MIN_QUERY_LEN && searchItems.length === 0) runSearch(false);
    });

    manufacturerSelect?.addEventListener('change', () => {
      invoiceRoot.dataset.manufacturer = manufacturerSelect.value;
      const q = (searchInput.value || '').trim();
      if (q.length >= MIN_QUERY_LEN) runSearch(false);
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
          const now = Date.now();
          if (now - lastEnterAt < 450 && selectedInList.size <= 1) {
            submitBatchAdd([item.value]);
            lastEnterAt = 0;
            return;
          }
          lastEnterAt = now;
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

    saveBtn?.addEventListener('click', () => {
      void runInvoiceMutation('invoice_save_all', collectSaveFields());
    });

    clearBtn?.addEventListener('click', () => {
      if (!window.confirm('تفريغ كل البنود؟')) return;
      void runInvoiceMutation('invoice_clear', {});
    });

    bindRemoveButtons();

    const onDocumentPointer = (event) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      if (searchInput.contains(target)) return;
      if (resultsEl.contains(target)) return;
      if (addSelectedBtn && (target === addSelectedBtn || addSelectedBtn.contains(target))) return;
      if (dropdownLayer && dropdownLayer.contains(target)) return;
      hideResults();
    };

    document.addEventListener('mousedown', onDocumentPointer);
    window.addEventListener('resize', positionDropdown);
    window.addEventListener('scroll', positionDropdown, true);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalProcurementInvoiceInit(document));
  } else {
    window.portalProcurementInvoiceInit(document);
  }
})();
