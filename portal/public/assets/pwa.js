/**
 * PWA install — header button + modal + optional auto banner.
 */
(function () {
  'use strict';

  const AUTO_DISMISS_KEY = 'pwa-auto-dismissed-at';
  const AUTO_DISMISS_DAYS = 3;
  const ENGAGE_MS = 28000;
  const pageLoadedAt = Date.now();
  let engagementReady = false;

  (function redirectHttpToHttps() {
    const host = window.location.hostname;
    if (window.location.protocol === 'http:' && host !== 'localhost' && host !== '127.0.0.1') {
      window.location.replace(
        'https://' + host + window.location.pathname + window.location.search + window.location.hash
      );
    }
  })();

  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isSecure = window.isSecureContext === true;

  let deferredPrompt = null;
  let modalEl = null;

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
          'افتح الموقع من متصفح Safari (ليس Chrome).',
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
    });
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
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      closeModal();
      document.getElementById('pwa-install-banner')?.remove();
      return true;
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
    if (isStandalone) {
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
    if (isStandalone || autoDismissedRecently() || document.getElementById('pwa-install-banner')) {
      return;
    }

    const mode = detectMode();
    const copy = copyForMode(mode);
    const canNativeInstall = mode === 'native' && deferredPrompt;
    const primaryLabel = canNativeInstall
      ? 'تثبيت الآن'
      : mode === 'http'
        ? 'فتح HTTPS'
        : 'التعليمات';

    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner' + (mode === 'http' ? ' pwa-install-banner--http' : '');
    banner.innerHTML =
      '<div class="pwa-install-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>' +
      '<div class="pwa-install-banner__text">' +
      '<strong>' + copy.title + '</strong>' +
      '<span>' + (copy.steps[0] || '') + '</span>' +
      '</div>' +
      '<button type="button" class="pwa-install-banner__btn" data-pwa-banner-primary>' + primaryLabel + '</button>' +
      '<button type="button" class="pwa-install-banner__close" data-pwa-banner-close aria-label="إغلاق">×</button>' +
      '</div>';

    banner.querySelector('[data-pwa-banner-primary]')?.addEventListener('click', async () => {
      if (canNativeInstall) {
        await triggerNativeInstall();
        return;
      }
      if (mode === 'http') {
        openHttpsUrl();
        return;
      }
      openModal(mode);
    });

    banner.querySelector('[data-pwa-banner-close]')?.addEventListener('click', () => {
      markAutoDismissed();
      banner.remove();
    });

    document.body.appendChild(banner);
    requestAnimationFrame(() => {
      banner.classList.add('is-visible');
    });
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
    return navigator.serviceWorker.getRegistration('/').then((existing) => {
      if (existing) {
        return existing;
      }
      return navigator.serviceWorker.register('/sw.js?v=5', { scope: '/', updateViaCache: 'none' });
    }).catch(() => null);
  }

  function markEngagementReady() {
    if (engagementReady) {
      return;
    }
    engagementReady = true;
    window.setTimeout(maybeShowEngagementBanner, 1500);
  }

  function maybeShowEngagementBanner() {
    if (deferredPrompt || isStandalone || autoDismissedRecently()) {
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
    event.preventDefault();
    deferredPrompt = event;
    updateHeaderButtonMode('native');
    if (!autoDismissedRecently() && !isStandalone) {
      document.getElementById('pwa-install-banner')?.remove();
      showAutoBanner();
    }
    document.querySelectorAll('[data-pwa-open]').forEach((btn) => {
      btn.classList.remove('hidden');
    });
  });

  function init() {
    if (isStandalone) {
      hideInstallTriggers();
      return;
    }

    updateHeaderButtonMode(detectMode());
    bindTriggers();
    registerServiceWorker();

    ['click', 'scroll', 'keydown', 'touchstart'].forEach((evt) => {
      document.addEventListener(evt, markEngagementReady, { once: true, passive: true });
    });

    window.setTimeout(markEngagementReady, ENGAGE_MS);
  }

  window.PortalPwa = { open: openModal, close: closeModal, install: triggerNativeInstall };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
