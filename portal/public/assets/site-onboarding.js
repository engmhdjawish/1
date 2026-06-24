(() => {
  const STORAGE_KEY = 'jawish_site_guide_v2';
  const PADDING = 10;
  const MOBILE_MAX = 767;

  const root = document.getElementById('siteOnboarding');
  if (!root) return;

  const spotlight = document.getElementById('siteGuideSpotlight');
  const tooltip = document.getElementById('siteGuideTooltip');
  const progressEl = document.getElementById('siteGuideProgress');
  const dotsEl = document.getElementById('siteGuideDots');
  const iconEl = document.getElementById('siteGuideIcon');
  const titleEl = document.getElementById('siteGuideStepTitle');
  const textEl = document.getElementById('siteGuideStepText');
  const btnPrev = document.getElementById('siteGuidePrev');
  const btnNext = document.getElementById('siteGuideNext');
  const btnSkip = document.getElementById('siteGuideSkip');

  const isMobile = () => window.matchMedia(`(max-width: ${MOBILE_MAX}px)`).matches;
  const isGuest = () => root.getAttribute('data-auth') === 'guest';
  const hasCart = () => root.getAttribute('data-cart') === '1';
  const hasCurrency = () => root.getAttribute('data-currency') === '1';

  let steps = [];
  let index = 0;
  let open = false;
  let activeTarget = null;
  let repositionTimer = null;

  const stepCatalog = {
    welcome: {
      icon: 'waving_hand',
      title: 'مرحباً بك في متجر جاويش',
      text: 'سنأخذك في جولة سريعة على أهم أزرار الموقع — نضيء كل زر ونشرح وظيفته خطوة بخطوة.',
      center: true,
    },
    store: {
      icon: 'storefront',
      title: 'المتجر',
      text: 'من هنا تدخل لصفحة المتجر لتصفّح المواد، معرفة الأسعار والكميات المتاحة، وإضافة ما تحتاجه للسلة.',
      target: 'nav-store',
      openDrawerOnMobile: true,
    },
    register: {
      icon: 'person_add',
      title: 'تسجيل عميل جديد',
      text: 'اضغط «تسجيل» وأدخل بياناتك. بعد موافقة الإدارة وتفعيل حسابك ستظهر لك الأسعار والكميات حسب صلاحياتك.',
      target: 'register',
      openDrawerOnMobile: true,
      guestOnly: true,
    },
    login: {
      icon: 'login',
      title: 'تسجيل الدخول',
      text: 'إذا كان حسابك مفعّلاً، استخدم «دخول» برقم الهاتف وكلمة المرور للوصول لحسابك وطلباتك.',
      target: 'login',
      openDrawerOnMobile: true,
      guestOnly: true,
    },
    account: {
      icon: 'account_circle',
      title: 'حسابي',
      text: 'بعد تسجيل الدخول يمكنك متابعة طلباتك وتحديث بياناتك من هنا.',
      target: 'account',
      openDrawerOnMobile: true,
      customerOnly: true,
    },
    cart: {
      icon: 'shopping_cart',
      title: 'السلة',
      text: 'راجع الأصناف التي أضفتها، عدّل الكميات، ثم أرسل طلبك. الأصناف غير المتوفرة تظهر في قسم منفصل ولن تُرسل.',
      target: 'cart',
    },
    currency: {
      icon: 'currency_exchange',
      title: 'عملة الأسعار',
      text: 'بدّل بين الليرة السورية والدولار لعرض الأسعار بالعملة التي تفضّلها — يُطبَّق على المتجر مباشرة.',
      target: 'currency',
      openDrawerOnMobile: true,
    },
    finish: {
      icon: 'celebration',
      title: 'أنت جاهز للبدء',
      text: 'يمكنك إعادة هذا الدليل في أي وقت من رابط «كيف أستخدم الموقع؟» في أسفل الصفحة. تصفّح بثقة وأرسل طلبك بسهولة.',
      center: true,
    },
  };

  const buildSteps = () => {
    const order = ['welcome', 'store'];
    if (isGuest()) {
      order.push('register', 'login');
    } else {
      order.push('account');
    }
    if (hasCart()) order.push('cart');
    if (hasCurrency()) order.push('currency');
    order.push('finish');

    return order
      .map((id) => stepCatalog[id])
      .filter((step) => {
        if (!step) return false;
        if (step.guestOnly && !isGuest()) return false;
        if (step.customerOnly && isGuest()) return false;
        return true;
      });
  };

  const isVisible = (el) => {
    if (!el || !el.isConnected) return false;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return false;
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return false;
    if (rect.right < 0 || rect.bottom < 0 || rect.left > window.innerWidth || rect.top > window.innerHeight) {
      return false;
    }
    return true;
  };

  const findTarget = (guideId) => {
    const nodes = document.querySelectorAll(`[data-guide="${guideId}"]`);
    for (const node of nodes) {
      if (isVisible(node)) return node;
    }
    return nodes[0] || null;
  };

  const setPublicNavOpen = (shouldOpen) => {
    if (typeof window.PublicNav?.setOpen === 'function') {
      window.PublicNav.setOpen(shouldOpen);
      return;
    }
    const drawer = document.getElementById('publicNavDrawer');
    const overlay = document.getElementById('publicNavOverlay');
    const openBtn = document.getElementById('openPublicNavBtn');
    if (!drawer || !overlay) return;
    drawer.classList.toggle('is-open', shouldOpen);
    overlay.classList.toggle('is-open', shouldOpen);
    drawer.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    openBtn?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    document.body.style.overflow = shouldOpen ? 'hidden' : '';
  };

  const clearTargetRing = () => {
    if (activeTarget) {
      activeTarget.classList.remove('site-guide__target-ring');
      activeTarget = null;
    }
  };

  const positionCentered = () => {
    if (!spotlight || !tooltip) return;
    const size = Math.min(window.innerWidth, window.innerHeight) * 0.22;
    const top = (window.innerHeight - size) / 2;
    const left = (window.innerWidth - size) / 2;
    spotlight.classList.add('is-centered');
    spotlight.style.top = `${top}px`;
    spotlight.style.left = `${left}px`;
    spotlight.style.width = `${size}px`;
    spotlight.style.height = `${size}px`;
    tooltip.classList.add('is-centered');
    tooltip.style.top = '';
    tooltip.style.left = '';
  };

  const positionAroundTarget = (target) => {
    if (!spotlight || !tooltip || !target) return;
    spotlight.classList.remove('is-centered');
    tooltip.classList.remove('is-centered');

    const rect = target.getBoundingClientRect();
    const top = Math.max(8, rect.top - PADDING);
    const left = Math.max(8, rect.left - PADDING);
    const width = Math.min(window.innerWidth - 16, rect.width + PADDING * 2);
    const height = Math.min(window.innerHeight - 16, rect.height + PADDING * 2);

    spotlight.style.top = `${top}px`;
    spotlight.style.left = `${left}px`;
    spotlight.style.width = `${width}px`;
    spotlight.style.height = `${height}px`;

    tooltip.style.top = '';
    tooltip.style.left = '';
    tooltip.offsetHeight;

    const tipRect = tooltip.getBoundingClientRect();
    const gap = 14;
    let tipTop = rect.bottom + gap;
    let tipLeft = rect.left;

    if (tipTop + tipRect.height > window.innerHeight - 8) {
      tipTop = rect.top - tipRect.height - gap;
    }
    if (tipTop < 8) {
      tipTop = Math.min(window.innerHeight - tipRect.height - 8, rect.bottom + gap);
    }

    tipLeft = Math.min(
      Math.max(8, tipLeft),
      window.innerWidth - tipRect.width - 8,
    );

    tooltip.style.top = `${tipTop}px`;
    tooltip.style.left = `${tipLeft}px`;
  };

  const prepareTarget = async (step) => {
    clearTargetRing();
    if (step.center) {
      setPublicNavOpen(false);
      positionCentered();
      return null;
    }

    if (step.openDrawerOnMobile && isMobile()) {
      setPublicNavOpen(true);
      await new Promise((resolve) => window.setTimeout(resolve, 280));
    } else {
      setPublicNavOpen(false);
    }

    let target = step.target ? findTarget(step.target) : null;

    if (!target && step.target === 'nav-store' && isMobile()) {
      target = findTarget('nav-menu');
    }

    if (target) {
      target.scrollIntoView({ block: 'center', behavior: 'smooth', inline: 'nearest' });
      await new Promise((resolve) => window.setTimeout(resolve, 320));
      target.classList.add('site-guide__target-ring');
      activeTarget = target;
      positionAroundTarget(target);
      return target;
    }

    positionCentered();
    return null;
  };

  const renderDots = () => {
    if (!dotsEl) return;
    dotsEl.innerHTML = steps.map((_, i) => (
      `<span class="site-guide__dot${i === index ? ' is-active' : ''}" aria-hidden="true"></span>`
    )).join('');
  };

  const renderStep = async () => {
    const step = steps[index];
    if (!step) return;

    if (progressEl) progressEl.textContent = `الخطوة ${index + 1} من ${steps.length}`;
    if (titleEl) titleEl.textContent = step.title;
    if (textEl) textEl.textContent = step.text;
    if (iconEl) {
      const symbol = iconEl.querySelector('.material-symbols-outlined');
      if (symbol) symbol.textContent = step.icon;
    }
    if (btnPrev) btnPrev.hidden = index === 0;
    if (btnNext) btnNext.textContent = index === steps.length - 1 ? 'إنهاء' : 'التالي';
    renderDots();

    tooltip?.classList.remove('is-visible');
    await prepareTarget(step);
    tooltip?.classList.add('is-visible');
    btnNext?.focus();
  };

  const markSeen = () => {
    try {
      localStorage.setItem(STORAGE_KEY, '1');
    } catch (_) {
      /* ignore */
    }
  };

  const reposition = () => {
    if (!open) return;
    const step = steps[index];
    if (!step) return;
    if (step.center) {
      positionCentered();
      return;
    }
    const target = activeTarget && activeTarget.isConnected ? activeTarget : (step.target ? findTarget(step.target) : null);
    if (target) positionAroundTarget(target);
  };

  const scheduleReposition = () => {
    if (repositionTimer) window.clearTimeout(repositionTimer);
    repositionTimer = window.setTimeout(reposition, 80);
  };

  const show = (force = false) => {
    if (!force) {
      try {
        if (localStorage.getItem(STORAGE_KEY) === '1') return;
      } catch (_) {
        /* ignore */
      }
    }

    steps = buildSteps();
    if (!steps.length) return;

    open = true;
    index = 0;
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    root.classList.add('is-active');
    document.body.style.overflow = 'hidden';
    renderStep();
  };

  const hide = (persist = true) => {
    open = false;
    root.classList.remove('is-active');
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    tooltip?.classList.remove('is-visible', 'is-centered');
    clearTargetRing();
    setPublicNavOpen(false);
    if (persist) markSeen();
  };

  btnPrev?.addEventListener('click', () => {
    index = Math.max(0, index - 1);
    renderStep();
  });

  btnNext?.addEventListener('click', () => {
    if (index >= steps.length - 1) {
      hide(true);
      return;
    }
    index += 1;
    renderStep();
  });

  btnSkip?.addEventListener('click', () => hide(true));

  document.addEventListener('keydown', (event) => {
    if (!open) return;
    if (event.key === 'Escape') hide(true);
  });

  window.addEventListener('resize', scheduleReposition);
  window.addEventListener('scroll', scheduleReposition, true);

  document.querySelectorAll('[data-site-guide-replay]').forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      show(true);
    });
  });

  window.SiteOnboarding = { show, hide };

  const autoStart = root.getAttribute('data-auto-start') === '1';
  if (autoStart) {
    window.setTimeout(() => show(false), 1100);
  }
})();
