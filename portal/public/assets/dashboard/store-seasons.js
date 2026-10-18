(function () {
  'use strict';

  const settings = document.querySelector('[data-season-settings]');
  if (settings) {
    const manual = settings.querySelector('[data-season-manual]');
    settings.addEventListener('change', (event) => {
      const target = event.target;
      if (target instanceof HTMLInputElement && target.hasAttribute('data-season-mode') && manual) {
        manual.hidden = target.value !== 'manual';
      }
    });
  }

  const form = document.querySelector('[data-season-form]');
  if (!form) return;

  const rows = Array.from(form.querySelectorAll('[data-season-group]'));
  const counter = form.querySelector('[data-season-count]');
  const search = form.querySelector('[data-season-group-search]');

  const updateCount = () => {
    if (counter) counter.textContent = String(form.querySelectorAll('input[name="group_guids[]"]:checked').length);
  };

  form.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.name === 'group_guids[]') updateCount();
  });

  search?.addEventListener('input', () => {
    const needle = search.value.trim().toLowerCase();
    rows.forEach((row) => {
      const name = (row.getAttribute('data-name') || '').toLowerCase();
      row.style.display = needle !== '' && !name.includes(needle) ? 'none' : '';
    });
  });

  form.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-season-pick]') : null;
    if (!button) return;
    const keyword = (button.getAttribute('data-season-pick') || '').toLowerCase();
    rows.forEach((row) => {
      const box = row.querySelector('input[type="checkbox"]');
      if (!(box instanceof HTMLInputElement)) return;
      if (keyword === '') {
        box.checked = false;
      } else if ((row.getAttribute('data-name') || '').toLowerCase().includes(keyword)) {
        box.checked = true;
      }
    });
    updateCount();
  });
})();
