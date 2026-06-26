/**
 * Site media library page — AJAX upload without full-page redirect.
 */
(function () {
  'use strict';

  const bindUploadForm = (root = document) => {
    const form = root.querySelector('#site-media-upload-form');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    const status = document.getElementById('site-media-upload-status');
    const submitBtn = form.querySelector('[data-site-media-submit]');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.uploading === '1') return;
      form.dataset.uploading = '1';
      if (status) status.textContent = 'جاري الرفع...';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-70');
      }

      const formData = new FormData(form);
      formData.append('action', 'upload');

      try {
        const response = await fetch('/dashboard/site-media-api.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const raw = await response.text();
        let data = {};
        try {
          data = raw ? JSON.parse(raw) : {};
        } catch (_) {
          throw new Error('تم الرفع لكن الاستجابة غير متوقعة. أعد تحميل الصفحة للتأكد.');
        }

        if (!response.ok || !data.ok) {
          throw new Error(data.message || 'تعذر رفع الصورة.');
        }

        if (status) status.textContent = data.message || 'تم رفع الصورة.';
        if (window.dashboardApp?.showToast) {
          window.dashboardApp.showToast(data.message || 'تم رفع الصورة.', 'success');
        }
        form.reset();
        const category = form.querySelector('[name="category"]')?.value || '';
        const target = category
          ? '/dashboard/site-media.php?category=' + encodeURIComponent(category)
          : '/dashboard/site-media.php';
        if (typeof window.dashboardApp?.navigate === 'function') {
          await window.dashboardApp.navigate(target);
        } else {
          window.location.assign(target);
        }
      } catch (error) {
        const message = error instanceof Error ? error.message : 'تعذر رفع الصورة.';
        if (status) status.textContent = message;
        if (window.dashboardApp?.showToast) {
          window.dashboardApp.showToast(message, 'error');
        }
      } finally {
        delete form.dataset.uploading;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.classList.remove('opacity-70');
        }
      }
    });
  };

  window.portalSiteMediaInit = function portalSiteMediaInit(root) {
    bindUploadForm(root || document);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalSiteMediaInit());
  } else {
    window.portalSiteMediaInit();
  }
})();
