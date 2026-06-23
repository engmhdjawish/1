(() => {
  const STORAGE_KEY = 'jawish_site_guide_v1';
  const root = document.getElementById('siteOnboarding');
  if (!root) return;

  const titleEl = document.getElementById('siteOnboardingTitle');
  const stepTitleEl = document.getElementById('siteOnboardingStepTitle');
  const stepTextEl = document.getElementById('siteOnboardingStepText');
  const iconEl = document.getElementById('siteOnboardingIcon');
  const linksEl = document.getElementById('siteOnboardingLinks');
  const dotsEl = document.getElementById('siteOnboardingDots');
  const btnPrev = document.getElementById('siteOnboardingPrev');
  const btnNext = document.getElementById('siteOnboardingNext');
  const btnSkip = document.getElementById('siteOnboardingSkip');
  const btnDismiss = document.getElementById('siteOnboardingDismiss');

  const steps = [
    {
      icon: 'waving_hand',
      title: 'مرحباً بك في متجر جاويش',
      text: 'من هنا يمكنك تصفّح المواد، معرفة الأسعار والكميات المتاحة، وإرسال طلبك بسهولة — دون الحاجة لشرح يدوي في كل مرة.',
      links: [{ href: '/store.php', label: 'اذهب للمتجر', icon: 'storefront' }],
    },
    {
      icon: 'person_add',
      title: 'إنشاء حساب عميل',
      text: 'اضغط «تسجيل» في الأعلى، أدخل بياناتك، ثم انتظر تفعيل حسابك من الإدارة. بعد التفعيل ستظهر لك الأسعار والكميات حسب صلاحياتك.',
      links: [{ href: '/register.php', label: 'تسجيل عميل جديد', icon: 'how_to_reg' }],
    },
    {
      icon: 'login',
      title: 'تسجيل الدخول',
      text: 'إذا كان حسابك مفعّلاً، استخدم «دخول» برقم الهاتف وكلمة المرور. من «حسابي» يمكنك متابعة طلباتك وتحديث بياناتك.',
      links: [{ href: '/login.php?type=customer', label: 'دخول العملاء', icon: 'login' }],
    },
    {
      icon: 'shopping_cart',
      title: 'السلة والطلب',
      text: 'أضف الأصناف للسلة من المتجر، راجع الكميات، ثم أرسل الطلب. إذا نفدت كمية صنف أثناء الطلب ستُعرض لك في قسم «غير المتوفرة» ولن يُرسل مع الطلب.',
      links: [{ href: '/store-cart.php', label: 'فتح السلة', icon: 'shopping_cart' }],
    },
    {
      icon: 'local_shipping',
      title: 'متابعة الطلب',
      text: 'بعد إرسال الطلب ستحصل على رقم طلب ورابط متابعة. يمكنك أيضاً مراجعة طلباتك من صفحة «حسابي» بعد تسجيل الدخول.',
      links: [
        { href: '/account.php?tab=orders', label: 'طلباتي', icon: 'receipt_long' },
        { href: '/about.php', label: 'تواصل معنا', icon: 'support_agent' },
      ],
    },
  ];

  let index = 0;
  let open = false;

  const renderDots = () => {
    if (!dotsEl) return;
    dotsEl.innerHTML = steps.map((_, i) => (
      `<span class="site-onboarding__dot${i === index ? ' is-active' : ''}" aria-hidden="true"></span>`
    )).join('');
  };

  const renderStep = () => {
    const step = steps[index];
    if (!step) return;
    if (titleEl) titleEl.textContent = `الخطوة ${index + 1} من ${steps.length}`;
    if (stepTitleEl) stepTitleEl.textContent = step.title;
    if (stepTextEl) stepTextEl.textContent = step.text;
    if (iconEl) {
      const symbol = iconEl.querySelector('.material-symbols-outlined');
      if (symbol) symbol.textContent = step.icon;
    }
    if (linksEl) {
      linksEl.innerHTML = (step.links || []).map((link) => (
        `<a class="site-onboarding__link" href="${link.href}">
          <span class="material-symbols-outlined text-base" aria-hidden="true">${link.icon || 'link'}</span>
          ${link.label}
        </a>`
      )).join('');
    }
    if (btnPrev) btnPrev.hidden = index === 0;
    if (btnNext) btnNext.textContent = index === steps.length - 1 ? 'إنهاء' : 'التالي';
    renderDots();
  };

  const markSeen = () => {
    try {
      localStorage.setItem(STORAGE_KEY, '1');
    } catch (_) {
      /* ignore */
    }
  };

  const show = (force = false) => {
    if (!force) {
      try {
        if (localStorage.getItem(STORAGE_KEY) === '1') return;
      } catch (_) {
        /* ignore */
      }
    }
    open = true;
    index = 0;
    renderStep();
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    btnNext?.focus();
  };

  const hide = (persist = true) => {
    open = false;
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
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
  btnDismiss?.addEventListener('click', () => hide(true));

  root.addEventListener('click', (event) => {
    if (event.target === root) hide(true);
  });

  document.addEventListener('keydown', (event) => {
    if (!open) return;
    if (event.key === 'Escape') hide(true);
  });

  document.querySelectorAll('[data-site-guide-replay]').forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      show(true);
    });
  });

  window.SiteOnboarding = { show, hide };

  const autoStart = root.getAttribute('data-auto-start') === '1';
  if (autoStart) {
    window.setTimeout(() => show(false), 900);
  }
})();
