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

  let filterOptionsPromise = null;

  const renderGuidFilterOptions = (list, paramName, items, selectedValues) => {
    if (!list) return;
    const selected = new Set((selectedValues || []).map((value) => String(value).toLowerCase()));
    const rows = (items || []).map((item) => {
      const value = String(item.guid || item.Guid || '');
      if (!value) return '';
      const label = String(item.name || item.Name || item.code || item.Code || value);
      const checked = selected.has(value.toLowerCase()) ? ' checked' : '';
      return '<label class="store-filter-option" data-filter-label="' + label.replace(/"/g, '&quot;') + '">'
        + '<input type="checkbox" name="' + paramName + '[]" value="' + value.replace(/"/g, '&quot;') + '"' + checked + '>'
        + '<span class="store-filter-option-text">' + label + '</span>'
        + '</label>';
    }).join('');
    list.innerHTML = rows || '<p class="text-xs text-text-muted px-2 py-1">لا توجد خيارات.</p>';
    list.dataset.filtersBound = '0';
    const groupId = list.getAttribute('data-filter-list');
    const input = catalogRoot.querySelector('[data-filter-search="' + groupId + '"]');
    const toggleBtn = catalogRoot.querySelector('[data-filter-toggle="' + groupId + '"]');
    if (groupId) {
      setupFilterList(list, input, toggleBtn);
    }
  };

  const loadDeferredFilterOptions = async () => {
    if (catalogRoot.dataset.filterOptionsLoaded === '1') {
      return;
    }
    if (filterOptionsPromise) {
      await filterOptionsPromise;
      return;
    }
    sidebar?.classList.add('is-loading-options');
    filterOptionsPromise = fetch('/api/store-filter-options.php', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        if (!data?.ok) {
          throw new Error(data?.message || 'تعذر تحميل خيارات الفلاتر.');
        }
        const options = data.filterOptions || {};
        renderGuidFilterOptions(
          catalogRoot.querySelector('[data-filter-list="stores"]'),
          'storeGuids',
          options.stores || [],
          Array.from(catalogRoot.querySelectorAll('input[name="storeGuids[]"]:checked')).map((el) => el.value)
        );
        renderGuidFilterOptions(
          catalogRoot.querySelector('[data-filter-list="groups"]'),
          'groupGuids',
          options.groups || [],
          Array.from(catalogRoot.querySelectorAll('input[name="groupGuids[]"]:checked')).map((el) => el.value)
        );
        catalogRoot.dataset.filterOptionsLoaded = '1';
        delete catalogRoot.dataset.storeFilterOptionsDeferred;
      })
      .catch(() => {})
      .finally(() => {
        sidebar?.classList.remove('is-loading-options');
      });
    await filterOptionsPromise;
  };

  const scheduleDeferredFilterOptions = () => {
    if (!catalogRoot.hasAttribute('data-store-filter-options-deferred')) {
      return;
    }
    const run = () => {
      loadDeferredFilterOptions().catch(() => {});
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(run, { timeout: 2500 });
    } else {
      window.setTimeout(run, 900);
    }
  };

  const setDrawerOpen = (open) => {
    document.body.classList.toggle('store-filters-drawer-open', open);
    if (backdrop) {
      backdrop.classList.toggle('is-open', open);
      backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
    if (open) {
      loadDeferredFilterOptions().catch(() => {});
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

  const setupFilterList = (list, input, toggleBtn) => {
    if (list.dataset.filtersBound === '1') {
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

  catalogRoot.querySelectorAll('[data-filter-list]').forEach((list) => {
    const groupId = list.getAttribute('data-filter-list');
    if (!groupId) {
      return;
    }
    const input = catalogRoot.querySelector(`[data-filter-search="${groupId}"]`);
    const toggleBtn = catalogRoot.querySelector(`[data-filter-toggle="${groupId}"]`);
    setupFilterList(list, input, toggleBtn);
  });

  scheduleDeferredFilterOptions();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => window.portalStoreFiltersInit());
} else {
  window.portalStoreFiltersInit();
}
