(() => {
  const drawer = document.getElementById('publicNavDrawer');
  const overlay = document.getElementById('publicNavOverlay');
  const openBtn = document.getElementById('openPublicNavBtn');
  const closeBtn = document.getElementById('closePublicNavBtn');
  if (!drawer || !overlay || !openBtn || !closeBtn) return;

  const setDrawerOpen = (open) => {
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

  window.PublicNav = { setOpen: setDrawerOpen };
  openBtn.addEventListener('click', () => setDrawerOpen(true));
  closeBtn.addEventListener('click', () => setDrawerOpen(false));
  overlay.addEventListener('click', () => setDrawerOpen(false));
  drawer.querySelectorAll('[data-public-nav-link]').forEach((link) => link.addEventListener('click', () => setDrawerOpen(false)));

  const accountRoot = document.querySelector('[data-site-account-menu]');
  const accountTrigger = accountRoot?.querySelector('.site-header__account-trigger');
  const accountMenu = accountRoot?.querySelector('.site-header__account-menu');

  const setAccountOpen = (open) => {
    if (!accountRoot || !accountTrigger || !accountMenu) return;
    accountRoot.classList.toggle('is-open', open);
    accountTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    accountMenu.hidden = !open;
    if (!open && document.activeElement instanceof HTMLElement && accountMenu.contains(document.activeElement)) {
      accountTrigger.focus();
    }
  };

  accountTrigger?.addEventListener('click', (event) => {
    event.stopPropagation();
    setAccountOpen(accountMenu.hidden);
  });

  document.addEventListener('click', (event) => {
    if (accountRoot && !accountRoot.contains(event.target)) {
      setAccountOpen(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (accountMenu && !accountMenu.hidden) {
      setAccountOpen(false);
      return;
    }
    setDrawerOpen(false);
  });
})();
