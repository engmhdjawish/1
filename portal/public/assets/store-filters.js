(() => {
  const DESKTOP_MIN = 1024;
  const PIN_GAP_PX = 8;

  const syncHeaderStickyOffset = () => {
    const header = document.querySelector('.site-header');
    if (!header) {
      return 0;
    }
    const height = Math.ceil(header.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--site-header-sticky-offset', `${height}px`);
    return height;
  };

  const clearDesktopPin = (sidebar) => {
    sidebar.classList.remove('is-desktop-pinned');
    sidebar.style.removeProperty('top');
    sidebar.style.removeProperty('right');
    sidebar.style.removeProperty('left');
    sidebar.style.removeProperty('width');
    sidebar.style.removeProperty('max-height');
  };

  const pinDesktopFilters = () => {
    const headerHeight = syncHeaderStickyOffset();
    const backdrop = document.getElementById('store-filters-backdrop');
    const sidebar = backdrop?.querySelector('.store-filters-sidebar');
    if (!sidebar) {
      return;
    }

    if (window.innerWidth < DESKTOP_MIN) {
      clearDesktopPin(sidebar);
      return;
    }

    if (!backdrop) {
      clearDesktopPin(sidebar);
      return;
    }

    const anchor = backdrop.getBoundingClientRect();
    const top = headerHeight + PIN_GAP_PX;
    sidebar.classList.add('is-desktop-pinned');
    sidebar.style.top = `${top}px`;
    sidebar.style.width = `${Math.max(240, Math.round(anchor.width))}px`;
    sidebar.style.right = `${Math.max(0, Math.round(window.innerWidth - anchor.right))}px`;
    sidebar.style.left = 'auto';
    sidebar.style.maxHeight = `calc(100vh - ${top + PIN_GAP_PX}px)`;
  };

  const schedulePin = () => {
    requestAnimationFrame(() => requestAnimationFrame(pinDesktopFilters));
  };

  const root = document.getElementById('store-filters-root');
  const backdrop = document.getElementById('store-filters-backdrop');
  const openBtn = document.getElementById('store-filters-open');
  const closeBtn = document.getElementById('store-filters-close');

  schedulePin();
  window.addEventListener('resize', schedulePin, { passive: true });
  window.addEventListener('load', schedulePin, { passive: true });

  const header = document.querySelector('.site-header');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(schedulePin).observe(header);
  }

  if (backdrop && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(schedulePin).observe(backdrop);
  }

  if (!root) {
    return;
  }

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
