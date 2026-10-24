(() => {
  const list = document.querySelector('[data-home-layout-list]');
  if (!list) return;

  const syncRow = (row) => {
    const on = row.querySelector('[data-home-layout-on]');
    row.classList.toggle('opacity-60', on instanceof HTMLInputElement && !on.checked);
  };
  const rows = () => Array.from(list.querySelectorAll('[data-home-layout-row]'));
  rows().forEach(syncRow);

  list.addEventListener('change', (event) => {
    const row = event.target instanceof Element ? event.target.closest('[data-home-layout-row]') : null;
    if (row) syncRow(row);
  });

  list.addEventListener('input', (event) => {
    const input = event.target instanceof Element ? event.target.closest('[data-home-layout-title]') : null;
    const preview = input?.closest('[data-home-layout-row]')?.querySelector('[data-home-layout-title-preview]');
    if (input instanceof HTMLInputElement && preview) preview.textContent = input.value || input.placeholder;
  });

  list.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-home-layout-move]') : null;
    if (!button) return;
    const row = button.closest('[data-home-layout-row]');
    if (!row) return;
    const siblings = rows();
    const index = siblings.indexOf(row);
    if (button.getAttribute('data-home-layout-move') === '-1') {
      const prev = siblings[index - 1];
      if (prev) list.insertBefore(row, prev);
    } else {
      const next = siblings[index + 1];
      if (next) list.insertBefore(next, row);
    }
    button.focus();
    row.classList.add('ring-2', 'ring-primary');
    window.setTimeout(() => row.classList.remove('ring-2', 'ring-primary'), 500);
  });

  let dragged = null;
  list.addEventListener('pointerdown', (event) => {
    const handle = event.target instanceof Element ? event.target.closest('[data-home-layout-handle]') : null;
    handle?.closest('[data-home-layout-row]')?.setAttribute('draggable', 'true');
  });
  list.addEventListener('dragstart', (event) => {
    const row = event.target instanceof Element ? event.target.closest('[data-home-layout-row]') : null;
    if (!row || row.getAttribute('draggable') !== 'true') return;
    dragged = row;
    row.classList.add('opacity-50');
    event.dataTransfer?.setData('text/plain', '');
  });
  list.addEventListener('dragover', (event) => {
    if (!dragged) return;
    event.preventDefault();
    const over = event.target instanceof Element ? event.target.closest('[data-home-layout-row]') : null;
    if (!over || over === dragged) return;
    const box = over.getBoundingClientRect();
    list.insertBefore(dragged, event.clientY > box.top + box.height / 2 ? over.nextElementSibling : over);
  });
  list.addEventListener('dragend', () => {
    dragged?.classList.remove('opacity-50');
    dragged?.removeAttribute('draggable');
    dragged = null;
  });
})();
