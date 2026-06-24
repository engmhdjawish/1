/**
 * PWA install — header button + modal + optional auto banner.
 */
(function () {
  'use strict';

  const AUTO_DISMISS_KEY = 'pwa-auto-dismissed-at';
  const AUTO_DISMISS_DAYS = 3;

  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isAndroid = /android/i.test(navigator.userAgent);
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
        title: 'تثبيت التطبيق على الهاتف',
        steps: [
          'على الشبكة المحلية (HTTP) لا يظهر زر التثبيت التلقائي في Chrome.',
          'من Chrome: القائمة ⋮ → «إضافة إلى الشاشة الرئيسية» أو «تثبيت التطبيق».',
          'للتثبيت التلقائي مع زر واحد، افتح الموقع عبر HTTPS (دومين حقيقي).',
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

  function buildModal(mode) {
    const copy = copyForMode(mode);
    const stepsHtml = copy.steps.map((line) => '<li>' + line + '</li>').join('');

    const el = document.createElement('div');
    el.id = 'pwa-install-modal';
    el.className = 'pwa-install-modal';
    el.innerHTML =
      '<div class="pwa-install-modal__backdrop" data-pwa-close></div>' +
      '<div class="pwa-install-modal__card" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">' +
      '<button type="button" class="pwa-install-modal__close" data-pwa-close aria-label="إغلاق">×</button>' +
      '<div class="pwa-install-modal__icon" aria-hidden="true"><span class="material-symbols-outlined">install_mobile</span></div>' +
      '<h2 id="pwa-install-title" class="pwa-install-modal__title">' + copy.title + '</h2>' +
      '<ol class="pwa-install-modal__steps">' + stepsHtml + '</ol>' +
      (copy.button
        ? '<button type="button" class="pwa-install-modal__btn" data-pwa-native-install>' + copy.button + '</button>'
        : '') +
      '<button type="button" class="pwa-install-modal__ghost" data-pwa-close>لاحقاً</button>' +
      '</div>';

    el.querySelectorAll('[data-pwa-close]').forEach((node) => {
      node.addEventListener('click', closeModal);
    });

    el.querySelector('[data-pwa-native-install]')?.addEventListener('click', async () => {
      if (!deferredPrompt) {
        return;
      }
      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      closeModal();
    });

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
    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner';
    banner.innerHTML =
      '<div class="pwa-install-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>' +
      '<div class="pwa-install-banner__text">' +
      '<strong>' + copy.title + '</strong>' +
      '<span>' + (copy.steps[0] || '') + '</span>' +
      '</div>' +
      '<button type="button" class="pwa-install-banner__btn" data-pwa-banner-open>كيف؟</button>' +
      '<button type="button" class="pwa-install-banner__close" data-pwa-banner-close aria-label="إغلاق">×</button>' +
      '</div>';

    banner.querySelector('[data-pwa-banner-open]')?.addEventListener('click', () => openModal(mode));
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
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        openModal();
      });
    });
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    if (!autoDismissedRecently() && !isStandalone) {
      showAutoBanner();
    }
  });

  if ('serviceWorker' in navigator && isSecure) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
  }

  function init() {
    if (isStandalone) {
      hideInstallTriggers();
      return;
    }

    bindTriggers();

    window.setTimeout(() => {
      if (!document.getElementById('pwa-install-banner')) {
        showAutoBanner();
      }
    }, 1200);
  }

  window.PortalPwa = { open: openModal, close: closeModal };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
