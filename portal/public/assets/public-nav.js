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
