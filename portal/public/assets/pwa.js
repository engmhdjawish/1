/**
 * PWA install — header button + modal + optional auto banner.
 */
(function () {
  'use strict';

  const AUTO_DISMISS_KEY = 'pwa-auto-dismissed-at';
  const INSTALLED_KEY = 'pwa-installed-v1';
  const MIGRATE_DISMISS_KEY = 'pwa-migrate-dismiss-session';
  const AUTO_MIGRATE_KEY = 'pwa-auto-migrate-session';
  const AUTO_DISMISS_DAYS = 3;
  const ENGAGE_MS = 28000;
  const pageLoadedAt = Date.now();
  let engagementReady = false;
  /** Canonical install host — only www is installable going forward. */
  const CANONICAL_INSTALL_HOST = 'www.jawishco.sy';
  const UNIFIED_ICON_COPY = 'أيقونة-موحّدة-v1';

  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: fullscreen)').matches
    || window.matchMedia('(display-mode: minimal-ui)').matches
    || window.navigator.standalone === true;

  /*
   * Chrome WebAPK shell (× Custom Tab) is NOT healed by Service Worker updates.
   * SKIP_WAITING only reloads page content inside the same broken CCT shell.
   * Detect homescreen launches that are no longer standalone and migrate the user
   * to a healthy install (www sibling or fresh install) — without mass-deleting
   * every customer's icon.
   */
  function looksLikeBrokenHomescreenLaunch() {
    if (isStandalone) {
      return false;
    }
    // start_url of the installed app always carries source=pwa.
    const params = new URLSearchParams(window.location.search);
    if (params.get('source') === 'pwa') {
      return true;
    }
    // WebAPK / TWA launches often expose an android-app referrer.
    const ref = String(document.referrer || '');
    if (/^android-app:\/\//i.test(ref)) {
      return true;
    }
    // Do NOT treat empty-referrer browser tabs as broken — that false-positives
    // normal Chrome visits and would nag healthy customers to reinstall.
    return false;
  }

  function siblingPublicOrigin() {
    const host = String(window.location.hostname || '').toLowerCase();
    if (!host || host === 'localhost' || host === '127.0.0.1') {
      return '';
    }
    const sibling = host.startsWith('www.') ? host.slice(4) : 'www.' + host;
    if (!sibling || sibling === host) {
      return '';
    }
    return window.location.protocol + '//' + sibling;
  }

  function canonicalInstallOrigin() {
    const host = String(window.location.hostname || '').toLowerCase();
    if (!host || host === 'localhost' || host === '127.0.0.1') {
      return '';
    }
    // Prefer the known production www host; otherwise derive www from apex/www pair.
    if (host === 'jawishco.sy' || host === 'www.jawishco.sy') {
      return window.location.protocol + '//' + CANONICAL_INSTALL_HOST;
    }
    if (host.startsWith('www.')) {
      return window.location.protocol + '//' + host;
    }
    const sibling = siblingPublicOrigin();
    return sibling || (window.location.protocol + '//' + host);
  }

  function isOnCanonicalInstallHost() {
    const host = String(window.location.hostname || '').toLowerCase();
    const install = canonicalInstallOrigin();
    if (!install) {
      return true;
    }
    try {
      return host === new URL(install).hostname.toLowerCase();
    } catch (_) {
      return host.startsWith('www.');
    }
  }

  /** Apex (and any non-www host) must not advertise a second installable app. */
  function shouldMigrateToWwwSibling() {
    return !isOnCanonicalInstallHost() && canonicalInstallOrigin() !== '';
  }

  (function rememberStandaloneHost() {
    if (!isStandalone || !window.isSecureContext) {
      return;
    }
    const host = window.location.hostname;
    if (!host || host === 'localhost' || host === '127.0.0.1') {
      return;
    }
    const parent = (document.body && document.body.dataset.cookieDomain) || '';
    const parts = [
      'portal_pwa_host=' + encodeURIComponent(host),
      'path=/',
      'max-age=31536000',
      'SameSite=Lax',
      'Secure',
    ];
    if (parent && parent.startsWith('.')) {
      parts.push('domain=' + parent);
    }
    try {
      document.cookie = parts.join(';');
    } catch {
      /* ignore */
    }
  })();

  (function upgradeToHttpsSameHost() {
    // Never change host here. www↔apex soft redirects reopen a Custom Tab (× bar)
    // for already-broken installs where display-mode is no longer standalone.
    if (window.location.protocol !== 'http:') {
      return;
    }
    const host = window.location.hostname;
    if (host === 'localhost' || host === '127.0.0.1') {
      return;
    }
    window.location.replace(
      'https://' + host + window.location.pathname + window.location.search + window.location.hash
    );
  })();
  const ua = navigator.userAgent || '';
  const isIOS = /iphone|ipad|ipod/i.test(ua)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // WhatsApp/Facebook/Instagram/Telegram webviews cannot install; Chrome there only offers a shortcut.
  const isInAppBrowser = /FBAN|FBAV|FB_IAB|Instagram|WhatsApp|Line\/|Telegram|Snapchat|; wv\)/i.test(ua);
  const isIOSSafari = isIOS && /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|GSA\//i.test(ua) && !isInAppBrowser;
  const isSecure = window.isSecureContext === true;

  let deferredPrompt = null;
  let modalEl = null;

  function canInstall() {
    if (isStandalone || isInAppBrowser || !isSecure) {
      return false;
    }
    return Boolean(deferredPrompt) || isIOSSafari;
  }

  function detectMode() {
    if (isIOS) {
      return 'ios';
    }
    if (!isSecure) {
      return 'http';
    }
    if (!deferredPrompt) {
      return 'manual';
    }
    return 'native';
  }

  function copyForMode(mode) {
    if (mode === 'ios') {
      return {
        title: 'إضافة الموقع للشاشة الرئيسية',
        steps: [
          'اضغط زر المشاركة ↗ أسفل الشاشة.',
          'اختر «إضافة إلى الشاشة الرئيسية».',
          'اضغط «إضافة».',
        ],
        button: null,
      };
    }
    if (mode === 'http') {
      return {
        title: 'التثبيت يتطلب HTTPS',
        steps: [
          'الرابط الحالي غير آمن (HTTP) — لذلك الزر لا يثبّت التطبيق.',
          'سيتم تحويلك تلقائياً إلى https:// عند توفر SSL.',
          'أو اكتب يدوياً: https://' + window.location.hostname,
        ],
        button: 'فتح HTTPS',
      };
    }
    if (mode === 'manual') {
      return {
        title: 'تثبيت التطبيق من Chrome',
        steps: [
          'ابحث عن أيقونة التثبيت ⊕ في شريط العنوان (قد تظهر بعد 30 ثانية من التصفح).',
          'أو: القائمة ⋮ أعلى اليمين → «تثبيت التطبيق» أو «Install Jawish».',
          'إن لم يظهر الخيار: امسح cache المتصفح (Ctrl+Shift+Delete) ثم أعد فتح https://' + window.location.hostname,
        ],
        button: null,
      };
    }
    return {
      title: 'تثبيت التطبيق',
      steps: [
        'اضغط «تثبيت الآن» أدناه.',
        'أو من قائمة المتصفح اختر «تثبيت التطبيق».',
      ],
      button: 'تثبيت الآن',
    };
  }

  function autoDismissedRecently() {
    try {
      const raw = localStorage.getItem(AUTO_DISMISS_KEY);
      if (!raw) {
        return false;
      }
      const ts = parseInt(raw, 10);
      if (!Number.isFinite(ts)) {
        return false;
      }
      return (Date.now() - ts) < AUTO_DISMISS_DAYS * 24 * 60 * 60 * 1000;
    } catch {
      return false;
    }
  }

  function markAutoDismissed() {
    try {
      localStorage.setItem(AUTO_DISMISS_KEY, String(Date.now()));
    } catch (_) {}
  }

  function hideInstallTriggers() {
    document.querySelectorAll('[data-pwa-open], [data-pwa-trigger]').forEach((el) => {
      el.classList.add('hidden');
      el.setAttribute('hidden', '');
      el.setAttribute('aria-hidden', 'true');
    });
  }

  function markInstalled() {
    try {
      localStorage.setItem(INSTALLED_KEY, '1');
    } catch (_) {}
    hideInstallTriggers();
    document.getElementById('pwa-install-banner')?.remove();
    deferredPrompt = null;
  }

  function clearInstalledMark() {
    try {
      localStorage.removeItem(INSTALLED_KEY);
    } catch (_) {}
  }

  function isMarkedInstalled() {
    try {
      return localStorage.getItem(INSTALLED_KEY) === '1';
    } catch {
      return false;
    }
  }

  function showInstallTriggers() {
    document.querySelectorAll('[data-pwa-open], [data-pwa-trigger]').forEach((el) => {
      el.classList.remove('hidden');
      el.removeAttribute('hidden');
      el.setAttribute('aria-hidden', 'false');
    });
  }

  async function detectInstalledApp() {
    // Already running as installed app.
    if (isStandalone) {
      return true;
    }

    // Browser offering install again => previous uninstall; drop stale flag.
    if (deferredPrompt) {
      clearInstalledMark();
      return false;
    }

    if (typeof navigator.getInstalledRelatedApps === 'function') {
      try {
        const relatedApps = await navigator.getInstalledRelatedApps();
        if (Array.isArray(relatedApps) && relatedApps.length > 0) {
          markInstalled();
          return true;
        }
        // Related-apps API says nothing is installed — clear stale localStorage
        // left behind after the user uninstalled the PWA.
        clearInstalledMark();
        return false;
      } catch (_) {}
    }

    // Without related-apps support, do not trust a persistent localStorage flag
    // alone (it survives uninstall). Prefer showing install UI again.
    if (isMarkedInstalled()) {
      clearInstalledMark();
    }
    return false;
  }

  let waitingWorker = null;
  let refreshingForUpdate = false;

  function isBrokenAppShell() {
    return looksLikeBrokenHomescreenLaunch();
  }

  function migrateDismissedRecently() {
    try {
      if (sessionStorage.getItem(MIGRATE_DISMISS_KEY) === '1') {
        return true;
      }
    } catch (_) {}
    try {
      const dismissed = Number(localStorage.getItem('pwa-shell-replace-dismissed-at') || '0');
      return Boolean(dismissed && (Date.now() - dismissed) < 12 * 60 * 60 * 1000);
    } catch (_) {
      return false;
    }
  }

  function markMigrateDismissed() {
    try {
      sessionStorage.setItem(MIGRATE_DISMISS_KEY, '1');
    } catch (_) {}
    try {
      localStorage.setItem('pwa-shell-replace-dismissed-at', String(Date.now()));
    } catch (_) {}
  }

  function chromeIntentUrl(absoluteHttpsUrl) {
    try {
      const url = new URL(absoluteHttpsUrl);
      if (url.protocol !== 'https:') {
        return absoluteHttpsUrl;
      }
      return 'intent://' + url.host + url.pathname + url.search + url.hash
        + '#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='
        + encodeURIComponent(url.href)
        + ';end';
    } catch (_) {
      return absoluteHttpsUrl;
    }
  }

  function openHealthyWwwInstall() {
    const targetOrigin = canonicalInstallOrigin();
    if (!targetOrigin) {
      return false;
    }
    const target = targetOrigin + '/?install=1&migrate=1&from=apex';
    window.location.href = /Android/i.test(ua) ? chromeIntentUrl(target) : target;
    return true;
  }

  function showRemoveOldIconBanner() {
    if (document.getElementById('pwa-remove-old-banner')) {
      return;
    }
    const banner = document.createElement('div');
    banner.id = 'pwa-remove-old-banner';
    banner.className = 'pwa-update-banner pwa-update-banner--migrate is-visible';
    banner.setAttribute('role', 'status');
    banner.innerHTML =
      '<div class="pwa-update-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">delete</span>' +
      '<div class="pwa-update-banner__text">' +
      '<strong>الأيقونة الصحيحة جاهزة</strong>' +
      '<span>Chrome لا يدمج تطبيقين — اضغط مطولاً على الأيقونة القديمة (×) → إزالة، مرة واحدة على هذا الجهاز فقط</span>' +
      '</div>' +
      '<button type="button" class="pwa-update-banner__later" data-pwa-dismiss-old>حسناً</button>' +
      '</div>';
    banner.querySelector('[data-pwa-dismiss-old]')?.addEventListener('click', () => banner.remove());
    document.body.appendChild(banner);
  }

  /**
   * Old WebAPK (× Custom Tab) cannot be healed by Service Worker reload —
   * Android keeps a frozen launcher shell. Only prompt the broken cohort.
   */
  function showShellReplaceBanner(options) {
    const opts = options || {};
    if (document.getElementById('pwa-update-banner') || document.getElementById('pwa-shell-replace-banner')) {
      return;
    }
    const toWww = shouldMigrateToWwwSibling();
    const wwwHost = String(canonicalInstallOrigin()).replace(/^https?:\/\//i, '') || 'www';
    const subtitle = toWww
      ? 'تحديث الصفحة لا يغيّر الأيقونة ولا يزيل × — ثبّت التطبيق الموحّد من ' + wwwHost + ' ثم احذف القديمة مرة واحدة'
      : 'تحديث الصفحة لا يغيّر غلاف Chrome — ثبّت التطبيق الصحيح ثم احذف الأيقونة التالفة مرة واحدة';
    const btnLabel = toWww ? 'تثبيت الأيقونة الصحيحة' : (opts.hasSwUpdate || waitingWorker ? 'تحديث وإصلاح' : 'تثبيت الصحيح');
    const banner = document.createElement('div');
    banner.id = 'pwa-shell-replace-banner';
    banner.className = 'pwa-update-banner pwa-update-banner--migrate';
    banner.setAttribute('role', 'status');
    banner.innerHTML =
      '<div class="pwa-update-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>' +
      '<div class="pwa-update-banner__text">' +
      '<strong>أيقونة موحّدة</strong>' +
      '<span>' + subtitle + '</span>' +
      '</div>' +
      '<button type="button" class="pwa-update-banner__btn" data-pwa-replace-shell>' + btnLabel + '</button>' +
      '<button type="button" class="pwa-update-banner__later" data-pwa-dismiss-replace>لاحقاً</button>' +
      '</div>';
    banner.querySelector('[data-pwa-replace-shell]')?.addEventListener('click', replaceBrokenShell);
    banner.querySelector('[data-pwa-dismiss-replace]')?.addEventListener('click', () => {
      markMigrateDismissed();
      banner.remove();
    });
    document.body.appendChild(banner);
    window.requestAnimationFrame(() => banner.classList.add('is-visible'));
  }

  function openMigrateModal() {
    closeModal();
    const toWww = shouldMigrateToWwwSibling() || !isOnCanonicalInstallHost();
    const wwwHost = String(canonicalInstallOrigin()).replace(/^https?:\/\//i, '') || 'www';
    const steps = toWww
      ? [
          'اضغط «تثبيت الأيقونة الصحيحة» لفتح ' + wwwHost + ' في Chrome.',
          'من Chrome اختر «تثبيت التطبيق» / Install (الأيقونة الموحّدة).',
          'بعد النجاح: اضغط مطولاً على أيقونة جاويش القديمة (×) → إزالة — مرة واحدة على هذا الجهاز فقط.',
        ]
      : [
          'اضغط «تثبيت الآن» لتثبيت النسخة الموحّدة من www.',
          'بعد النجاح افتح الأيقونة الجديدة.',
          'احذف الأيقونة القديمة (إن بقيت) بضغط مطوّل → إزالة — مرة واحدة فقط.',
        ];
    const el = document.createElement('div');
    el.id = 'pwa-install-modal';
    el.className = 'pwa-install-modal';
    el.innerHTML =
      '<div class="pwa-install-modal__backdrop" data-pwa-close></div>' +
      '<div class="pwa-install-modal__card" role="dialog" aria-modal="true" aria-labelledby="pwa-migrate-title">' +
      '<button type="button" class="pwa-install-modal__close" data-pwa-close aria-label="إغلاق">×</button>' +
      '<div class="pwa-install-modal__icon" aria-hidden="true"><span class="material-symbols-outlined">build_circle</span></div>' +
      '<h2 id="pwa-migrate-title" class="pwa-install-modal__title">توحيد الأيقونة</h2>' +
      '<p class="pwa-install-modal__lead">Chrome لا يدمج تطبيقي www وapex ولا يغيّر أيقونة WebAPK عبر Service Worker. نثبّت من www فقط لهذه الأيقونة التالفة — بدون حذف جماعي لكل الزبائن.</p>' +
      '<ol class="pwa-install-modal__steps">' + steps.map((line) => '<li>' + line + '</li>').join('') + '</ol>' +
      (toWww
        ? '<button type="button" class="pwa-install-modal__btn" data-pwa-open-www>تثبيت الأيقونة الصحيحة (' + wwwHost + ')</button>'
        : (deferredPrompt
          ? '<button type="button" class="pwa-install-modal__btn" data-pwa-native-install>تثبيت الآن</button>'
          : '')) +
      '<button type="button" class="pwa-install-modal__ghost" data-pwa-close>لاحقاً</button>' +
      '</div>';
    el.querySelectorAll('[data-pwa-close]').forEach((node) => {
      node.addEventListener('click', () => {
        markMigrateDismissed();
        closeModal();
      });
    });
    el.querySelector('[data-pwa-open-www]')?.addEventListener('click', () => {
      openHealthyWwwInstall();
    });
    el.querySelector('[data-pwa-native-install]')?.addEventListener('click', () => {
      triggerNativeInstall();
    });
    modalEl = el;
    document.body.appendChild(modalEl);
    document.body.classList.add('pwa-modal-open');
  }

  async function replaceBrokenShell() {
    const btn = document.querySelector('[data-pwa-replace-shell], [data-pwa-apply-update]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'جاري التثبيت…';
    }

    clearInstalledMark();
    fetch('/manifest.php?heal=1', { cache: 'reload', credentials: 'same-origin' }).catch(() => {});

    // Apex / non-www → always open the single canonical www WebAPK install.
    // Block controllerchange reload so SKIP_WAITING cannot race the navigation.
    if (shouldMigrateToWwwSibling()) {
      refreshingForUpdate = true;
      if (waitingWorker) {
        try {
          waitingWorker.postMessage({ type: 'SKIP_WAITING' });
        } catch (_) {}
      }
      if (openHealthyWwwInstall()) {
        return;
      }
      refreshingForUpdate = false;
    }

    // On www: offer native install. SW reload alone cannot change × / launcher icon.
    if (waitingWorker) {
      try {
        waitingWorker.postMessage({ type: 'SKIP_WAITING' });
      } catch (_) {}
    }

    if (deferredPrompt) {
      const ok = await triggerNativeInstall();
      if (ok) {
        document.getElementById('pwa-shell-replace-banner')?.remove();
        document.getElementById('pwa-update-banner')?.remove();
        showRemoveOldIconBanner();
        return;
      }
    }

    openMigrateModal();
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'تثبيت الأيقونة الصحيحة';
    }
  }

  function maybePromptShellReplace() {
    if (isStandalone || isInAppBrowser || migrateDismissedRecently()) {
      return;
    }
    // Only the broken homescreen/CCT cohort — never mass-prompt browser tabs.
    if (isBrokenAppShell()) {
      showShellReplaceBanner({ hasSwUpdate: Boolean(waitingWorker) });
      maybeAutoMigrateBrokenShell();
    }
  }

  /** One automatic hop to www install for the broken apex CCT cohort only. */
  function maybeAutoMigrateBrokenShell() {
    if (!isBrokenAppShell() || !shouldMigrateToWwwSibling() || migrateDismissedRecently()) {
      return;
    }
    try {
      if (sessionStorage.getItem(AUTO_MIGRATE_KEY) === '1') {
        return;
      }
      sessionStorage.setItem(AUTO_MIGRATE_KEY, '1');
    } catch (_) {
      return;
    }
    window.setTimeout(() => {
      if (migrateDismissedRecently() || isStandalone) {
        return;
      }
      openHealthyWwwInstall();
    }, 1800);
  }

  function showUpdateBanner(worker) {
    waitingWorker = worker || waitingWorker;
    // Only the broken homescreen shell needs migrate — normal browser tabs keep SW update.
    if (isBrokenAppShell()) {
      maybePromptShellReplace();
      return;
    }
    if (!waitingWorker || document.getElementById('pwa-update-banner') || document.getElementById('pwa-shell-replace-banner')) {
      return;
    }
    const banner = document.createElement('div');
    banner.id = 'pwa-update-banner';
    banner.className = 'pwa-update-banner';
    banner.setAttribute('role', 'status');
    banner.innerHTML =
      '<div class="pwa-update-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">system_update</span>' +
      '<div class="pwa-update-banner__text">' +
      '<strong>يتوفر تحديث جديد</strong>' +
      '<span>حدّث الآن لتحصل على آخر تحسينات التطبيق</span>' +
      '</div>' +
      '<button type="button" class="pwa-update-banner__btn" data-pwa-apply-update>تحديث</button>' +
      '<button type="button" class="pwa-update-banner__later" data-pwa-dismiss-update>لاحقاً</button>' +
      '</div>';
    banner.querySelector('[data-pwa-apply-update]')?.addEventListener('click', applyWaitingUpdate);
    banner.querySelector('[data-pwa-dismiss-update]')?.addEventListener('click', () => {
      banner.remove();
    });
    document.body.appendChild(banner);
    window.requestAnimationFrame(() => banner.classList.add('is-visible'));
  }

  function applyWaitingUpdate() {
    if (isBrokenAppShell()) {
      replaceBrokenShell();
      return;
    }
    if (!waitingWorker) {
      window.location.reload();
      return;
    }
    const btn = document.querySelector('[data-pwa-apply-update]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'جاري التحديث…';
    }
    waitingWorker.postMessage({ type: 'SKIP_WAITING' });
  }

  function watchServiceWorkerUpdates(registration) {
    if (!registration) {
      return;
    }

    if (registration.waiting && navigator.serviceWorker.controller) {
      showUpdateBanner(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
      const installing = registration.installing;
      if (!installing) {
        return;
      }
      installing.addEventListener('statechange', () => {
        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateBanner(installing);
        }
      });
    });

    // On every app open / return to foreground, check for a new SW.
    const check = () => {
      registration.update().catch(() => {});
    };
    check();
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        check();
      }
    });
    window.setInterval(check, 15 * 60 * 1000);
  }

  function updateHeaderButtonMode(mode) {
    document.querySelectorAll('[data-pwa-open]').forEach((btn) => {
      btn.classList.remove('hidden', 'is-pwa-native', 'is-pwa-manual', 'is-pwa-http');
      btn.classList.add(
        mode === 'native' ? 'is-pwa-native'
          : mode === 'http' ? 'is-pwa-http'
            : 'is-pwa-manual'
      );
    });
  }

  async function triggerNativeInstall() {
    if (!deferredPrompt) {
      openModal(detectMode());
      return false;
    }
    try {
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      deferredPrompt = null;
      if (choice && choice.outcome === 'accepted') {
        markInstalled();
        closeModal();
        document.getElementById('pwa-install-banner')?.remove();
        return true;
      }
      syncInstallTriggers();
      return false;
    } catch (_) {
      openModal('manual');
      return false;
    }
  }

  function openHttpsUrl() {
    const host = window.location.hostname;
    window.location.href = 'https://' + host + window.location.pathname + window.location.search;
  }

  function buildModal(mode) {
    const effectiveMode = mode === 'native' && !deferredPrompt ? 'manual' : mode;
    const copy = copyForMode(effectiveMode);
    const stepsHtml = copy.steps.map((line) => '<li>' + line + '</li>').join('');
    const showNativeBtn = effectiveMode === 'native' && deferredPrompt && copy.button;
    const showHttpsBtn = effectiveMode === 'http';

    const el = document.createElement('div');
    el.id = 'pwa-install-modal';
    el.className = 'pwa-install-modal' + (effectiveMode === 'http' ? ' pwa-install-modal--http' : '');
    el.innerHTML =
      '<div class="pwa-install-modal__backdrop" data-pwa-close></div>' +
      '<div class="pwa-install-modal__card" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">' +
      '<button type="button" class="pwa-install-modal__close" data-pwa-close aria-label="إغلاق">×</button>' +
      '<div class="pwa-install-modal__icon" aria-hidden="true"><span class="material-symbols-outlined">install_mobile</span></div>' +
      '<h2 id="pwa-install-title" class="pwa-install-modal__title">' + copy.title + '</h2>' +
      '<ol class="pwa-install-modal__steps">' + stepsHtml + '</ol>' +
      (showNativeBtn
        ? '<button type="button" class="pwa-install-modal__btn" data-pwa-native-install>' + copy.button + '</button>'
        : '') +
      (showHttpsBtn
        ? '<button type="button" class="pwa-install-modal__btn pwa-install-modal__btn--warn" data-pwa-open-https>فتح https://</button>'
        : '') +
      '<button type="button" class="pwa-install-modal__ghost" data-pwa-close>لاحقاً</button>' +
      '</div>';

    el.querySelectorAll('[data-pwa-close]').forEach((node) => {
      node.addEventListener('click', closeModal);
    });

    el.querySelector('[data-pwa-native-install]')?.addEventListener('click', () => {
      triggerNativeInstall();
    });

    el.querySelector('[data-pwa-open-https]')?.addEventListener('click', openHttpsUrl);

    return el;
  }

  function openModal(forceMode) {
    // Allow install instructions when replacing a broken old shell or migrate=1.
    if (isStandalone) {
      return;
    }
    const params = new URLSearchParams(window.location.search);
    const migrating = params.get('migrate') === '1' || params.get('install') === '1';
    if (isMarkedInstalled() && !isBrokenAppShell() && !migrating && !document.getElementById('pwa-shell-replace-banner')) {
      return;
    }
    closeModal();
    const mode = forceMode || detectMode();
    modalEl = buildModal(mode);
    document.body.appendChild(modalEl);
    document.body.classList.add('pwa-modal-open');
  }

  function closeModal() {
    if (modalEl) {
      modalEl.remove();
      modalEl = null;
    }
    document.body.classList.remove('pwa-modal-open');
  }

  function showAutoBanner() {
    // Public-site install + notification consent uses the unified banner in notifications.js.
    return;
  }

  function bindTriggers() {
    document.querySelectorAll('[data-pwa-open]').forEach((btn) => {
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const mode = detectMode();
        if (mode === 'native' && deferredPrompt) {
          await triggerNativeInstall();
          return;
        }
        if (mode === 'http') {
          openHttpsUrl();
          return;
        }
        openModal(mode);
      });
    });
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !isSecure) {
      return Promise.resolve(null);
    }

    if (!refreshingForUpdate) {
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (refreshingForUpdate) {
          return;
        }
        refreshingForUpdate = true;
        window.location.reload();
      });
    }

    const register = () => navigator.serviceWorker
      .register('/sw.js?v=17', { scope: '/', updateViaCache: 'none' })
      .then((registration) => {
        watchServiceWorkerUpdates(registration);
        return registration;
      })
      .catch(() => null);
    // Registration competes with first paint on slow phones; wait until the page has loaded.
    if (document.readyState === 'complete') {
      return register();
    }
    return new Promise((resolve) => {
      window.addEventListener('load', () => resolve(register()), { once: true });
    });
  }

  function markEngagementReady() {
    if (engagementReady) {
      return;
    }
    engagementReady = true;
    window.setTimeout(maybeShowEngagementBanner, 1500);
  }

  function maybeShowEngagementBanner() {
    if (deferredPrompt || isStandalone || isMarkedInstalled() || autoDismissedRecently()) {
      return;
    }
    if (document.getElementById('pwa-install-banner')) {
      return;
    }
    if (!engagementReady && (Date.now() - pageLoadedAt) < ENGAGE_MS) {
      return;
    }
    showAutoBanner();
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    if (isStandalone) {
      return;
    }
    // Apex must never become a second installable app — send BIP traffic to www.
    if (!isOnCanonicalInstallHost()) {
      event.preventDefault();
      deferredPrompt = null;
      hideInstallTriggers();
      if (isBrokenAppShell()) {
        maybePromptShellReplace();
      }
      return;
    }
    // Chrome only fires this when the app is not installed — clear stale flag
    // left from a previous install after the user deleted the PWA.
    clearInstalledMark();
    event.preventDefault();
    deferredPrompt = event;
    updateHeaderButtonMode('native');
    // Broken shell on www: keep migrate/install primary.
    if (isBrokenAppShell()) {
      maybePromptShellReplace();
      return;
    }
    showInstallTriggers();
    if (!autoDismissedRecently()) {
      document.getElementById('pwa-install-banner')?.remove();
    }
    const params = new URLSearchParams(window.location.search);
    if (params.get('migrate') === '1' || params.get('install') === '1') {
      window.setTimeout(() => {
        triggerNativeInstall();
      }, 400);
    }
  });

  window.addEventListener('appinstalled', () => {
    markInstalled();
    document.getElementById('pwa-shell-replace-banner')?.remove();
    document.getElementById('pwa-update-banner')?.remove();
    const params = new URLSearchParams(window.location.search);
    if (params.get('migrate') === '1' || params.get('from') === 'apex') {
      showRemoveOldIconBanner();
    }
  });

  function syncInstallTriggers() {
    if (canInstall()) {
      updateHeaderButtonMode(detectMode());
      showInstallTriggers();
    } else {
      hideInstallTriggers();
    }
  }

  async function init() {
    registerServiceWorker();
    const installed = await detectInstalledApp();
    if (isStandalone) {
      hideInstallTriggers();
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const migratingIn = params.get('migrate') === '1';

    // Non-www hosts: never advertise a separate install — only repair broken shells.
    if (!isOnCanonicalInstallHost()) {
      hideInstallTriggers();
      if (isBrokenAppShell()) {
        maybePromptShellReplace();
      }
      return;
    }

    // Old launcher icon / × bar: SW "update" cannot change Chrome's shell.
    if (isBrokenAppShell()) {
      hideInstallTriggers();
      maybePromptShellReplace();
      return;
    }
    if (installed && !migratingIn) {
      hideInstallTriggers();
      return;
    }

    bindTriggers();
    syncInstallTriggers();

    if (params.get('install') === '1' || migratingIn || window.location.pathname.endsWith('/install.php')) {
      window.setTimeout(() => {
        if (deferredPrompt) {
          triggerNativeInstall();
        } else {
          openModal();
        }
      }, 600);
    }

    ['click', 'scroll', 'keydown', 'touchstart'].forEach((evt) => {
      document.addEventListener(evt, markEngagementReady, { once: true, passive: true });
    });

    window.setTimeout(markEngagementReady, ENGAGE_MS);
  }

  window.PortalPwa = {
    open: openModal,
    close: closeModal,
    install: triggerNativeInstall,
    applyUpdate: applyWaitingUpdate,
    replaceBrokenShell,
    shouldPromptInstall: () => !isStandalone && isOnCanonicalInstallHost() && !isMarkedInstalled() && canInstall(),
    canInstall,
    isInAppBrowser: () => isInAppBrowser,
    isBrokenAppShell: () => isBrokenAppShell(),
    isCanonicalInstallHost: () => isOnCanonicalInstallHost(),
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
