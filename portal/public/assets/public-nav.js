(() => {
  const drawer = document.getElementById('publicNavDrawer');
  const overlay = document.getElementById('publicNavOverlay');
  const openBtn = document.getElementById('openPublicNavBtn');
  const closeBtn = document.getElementById('closePublicNavBtn');
  if (!drawer || !overlay || !openBtn || !closeBtn) return;

  const setOpen = (open) => {
    if (!open && document.activeElement instanceof HTMLElement && drawer.contains(document.activeElement)) {
      document.activeElement.blur();
    }
    drawer.classList.toggle('is-open', open);
    overlay.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
    openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  };

  window.PublicNav = { setOpen };
  openBtn.addEventListener('click', () => setOpen(true));
  closeBtn.addEventListener('click', () => setOpen(false));
  overlay.addEventListener('click', () => setOpen(false));
  drawer.querySelectorAll('[data-public-nav-link]').forEach((link) => link.addEventListener('click', () => setOpen(false)));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
})();
