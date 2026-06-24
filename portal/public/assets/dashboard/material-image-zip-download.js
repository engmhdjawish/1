/**
 * Material ZIP split — validate chips before normal form submit (server builds split archive).
 */
(function () {
  'use strict';

  const SPLIT_CONFIG = {
    materialTypes: { pickerId: 'mid-material-types', label: 'نوع المادة' },
    ageCategories: { pickerId: 'mid-age-categories', label: 'الفئة العمرية' },
    manufacturers: { pickerId: 'mid-manufacturers', label: 'الشركة المصنعة' },
    sizeRanges: { pickerId: 'mid-size-ranges', label: 'القياس' },
    countryOfOrigins: { pickerId: 'mid-country-origins', label: 'بلد المنشأ' },
    storeGuids: { pickerId: 'mid-store-guids', label: 'المخزن' },
    groupGuids: { pickerId: 'mid-group-guids', label: 'المجموعة' },
  };

  const showStatus = (host, message, tone) => {
    if (!host) {
      return;
    }
    host.classList.remove('hidden', 'text-emerald-700', 'text-amber-700', 'text-red-700', 'bg-emerald-50', 'bg-amber-50', 'bg-red-50', 'border-emerald-200', 'border-amber-200', 'border-red-200');
    host.textContent = message;
    if (tone === 'success') {
      host.classList.add('text-emerald-700', 'bg-emerald-50', 'border-emerald-200');
    } else if (tone === 'error') {
      host.classList.add('text-red-700', 'bg-red-50', 'border-red-200');
    } else {
      host.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-200');
    }
  };

  const initForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const splitSelect = form.querySelector('[data-zip-split-by]');

    form.addEventListener('submit', (event) => {
      const splitKey = splitSelect?.value || '';
      if (!splitKey || !SPLIT_CONFIG[splitKey]) {
        showStatus(statusHost, '', 'success');
        if (statusHost) {
          statusHost.classList.add('hidden');
        }
        return;
      }

      const config = SPLIT_CONFIG[splitKey];
      const values = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected(config.pickerId)
        : [];

      if (!Array.isArray(values) || values.length === 0) {
        event.preventDefault();
        showStatus(
          statusHost,
          'للتقسيم: أضف تشيباً واحداً على الأقل في فلتر «' + config.label + '».',
          'error'
        );
        return;
      }

      showStatus(
        statusHost,
        'جاري تجهيز أرشيف يحتوي ' + values.length + ' ملف ZIP داخلي...',
        'progress'
      );
    });
  };

  window.portalMaterialZipDownloadInit = (root = document) => {
    root.querySelectorAll('[data-material-zip-form]').forEach((form) => initForm(form));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalMaterialZipDownloadInit(document));
  } else {
    window.portalMaterialZipDownloadInit(document);
  }
})();
