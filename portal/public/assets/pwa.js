/**
 * PWA install prompt + service worker + manual install hints (iOS / HTTP LAN).
 */
(function () {
  'use strict';

  const DISMISS_KEY = 'pwa-install-dismissed';
  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isAndroid = /android/i.test(navigator.userAgent);
  const isMobile = window.matchMedia('(max-width: 900px)').matches || isIOS || isAndroid;
  const isSecure = window.isSecureContext === true;

  let deferredPrompt = null;

  function isDismissed() {
    try {
      return sessionStorage.getItem(DISMISS_KEY) === '1';
    } catch {
      return false;
    }
  }

  function dismissBanner() {
    const banner = document.getElementById('pwa-install-banner');
    if (banner) banner.remove();
    try {
      sessionStorage.setItem(DISMISS_KEY, '1');
    } catch (_) {}
  }

  function bannerContent(mode) {
    if (mode === 'ios') {
      return {
        title: 'أضف الموقع للشاشة الرئيسية',
        hint: 'من Safari: زر المشاركة ↗ ثم «إضافة إلى الشاشة الرئيسية»',
        button: null,
      };
    }
    if (mode === 'http') {
      return {
        title: 'تثبيت التطبيق',
        hint: 'للتثبيت التلقائي استخدم HTTPS. على الشبكة المحلية يمكن الإضافة يدوياً من قائمة المتصفح.',
        button: null,
      };
    }
    return {
      title: 'ثبّت التطبيق على هاتفك',
      hint: 'وصول أسرع للمتجر من الشاشة الرئيسية',
      button: 'تثبيت',
    };
  }

  function showInstallBanner(mode) {
    if (document.getElementById('pwa-install-banner') || isStandalone || isDismissed()) {
      return;
    }

    const copy = bannerContent(mode);
    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner';
    banner.innerHTML =
      '<div class="pwa-install-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>' +
      '<div class="pwa-install-banner__text">' +
      '<strong>' + copy.title + '</strong>' +
      '<span>' + copy.hint + '</span>' +
      '</div>' +
      (copy.button
        ? '<button type="button" class="pwa-install-banner__btn" data-pwa-install>' + copy.button + '</button>'
        : '') +
      '<button type="button" class="pwa-install-banner__close" data-pwa-dismiss aria-label="إغلاق">×</button>' +
      '</div>';

    document.body.appendChild(banner);

    banner.querySelector('[data-pwa-install]')?.addEventListener('click', async () => {
      if (!deferredPrompt) {
        return;
      }
      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      dismissBanner();
    });

    banner.querySelector('[data-pwa-dismiss]')?.addEventListener('click', dismissBanner);
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    showInstallBanner('native');
  });

  if ('serviceWorker' in navigator && isSecure) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
  }

  if (!isDismissed() && !isStandalone && isMobile) {
    window.setTimeout(() => {
      if (document.getElementById('pwa-install-banner')) {
        return;
      }
      if (deferredPrompt) {
        showInstallBanner('native');
        return;
      }
      if (isIOS) {
        showInstallBanner('ios');
        return;
      }
      if (!isSecure) {
        showInstallBanner('http');
        return;
      }
      showInstallBanner('native');
    }, 2200);
  }
})();
