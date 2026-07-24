(() => {
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
    if (event.key === 'Escape' && accountMenu && !accountMenu.hidden) {
      setAccountOpen(false);
    }
  });

  window.PublicNav = { setOpen: () => {} };
  window.SiteAccountMenu = { setOpen: setAccountOpen };
})();
