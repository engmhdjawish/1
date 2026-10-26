(() => {
  const scope = document.querySelector('[data-product-share-scope]');
  if (!scope) return;

  const CARD = '.store-product-card, .home-product-card';
  const company = scope.getAttribute('data-product-share-company') || document.title;

  const guidOf = (card) => {
    const direct = card.getAttribute('data-preview-guid');
    if (direct) return direct;
    const link = card.querySelector('a[href*="product.php"]');
    if (!link) return '';
    try {
      return new URL(link.getAttribute('href') || '', window.location.origin).searchParams.get('guid') || '';
    } catch {
      return '';
    }
  };
  const nameOf = (card) => (card.querySelector('.store-product-card__title, .home-product-card__name')?.textContent || '').trim();
  const shortCache = new Map();
  const urlOf = async (guid) => {
    if (shortCache.has(guid)) return shortCache.get(guid);
    const target = `/product.php?guid=${encodeURIComponent(guid)}`;
    let url = '';
    if (window.PortalFilterView?.shortenPath) {
      url = await window.PortalFilterView.shortenPath(target);
    } else {
      const response = await fetch('/api/store-filter-view.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ kind: 'path', target }),
      });
      const data = await response.json();
      if (!response.ok || !data?.ok) throw new Error('short link failed');
      url = data.share_url || `${window.location.origin}${data.short_url}`;
    }
    shortCache.set(guid, url);
    return url;
  };

  let menu = null;
  let menuFor = null;
  const closeMenu = () => {
    if (!menu) return;
    menu.remove();
    menu = null;
    menuFor?.setAttribute('aria-expanded', 'false');
    menuFor = null;
  };

  const flash = (button, text) => {
    const tip = document.createElement('span');
    tip.className = 'product-share__toast';
    tip.setAttribute('role', 'status');
    tip.textContent = text;
    button.closest(CARD)?.appendChild(tip);
    window.setTimeout(() => tip.remove(), 1800);
  };

  const copy = async (button, url) => {
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      const field = document.createElement('textarea');
      field.value = url;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      document.execCommand('copy');
      field.remove();
    }
    flash(button, 'تم نسخ الرابط');
  };

  const openMenu = (button, name, url) => {
    closeMenu();
    const text = `${name} - ${company}`;
    menu = document.createElement('div');
    menu.className = 'product-share__menu';
    menu.setAttribute('role', 'menu');
    const items = [
      { label: 'واتساب', icon: 'chat', href: `https://wa.me/?text=${encodeURIComponent(`${text}\n${url}`)}`, tone: 'green' },
      { label: 'تيليغرام', icon: 'send', href: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text)}`, tone: 'blue' },
      { label: 'فيسبوك', icon: 'thumb_up', href: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`, tone: 'navy' },
      { label: 'نسخ الرابط', icon: 'link', copy: true, tone: 'ink' },
    ];
    items.forEach((item) => {
      const node = document.createElement(item.copy ? 'button' : 'a');
      node.className = `product-share__item product-share__item--${item.tone}`;
      node.setAttribute('role', 'menuitem');
      if (item.copy) {
        node.type = 'button';
        node.addEventListener('click', () => {
          copy(button, url);
          closeMenu();
        });
      } else {
        node.href = item.href;
        node.target = '_blank';
        node.rel = 'noopener';
        node.addEventListener('click', closeMenu);
      }
      const icon = document.createElement('span');
      icon.className = 'material-symbols-outlined';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = item.icon;
      node.append(icon, item.label);
      menu.appendChild(node);
    });
    button.closest(CARD)?.appendChild(menu);
    button.setAttribute('aria-expanded', 'true');
    menuFor = button;
    menu.querySelector('.product-share__item')?.focus();
  };

  const decorate = (root) => {
    root.querySelectorAll(CARD).forEach((card) => {
      if (card.querySelector(':scope > .product-share')) return;
      const guid = guidOf(card);
      if (!guid) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'product-share';
      button.setAttribute('aria-label', `مشاركة ${nameOf(card)}`.trim());
      button.setAttribute('aria-haspopup', 'menu');
      button.setAttribute('aria-expanded', 'false');
      button.setAttribute('data-product-share', guid);
      button.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">share</span>';
      card.classList.add('has-share');
      card.appendChild(button);
    });
  };

  document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-product-share]');
    if (!button) {
      if (menu && !(target && menu.contains(target))) closeMenu();
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    if (menuFor === button) {
      closeMenu();
      return;
    }
    const card = button.closest(CARD);
    const name = card ? nameOf(card) : '';
    let url;
    try {
      url = await urlOf(button.getAttribute('data-product-share') || '');
    } catch {
      flash(button, 'تعذر إنشاء الرابط المختصر، حاول مجددًا');
      return;
    }
    if (navigator.share && window.matchMedia('(pointer: coarse)').matches) {
      try {
        await navigator.share({ title: `${name} - ${company}`, url });
        return;
      } catch (error) {
        if (error && error.name === 'AbortError') return;
      }
    }
    openMenu(button, name, url);
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menu) {
      const owner = menuFor;
      closeMenu();
      owner?.focus();
    }
  });

  decorate(scope);
  if ('MutationObserver' in window) {
    let pending = 0;
    new MutationObserver(() => {
      window.cancelAnimationFrame(pending);
      pending = window.requestAnimationFrame(() => decorate(scope));
    }).observe(scope, { childList: true, subtree: true });
  }
})();
