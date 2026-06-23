(() => {
  const syncHeaderStickyOffset = () => {
    const header = document.querySelector('.site-header');
    if (!header) {
      return;
    }
    const height = Math.ceil(header.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--site-header-sticky-offset', `${height}px`);
  };

  syncHeaderStickyOffset();
  window.addEventListener('resize', syncHeaderStickyOffset, { passive: true });
  window.addEventListener('load', syncHeaderStickyOffset, { passive: true });

  const header = document.querySelector('.site-header');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(syncHeaderStickyOffset).observe(header);
  }

  const root = document.getElementById('store-filters-root');
  if (!root) {
    return;
  }

  const openBtn = document.getElementById('store-filters-open');
  const closeBtn = document.getElementById('store-filters-close');
  const backdrop = document.getElementById('store-filters-backdrop');

  const setDrawerOpen = (open) => {
    document.body.classList.toggle('store-filters-drawer-open', open);
    if (backdrop) {
      backdrop.classList.toggle('is-open', open);
      backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
  };

  openBtn?.addEventListener('click', () => setDrawerOpen(true));
  closeBtn?.addEventListener('click', () => setDrawerOpen(false));
  backdrop?.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      setDrawerOpen(false);
    }
  });

  const setupFilterList = (list, input, toggleBtn) => {
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

    input?.addEventListener('input', applyVisibility);

    toggleBtn?.addEventListener('click', () => {
      expanded = !expanded;
      applyVisibility();
    });

    applyVisibility();
  };

  root.querySelectorAll('[data-filter-list]').forEach((list) => {
    const groupId = list.getAttribute('data-filter-list');
    if (!groupId) {
      return;
    }
    const input = root.querySelector(`[data-filter-search="${groupId}"]`);
    const toggleBtn = root.querySelector(`[data-filter-toggle="${groupId}"]`);
    setupFilterList(list, input, toggleBtn);
  });
})();
