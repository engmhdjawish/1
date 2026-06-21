(() => {
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

  root.querySelectorAll('[data-filter-search]').forEach((input) => {
    const groupId = input.getAttribute('data-filter-search');
    const list = root.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list) {
      return;
    }
    input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      list.querySelectorAll('.store-filter-option').forEach((row) => {
        const label = (row.getAttribute('data-filter-label') || '').toLowerCase();
        const matches = query === '' || label.includes(query);
        row.classList.toggle('is-search-hidden', !matches);
      });
    });
  });

  root.querySelectorAll('[data-filter-toggle]').forEach((button) => {
    const groupId = button.getAttribute('data-filter-toggle');
    const list = root.querySelector(`[data-filter-list="${groupId}"]`);
    if (!list) {
      return;
    }
    const initialVisible = Number.parseInt(list.getAttribute('data-initial-visible') || '6', 10);
    let expanded = false;

    const syncToggle = () => {
      list.querySelectorAll('.store-filter-option.is-collapsed').forEach((row) => {
        if (expanded || row.querySelector('input')?.checked) {
          row.classList.remove('is-collapsed');
        } else {
          row.classList.add('is-collapsed');
        }
      });
      const hiddenCount = list.querySelectorAll('.store-filter-option.is-collapsed:not(.is-search-hidden)').length;
      button.textContent = expanded ? 'عرض أقل' : (hiddenCount > 0 ? `عرض المزيد (${hiddenCount})` : 'عرض أقل');
      button.classList.toggle('is-expanded', expanded);
    };

    button.addEventListener('click', () => {
      expanded = !expanded;
      if (!expanded) {
        list.querySelectorAll('.store-filter-option').forEach((row, index) => {
          const checked = row.querySelector('input')?.checked;
          if (!checked && index >= initialVisible) {
            row.classList.add('is-collapsed');
          }
        });
      } else {
        list.querySelectorAll('.store-filter-option.is-collapsed').forEach((row) => {
          row.classList.remove('is-collapsed');
        });
      }
      syncToggle();
    });

    syncToggle();
  });
})();
