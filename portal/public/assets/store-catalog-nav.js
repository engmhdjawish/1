/**
 * Store catalog — AJAX navigation with immediate loading state.
 */
(function () {
  'use strict';

  const NAV_HEADER = 'X-Store-Nav';
  let navAbort = null;
  let navGeneration = 0;
  let navBusy = false;

  function isStoreCatalogUrl(url) {
    try {
      const parsed = new URL(url, window.location.origin);
      if (parsed.origin !== window.location.origin) return false;
      const path = parsed.pathname.replace(/\/+$/, '') || '/';
      return path === '/store.php' || path === '/store';
    } catch {
      return false;
    }
  }

  function catalogRoot() {
    return document.querySelector('[data-store-catalog-root]');
  }

  function skeletonCardHtml(includeFooter = false) {
    const footer = includeFooter
      ? '<div class="store-product-card__footer store-product-card__footer--skeleton">'
        + '<div class="store-skeleton-block store-skeleton-block--footer"></div>'
        + '</div>'
      : '';

    return '<article class="store-product-card store-product-card--skeleton" aria-hidden="true">'
      + '<div class="store-product-card__media store-product-card__media--skeleton">'
      + '<div class="store-skeleton-block store-skeleton-block--image"></div>'
      + '</div>'
      + '<div class="store-product-card__body store-product-card__body--skeleton">'
      + '<div class="store-skeleton-block store-skeleton-block--title"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line store-skeleton-block--short"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line store-skeleton-block--price"></div>'
      + '</div>'
      + footer
      + '</article>';
  }

  function inferSkeletonCardCount(results) {
    const grid = results.querySelector('.store-product-grid');
    const currentCards = grid ? grid.querySelectorAll('.store-product-card:not(.store-product-card--skeleton)').length : 0;
    if (currentCards > 0) {
      return currentCards;
    }

    const meta = results.querySelector('.store-results-meta')?.textContent || '';
    const rangeMatch = meta.match(/عرض\s+(\d+)\s*[–-]\s*(\d+)/u);
    if (rangeMatch) {
      const start = Number.parseInt(rangeMatch[1], 10);
      const end = Number.parseInt(rangeMatch[2], 10);
      if (Number.isFinite(start) && Number.isFinite(end) && end >= start) {
        return Math.max(1, end - start + 1);
      }
    }

    const columns = window.matchMedia('(min-width: 1280px)').matches ? 4
      : window.matchMedia('(min-width: 1024px)').matches ? 3
        : window.matchMedia('(min-width: 640px)').matches ? 2
          : 1;

    return Math.max(columns * 3, 8);
  }

  function showCatalogLoading(root) {
    if (!root) return;
    root.setAttribute('aria-busy', 'true');
    root.classList.add('is-catalog-loading');

    const results = root.querySelector('.store-results');
    if (!results) return;

    results.classList.add('is-loading');

    const grid = results.querySelector('.store-product-grid');
    const hadFooter = Boolean(grid?.querySelector('.store-product-card__footer'));
    const cardCount = inferSkeletonCardCount(results);

    results.querySelector('.store-empty-state')?.remove();

    let targetGrid = grid;
    if (!targetGrid) {
      targetGrid = document.createElement('div');
      targetGrid.className = 'store-product-grid';
      const toolbar = results.querySelector('.store-results-toolbar');
      if (toolbar?.nextElementSibling) {
        results.insertBefore(targetGrid, toolbar.nextElementSibling);
      } else {
        results.appendChild(targetGrid);
      }
    }

    targetGrid.classList.add('store-product-grid--loading');
    targetGrid.setAttribute('aria-busy', 'true');
    targetGrid.innerHTML = Array.from({ length: cardCount }, () => skeletonCardHtml(hadFooter)).join('');

    const meta = results.querySelector('.store-results-meta');
    if (meta) {
      meta.textContent = 'جاري تحميل المواد...';
      meta.classList.add('is-loading');
    }

    results.querySelectorAll('.store-pagination a, .store-pagination button').forEach((el) => {
      el.setAttribute('aria-disabled', 'true');
      el.classList.add('is-disabled');
      el.tabIndex = -1;
    });
  }

  function syncPreviewPaging(root) {
    const script = root?.querySelector('[data-store-preview-paging]');
    if (!script) return;
    try {
      window.__storePreviewPaging = JSON.parse(script.textContent || '{}');
    } catch {
      window.__storePreviewPaging = {};
    }
  }

  function syncCanonicalFilterUrl(root) {
    const script = root?.querySelector('[data-store-canonical-query]');
    if (!script) return;

    let missingParams = {};
    try {
      missingParams = JSON.parse(script.textContent || '{}');
    } catch {
      return;
    }

    const url = new URL(window.location.href);
    let changed = false;
    Object.entries(missingParams).forEach(([key, value]) => {
      if (url.searchParams.has(key)) {
        return;
      }
      const normalized = String(value ?? '').trim();
      if (normalized === '') {
        return;
      }
      url.searchParams.set(key, normalized);
      changed = true;
    });

    if (!changed) {
      return;
    }

    const nextUrl = url.toString();
    history.replaceState({ storeCatalogUrl: nextUrl }, '', nextUrl);
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function filterToneForGroup(groupId) {
    const tones = {
      materialTypes: 'material',
      ageCategories: 'age',
      manufacturers: 'manufacturer',
      sizeRanges: 'size',
      countryOfOrigins: 'country',
      stores: 'stores',
      groups: 'groups',
    };
    return tones[groupId] || 'default';
  }

  function urlWithoutScalarParam(urlString, key) {
    const url = new URL(urlString, window.location.origin);
    url.searchParams.delete(key);
    url.searchParams.set('page', '1');
    return url.pathname + url.search;
  }

  function urlWithoutArrayValue(urlString, param, value) {
    const url = new URL(urlString, window.location.origin);
    [param, `${param}[]`].forEach((key) => {
      const remaining = url.searchParams.getAll(key).filter((item) => item !== value);
      url.searchParams.delete(key);
      remaining.forEach((item) => url.searchParams.append(key, item));
    });
    url.searchParams.set('page', '1');
    return url.pathname + url.search;
  }

  function buildClearAllFiltersUrl(targetUrl) {
    const source = new URL(targetUrl, window.location.origin);
    const clear = new URL('/store.php', window.location.origin);
    const section = source.searchParams.get('section');
    const offer = source.searchParams.get('offer');
    if (section) clear.searchParams.set('section', section);
    if (offer) clear.searchParams.set('offer', offer);
    return clear.pathname + clear.search;
  }

  function updateMobileFilterBadge(chipCount) {
    const openBtn = document.getElementById('store-filters-open');
    if (!openBtn) return;
    let badge = openBtn.querySelector('.badge');
    if (chipCount <= 0) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'badge';
      openBtn.appendChild(badge);
    }
    badge.textContent = String(chipCount);
  }

  function countOptimisticFilterChips(groups) {
    return groups.reduce((total, group) => total + (group.chips?.length || 0), 0);
  }

  function getUrlParamValues(url, param) {
    const values = [];
    url.searchParams.forEach((value, key) => {
      if (key === param || key === `${param}[]` || key.startsWith(`${param}[`)) {
        values.push(value);
      }
    });
    return values;
  }

  function syncFilterFormFromUrl(urlString) {
    const form = catalogRoot()?.querySelector('.store-filters-sidebar-inner');
    if (!form) return;

    const url = new URL(urlString, window.location.origin);
    const searchInput = form.querySelector('#store-search-q');
    if (searchInput) {
      searchInput.value = url.searchParams.get('q') || '';
    }

    form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      const param = input.name.replace(/\[\]$/, '');
      const selected = new Set(getUrlParamValues(url, param));
      input.checked = selected.has(input.value);
    });

    const availability = url.searchParams.get('isAvailable');
    form.querySelectorAll('input[name="isAvailable"]').forEach((input) => {
      if (availability === null || availability === '') {
        input.checked = input.value === '';
        return;
      }
      input.checked = input.value === availability;
    });

    [
      'minWarehouseQuantity',
      'maxWarehouseQuantity',
      'minUnitSalePriceSyp',
      'maxUnitSalePriceSyp',
      'minUnitSalePriceUsd',
      'maxUnitSalePriceUsd',
      'minUnitPurchasePriceUsd',
      'maxUnitPurchasePriceUsd',
    ].forEach((name) => {
      const input = form.querySelector(`input[name="${name}"]`);
      if (input) {
        input.value = url.searchParams.get(name) || '';
      }
    });

    const groupBy = form.querySelector('#store-group-by');
    if (groupBy) {
      groupBy.value = url.searchParams.get('groupBy') || 'none';
    }
  }

  function removeFilterChipOptimistically(chipLink) {
    const section = chipLink.closest('.store-active-filters');
    if (!section) return;

    const group = chipLink.closest('.store-active-filter-group');
    chipLink.remove();
    if (group && group.querySelectorAll('.store-active-chip').length === 0) {
      group.remove();
    }

    const remaining = section.querySelectorAll('.store-active-chip').length;
    updateMobileFilterBadge(remaining);
    if (remaining === 0) {
      section.remove();
    }
  }

  function clearFilterChipsOptimistically() {
    catalogRoot()?.querySelector('.store-active-filters')?.remove();
    updateMobileFilterBadge(0);
  }

  function renderOptimisticFilterChipSection(groups, targetUrl) {
    const root = catalogRoot();
    const results = root?.querySelector('.store-results');
    if (!results) return;

    updateMobileFilterBadge(countOptimisticFilterChips(groups));

    let section = results.querySelector('.store-active-filters');
    if (groups.length === 0) {
      section?.remove();
      return;
    }

    const clearUrl = buildClearAllFiltersUrl(targetUrl);
    const groupsHtml = groups.map((group) => {
      const chipsHtml = group.chips.map((chip) => (
        `<a href="${escapeHtml(chip.url)}" class="store-active-chip" title="إزالة ${escapeHtml(chip.text)}">`
        + `<span>${escapeHtml(chip.text)}</span>`
        + '<span class="store-active-chip-remove material-symbols-outlined" aria-hidden="true">close</span>'
        + '</a>'
      )).join('');
      return `<div class="store-active-filter-group store-active-filter-group--${escapeHtml(group.tone)}">`
        + `<span class="store-active-filter-group-label">${escapeHtml(group.label)}</span>`
        + `<div class="store-active-filter-chips">${chipsHtml}</div>`
        + '</div>';
    }).join('');

    const html = '<div class="store-active-filters-head">'
      + '<span class="store-active-filters-title">الفلاتر المختارة</span>'
      + `<a href="${escapeHtml(clearUrl)}" class="store-active-filters-clear">مسح الكل</a>`
      + '</div>'
      + groupsHtml;

    if (!section) {
      section = document.createElement('section');
      section.className = 'store-active-filters';
      section.setAttribute('aria-label', 'الفلاتر المطبّقة');
      results.insertBefore(section, results.firstChild);
    }
    section.innerHTML = html;
  }

  function showOptimisticFilterChips(form, targetUrl) {
    const root = catalogRoot();
    const results = root?.querySelector('.store-results');
    if (!results || !form) return;

    const groups = [];
    const pushGroup = (label, tone, chips) => {
      const normalized = (chips || []).filter((chip) => chip?.text && chip?.url);
      if (normalized.length > 0) {
        groups.push({ label, tone, chips: normalized });
      }
    };

    const searchValue = form.querySelector('#store-search-q')?.value?.trim();
    if (searchValue) {
      pushGroup('بحث', 'search', [{
        text: searchValue,
        url: urlWithoutScalarParam(targetUrl, 'q'),
      }]);
    }

    form.querySelectorAll('[data-filter-group]').forEach((accordion) => {
      const title = accordion.querySelector('.store-filter-accordion-summary span')?.textContent?.trim() || '';
      const groupId = accordion.getAttribute('data-filter-group') || 'default';
      const chips = [];
      accordion.querySelectorAll('input[type="checkbox"]:checked').forEach((input) => {
        const text = input.closest('.store-filter-option')
          ?.querySelector('.store-filter-option-text')
          ?.textContent?.trim() || input.value;
        const param = input.name.replace(/\[\]$/, '');
        chips.push({
          text,
          url: urlWithoutArrayValue(targetUrl, param, input.value),
        });
      });
      if (title) {
        pushGroup(title, filterToneForGroup(groupId), chips);
      }
    });

    const availability = form.querySelector('input[name="isAvailable"]:checked');
    if (availability && availability.value !== '') {
      const text = availability.closest('.store-filter-option')
        ?.querySelector('.store-filter-option-text')
        ?.textContent?.trim() || availability.value;
      pushGroup('التوفر', 'availability', [{
        text,
        url: urlWithoutScalarParam(targetUrl, 'isAvailable'),
      }]);
    }

    const minWarehouse = form.querySelector('input[name="minWarehouseQuantity"]')?.value?.trim() || '';
    const maxWarehouse = form.querySelector('input[name="maxWarehouseQuantity"]')?.value?.trim() || '';
    if (minWarehouse !== '' || maxWarehouse !== '') {
      const text = `من ${minWarehouse || '…'} إلى ${maxWarehouse || '…'}`;
      const url = new URL(targetUrl, window.location.origin);
      url.searchParams.delete('minWarehouseQuantity');
      url.searchParams.delete('maxWarehouseQuantity');
      url.searchParams.set('page', '1');
      pushGroup('مدى الكمية', 'warehouse', [{ text, url: url.pathname + url.search }]);
    }

    const priceRanges = [
      ['minUnitSalePriceSyp', 'maxUnitSalePriceSyp', 'سعر البيع ل.س', 'price-syp'],
      ['minUnitSalePriceUsd', 'maxUnitSalePriceUsd', 'سعر البيع $', 'price-usd'],
      ['minUnitPurchasePriceUsd', 'maxUnitPurchasePriceUsd', 'سعر الشراء $', 'price-purchase'],
    ];
    priceRanges.forEach(([minKey, maxKey, label, tone]) => {
      const min = form.querySelector(`input[name="${minKey}"]`)?.value?.trim() || '';
      const max = form.querySelector(`input[name="${maxKey}"]`)?.value?.trim() || '';
      if (min === '' && max === '') {
        return;
      }
      const text = `من ${min || '…'} إلى ${max || '…'}`;
      const url = new URL(targetUrl, window.location.origin);
      url.searchParams.delete(minKey);
      url.searchParams.delete(maxKey);
      url.searchParams.set('page', '1');
      pushGroup(label, tone, [{ text, url: url.pathname + url.search }]);
    });

    const groupBy = form.querySelector('#store-group-by');
    if (groupBy && groupBy.value && groupBy.value !== 'none') {
      const text = groupBy.options[groupBy.selectedIndex]?.textContent?.trim() || groupBy.value;
      pushGroup('التجميع', 'group-by', [{
        text,
        url: urlWithoutScalarParam(targetUrl, 'groupBy'),
      }]);
    }

    renderOptimisticFilterChipSection(groups, targetUrl);
  }

  function applyOptimisticFilterChipNavigation(link, targetUrl) {
    if (link.classList.contains('store-active-chip')) {
      removeFilterChipOptimistically(link);
    } else if (link.classList.contains('store-active-filters-clear')) {
      clearFilterChipsOptimistically();
    }
    syncFilterFormFromUrl(targetUrl);
  }

  function closeFilterDrawer() {
    document.body.classList.remove('store-filters-drawer-open');
    const backdrop = document.getElementById('store-filters-backdrop');
    if (backdrop) {
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('aria-hidden', 'true');
    }
  }

  function buildUrlFromGetForm(form, submitter) {
    const formData = new FormData(form);
    if (submitter instanceof HTMLElement && submitter.name) {
      formData.set(submitter.name, submitter.value);
    }
    const action = form.getAttribute('action') || window.location.pathname;
    const url = new URL(action, window.location.origin);
    url.search = '';
    formData.forEach((value, key) => {
      if (key.endsWith('[]')) {
        url.searchParams.append(key, value);
      } else {
        url.searchParams.set(key, value);
      }
    });
    return url.toString();
  }

  function bindCatalogForms(root) {
    root.querySelectorAll('form').forEach((form) => {
      if (form.dataset.storeNavBound === '1') return;
      if (form.method && form.method.toLowerCase() !== 'get') return;
      form.dataset.storeNavBound = '1';
      form.addEventListener('submit', (event) => {
        if (form.dataset.storeSubmitting === '1') {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        form.dataset.storeSubmitting = '1';
        const submitter = event.submitter;
        const targetUrl = buildUrlFromGetForm(form, submitter);
        if (form.classList.contains('store-filters-sidebar-inner')) {
          showOptimisticFilterChips(form, targetUrl);
        }
        closeFilterDrawer();
        navigateStore(targetUrl);
      });
    });
  }

  function bindCatalogLinks(root) {
    root.querySelectorAll('a[href]').forEach((link) => {
      if (link.dataset.storeNavBound === '1') return;
      const href = link.getAttribute('href');
      if (!href || !isStoreCatalogUrl(href)) return;
      if (link.target === '_blank' || link.hasAttribute('download')) return;
      link.dataset.storeNavBound = '1';
      link.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (link.classList.contains('store-active-chip') || link.classList.contains('store-active-filters-clear')) {
          applyOptimisticFilterChipNavigation(link, href);
        }
        closeFilterDrawer();
        navigateStore(href);
      });
    });
  }

  function bindCatalog(root = catalogRoot()) {
    if (!root) return;
    bindCatalogForms(root);
    bindCatalogLinks(root);
    syncPreviewPaging(root);
    syncCanonicalFilterUrl(root);
    if (typeof window.portalStoreFiltersInit === 'function') {
      window.portalStoreFiltersInit(root);
    }
    if (window.StoreCart?.bindAddForms) {
      window.StoreCart.bindAddForms();
    }
    if (window.StoreCart?.bindQtySteppers) {
      window.StoreCart.bindQtySteppers(root);
    }
    window.StoreImageZoom?.seedLoadedImages?.(root);
  }

  async function navigateStore(url, push = true) {
    if (!isStoreCatalogUrl(url)) {
      window.location.href = url;
      return;
    }

    if (navAbort) {
      navAbort.abort();
    }
    const generation = ++navGeneration;
    navAbort = new AbortController();
    const signal = navAbort.signal;
    navBusy = true;
    document.body.classList.add('store-catalog-nav-busy');

    const root = catalogRoot();
    showCatalogLoading(root);
    window.scrollTo({ top: root ? root.offsetTop - 80 : 0, behavior: 'smooth' });

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { [NAV_HEADER]: '1', Accept: 'text/html' },
        signal,
      });
      if (generation !== navGeneration) return;
      if (!response.ok) throw new Error('تعذر تحميل المتجر.');

      const html = await response.text();
      if (generation !== navGeneration) return;

      const doc = new DOMParser().parseFromString(html, 'text/html');
      const newRoot = doc.querySelector('[data-store-catalog-root]');
      const currentRoot = catalogRoot();
      if (!newRoot || !currentRoot) {
        window.location.href = url;
        return;
      }

      currentRoot.replaceWith(newRoot);
      newRoot.classList.remove('is-catalog-loading');
      newRoot.removeAttribute('aria-busy');
      newRoot.querySelector('.store-results')?.classList.remove('is-loading');
      newRoot.querySelector('.store-results-meta')?.classList.remove('is-loading');

      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ storeCatalogUrl: url }, '', url);
      bindCatalog(newRoot);
    } catch (error) {
      if (error?.name === 'AbortError') return;
      const currentRoot = catalogRoot();
      if (currentRoot) {
        currentRoot.classList.remove('is-catalog-loading');
        currentRoot.removeAttribute('aria-busy');
        const results = currentRoot.querySelector('.store-results');
        if (results) {
          results.classList.remove('is-loading');
          const grid = results.querySelector('.store-product-grid');
          if (grid) {
            grid.classList.remove('store-product-grid--loading');
            grid.removeAttribute('aria-busy');
            grid.innerHTML = '<div class="store-empty-state">'
              + (error instanceof Error ? error.message : 'تعذر تحميل المتجر.')
              + '</div>';
          }
        }
      }
    } finally {
      if (generation === navGeneration) {
        navBusy = false;
        document.body.classList.remove('store-catalog-nav-busy');
        catalogRoot()?.querySelectorAll('form[data-store-submitting]').forEach((form) => {
          delete form.dataset.storeSubmitting;
        });
      }
    }
  }

  function bindHistory() {
    window.addEventListener('popstate', (event) => {
      const url = event.state?.storeCatalogUrl || window.location.href;
      if (isStoreCatalogUrl(url)) {
        navigateStore(url, false);
      }
    });
  }

  window.portalStoreCatalogInit = function portalStoreCatalogInit(root = catalogRoot()) {
    bindCatalog(root);
  };

  window.portalStoreCatalogNavigate = navigateStore;

  if (catalogRoot()) {
    bindCatalog();
    bindHistory();
    history.replaceState({ storeCatalogUrl: window.location.href }, '', window.location.href);
  }
})();
