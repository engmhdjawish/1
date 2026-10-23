(() => {
  window.portalMaterialImageFrameEmpty = function portalMaterialImageFrameEmpty(img) {
    if (!(img instanceof HTMLImageElement)) return;
    img.onerror = null;
    img.removeAttribute('src');
    img.classList.add('is-broken');
    const frame = img.closest('.material-image-frame');
    const photo = img.parentElement;
    if (frame) {
      frame.classList.add('material-image-frame--empty', 'material-image-frame--broken');
    }
    if (photo instanceof HTMLElement && !photo.querySelector('.material-image-frame__empty')) {
      const empty = document.createElement('div');
      empty.className = 'material-image-frame__empty';
      empty.setAttribute('role', 'img');
      empty.setAttribute('aria-label', 'بلا صورة');
      empty.innerHTML = '<span class="material-symbols-outlined material-image-frame__empty-icon" aria-hidden="true">hide_image</span><span class="material-image-frame__empty-label">بلا صورة</span>';
      photo.appendChild(empty);
    }
  };

  const accountRoot = document.querySelector('[data-site-account-menu]');
  const accountTrigger = accountRoot?.querySelector('[data-site-account-toggle]');
  const accountMenu = accountRoot?.querySelector('.site-account__menu');
  const accountBackdrop = accountRoot?.querySelector('.site-account__backdrop');
  const sheetMode = () => window.matchMedia('(max-width: 639px)').matches;
  let accountReturnFocus = null;

  const setAccountOpen = (open, opener = null) => {
    if (!accountRoot || !accountTrigger || !accountMenu) return false;
    accountRoot.classList.toggle('is-open', open);
    accountTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.querySelectorAll('[data-site-account-open]').forEach((btn) => btn.setAttribute('aria-expanded', open ? 'true' : 'false'));
    accountMenu.hidden = !open;
    if (accountBackdrop) accountBackdrop.hidden = !open;
    document.documentElement.classList.toggle('site-account-sheet-open', open && sheetMode());
    if (open) {
      accountReturnFocus = opener || accountTrigger;
      accountMenu.querySelector('a, button')?.focus({ preventScroll: true });
    } else if (document.activeElement instanceof HTMLElement && accountMenu.contains(document.activeElement)) {
      (accountReturnFocus || accountTrigger).focus({ preventScroll: true });
    }
    return true;
  };

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target || !accountRoot) return;
    const opener = target.closest('[data-site-account-toggle], [data-site-account-open]');
    if (opener) {
      if (setAccountOpen(accountMenu.hidden, opener)) event.preventDefault();
      return;
    }
    if (target.closest('[data-site-account-close]')) {
      setAccountOpen(false);
      return;
    }
    if (!accountMenu.hidden && !accountMenu.contains(target)) setAccountOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && accountMenu && !accountMenu.hidden) {
      setAccountOpen(false);
    }
  });

  const siteHeader = document.querySelector('.site-header');
  if (siteHeader instanceof HTMLElement) {
    const root = document.documentElement;
    const syncHeaderHeight = () => {
      const height = siteHeader.offsetHeight;
      root.style.setProperty('--site-header-h', `${height}px`);
      root.style.setProperty('--hl-nav-top', `${height + 8}px`);
      root.style.setProperty('--hn-top', `${height}px`);
    };
    syncHeaderHeight();
    window.addEventListener('resize', syncHeaderHeight);
    if ('ResizeObserver' in window) new ResizeObserver(syncHeaderHeight).observe(siteHeader);

    // Phones: fold the account/currency row away while scrolling down; hysteresis avoids flicker.
    const compactQuery = window.matchMedia('(max-width: 639px)');
    let compact = false;
    let ticking = false;
    const syncCompact = () => {
      ticking = false;
      const y = window.scrollY;
      const touring = root.classList.contains('site-tour-open');
      const next = compactQuery.matches && !touring && (compact ? y > 40 : y > 160);
      if (next === compact) return;
      if (next && siteHeader.querySelector('.site-account.is-open')) return;
      compact = next;
      siteHeader.classList.toggle('site-header--compact', compact);
    };
    window.addEventListener('scroll', () => {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(syncCompact);
      }
    }, { passive: true });
    compactQuery.addEventListener?.('change', syncCompact);
    window.addEventListener('site-tour-toggle', syncCompact);
    syncCompact();
  }

  window.PublicNav = { setOpen: () => {} };
  window.SiteAccountMenu = { setOpen: setAccountOpen };
})();
