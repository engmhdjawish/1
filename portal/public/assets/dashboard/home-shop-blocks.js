(() => {
  const form = document.querySelector('[data-shop-block-form]');
  if (!form) return;

  const typeSelect = form.querySelector('[data-shop-type]');

  const syncType = () => {
    const type = typeSelect?.value || 'facet';
    form.querySelectorAll('[data-shop-panel]').forEach((panel) => {
      const types = (panel.getAttribute('data-shop-panel') || '').split(' ');
      panel.hidden = !types.includes(type);
    });
  };

  const syncValues = (select) => {
    const role = select.getAttribute('data-shop-param');
    form.querySelectorAll(`[data-shop-values="${role}"]`).forEach((box) => {
      box.hidden = box.getAttribute('data-param') !== select.value;
    });
  };

  typeSelect?.addEventListener('change', syncType);
  form.querySelectorAll('[data-shop-param]').forEach((select) => {
    select.addEventListener('change', () => syncValues(select));
  });

  form.querySelector('[data-shop-tile-add]')?.addEventListener('click', (event) => {
    const next = form.querySelector('[data-shop-tile][hidden]');
    if (next) {
      next.hidden = false;
      next.querySelector('input')?.focus();
    }
    if (!form.querySelector('[data-shop-tile][hidden]')) {
      event.currentTarget.hidden = true;
    }
  });

  syncType();
})();
