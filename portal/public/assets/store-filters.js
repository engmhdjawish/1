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
  const openButtons = catalogRoot.querySelectorAll('[data-store-filters-open]');
  const closeBtn = catalogRoot.querySelector('#store-filters-close');
  const filterForm = catalogRoot.querySelector('#store-filters-form');
  const sidebarSearchInput = catalogRoot.querySelector('#store-search-q');
  const mobileSearchInput = catalogRoot.querySelector('#store-mobile-search-q');

  const setupExclusiveFilterAccordions = () => {
    const accordions = catalogRoot.querySelectorAll('.store-filter-accordion');
    accordions.forEach((accordion) => {
      if (accordion.dataset.accordionBound === '1') {
        return;
      }
      accordion.dataset.accordionBound = '1';
      accordion.addEventListener('toggle', () => {
        if (!accordion.open) {
          return;
        }
        accordions.forEach((other) => {
          if (other !== accordion) {
            other.open = false;
          }
        });
      });
    });
  };

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

      let visibleIndex = 0;
      list.querySelectorAll('.store-filter-option').forEach((row) => {
        const label = (row.getAttribute('data-filter-label') || '').toLowerCase();
        const matchesSearch = !searching || label.includes(query);
        row.classList.toggle('is-search-hidden', !matchesSearch);

        if (!matchesSearch) {
          return;
        }

        const checked = Boolean(row.querySelector('input')?.checked);
        const shouldCollapse = !searching && !expanded && !checked && visibleIndex >= initialVisible;
        row.classList.toggle('is-collapsed', shouldCollapse);
        visibleIndex += 1;
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

  const FILTER_GROUP_META = {
    materialTypes: { tone: 'material', label: 'نوع المادة', kind: 'checkbox', param: 'materialTypes', containerGroup: 'materialTypes' },
    ageCategories: { tone: 'age', label: 'الفئة العمرية', kind: 'checkbox', param: 'ageCategories', containerGroup: 'ageCategories' },
    manufacturers: { tone: 'manufacturer', label: 'الشركة', kind: 'checkbox', param: 'manufacturers', containerGroup: 'manufacturers' },
    sizeRanges: { tone: 'size', label: 'القياس', kind: 'checkbox', param: 'sizeRanges', containerGroup: 'sizeRanges' },
    countryOfOrigins: { tone: 'country', label: 'بلد المنشأ', kind: 'checkbox', param: 'countryOfOrigins', containerGroup: 'countryOfOrigins' },
    stores: { tone: 'stores', label: 'المخازn', kind: 'checkbox', param: 'storeGuids', containerGroup: 'stores' },
    groups: { tone: 'groups', label: 'المجموعات', kind: 'checkbox', param: 'groupGuids', containerGroup: 'groups' },
  };

  const PRICE_RANGE_FIELDS = [
    { tone: 'price-syp', label: 'بيع ل.س', min: 'minUnitSalePriceSyp', max: 'maxUnitSalePriceSyp' },
    { tone: 'price-usd', label: 'بيع $', min: 'minUnitSalePriceUsd', max: 'maxUnitSalePriceUsd' },
    { tone: 'price-purchase', label: 'شراء $', min: 'minUnitPurchasePriceUsd', max: 'maxUnitPurchasePriceUsd' },
  ];

  const escapeChipHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/"/g, '&quot;');

  const escapeChipSelector = (value) => {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
      return CSS.escape(String(value));
    }

    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  };

  const collectPendingFilterChips = () => {
    if (!filterForm) {
      return [];
    }

    const chips = [];

    Object.entries(FILTER_GROUP_META).forEach(([groupId, meta]) => {
      filterForm.querySelectorAll(`input[name="${meta.param}[]"]:checked`).forEach((input) => {
        const text = input.closest('.store-filter-option')
          ?.querySelector('.store-filter-option-text')
          ?.textContent?.trim() || input.value;
        chips.push({
          kind: 'checkbox',
          param: meta.param,
          value: input.value,
          text,
          tone: meta.tone,
          groupLabel: meta.label,
          containerGroup: meta.containerGroup,
        });
      });
      void groupId;
    });

    const availabilityInput = filterForm.querySelector('input[name="isAvailable"]:checked');
    if (availabilityInput && availabilityInput.value !== '') {
      const text = availabilityInput.closest('.store-filter-option')
        ?.querySelector('.store-filter-option-text')
        ?.textContent?.trim() || availabilityInput.value;
      chips.push({
        kind: 'radio',
        param: 'isAvailable',
        value: availabilityInput.value,
        text,
        tone: 'availability',
        groupLabel: 'التوفر',
        containerGroup: 'availability',
      });
    }

    const minWarehouse = filterForm.querySelector('input[name="minWarehouseQuantity"]')?.value?.trim() || '';
    const maxWarehouse = filterForm.querySelector('input[name="maxWarehouseQuantity"]')?.value?.trim() || '';
    if (minWarehouse !== '' || maxWarehouse !== '') {
      chips.push({
        kind: 'range',
        minKey: 'minWarehouseQuantity',
        maxKey: 'maxWarehouseQuantity',
        text: `من ${minWarehouse || '…'} إلى ${maxWarehouse || '…'}`,
        tone: 'warehouse',
        groupLabel: 'مدى الكمية',
        containerGroup: 'warehouse',
      });
    }

    PRICE_RANGE_FIELDS.forEach((range) => {
      const min = filterForm.querySelector(`input[name="${range.min}"]`)?.value?.trim() || '';
      const max = filterForm.querySelector(`input[name="${range.max}"]`)?.value?.trim() || '';
      if (min === '' && max === '') {
        return;
      }
      chips.push({
        kind: 'range',
        minKey: range.min,
        maxKey: range.max,
        text: `${range.label}: من ${min || '…'} إلى ${max || '…'}`,
        tone: range.tone,
        groupLabel: range.label,
        containerGroup: 'price',
      });
    });

    const groupBy = filterForm.querySelector('#store-group-by');
    if (groupBy && groupBy.value && groupBy.value !== 'none') {
      const text = groupBy.options[groupBy.selectedIndex]?.textContent?.trim() || groupBy.value;
      chips.push({
        kind: 'select',
        param: 'groupBy',
        value: groupBy.value,
        text,
        tone: 'group-by',
        groupLabel: 'التجميع',
        containerGroup: 'groupBy',
      });
    }

    const searchValue = (sidebarSearchInput?.value || mobileSearchInput?.value || '').trim();
    if (searchValue !== '') {
      chips.push({
        kind: 'search',
        text: searchValue,
        tone: 'search',
        groupLabel: 'بحث',
        containerGroup: 'search',
      });
    }

    return chips;
  };

  const renderPendingChipHtml = (chip, showPrefix) => {
    const prefix = showPrefix && chip.groupLabel
      ? `<span class="store-filter-pending-chip-prefix">${escapeChipHtml(chip.groupLabel)}:</span> `
      : '';
    return `<button type="button" class="store-filter-pending-chip store-filter-pending-chip--${escapeChipHtml(chip.tone)}"`
      + ` data-chip-kind="${escapeChipHtml(chip.kind)}"`
      + ` data-chip-param="${escapeChipHtml(chip.param || '')}"`
      + ` data-chip-value="${escapeChipHtml(chip.value || '')}"`
      + ` data-chip-min="${escapeChipHtml(chip.minKey || '')}"`
      + ` data-chip-max="${escapeChipHtml(chip.maxKey || '')}"`
      + ` title="إزالة ${escapeChipHtml(chip.text)}">`
      + `<span class="store-filter-pending-chip-label">${prefix}${escapeChipHtml(chip.text)}</span>`
      + `<span class="store-filter-pending-chip-remove material-symbols-outlined" aria-hidden="true">remove</span>`
      + '</button>';
  };

  const updateAccordionBadge = (groupId, count) => {
    const accordion = catalogRoot.querySelector(`[data-filter-group="${groupId}"]`);
    if (!accordion) {
      return;
    }
    let badge = accordion.querySelector('.store-filter-accordion-badge');
    if (count <= 0) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'store-filter-accordion-badge';
      accordion.querySelector('.store-filter-accordion-summary')?.appendChild(badge);
    }
    badge.textContent = String(count);
  };

  const updatePendingOptionStates = () => {
    if (!filterForm) {
      return;
    }
    filterForm.querySelectorAll('.store-filter-option input[type="checkbox"]').forEach((input) => {
      input.closest('.store-filter-option')?.classList.toggle('is-pending-selected', input.checked);
    });
  };

  const removePendingChip = (button) => {
    if (!filterForm || !button) {
      return;
    }
    const kind = button.getAttribute('data-chip-kind') || '';
    const param = button.getAttribute('data-chip-param') || '';
    const value = button.getAttribute('data-chip-value') || '';
    const minKey = button.getAttribute('data-chip-min') || '';
    const maxKey = button.getAttribute('data-chip-max') || '';

    if (kind === 'checkbox') {
      const input = filterForm.querySelector(`input[name="${param}[]"][value="${escapeChipSelector(value)}"]`);
      if (input) {
        input.checked = false;
      }
    } else if (kind === 'radio') {
      const empty = filterForm.querySelector(`input[name="${param}"][value=""]`);
      if (empty) {
        empty.checked = true;
      }
    } else if (kind === 'range') {
      const minInput = minKey ? filterForm.querySelector(`input[name="${minKey}"]`) : null;
      const maxInput = maxKey ? filterForm.querySelector(`input[name="${maxKey}"]`) : null;
      if (minInput) {
        minInput.value = '';
      }
      if (maxInput) {
        maxInput.value = '';
      }
    } else if (kind === 'select') {
      const select = filterForm.querySelector(`select[name="${param}"]`);
      if (select) {
        select.value = 'none';
      }
    } else if (kind === 'search') {
      if (sidebarSearchInput) {
        sidebarSearchInput.value = '';
      }
      if (mobileSearchInput) {
        mobileSearchInput.value = '';
      }
    }

    refreshPendingFilterChips();
  };

  const clearAllPendingSelections = () => {
    if (!filterForm) {
      return;
    }
    filterForm.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.checked = false;
    });
    const emptyAvailability = filterForm.querySelector('input[name="isAvailable"][value=""]');
    if (emptyAvailability) {
      emptyAvailability.checked = true;
    }
    filterForm.querySelectorAll('input[type="number"]').forEach((input) => {
      input.value = '';
    });
    const groupBy = filterForm.querySelector('#store-group-by');
    if (groupBy) {
      groupBy.value = 'none';
    }
    if (sidebarSearchInput) {
      sidebarSearchInput.value = '';
    }
    if (mobileSearchInput) {
      mobileSearchInput.value = '';
    }
    refreshPendingFilterChips();
  };

  const refreshPendingFilterChips = () => {
    const chips = collectPendingFilterChips();
    const globalPanel = catalogRoot.querySelector('#store-filter-pending-panel');
    const globalContainer = catalogRoot.querySelector('#store-filter-pending-chips-global');
    const clearAllBtn = catalogRoot.querySelector('#store-filter-pending-clear-all');

    if (globalContainer) {
      globalContainer.innerHTML = chips.map((chip) => renderPendingChipHtml(chip, true)).join('');
    }
    if (globalPanel) {
      globalPanel.classList.toggle('has-selection', chips.length > 0);
    }
    if (clearAllBtn) {
      clearAllBtn.hidden = chips.length === 0;
    }

    catalogRoot.querySelectorAll('[data-filter-group-chips]').forEach((container) => {
      const groupId = container.getAttribute('data-filter-group-chips') || '';
      const groupChips = chips.filter((chip) => chip.containerGroup === groupId);
      container.hidden = groupChips.length === 0;
      container.innerHTML = groupChips.map((chip) => renderPendingChipHtml(chip, false)).join('');
    });

    Object.keys(FILTER_GROUP_META).forEach((groupId) => {
      const count = chips.filter((chip) => chip.containerGroup === groupId).length;
      updateAccordionBadge(groupId, count);
    });
    updateAccordionBadge('availability', chips.filter((chip) => chip.containerGroup === 'availability').length);
    updateAccordionBadge('warehouse', chips.filter((chip) => chip.containerGroup === 'warehouse').length);
    updateAccordionBadge('price', chips.filter((chip) => chip.containerGroup === 'price').length);

    updatePendingOptionStates();
  };

  window.portalStoreFiltersRefreshPending = refreshPendingFilterChips;

  if (filterForm && filterForm.dataset.pendingChipsBound !== '1') {
    filterForm.dataset.pendingChipsBound = '1';
    filterForm.addEventListener('change', refreshPendingFilterChips);
    filterForm.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target.matches('input[type="number"], input[type="search"], #store-search-q')) {
        refreshPendingFilterChips();
      }
    });
    filterForm.addEventListener('click', (event) => {
      const chipBtn = event.target.closest?.('.store-filter-pending-chip');
      if (!chipBtn || !filterForm.contains(chipBtn)) {
        return;
      }
      event.preventDefault();
      removePendingChip(chipBtn);
    });
  }

  const clearAllPendingBtn = catalogRoot.querySelector('#store-filter-pending-clear-all');
  if (clearAllPendingBtn && clearAllPendingBtn.dataset.filtersBound !== '1') {
    clearAllPendingBtn.dataset.filtersBound = '1';
    clearAllPendingBtn.addEventListener('click', clearAllPendingSelections);
  }

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

  const ensureFilterGroupControls = (groupId, optionCount) => {
    const accordion = catalogRoot.querySelector(`[data-filter-group="${groupId}"]`);
    const body = accordion?.querySelector('.store-filter-accordion-body');
    const list = body?.querySelector(`[data-filter-list="${groupId}"]`);
    if (!body || !list) {
      return;
    }

    const initialVisible = Number.parseInt(list.getAttribute('data-initial-visible') || '6', 10);
    const searchThreshold = 5;
    const title = accordion?.querySelector('.store-filter-accordion-summary span')?.textContent?.trim() || '';

    if (optionCount >= searchThreshold && !body.querySelector(`[data-filter-search="${groupId}"]`)) {
      const input = document.createElement('input');
      input.type = 'search';
      input.className = 'store-filter-search';
      input.placeholder = title ? `ابحث في ${title}...` : 'بحث...';
      input.dataset.filterSearch = groupId;
      input.autocomplete = 'off';
      body.insertBefore(input, list);
    }

    let toggleBtn = body.querySelector(`[data-filter-toggle="${groupId}"]`);
    if (optionCount > initialVisible) {
      if (!toggleBtn) {
        toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'store-filter-toggle-more';
        toggleBtn.dataset.filterToggle = groupId;
        body.appendChild(toggleBtn);
      }
      toggleBtn.hidden = false;
    } else if (toggleBtn) {
      toggleBtn.hidden = true;
    }

    list.dataset.filtersBound = '0';
    setupFilterList(
      list,
      body.querySelector(`[data-filter-search="${groupId}"]`),
      body.querySelector(`[data-filter-toggle="${groupId}"]`)
    );
  };

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
      return `<label class="store-filter-option" data-filter-label="${value.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${value}</span>`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    ensureFilterGroupControls(groupId, (facets || []).filter((facet) => String(facet?.value || '').trim() !== '').length);
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
      return `<label class="store-filter-option" data-filter-label="${label.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${label}</span>`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    ensureFilterGroupControls(groupId, (items || []).filter((item) => String(item?.guid || item?.Guid || '').trim() !== '').length);
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

    const groupFacets = Array.isArray(resultFilters.groups) ? resultFilters.groups : [];
    renderGuidFacetOptions('groups', 'groupGuids', groupFacets);
    renderGuidFacetOptions('stores', 'storeGuids', filterOptions.stores || []);

    catalogRoot.dataset.storeFiltersLoaded = '1';
    catalogRoot.removeAttribute('data-store-filters-deferred');
    refreshPendingFilterChips();
    bindFilterLists();
  };

  const loadDeferredFilters = () => {
    if (!needsDeferredFilters()) {
      return Promise.resolve();
    }
    if (deferredFiltersPromise) {
      return deferredFiltersPromise;
    }

    const queryString = window.location.search || '';
    deferredFiltersPromise = fetch(`/api/store-filter-options.php${queryString}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        applyDeferredFilters(data);
      })
      .catch(() => {})
      .finally(() => {
        deferredFiltersPromise = null;
      });

    return deferredFiltersPromise;
  };

  const scheduleDeferredFilters = () => {
    if (!needsDeferredFilters()) {
      return;
    }
    loadDeferredFilters().catch(() => {});
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

  const syncMobileSearchFromSidebar = () => {
    if (!mobileSearchInput || !sidebarSearchInput) {
      return;
    }
    mobileSearchInput.value = sidebarSearchInput.value;
  };

  const syncSidebarSearchFromMobile = () => {
    if (!mobileSearchInput || !sidebarSearchInput) {
      return;
    }
    sidebarSearchInput.value = mobileSearchInput.value;
  };

  const submitFilterForm = () => {
    if (!filterForm || typeof filterForm.requestSubmit !== 'function') {
      return;
    }
    syncSidebarSearchFromMobile();
    filterForm.requestSubmit();
  };

  if (sidebarSearchInput && mobileSearchInput && mobileSearchInput.dataset.filtersBound !== '1') {
    mobileSearchInput.dataset.filtersBound = '1';
    syncMobileSearchFromSidebar();
    mobileSearchInput.addEventListener('input', () => {
      syncSidebarSearchFromMobile();
      refreshPendingFilterChips();
    });
    sidebarSearchInput.addEventListener('input', () => {
      syncMobileSearchFromSidebar();
      refreshPendingFilterChips();
    });
    mobileSearchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitFilterForm();
      }
    });
  }

  openButtons.forEach((openBtn) => {
    if (openBtn.dataset.filtersBound === '1') {
      return;
    }
    openBtn.dataset.filtersBound = '1';
    openBtn.addEventListener('click', () => setDrawerOpen(true));
  });
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

  if (!catalogRoot.dataset.filtersEscapeBound) {
    catalogRoot.dataset.filtersEscapeBound = '1';
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !backdrop?.classList.contains('is-open')) {
        return;
      }
      setDrawerOpen(false);
    });
  }

  scheduleDeferredFilters();
  setupExclusiveFilterAccordions();
  refreshPendingFilterChips();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => window.portalStoreFiltersInit());
} else {
  window.portalStoreFiltersInit();
}
