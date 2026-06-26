window.portalStoreFiltersInit = (root = document) => {
  const syncHeaderStickyOffset = () => {
    const header = document.querySelector('.site-header');
    if (!header) {
      return;
    }
    const height = Math.ceil(header.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--site-header-sticky-offset', `${height}px`);
  };

  syncHeaderStickyOffset();
  if (!window.__storeFiltersHeaderSyncBound) {
    window.__storeFiltersHeaderSyncBound = true;
    window.addEventListener('resize', syncHeaderStickyOffset, { passive: true });
    window.addEventListener('load', syncHeaderStickyOffset, { passive: true });

    const header = document.querySelector('.site-header');
    if (header && typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(syncHeaderStickyOffset).observe(header);
    }
  }

  const catalogRoot = root.matches?.('[data-store-catalog-root]')
    ? root
    : root.querySelector('[data-store-catalog-root]');
  if (!catalogRoot) {
    return;
  }

  const backdrop = catalogRoot.querySelector('#store-filters-backdrop');
  const openBtn = catalogRoot.querySelector('#store-filters-open');
  const closeBtn = catalogRoot.querySelector('#store-filters-close');
  const sidebar = catalogRoot.querySelector('.store-filters-sidebar');

  const setupFilterList = (list, input, toggleBtn) => {
    if (!list || list.dataset.filtersBound === '1') {
      return;
    }
    list.dataset.filtersBound = '1';

    const initialVisible = Number.parseInt(list.getAttribute('data-initial-visible') || '6', 10);
    let expanded = false;

    const applyVisibility = () => {
      const query = (input?.value || '').trim().toLowerCase();
      const searching = query !== '';

      list.querySelectorAll('.store-filter-option').forEach((row, index) => {
        const label = (row.getAttribute('data-filter-label') || '').toLowerCase();
        const matchesSearch = !searching || label.includes(query);
        row.classList.toggle('is-search-hidden', !matchesSearch);

        if (!matchesSearch) {
          return;
        }

        const checked = Boolean(row.querySelector('input')?.checked);
        const shouldCollapse = !searching && !expanded && !checked && index >= initialVisible;
        row.classList.toggle('is-collapsed', shouldCollapse);
      });

      if (!toggleBtn) {
        return;
      }

      if (searching) {
        toggleBtn.hidden = true;
        return;
      }

      toggleBtn.hidden = false;
      const hiddenCount = list.querySelectorAll('.store-filter-option.is-collapsed:not(.is-search-hidden)').length;
      toggleBtn.textContent = expanded ? 'عرض أقل' : (hiddenCount > 0 ? `عرض المزيد (${hiddenCount})` : 'عرض أقل');
      toggleBtn.classList.toggle('is-expanded', expanded);
    };

    if (input && input.dataset.filtersBound !== '1') {
      input.dataset.filtersBound = '1';
      input.addEventListener('input', applyVisibility);
    }

    if (toggleBtn && toggleBtn.dataset.filtersBound !== '1') {
      toggleBtn.dataset.filtersBound = '1';
      toggleBtn.addEventListener('click', () => {
        expanded = !expanded;
        applyVisibility();
      });
    }

    applyVisibility();
  };

  const bindFilterLists = () => {
    catalogRoot.querySelectorAll('[data-filter-list]').forEach((list) => {
      const groupId = list.getAttribute('data-filter-list');
      if (!groupId) {
        return;
      }
      const input = catalogRoot.querySelector(`[data-filter-search="${groupId}"]`);
      const toggleBtn = catalogRoot.querySelector(`[data-filter-toggle="${groupId}"]`);
      if (list.dataset.filtersBound !== '1') {
        setupFilterList(list, input, toggleBtn);
      }
    });
  };

  bindFilterLists();

  const filterListIsEmpty = (groupId) => {
    const list = catalogRoot.querySelector(`[data-filter-list="${groupId}"]`);
    return Boolean(list && list.querySelectorAll('.store-filter-option').length === 0);
  };

  const needsDeferredFilters = () => {
    if (catalogRoot.dataset.storeFiltersLoaded === '1') {
      return false;
    }
    if (catalogRoot.hasAttribute('data-store-filters-deferred')) {
      return true;
    }
    return ['materialTypes', 'ageCategories', 'manufacturers', 'sizeRanges', 'countryOfOrigins', 'stores', 'groups']
      .some((groupId) => filterListIsEmpty(groupId));
  };

  let deferredFiltersPromise = null;

  const renderStringFacetOptions = (groupId, paramName, facets) => {
    const list = catalogRoot.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list || list.querySelectorAll('.store-filter-option').length > 0) {
      return;
    }

    const selected = new Set(
      Array.from(catalogRoot.querySelectorAll(`input[name="${paramName}[]"]:checked`)).map((el) => el.value)
    );
    const rows = (facets || []).map((facet) => {
      const value = String(facet?.value || '').trim();
      if (!value) {
        return '';
      }
      const checked = selected.has(value) ? ' checked' : '';
      const count = facet?.count != null
        ? `<span class="store-filter-option-count">${Number.parseInt(String(facet.count), 10) || 0}</span>`
        : '';
      return `<label class="store-filter-option" data-filter-label="${value.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${value}</span>${count}`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    list.dataset.filtersBound = '0';
    const input = catalogRoot.querySelector(`[data-filter-search="${groupId}"]`);
    const toggleBtn = catalogRoot.querySelector(`[data-filter-toggle="${groupId}"]`);
    setupFilterList(list, input, toggleBtn);
  };

  const renderGuidFacetOptions = (groupId, paramName, items) => {
    const list = catalogRoot.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list || list.querySelectorAll('.store-filter-option').length > 0) {
      return;
    }

    const selected = new Set(
      Array.from(catalogRoot.querySelectorAll(`input[name="${paramName}[]"]:checked`)).map((el) => el.value.toLowerCase())
    );
    const rows = (items || []).map((item) => {
      const value = String(item?.guid || item?.Guid || '').trim();
      if (!value) {
        return '';
      }
      const label = String(item?.name || item?.Name || item?.code || item?.Code || value);
      const checked = selected.has(value.toLowerCase()) ? ' checked' : '';
      const count = item?.count != null
        ? `<span class="store-filter-option-count">${Number.parseInt(String(item.count), 10) || 0}</span>`
        : '';
      return `<label class="store-filter-option" data-filter-label="${label.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${label}</span>${count}`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    list.dataset.filtersBound = '0';
    const input = catalogRoot.querySelector(`[data-filter-search="${groupId}"]`);
    const toggleBtn = catalogRoot.querySelector(`[data-filter-toggle="${groupId}"]`);
    setupFilterList(list, input, toggleBtn);
  };

  const applyDeferredFilters = (data) => {
    if (!data?.ok) {
      throw new Error(data?.message || 'تعذر تحميل خيارات الفلاتر.');
    }

    const resultFilters = data.resultFilters || {};
    const filterOptions = data.filterOptions || {};
    const facetMap = [
      ['materialTypes', 'materialTypes'],
      ['ageCategories', 'ageCategories'],
      ['manufacturers', 'manufacturers'],
      ['sizeRanges', 'sizeRanges'],
      ['countryOfOrigins', 'countryOfOrigins'],
    ];

    facetMap.forEach(([groupId, paramName]) => {
      renderStringFacetOptions(groupId, paramName, resultFilters[groupId] || []);
    });

    const groupFacets = Array.isArray(resultFilters.groups) && resultFilters.groups.length > 0
      ? resultFilters.groups
      : (filterOptions.groups || []);
    renderGuidFacetOptions('groups', 'groupGuids', groupFacets);
    renderGuidFacetOptions('stores', 'storeGuids', filterOptions.stores || []);

    catalogRoot.dataset.storeFiltersLoaded = '1';
    catalogRoot.removeAttribute('data-store-filters-deferred');
  };

  const loadDeferredFilters = () => {
    if (!needsDeferredFilters()) {
      return Promise.resolve();
    }
    if (deferredFiltersPromise) {
      return deferredFiltersPromise;
    }

    sidebar?.classList.add('is-loading-options');
    deferredFiltersPromise = fetch('/api/store-filter-options.php', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        applyDeferredFilters(data);
      })
      .finally(() => {
        sidebar?.classList.remove('is-loading-options');
      });

    return deferredFiltersPromise;
  };

  const scheduleDeferredFilters = () => {
    if (!needsDeferredFilters()) {
      return;
    }
    const run = () => {
      loadDeferredFilters().catch(() => {});
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(run, { timeout: 2000 });
    } else {
      window.setTimeout(run, 600);
    }
  };

  const setDrawerOpen = (open) => {
    document.body.classList.toggle('store-filters-drawer-open', open);
    if (backdrop) {
      backdrop.classList.toggle('is-open', open);
      backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
    if (open) {
      loadDeferredFilters().catch(() => {});
    }
  };

  if (openBtn && openBtn.dataset.filtersBound !== '1') {
    openBtn.dataset.filtersBound = '1';
    openBtn.addEventListener('click', () => setDrawerOpen(true));
  }
  if (closeBtn && closeBtn.dataset.filtersBound !== '1') {
    closeBtn.dataset.filtersBound = '1';
    closeBtn.addEventListener('click', () => setDrawerOpen(false));
  }
  if (backdrop && backdrop.dataset.filtersBound !== '1') {
    backdrop.dataset.filtersBound = '1';
    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop) {
        setDrawerOpen(false);
      }
    });
  }

  scheduleDeferredFilters();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => window.portalStoreFiltersInit());
} else {
  window.portalStoreFiltersInit();
}
