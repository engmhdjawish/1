(() => {
  const sectionLinks = () => Array.from(document.querySelectorAll('[data-home-section-link]'));

  const setActiveSection = (sectionId) => {
    if (!sectionId) return;
    sectionLinks().forEach((link) => {
      const isActive = link.getAttribute('data-home-section-link') === sectionId;
      link.classList.toggle('is-active', isActive);
      if (link.classList.contains('home-section-nav__link')) {
        link.setAttribute('aria-current', isActive ? 'true' : 'false');
      }
    });
  };

  const initSectionNav = () => {
    const nav = document.querySelector('[data-home-section-nav]');
    if (!nav) return;

    const sections = Array.from(document.querySelectorAll('.home-section[id]'));
    const measureOffset = () => {
      const header = document.querySelector('.site-header');
      const headerHeight = header?.offsetHeight ?? 0;
      const navHeight = nav.offsetHeight ?? 0;
      const gap = 14;
      const offset = headerHeight + navHeight + gap;
      document.documentElement.style.setProperty('--home-scroll-offset', `${offset}px`);
      return offset;
    };

    let offset = measureOffset();
    window.addEventListener('resize', () => {
      offset = measureOffset();
    });

    const scrollToSection = (target) => {
      if (!target) return;
      offset = measureOffset();
      const top = target.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    };

    sectionLinks().forEach((link) => {
      link.addEventListener('click', (event) => {
        const hash = link.getAttribute('href') || '';
        if (!hash.startsWith('#')) return;
        const target = document.querySelector(hash);
        if (!target) return;
        event.preventDefault();
        scrollToSection(target);
        history.replaceState(null, '', hash);
        setActiveSection(link.getAttribute('data-home-section-link') || '');
      });
    });

    if (sections.length > 0 && 'IntersectionObserver' in window) {
      let activeId = '';
      const observer = new IntersectionObserver(
        (entries) => {
          const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
          const next = visible[0]?.target?.id || activeId;
          if (next && next !== activeId) {
            activeId = next;
            setActiveSection(next);
          }
        },
        {
          root: null,
          rootMargin: '-42% 0px -48% 0px',
          threshold: [0, 0.15, 0.35, 0.55],
        },
      );
      sections.forEach((section) => observer.observe(section));
    }

    if (window.location.hash) {
      const target = document.querySelector(window.location.hash);
      if (target) {
        window.requestAnimationFrame(() => {
          scrollToSection(target);
          setActiveSection(window.location.hash.slice(1));
        });
      }
    } else if (sections[0]?.id) {
      setActiveSection(sections[0].id);
    }
  };

  const initAdCarousel = () => {
    const root = document.querySelector('[data-home-ad-carousel]');
    if (!root) return;
    const slides = Array.from(root.querySelectorAll('.home-ad-slide'));
    if (slides.length <= 1) return;
    const dots = Array.from(root.querySelectorAll('[data-ad-dot]'));
    const prevBtn = root.querySelector('[data-ad-prev]');
    const nextBtn = root.querySelector('[data-ad-next]');
    let index = 0;
    let timer = null;
    const intervalMs = 5500;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const show = (next) => {
      index = (next + slides.length) % slides.length;
      slides.forEach((slide, i) => {
        const active = i === index;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
      });
      dots.forEach((dot, i) => {
        const active = i === index;
        dot.classList.toggle('is-active', active);
        dot.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    };

    const stop = () => {
      if (timer !== null) {
        clearInterval(timer);
        timer = null;
      }
    };

    const start = () => {
      if (reducedMotion) return;
      stop();
      timer = setInterval(() => show(index + 1), intervalMs);
    };

    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        show(Number.parseInt(dot.getAttribute('data-ad-dot') || '0', 10));
        start();
      });
    });
    prevBtn?.addEventListener('click', () => { show(index - 1); start(); });
    nextBtn?.addEventListener('click', () => { show(index + 1); start(); });

    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', (event) => {
      if (!root.contains(event.relatedTarget)) start();
    });

    show(0);
    start();
  };

  const applyProductStrips = (root, strips, pendingOnly = false) => {
    Object.entries(strips).forEach(([key, html]) => {
      const slot = root.querySelector(`[data-home-products="${CSS.escape(key)}"]`);
      if (!slot) return;
      if (pendingOnly && !slot.hasAttribute('data-home-products-pending')) return;
      slot.innerHTML = typeof html === 'string' ? html : '';
      slot.removeAttribute('data-home-products-pending');
    });
  };

  const initDeferredProducts = () => {
    const root = document.querySelector('[data-home-deferred-products="1"]');
    if (!root) return;

    const pendingOnly = root.hasAttribute('data-home-has-embedded-strips');
    const fetchPromise = window.__homeProductsFetch || fetch('/api/home-products.php', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    fetchPromise
      .then((response) => response.json().catch(() => null))
      .then((data) => {
        if (!data?.ok || !data.strips || typeof data.strips !== 'object') {
          if (!pendingOnly) {
            root.querySelectorAll('[data-home-products-pending]').forEach((slot) => {
              slot.innerHTML = '<div class="home-section__empty">تعذر تحميل منتجات هذا القسم.</div>';
              slot.removeAttribute('data-home-products-pending');
            });
          }
          return;
        }

        applyProductStrips(root, data.strips, pendingOnly);
      })
      .catch(() => {
        if (!pendingOnly) {
          root.querySelectorAll('[data-home-products-pending]').forEach((slot) => {
            slot.innerHTML = '<div class="home-section__empty">تعذر تحميل منتجات هذا القسم.</div>';
            slot.removeAttribute('data-home-products-pending');
          });
        }
      });
  };

  const initDeferredStoreScripts = () => {
    const urlsEl = document.getElementById('deferStoreScriptUrls');
    if (!urlsEl || window.__storeScriptsLoaded) return;

    let scriptUrls = [];
    try {
      scriptUrls = JSON.parse(urlsEl.textContent || '[]');
    } catch {
      return;
    }
    if (!Array.isArray(scriptUrls) || scriptUrls.length === 0) return;

    let loadPromise = null;
    const loadScripts = () => {
      if (window.__storeScriptsLoaded) return Promise.resolve();
      if (loadPromise) return loadPromise;
      loadPromise = scriptUrls.reduce(
        (chain, src) => chain.then(() => new Promise((resolve, reject) => {
          const existing = document.querySelector(`script[src="${src}"]`);
          if (existing) {
            resolve();
            return;
          }
          const script = document.createElement('script');
          script.src = src;
          script.defer = true;
          script.onload = () => resolve();
          script.onerror = () => reject(new Error(`Failed to load ${src}`));
          document.body.appendChild(script);
        })),
        Promise.resolve(),
      ).then(() => {
        window.__storeScriptsLoaded = true;
      });
      return loadPromise;
    };

    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-store-product-preview], [data-store-cart-open], [data-store-add-cart]');
      if (!trigger || window.__storeScriptsLoaded) return;
      event.preventDefault();
      event.stopPropagation();
      loadScripts()
        .then(() => trigger.click())
        .catch(() => {});
    }, true);
  };

  const init = () => {
    initSectionNav();
    initAdCarousel();
    initDeferredProducts();
    initDeferredStoreScripts();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
