/**
 * Interactive page tours: spotlight + tooltip on the real controls.
 * Each page has its own tour (home / store / cart / site); it starts once on the first
 * visit to that page and can be replayed from any [data-site-guide-replay] or ?tour=1.
 * Steps follow the visitor's access policy (prices, cart, orders, account state).
 */
(() => {
  const STORAGE_PREFIX = 'jawish_tour_v3_';
  const PADDING = 8;

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

  const flag = (name) => root.getAttribute(`data-${name}`) === '1';
  const auth = root.getAttribute('data-auth') || 'guest';
  const tourName = root.getAttribute('data-tour') || 'site';
  const ctx = {
    guest: auth === 'guest',
    customer: auth === 'customer',
    staff: auth === 'staff',
    cart: flag('cart'),
    price: flag('price') || flag('currency'),
    currency: flag('currency'),
    order: flag('order'),
  };
  const phone = () => window.matchMedia('(max-width: 767px)').matches;

  const common = {
    account: {
      target: '[data-guide="auth"]',
      icon: ctx.staff ? 'badge' : 'storefront',
      title: 'حسابك',
      text: ctx.staff
        ? 'اسمك هنا. اضغط عليه للوصول إلى لوحة التحكم والطلبات وتسجيل الخروج — وهي نفس قائمة «حسابي» في الشريط السفلي.'
        : 'اسم محلّك هنا. اضغط عليه لفتح طلباتك وملفك والإشعارات — وهي نفس قائمة «حسابي» في الشريط السفلي.',
      when: () => !ctx.guest,
    },
    register: {
      target: '[data-guide="register"]',
      icon: 'person_add',
      title: 'افتح حساب جملة',
      text: 'سجّل باسم المحل ورقم الهاتف وعنوان المحل. بعد تفعيل الإدارة لحسابك تظهر لك الأسعار وتستطيع الطلب حسب صلاحياتك.',
      when: () => ctx.guest,
    },
    login: {
      target: '[data-guide="login"]',
      icon: 'login',
      title: 'لديك حساب؟',
      text: 'ادخل برقم الهاتف وكلمة المرور لترى أسعارك وطلباتك.',
      when: () => ctx.guest,
    },
    currency: {
      target: '[data-guide="currency"]',
      icon: 'currency_exchange',
      title: 'عملة الأسعار',
      text: 'بدّل بين الليرة السورية والدولار؛ تتغير كل الأسعار في الصفحة فوراً.',
      when: () => ctx.currency,
    },
    cart: {
      target: phone() ? '[data-app-dock] [href="/store-cart.php"], [data-guide="cart"]' : '[data-guide="cart"]',
      icon: 'shopping_cart',
      title: 'السلة',
      text: 'كل ما تضيفه يُجمع هنا بالطرود. افتحها لمراجعة الكميات وإرسال الطلب.',
      when: () => ctx.cart,
    },
    dock: {
      target: '[data-app-dock]',
      icon: 'touch_app',
      title: 'الشريط السفلي',
      text: 'تنقّل سريع: الرئيسية، الأقسام، البحث، السلة وحسابك — في متناول إصبعك دائماً.',
      when: () => phone(),
    },
    help: {
      target: '[data-guide="help"]',
      icon: 'help',
      title: 'أعد الجولة متى شئت',
      text: 'زر «؟» يعيد هذه الجولة في أي وقت. موفّق في طلبيتك!',
    },
  };

  const tours = {
    home: [
      { center: true, icon: 'waving_hand', title: 'أهلاً بك', text: 'جولة قصيرة على الصفحة الرئيسية: نضيء كل جزء ونشرح ماذا يفعل. استخدم «التالي» للمتابعة.' },
      { target: '.hn-top .hn-search', icon: 'search', title: 'ابحث مباشرة', text: 'اكتب اسم المادة أو الشركة أو رقمها؛ تظهر النتائج أثناء الكتابة مجمّعة حسب النوع والشركة. يمكنك البحث بالصوت أيضاً.' },
      { target: '.hn-stories-wrap', icon: 'apps', title: 'تصفّح سريع', text: 'اختصارات للأقسام والعروض. «كل الأقسام» يفتح القائمة الكاملة.' },
      { target: '.hn-guide', icon: 'touch_app', title: 'تسوّق خطوة بخطوة', text: 'لا تعرف من أين تبدأ؟ اختر النوع ثم الشركة ثم المقاس، ونعرض لك المواد المطابقة فقط.' },
      { target: '.hn-shop', icon: 'category', title: 'تسوّق حسب', text: 'بدّل بين النوع والشركة واضغط على أي اختيار للانتقال إلى المتجر مصفّى عليه.' },
      common.dock,
      common.cart,
      common.currency,
      common.register,
      common.login,
      common.account,
      common.help,
    ],
    store: [
      { center: true, icon: 'storefront', title: 'المتجر', text: 'هنا كل المواد. سنريك كيف تبحث وتصفّي وتضيف للسلة بسرعة.' },
      { target: '.se-search .hn-search', icon: 'search', title: 'البحث', text: 'نفس بحث الصفحة الرئيسية: اكتب وستظهر المواد فوراً؛ الفلاتر المختارة تبقى كما هي.' },
      { target: '.se-shelf, .se-section-banner', icon: 'category', title: 'الأنواع', text: 'اضغط نوعاً لعرض مواده فقط، و«الكل» للعودة لكل المواد.' },
      { target: '.se-bar', icon: 'tune', title: 'الفلاتر', text: 'اختر الشركة أو المقاس أو الترتيب. النتائج تتحدّث مباشرة دون إعادة تحميل الصفحة.' },
      { target: '.se-toggle', icon: 'inventory', title: 'المتوفر فقط', text: 'فعّله لإخفاء المواد غير المتوفرة حالياً.' },
      { target: '[data-se-density]', icon: 'grid_view', title: 'طريقة العرض', text: 'صورة كبيرة لكل مادة، أو مادتان في السطر لتصفّح أسرع.' },
      { target: '.se-grid > *:first-child', icon: 'checkroom', title: 'بطاقة المادة', text: ctx.price ? 'اضغط الصورة للتفاصيل والمقاسات. السعر للطرد الواحد ويتبع صلاحيات حسابك.' : 'اضغط الصورة للتفاصيل والمقاسات. الأسعار تظهر بعد تفعيل حساب الجملة.' },
      { target: '.se-grid > *:first-child form[data-store-add-cart], .se-grid > *:first-child [data-store-qty-stepper]', icon: 'add_shopping_cart', title: 'أضف للسلة', text: 'حدّد عدد الطرود واضغط «أضف». يمكنك تعديل الكمية لاحقاً من السلة.', when: () => ctx.cart },
      common.cart,
      common.currency,
      common.register,
      common.account,
      common.help,
    ],
    cart: [
      { center: true, icon: 'shopping_cart', title: 'سلتك', text: 'راجع طلبيتك قبل الإرسال — نعرّفك على أجزاء السلة.' },
      { target: '.store-cart-lines .store-cart-line-card:first-child', icon: 'inventory_2', title: 'المواد', text: 'كل مادة مع عدد الطرود. زر الحذف يزيلها من السلة.' },
      { target: '.store-cart-lines [data-cart-qty-control]', icon: 'exposure', title: 'الكمية', text: 'غيّر عدد الطرود بالأزرار؛ يتحدّث الإجمالي مباشرة.' },
      { target: '[data-cart-unavailable]', icon: 'error', title: 'غير متوفرة', text: 'هذه المواد لن تُرسل مع الطلب. أزلها أو انتظر توفرها.' },
      { target: '[data-cart-summary]', icon: 'receipt_long', title: 'الإجمالي والإرسال', text: ctx.order ? 'تأكد من الإجمالي، أضف ملاحظة إن أردت، ثم اضغط «تأكيد وإرسال الطلب» مرة واحدة فقط.' : 'سياسة حسابك لا تسمح بإرسال الطلب حالياً؛ تواصل معنا للتفعيل.' },
      common.account,
      common.help,
    ],
    site: [
      { center: true, icon: 'waving_hand', title: 'مرحباً بك', text: 'جولة سريعة على أهم أزرار الموقع.' },
      { target: '[data-guide="nav-store"]', icon: 'storefront', title: 'المتجر', text: 'تصفّح المواد وأضف ما تحتاجه للسلة.' },
      common.cart,
      common.currency,
      common.register,
      common.login,
      common.account,
      common.help,
    ],
  };

  const storageKey = STORAGE_PREFIX + tourName;
  let steps = [];
  let index = 0;
  let open = false;
  let activeTarget = null;
  let repositionTimer = 0;

  const rendered = (el) => {
    if (!(el instanceof HTMLElement) || !el.isConnected) return false;
    if (el.closest('[hidden]')) return false;
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return false;
    const style = window.getComputedStyle(el);
    return style.visibility !== 'hidden' && style.display !== 'none';
  };

  const findTarget = (selector) => {
    if (!selector) return null;
    for (const node of document.querySelectorAll(selector)) {
      if (rendered(node)) return node;
    }
    return null;
  };

  const buildSteps = () => (tours[tourName] || tours.site)
    .filter((step) => step && (!step.when || step.when()))
    .filter((step) => step.center || findTarget(step.target));

  const clearTargetRing = () => {
    activeTarget?.classList.remove('site-guide__target-ring');
    activeTarget = null;
  };

  const positionCentered = () => {
    const size = Math.min(window.innerWidth, window.innerHeight) * 0.2;
    spotlight.classList.add('is-centered');
    Object.assign(spotlight.style, {
      top: `${(window.innerHeight - size) / 2}px`,
      left: `${(window.innerWidth - size) / 2}px`,
      width: `${size}px`,
      height: `${size}px`,
    });
    tooltip.classList.add('is-centered');
    tooltip.style.top = '';
    tooltip.style.left = '';
  };

  const positionAroundTarget = (target) => {
    spotlight.classList.remove('is-centered');
    tooltip.classList.remove('is-centered');
    const rect = target.getBoundingClientRect();
    const top = Math.max(6, rect.top - PADDING);
    const left = Math.max(6, rect.left - PADDING);
    const width = Math.min(window.innerWidth - 12, rect.width + PADDING * 2);
    const height = Math.min(window.innerHeight - 12, rect.height + PADDING * 2);
    Object.assign(spotlight.style, { top: `${top}px`, left: `${left}px`, width: `${width}px`, height: `${height}px` });

    tooltip.style.top = '0px';
    tooltip.style.left = '0px';
    const tip = tooltip.getBoundingClientRect();
    const gap = 12;
    let tipTop = top + height + gap;
    if (tipTop + tip.height > window.innerHeight - 8) tipTop = top - tip.height - gap;
    if (tipTop < 8) {
      tipTop = Math.max(8, window.innerHeight - tip.height - 8);
      const visibleHeight = Math.max(48, tipTop - gap - top);
      spotlight.style.height = `${Math.min(height, visibleHeight)}px`;
    }
    const tipLeft = Math.min(Math.max(8, rect.left + rect.width / 2 - tip.width / 2), window.innerWidth - tip.width - 8);
    tooltip.style.top = `${tipTop}px`;
    tooltip.style.left = `${tipLeft}px`;
  };

  const scrollToTarget = (target) => new Promise((resolve) => {
    const rect = target.getBoundingClientRect();
    const header = document.querySelector('.site-header');
    const headerH = header instanceof HTMLElement && getComputedStyle(header).position === 'sticky' ? header.offsetHeight : 0;
    const fixed = getComputedStyle(target).position === 'fixed' || target.closest('.site-header, [data-app-dock]');
    if (fixed) {
      resolve();
      return;
    }
    const roomTop = headerH + 12;
    const wanted = rect.top - Math.max(roomTop, (window.innerHeight - rect.height) / 3);
    if (rect.top >= roomTop && rect.bottom <= window.innerHeight * 0.6) {
      resolve();
      return;
    }
    window.scrollBy({ top: wanted, behavior: 'smooth' });
    window.setTimeout(resolve, 380);
  });

  const renderDots = () => {
    dotsEl.innerHTML = steps.map((_, i) => `<span class="site-guide__dot${i === index ? ' is-active' : ''}" aria-hidden="true"></span>`).join('');
  };

  const renderStep = async () => {
    const step = steps[index];
    if (!step) return;
    progressEl.textContent = `${index + 1} من ${steps.length}`;
    titleEl.textContent = step.title;
    textEl.textContent = step.text;
    const symbol = iconEl.querySelector('.material-symbols-outlined');
    if (symbol) symbol.textContent = step.icon || 'info';
    btnPrev.hidden = index === 0;
    btnNext.textContent = index === steps.length - 1 ? 'إنهاء' : 'التالي';
    renderDots();

    tooltip.classList.remove('is-visible');
    clearTargetRing();
    window.SiteAccountMenu?.setOpen?.(false);
    const target = step.center ? null : findTarget(step.target);
    if (target) {
      await scrollToTarget(target);
      if (!open || steps[index] !== step) return;
      target.classList.add('site-guide__target-ring');
      activeTarget = target;
      positionAroundTarget(target);
    } else {
      positionCentered();
    }
    tooltip.classList.add('is-visible');
    btnNext.focus({ preventScroll: true });
  };

  const markSeen = () => {
    try {
      localStorage.setItem(storageKey, '1');
    } catch {
      // Private mode: the tour may show again next visit.
    }
  };
  const seen = () => {
    try {
      return localStorage.getItem(storageKey) === '1';
    } catch {
      return true;
    }
  };

  const reposition = () => {
    if (!open) return;
    if (activeTarget?.isConnected) positionAroundTarget(activeTarget);
    else if (steps[index]?.center) positionCentered();
  };
  const scheduleReposition = () => {
    window.clearTimeout(repositionTimer);
    repositionTimer = window.setTimeout(reposition, 60);
  };

  const show = () => {
    if (open) return;
    steps = buildSteps();
    if (steps.length < 2) return;
    open = true;
    index = 0;
    document.documentElement.classList.add('site-tour-open');
    window.dispatchEvent(new Event('site-tour-toggle'));
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    root.classList.add('is-active');
    renderStep();
  };

  const hide = () => {
    if (!open) return;
    open = false;
    document.documentElement.classList.remove('site-tour-open');
    window.dispatchEvent(new Event('site-tour-toggle'));
    root.classList.remove('is-active');
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    tooltip.classList.remove('is-visible', 'is-centered');
    clearTargetRing();
    markSeen();
  };

  btnPrev?.addEventListener('click', () => {
    index = Math.max(0, index - 1);
    renderStep();
  });
  btnNext?.addEventListener('click', () => {
    if (index >= steps.length - 1) {
      hide();
      return;
    }
    index += 1;
    renderStep();
  });
  btnSkip?.addEventListener('click', hide);
  root.addEventListener('click', (event) => {
    if (event.target === root) hide();
  });
  document.addEventListener('keydown', (event) => {
    if (!open) return;
    if (event.key === 'Escape') hide();
    if (event.key === 'ArrowLeft') btnNext?.click();
    if (event.key === 'ArrowRight' && index > 0) btnPrev?.click();
  });
  window.addEventListener('resize', scheduleReposition);
  window.addEventListener('scroll', scheduleReposition, { passive: true });

  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-site-guide-replay]') : null;
    if (!trigger) return;
    event.preventDefault();
    window.SiteAccountMenu?.setOpen?.(false);
    show();
  });

  window.SiteOnboarding = { show, hide };

  const busyTyping = () => {
    const active = document.activeElement;
    return active instanceof HTMLElement && active.matches('input, textarea, select, [contenteditable="true"]');
  };
  const blocked = () => busyTyping() || Boolean(
    document.querySelector('#pwa-install-modal:not([hidden]), #site-consent-banner:not([hidden]), .site-account.is-open, [data-se-pill][open], .hn-sheet:not([hidden]), [data-hn-suggest]:not([hidden]), .notif-bell__panel:not([hidden])'),
  );
  const forced = new URLSearchParams(window.location.search).get('tour') === '1';
  if (forced) {
    const url = new URL(window.location.href);
    url.searchParams.delete('tour');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
  }
  if (forced || (root.getAttribute('data-auto-start') === '1' && !seen())) {
    let tries = 0;
    const attempt = () => {
      tries += 1;
      if (window.scrollY > 400 && !forced) return;
      if (blocked() && tries < 6) {
        window.setTimeout(attempt, 2500);
        return;
      }
      if (!blocked()) show();
    };
    window.setTimeout(attempt, forced ? 400 : 1600);
  }
})();
