(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const syncStickyTop = () => {
    const header = document.querySelector('.site-header');
    const height = header instanceof HTMLElement ? header.offsetHeight : 72;
    document.documentElement.style.setProperty('--hl-sticky-top', `${height + 8}px`);
  };

  const cardVisible = (card, box) => {
    const rect = card.getBoundingClientRect();
    const visibleWidth = Math.min(rect.right, box.right) - Math.max(rect.left, box.left);
    return visibleWidth > Math.min(rect.width, box.width) * 0.55;
  };

  const updateRail = (rail) => {
    const strip = rail.querySelector('.home-strip');
    const prev = rail.querySelector('[data-hl-prev]');
    const next = rail.querySelector('[data-hl-next]');
    if (!(strip instanceof HTMLElement) || !(prev instanceof HTMLButtonElement) || !(next instanceof HTMLButtonElement)) {
      rail.classList.add('is-static');
      return;
    }

    const cards = Array.from(strip.querySelectorAll('.home-product-card'));
    const overflow = strip.scrollWidth - strip.clientWidth > 12;
    rail.classList.toggle('is-static', !overflow || cards.length === 0);
    if (!overflow || cards.length === 0) {
      prev.disabled = true;
      next.disabled = true;
      return;
    }

    const box = strip.getBoundingClientRect();
    let first = -1;
    let last = -1;
    cards.forEach((card, index) => {
      if (!cardVisible(card, box)) return;
      if (first === -1) first = index;
      last = index;
    });
    prev.disabled = first <= 0;
    next.disabled = last === -1 || last >= cards.length - 1;
  };

  const pageRail = (rail, direction) => {
    const strip = rail.querySelector('.home-strip');
    if (!(strip instanceof HTMLElement)) return;
    const cards = Array.from(strip.querySelectorAll('.home-product-card'));
    if (cards.length === 0) return;

    const box = strip.getBoundingClientRect();
    const visible = cards.filter((card) => cardVisible(card, box));
    const step = Math.max(1, visible.length || 1);
    const first = Math.max(0, cards.findIndex((card) => cardVisible(card, box)));
    const targetIndex = direction === 'next'
      ? Math.min(cards.length - 1, first + step)
      : Math.max(0, first - step);
    cards[targetIndex]?.scrollIntoView({
      behavior: reducedMotion ? 'auto' : 'smooth',
      inline: 'start',
      block: 'nearest',
    });
  };

  const initRails = () => {
    const rails = Array.from(document.querySelectorAll('[data-hl-rail]'));
    rails.forEach((rail) => {
      const strip = rail.querySelector('.home-strip');
      rail.querySelector('[data-hl-prev]')?.addEventListener('click', () => pageRail(rail, 'prev'));
      rail.querySelector('[data-hl-next]')?.addEventListener('click', () => pageRail(rail, 'next'));
      strip?.addEventListener('scroll', () => updateRail(rail), { passive: true });
      updateRail(rail);
    });

    const refresh = () => rails.forEach(updateRail);
    window.addEventListener('resize', refresh);
    window.setTimeout(refresh, 400);
  };

  const initJumpState = () => {
    const links = Array.from(document.querySelectorAll('.hl-jump .home-section-nav__link[href^="#"]'));
    if (links.length === 0) return;

    const setCurrent = (id) => {
      links.forEach((link) => {
        const active = link.getAttribute('href') === `#${id}`;
        link.classList.toggle('is-current', active);
        if (active) link.setAttribute('aria-current', 'true');
        else link.removeAttribute('aria-current');
      });
    };

    const sections = links
      .map((link) => document.querySelector(link.getAttribute('href') || ''))
      .filter((section) => section instanceof HTMLElement);

    if (sections.length === 0 || !('IntersectionObserver' in window)) return;

    const observer = new IntersectionObserver((entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible?.target?.id) setCurrent(visible.target.id);
    }, { rootMargin: '-30% 0px -55% 0px', threshold: [0.15, 0.4] });

    sections.forEach((section) => observer.observe(section));
  };

  const init = () => {
    syncStickyTop();
    initRails();
    initJumpState();
    window.addEventListener('resize', syncStickyTop);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
