/**
 * Price checker slideshow settings — mode panels + material search.
 */
(function () {
  'use strict';

  const root = document.getElementById('price-checker-slideshow-settings');
  if (!root) return;

  const filterPanel = document.getElementById('pc-filter-panel');
  const manualPanel = document.getElementById('pc-manual-panel');
  const offerPanel = document.getElementById('pc-offer-panel');
  const offerPricesPanel = document.getElementById('pc-offer-prices-panel');
  const modeInputs = root.querySelectorAll('input[name="slideshow_mode"]');

  const syncPanels = () => {
    const mode = root.querySelector('input[name="slideshow_mode"]:checked')?.value || 'filter';
    filterPanel?.classList.toggle('hidden', mode !== 'filter');
    manualPanel?.classList.toggle('hidden', mode !== 'manual');
    offerPanel?.classList.toggle('hidden', mode !== 'offer');
    offerPricesPanel?.classList.toggle('hidden', mode === 'offer');
  };

  modeInputs.forEach((input) => input.addEventListener('change', syncPanels));
  syncPanels();

  const searchInput = document.getElementById('pc-material-search');
  const resultsEl = document.getElementById('pc-material-search-results');
  const statusEl = document.getElementById('pc-material-search-status');
  if (!searchInput) return;

  const MANUAL_PICKER_ID = 'pc-manual-materials';
  let searchTimer = null;
  let searchRequestId = 0;

  const hideResults = () => {
    if (!resultsEl) return;
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
  };

  const addMaterial = (item) => {
    if (!item?.value || typeof window.portalTokenPickerAdd !== 'function') return;
    const added = window.portalTokenPickerAdd(MANUAL_PICKER_ID, [item]);
    if (added > 0) {
      searchInput.value = '';
      hideResults();
      if (statusEl) statusEl.textContent = 'تمت الإضافة: ' + (item.label || item.value);
    }
  };

  const renderResults = (items) => {
    if (!resultsEl) return;
    resultsEl.innerHTML = '';
    if (!items.length) {
      resultsEl.innerHTML = '<p class="p-3 text-xs text-text-muted">لا توجد نتائج.</p>';
      resultsEl.classList.remove('hidden');
      return;
    }
    items.forEach((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'w-full text-right px-3 py-2 text-sm hover:bg-slate-50 border-b border-border-subtle last:border-0';
      btn.textContent = item.label || item.value || '';
      btn.addEventListener('click', () => addMaterial(item));
      resultsEl.appendChild(btn);
    });
    resultsEl.classList.remove('hidden');
  };

  const runSearch = async (q) => {
    const query = (q || '').trim();
    if (query.length < 2) {
      hideResults();
      if (statusEl) statusEl.textContent = '';
      return;
    }
    const requestId = ++searchRequestId;
    if (statusEl) statusEl.textContent = 'جاري البحث...';
    try {
      const res = await fetch(`/dashboard/home-sections-api.php?q=${encodeURIComponent(query)}&page=1`);
      const data = await res.json();
      if (requestId !== searchRequestId) return;
      const items = Array.isArray(data.items) ? data.items : [];
      renderResults(items);
      if (statusEl) statusEl.textContent = items.length ? '' : 'لا توجد نتائج.';
    } catch {
      if (statusEl) statusEl.textContent = 'فشل البحث.';
      hideResults();
    }
  };

  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(searchInput.value), 280);
  });

  document.addEventListener('click', (event) => {
    if (!resultsEl || !searchInput) return;
    const wrap = document.getElementById('pc-material-search-wrap');
    if (wrap && !wrap.contains(event.target)) {
      hideResults();
    }
  });
})();
