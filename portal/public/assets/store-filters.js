window.portalStoreFiltersInit = (root = document) => {
  const syncHeaderStickyOffset = () => {
    const header = document.querySelector('.site-header');
    if (!header) {
      return;
    }
    // Include the mobile nav row; prefer the larger of layout vs painted height.
    const height = Math.max(
      Math.ceil(header.offsetHeight || 0),
      Math.ceil(header.getBoundingClientRect().height || 0)
    );
    if (height > 0) {
      document.documentElement.style.setProperty('--site-header-sticky-offset', `${height}px`);
    }
  };

  const syncMobileFilterBarOffset = () => {
    const bar = document.querySelector('[data-store-mobile-filter-bar]');
    if (!bar || window.matchMedia('(min-width: 1024px)').matches) {
      document.documentElement.style.removeProperty('--store-mobile-filter-bar-height');
      return;
    }
    const height = Math.max(
      Math.ceil(bar.offsetHeight || 0),
      Math.ceil(bar.getBoundingClientRect().height || 0)
    );
    if (height > 0) {
      document.documentElement.style.setProperty('--store-mobile-filter-bar-height', `${height}px`);
    }
  };

  const syncStickyOffsets = () => {
    syncHeaderStickyOffset();
    syncMobileFilterBarOffset();
  };

  const syncStickyOffsetsSoon = () => {
    syncStickyOffsets();
    window.requestAnimationFrame(() => {
      syncStickyOffsets();
      window.setTimeout(syncStickyOffsets, 80);
    });
  };

  syncStickyOffsetsSoon();
  if (!window.__storeFiltersHeaderSyncBound) {
    window.__storeFiltersHeaderSyncBound = true;
    window.addEventListener('resize', syncStickyOffsetsSoon, { passive: true });
    window.addEventListener('load', syncStickyOffsetsSoon, { passive: true });
    window.addEventListener('orientationchange', () => {
      window.setTimeout(syncStickyOffsetsSoon, 120);
    }, { passive: true });

    const header = document.querySelector('.site-header');
    if (header && typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(syncStickyOffsetsSoon).observe(header);
    }
  }

  const mobileFilterBar = (root.querySelector?.('[data-store-mobile-filter-bar]')
    || document.querySelector('[data-store-mobile-filter-bar]'));
  if (mobileFilterBar && typeof ResizeObserver !== 'undefined' && !mobileFilterBar.__storeFilterBarObserved) {
    mobileFilterBar.__storeFilterBarObserved = true;
    new ResizeObserver(syncMobileFilterBarOffset).observe(mobileFilterBar);
  }

  const filtersRoot = root.matches?.('[data-store-catalog-root], [data-store-filters-root]')
    ? root
    : root.querySelector('[data-store-catalog-root], [data-store-filters-root]');
  if (!filtersRoot) {
    return;
  }

  const isStaticFilters = filtersRoot.hasAttribute('data-store-filters-static');
  const defaultAvailability = filtersRoot.getAttribute('data-store-filters-default-availability') ?? '';

  const backdrop = filtersRoot.querySelector('#store-filters-backdrop');
  const openButtons = filtersRoot.querySelectorAll('[data-store-filters-open]');
  const closeBtn = filtersRoot.querySelector('#store-filters-close');
  const filterForm = filtersRoot.querySelector('[data-store-filters-form], #store-filters-form')
    || filtersRoot.closest('form[data-store-filters-form], form#store-filters-form');
  const sidebarSearchInput = filtersRoot.querySelector('#store-search-q');
  const mobileSearchInput = filtersRoot.querySelector('#store-mobile-search-q');
  const genericSearchInput = filterForm?.querySelector('input[name="search"]') ?? null;

  const setupExclusiveFilterAccordions = () => {
    const accordions = filtersRoot.querySelectorAll('.store-filter-accordion');
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
    filtersRoot.querySelectorAll('[data-filter-list]').forEach((list) => {
      const groupId = list.getAttribute('data-filter-list');
      if (!groupId) {
        return;
      }
      const input = filtersRoot.querySelector(`[data-filter-search="${groupId}"]`);
      const toggleBtn = filtersRoot.querySelector(`[data-filter-toggle="${groupId}"]`);
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
    stores: { tone: 'stores', label: 'المخازن', kind: 'checkbox', param: 'storeGuids', containerGroup: 'stores' },
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

    const splitByInput = filterForm.querySelector('input[name="splitBy"]:checked');
    if (splitByInput && splitByInput.value) {
      const text = splitByInput.closest('.store-filter-option')
        ?.querySelector('.store-filter-option-text')
        ?.textContent?.trim() || splitByInput.value;
      chips.push({
        kind: 'radio',
        param: 'splitBy',
        value: splitByInput.value,
        text,
        tone: 'group-by',
        groupLabel: 'تقسيم ZIP',
        containerGroup: 'splitBy',
      });
    }

    const searchValue = (
      sidebarSearchInput?.value
      || mobileSearchInput?.value
      || genericSearchInput?.value
      || ''
    ).trim();
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
    const group = filtersRoot.querySelector(`[data-filter-group="${groupId}"]`);
    if (!group) {
      return;
    }
    const anchor = group.querySelector('.store-filter-accordion-summary')
      || group.querySelector('.store-filter-chip-section-head');
    if (!anchor) {
      return;
    }
    let badge = group.querySelector('.store-filter-accordion-badge');
    if (count <= 0) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'store-filter-accordion-badge';
      anchor.appendChild(badge);
    }
    badge.textContent = String(count);
  };

  let lastPendingChipCount = 0;

  const updatePendingOptionStates = () => {
    if (!filterForm) {
      return;
    }
    filterForm.querySelectorAll('.store-filter-pill').forEach((pill) => {
      const input = pill.querySelector('input');
      const action = pill.querySelector('.store-filter-option-action');
      const checked = Boolean(input?.checked);
      pill.classList.toggle('is-selected', checked);
      if (input?.type === 'radio') {
        pill.classList.toggle('is-neutral', checked && (input.value === '' || input.value === 'none'));
      }
      if (action && input?.type === 'checkbox') {
        action.textContent = checked ? 'remove' : 'add';
      }
    });
  };

  const updateSubmitButtonLabel = (count) => {
    const submitBtn = filtersRoot.querySelector('#store-filters-submit');
    if (!submitBtn) {
      return;
    }
    const defaultLabel = submitBtn.dataset.labelDefault || 'عرض النتائج';
    submitBtn.textContent = count > 0 ? `${defaultLabel} (${count})` : defaultLabel;
    submitBtn.classList.toggle('has-pending-count', count > 0);
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
      if (param === 'splitBy') {
        const emptySplit = filterForm.querySelector('input[name="splitBy"][value=""]');
        if (emptySplit) {
          emptySplit.checked = true;
        }
      } else {
        const resetInput = filterForm.querySelector(`input[name="${param}"][value="${escapeChipSelector(defaultAvailability)}"]`)
          || filterForm.querySelector(`input[name="${param}"][value=""]`);
        if (resetInput) {
          resetInput.checked = true;
        }
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
        select.value = param === 'groupBy' ? 'none' : '';
      }
    } else if (kind === 'search') {
      if (sidebarSearchInput) {
        sidebarSearchInput.value = '';
      }
      if (mobileSearchInput) {
        mobileSearchInput.value = '';
      }
      if (genericSearchInput) {
        genericSearchInput.value = '';
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
    const resetAvailability = filterForm.querySelector(`input[name="isAvailable"][value="${escapeChipSelector(defaultAvailability)}"]`)
      || filterForm.querySelector('input[name="isAvailable"][value=""]');
    if (resetAvailability) {
      resetAvailability.checked = true;
    }
    filterForm.querySelectorAll('input[type="number"]').forEach((input) => {
      input.value = '';
    });
    const groupBy = filterForm.querySelector('#store-group-by');
    if (groupBy) {
      groupBy.value = 'none';
    }
    const splitByEmpty = filterForm.querySelector('input[name="splitBy"][value=""]');
    if (splitByEmpty) {
      splitByEmpty.checked = true;
    }
    if (sidebarSearchInput) {
      sidebarSearchInput.value = '';
    }
    if (mobileSearchInput) {
      mobileSearchInput.value = '';
    }
    if (genericSearchInput) {
      genericSearchInput.value = '';
    }
    refreshPendingFilterChips();
  };

  const refreshPendingFilterChips = () => {
    const chips = collectPendingFilterChips();
    const globalPanel = filtersRoot.querySelector('#store-filter-pending-panel');
    const globalContainer = filtersRoot.querySelector('#store-filter-pending-chips-global');
    const clearAllBtn = filtersRoot.querySelector('#store-filter-pending-clear-all');

    if (globalContainer) {
      globalContainer.innerHTML = chips.map((chip) => renderPendingChipHtml(chip, true)).join('');
    }
    if (globalPanel) {
      globalPanel.classList.toggle('has-selection', chips.length > 0);
      if (chips.length > lastPendingChipCount) {
        globalPanel.classList.remove('is-updated');
        void globalPanel.offsetWidth;
        globalPanel.classList.add('is-updated');
      }
    }
    lastPendingChipCount = chips.length;
    updateSubmitButtonLabel(chips.length);
    if (clearAllBtn) {
      clearAllBtn.hidden = chips.length === 0;
    }

    Object.keys(FILTER_GROUP_META).forEach((groupId) => {
      const count = chips.filter((chip) => chip.containerGroup === groupId).length;
      updateAccordionBadge(groupId, count);
    });
    updateAccordionBadge('availability', chips.filter((chip) => chip.containerGroup === 'availability').length);
    updateAccordionBadge('warehouse', chips.filter((chip) => chip.containerGroup === 'warehouse').length);
    updateAccordionBadge('price', chips.filter((chip) => chip.containerGroup === 'price').length);
    updateAccordionBadge('splitBy', chips.filter((chip) => chip.containerGroup === 'splitBy').length);

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
      if (target.matches('input[type="number"], input[type="search"], #store-search-q, input[name="search"]')) {
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

  const clearAllPendingBtn = filtersRoot.querySelector('#store-filter-pending-clear-all');
  if (clearAllPendingBtn && clearAllPendingBtn.dataset.filtersBound !== '1') {
    clearAllPendingBtn.dataset.filtersBound = '1';
    clearAllPendingBtn.addEventListener('click', clearAllPendingSelections);
  }

  const filterListIsEmpty = (groupId) => {
    const list = filtersRoot.querySelector(`[data-filter-list="${groupId}"]`);
    return Boolean(list && list.querySelectorAll('.store-filter-option').length === 0);
  };

  const needsDeferredFilters = () => {
    if (isStaticFilters) {
      return false;
    }
    if (filtersRoot.dataset.storeFiltersLoaded === '1') {
      return false;
    }
    if (filtersRoot.hasAttribute('data-store-filters-deferred')) {
      return true;
    }
    return ['materialTypes', 'ageCategories', 'manufacturers', 'sizeRanges', 'countryOfOrigins', 'stores', 'groups']
      .some((groupId) => filterListIsEmpty(groupId));
  };

  let deferredFiltersPromise = null;

  const ensureFilterGroupControls = (groupId, optionCount) => {
    const accordion = filtersRoot.querySelector(`[data-filter-group="${groupId}"]`);
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

  const renderStringFacetOptions = (groupId, paramName, facets, replace = false) => {
    const list = filtersRoot.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list) {
      return;
    }
    if (!replace && list.querySelectorAll('.store-filter-option').length > 0) {
      return;
    }

    const selected = new Set(readSelectedFilterValues(`${paramName}[]`));
    const rows = (facets || []).map((facet) => {
      const value = String(facet?.value || '').trim();
      if (!value) {
        return '';
      }
      const checked = selected.has(value) ? ' checked' : '';
      const selectedClass = selected.has(value) ? ' is-selected' : '';
      return `<label class="store-filter-option store-filter-pill${selectedClass}" data-filter-label="${value.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${value}</span>`
        + `<span class="store-filter-option-action material-symbols-outlined" aria-hidden="true">${selected.has(value) ? 'remove' : 'add'}</span>`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    list.querySelector(`[data-filter-loading="${groupId}"]`)?.remove();
    ensureFilterGroupControls(groupId, (facets || []).filter((facet) => String(facet?.value || '').trim() !== '').length);
    list.classList.add('store-filter-options--pills');
  };

  const renderGuidFacetOptions = (groupId, paramName, items, replace = false) => {
    const list = filtersRoot.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list) {
      return;
    }
    if (!replace && list.querySelectorAll('.store-filter-option').length > 0) {
      return;
    }

    const selected = new Set(
      readSelectedFilterValues(`${paramName}[]`).map((value) => value.toLowerCase())
    );
    const rows = (items || []).map((item) => {
      const value = String(item?.guid || item?.Guid || '').trim();
      if (!value) {
        return '';
      }
      const label = String(item?.name || item?.Name || item?.code || item?.Code || value);
      const checked = selected.has(value.toLowerCase()) ? ' checked' : '';
      const selectedClass = selected.has(value.toLowerCase()) ? ' is-selected' : '';
      return `<label class="store-filter-option store-filter-pill${selectedClass}" data-filter-label="${label.replace(/"/g, '&quot;')}">`
        + `<input type="checkbox" name="${paramName}[]" value="${value.replace(/"/g, '&quot;')}"${checked}>`
        + `<span class="store-filter-option-text">${label}</span>`
        + `<span class="store-filter-option-action material-symbols-outlined" aria-hidden="true">${selected.has(value.toLowerCase()) ? 'remove' : 'add'}</span>`
        + '</label>';
    }).join('');
    if (!rows) {
      return;
    }
    list.innerHTML = rows;
    list.querySelector(`[data-filter-loading="${groupId}"]`)?.remove();
    ensureFilterGroupControls(groupId, (items || []).filter((item) => String(item?.guid || item?.Guid || '').trim() !== '').length);
    list.classList.add('store-filter-options--pills');
  };

  const mergeStringFacets = (globalValues, scopedFacets, selectedValues, includeZeroCountOptions) => {
    const countByValue = new Map();
    (scopedFacets || []).forEach((facet) => {
      const value = String(facet?.value || '').trim();
      if (!value) {
        return;
      }
      countByValue.set(value.toLowerCase(), facet?.count ?? null);
    });

    const merged = [];
    const seen = new Set();
    const append = (value, count) => {
      const normalized = String(value || '').trim();
      if (!normalized) {
        return;
      }
      const key = normalized.toLowerCase();
      if (seen.has(key)) {
        return;
      }
      seen.add(key);
      merged.push({ value: normalized, count });
    };

    if (includeZeroCountOptions) {
      (globalValues || []).forEach((value) => {
        const normalized = String(value || '').trim();
        if (!normalized) {
          return;
        }
        const key = normalized.toLowerCase();
        append(normalized, countByValue.has(key) ? countByValue.get(key) : 0);
      });
      (scopedFacets || []).forEach((facet) => {
        append(facet?.value, facet?.count ?? null);
      });
    } else {
      if ((globalValues || []).length > 0) {
        (globalValues || []).forEach((value) => {
          const normalized = String(value || '').trim();
          if (!normalized) {
            return;
          }
          const key = normalized.toLowerCase();
          if (!countByValue.has(key)) {
            return;
          }
          const count = countByValue.get(key);
          if (count !== null && Number(count) <= 0) {
            return;
          }
          append(normalized, count);
        });
      } else {
        (scopedFacets || []).forEach((facet) => {
          const count = facet?.count ?? null;
          if (count !== null && Number(count) <= 0) {
            return;
          }
          append(facet?.value, count);
        });
      }
    }

    (selectedValues || []).forEach((value) => {
      const normalized = String(value || '').trim();
      if (!normalized) {
        return;
      }
      const key = normalized.toLowerCase();
      append(normalized, countByValue.has(key) ? countByValue.get(key) : 0);
    });

    return merged;
  };

  const mergeGroupFacets = (globalGroups, scopedFacets, selectedGuids, includeZeroCountOptions) => {
    const countByGuid = new Map();
    const scopedByGuid = new Map();
    (scopedFacets || []).forEach((facet) => {
      const guid = String(facet?.guid || facet?.Guid || '').trim();
      if (!guid) {
        return;
      }
      const key = guid.toLowerCase();
      countByGuid.set(key, facet?.count ?? null);
      scopedByGuid.set(key, facet);
    });

    const globalByGuid = new Map();
    (globalGroups || []).forEach((group) => {
      const guid = String(group?.guid || group?.Guid || '').trim();
      if (!guid) {
        return;
      }
      globalByGuid.set(guid.toLowerCase(), group);
    });

    const merged = [];
    const seen = new Set();
    const appendGroup = (guid, row) => {
      const normalized = String(guid || '').trim();
      if (!normalized) {
        return;
      }
      const key = normalized.toLowerCase();
      if (seen.has(key)) {
        return;
      }
      seen.add(key);
      merged.push(row);
    };

    if (includeZeroCountOptions) {
      (globalGroups || []).forEach((group) => {
        const guid = String(group?.guid || group?.Guid || '').trim();
        if (!guid) {
          return;
        }
        const key = guid.toLowerCase();
        appendGroup(guid, {
          guid,
          code: String(group?.code || group?.Code || ''),
          name: String(group?.name || group?.Name || ''),
          count: countByGuid.has(key) ? countByGuid.get(key) : 0,
        });
      });
      (scopedFacets || []).forEach((facet) => {
        const guid = String(facet?.guid || facet?.Guid || '').trim();
        if (!guid) {
          return;
        }
        appendGroup(guid, {
          guid,
          code: String(facet?.code || facet?.Code || ''),
          name: String(facet?.name || facet?.Name || ''),
          count: facet?.count ?? null,
        });
      });
    } else if ((globalGroups || []).length > 0) {
      (globalGroups || []).forEach((group) => {
        const guid = String(group?.guid || group?.Guid || '').trim();
        if (!guid) {
          return;
        }
        const key = guid.toLowerCase();
        if (!countByGuid.has(key)) {
          return;
        }
        const count = countByGuid.get(key);
        if (count !== null && Number(count) <= 0) {
          return;
        }
        appendGroup(guid, {
          guid,
          code: String(group?.code || group?.Code || ''),
          name: String(group?.name || group?.Name || ''),
          count,
        });
      });
    } else {
      (scopedFacets || []).forEach((facet) => {
        const count = facet?.count ?? null;
        if (count !== null && Number(count) <= 0) {
          return;
        }
        const guid = String(facet?.guid || facet?.Guid || '').trim();
        if (!guid) {
          return;
        }
        appendGroup(guid, {
          guid,
          code: String(facet?.code || facet?.Code || ''),
          name: String(facet?.name || facet?.Name || ''),
          count,
        });
      });
    }

    (selectedGuids || []).forEach((guid) => {
      const normalized = String(guid || '').trim();
      if (!normalized) {
        return;
      }
      const key = normalized.toLowerCase();
      const source = scopedByGuid.get(key) || globalByGuid.get(key) || {};
      appendGroup(normalized, {
        guid: normalized,
        code: String(source?.code || source?.Code || ''),
        name: String(source?.name || source?.Name || ''),
        count: countByGuid.has(key) ? countByGuid.get(key) : 0,
      });
    });

    return merged;
  };

  const readAppliedFiltersState = () => {
    const raw = filtersRoot.getAttribute('data-applied-filters');
    if (!raw) {
      return {};
    }
    try {
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  };

  const readUrlFilterValues = (paramName) => {
    const params = new URLSearchParams(window.location.search || '');
    const values = [];
    params.forEach((value, key) => {
      if (key === `${paramName}[]` || key === paramName) {
        const normalized = String(value || '').trim();
        if (normalized !== '') {
          values.push(normalized);
        }
      }
    });

    return values;
  };

  const readSelectedFilterValues = (inputName) => {
    const paramName = String(inputName || '').replace(/\[\]$/, '');
    const checked = Array.from(
      filtersRoot.querySelectorAll(`input[name="${inputName}"]:checked`)
    ).map((el) => el.value).filter((value) => String(value || '').trim() !== '');
    if (checked.length > 0) {
      return checked;
    }

    const applied = readAppliedFiltersState();
    const fromApplied = applied[paramName];
    if (Array.isArray(fromApplied) && fromApplied.length > 0) {
      return fromApplied.map((value) => String(value)).filter((value) => value.trim() !== '');
    }

    return readUrlFilterValues(paramName);
  };

  const shouldIncludeZeroCountFilterOptions = () => {
    const availability = filterForm?.querySelector('input[name="isAvailable"]:checked')?.value;
    return availability === '0';
  };

  const applyDeferredFilters = (data) => {
    if (!data?.ok) {
      throw new Error(data?.message || 'تعذر تحميل خيارات الفلاتر.');
    }

    const resultFilters = data.resultFilters || {};
    const filterOptions = data.filterOptions || {};
    const includeZeroCountOptions = shouldIncludeZeroCountFilterOptions();
    const facetMap = [
      ['materialTypes', 'materialTypes'],
      ['ageCategories', 'ageCategories'],
      ['manufacturers', 'manufacturers'],
      ['sizeRanges', 'sizeRanges'],
      ['countryOfOrigins', 'countryOfOrigins'],
    ];

    facetMap.forEach(([groupId, paramName]) => {
      const scoped = resultFilters[groupId] || [];
      const global = Array.isArray(filterOptions[groupId]) ? filterOptions[groupId] : [];
      const selected = readSelectedFilterValues(`${paramName}[]`);
      renderStringFacetOptions(
        groupId,
        paramName,
        mergeStringFacets(global, scoped, selected, includeZeroCountOptions),
        true
      );
    });

    const groupFacets = mergeGroupFacets(
      filterOptions.groups || [],
      resultFilters.groups || [],
      readSelectedFilterValues('groupGuids[]'),
      includeZeroCountOptions
    );
    renderGuidFacetOptions('groups', 'groupGuids', groupFacets, true);
    renderGuidFacetOptions('stores', 'storeGuids', filterOptions.stores || [], true);

    filtersRoot.dataset.storeFiltersLoaded = '1';
    filtersRoot.removeAttribute('data-store-filters-deferred');
    refreshPendingFilterChips();
    bindFilterLists();
    syncAccordionSelectionBadges();
  };

  const syncAccordionSelectionBadges = () => {
    Object.entries(FILTER_GROUP_META).forEach(([groupId, meta]) => {
      const accordion = filtersRoot.querySelector(`[data-filter-group="${groupId}"]`);
      if (!accordion) {
        return;
      }
      const summary = accordion.querySelector('.store-filter-accordion-summary')
        || accordion.querySelector('.store-filter-chip-section-head');
      if (!summary) {
        return;
      }
      const selectedCount = readSelectedFilterValues(`${meta.param}[]`).length;
      let badge = summary.querySelector('.store-filter-accordion-badge');
      if (selectedCount <= 0) {
        badge?.remove();
        return;
      }
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'store-filter-accordion-badge';
        summary.appendChild(badge);
      }
      badge.textContent = String(selectedCount);
    });
  };

  const buildFilterOptionsQuery = () => {
    const current = new URLSearchParams(window.location.search || '');
    const minimal = new URLSearchParams();
    ['section', 'offer', 'isAvailable', 'token'].forEach((key) => {
      const value = current.get(key);
      if (value !== null && value !== '') {
        minimal.set(key, value);
      }
    });
    const query = minimal.toString();
    return query ? `?${query}` : '';
  };

  const readEmbeddedFiltersPayload = () => {
    const node = document.getElementById('store-filters-bootstrap');
    if (!node) {
      return null;
    }
    try {
      const data = JSON.parse(node.textContent || '');
      return data?.ok ? data : null;
    } catch {
      return null;
    }
  };

  const loadDeferredFilters = () => {
    if (!needsDeferredFilters()) {
      return Promise.resolve();
    }
    if (deferredFiltersPromise) {
      return deferredFiltersPromise;
    }

    const embedded = readEmbeddedFiltersPayload();
    if (embedded) {
      applyDeferredFilters(embedded);
      return Promise.resolve();
    }

    deferredFiltersPromise = fetch(`/api/store-filter-options.php${buildFilterOptionsQuery()}`, {
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

  if (!filtersRoot.dataset.filtersEscapeBound) {
    filtersRoot.dataset.filtersEscapeBound = '1';
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

  // Move the fixed bar to <body> so it is not trapped by transformed/filtered
  // ancestors inside the catalog grid (otherwise it scrolls away with content).
  const mobileBar = filtersRoot.querySelector('[data-store-mobile-filter-bar]');
  if (mobileBar) {
    document.querySelectorAll('body > [data-store-mobile-filter-bar]').forEach((el) => {
      if (el !== mobileBar) {
        el.remove();
      }
    });
    if (mobileBar.parentElement !== document.body) {
      document.body.appendChild(mobileBar);
    }
    mobileBar.setAttribute('data-portaled', '1');
    syncStickyOffsetsSoon();
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-store-filters-root], [data-store-catalog-root]').forEach((root) => {
      window.portalStoreFiltersInit(root);
    });
  });
} else {
  document.querySelectorAll('[data-store-filters-root], [data-store-catalog-root]').forEach((root) => {
    window.portalStoreFiltersInit(root);
  });
}
