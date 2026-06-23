/**
 * Material image ZIP download — split-by-filter multi-file downloads.
 */
(function () {
  'use strict';

  const SPLIT_CONFIG = {
    materialTypes: { pickerId: 'mid-material-types', field: 'materialTypes[]', label: 'نوع-المادة' },
    ageCategories: { pickerId: 'mid-age-categories', field: 'ageCategories[]', label: 'الفئة-العمرية' },
    manufacturers: { pickerId: 'mid-manufacturers', field: 'manufacturers[]', label: 'الشركة' },
    sizeRanges: { pickerId: 'mid-size-ranges', field: 'sizeRanges[]', label: 'القياس' },
    countryOfOrigins: { pickerId: 'mid-country-origins', field: 'countryOfOrigins[]', label: 'بلد-المنشأ' },
    storeGuids: { pickerId: 'mid-store-guids', field: 'storeGuids[]', label: 'مخزن' },
    groupGuids: { pickerId: 'mid-group-guids', field: 'groupGuids[]', label: 'مجموعة' },
  };

  const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  const sanitizeSlug = (value) => {
    return (value || '')
      .toString()
      .trim()
      .replace(/[^\p{L}\p{N}\-_.]+/gu, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '')
      .slice(0, 48) || 'قيمة';
  };

  const getPickerLabel = (pickerId, value) => {
    const picker = document.querySelector(`[data-picker-id="${pickerId}"]`);
    if (!picker) {
      return value;
    }
    try {
      const labels = JSON.parse(picker.querySelector('script[data-role="option-labels"]')?.textContent || '[]');
      const match = Array.isArray(labels) ? labels.find((item) => item && item.value === value) : null;
      return match?.label || value;
    } catch (_) {
      return value;
    }
  };

  const buildSplitUrl = (form, splitKey, splitValue, splitLabel) => {
    const params = new URLSearchParams();
    params.set('mode', 'materials');

    const splitField = SPLIT_CONFIG[splitKey]?.field;
    const elements = form.querySelectorAll('input, select, textarea');
    elements.forEach((element) => {
      if (!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement)) {
        return;
      }
      if (!element.name || element.disabled) {
        return;
      }
      if (element.type === 'submit' || element.type === 'button') {
        return;
      }
      if (splitField && element.name === splitField) {
        return;
      }
      if (element instanceof HTMLInputElement && (element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
        return;
      }
      params.append(element.name, element.value);
    });

    if (splitField) {
      params.append(splitField, splitValue);
    }

    const baseLabel = SPLIT_CONFIG[splitKey]?.label || 'تقسيم';
    params.set('archiveName', sanitizeSlug('صور-' + baseLabel + '-' + splitLabel));

    return '/api/material-images-zip.php?' + params.toString();
  };

  const triggerDownload = (url) => {
    const frame = document.createElement('iframe');
    frame.style.display = 'none';
    frame.setAttribute('aria-hidden', 'true');
    frame.src = url;
    document.body.appendChild(frame);
    window.setTimeout(() => frame.remove(), 120000);
  };

  const showStatus = (host, message, tone) => {
    if (!host) {
      return;
    }
    host.textContent = message;
    host.classList.remove('hidden', 'text-emerald-700', 'text-amber-700', 'text-red-700', 'bg-emerald-50', 'bg-amber-50', 'bg-red-50', 'border-emerald-200', 'border-amber-200', 'border-red-200');
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

    form.addEventListener('submit', async (event) => {
      const splitKey = splitSelect?.value || '';
      if (!splitKey || !SPLIT_CONFIG[splitKey]) {
        return;
      }

      event.preventDefault();
      const config = SPLIT_CONFIG[splitKey];
      const values = window.portalTokenPickerGetSelected
        ? window.portalTokenPickerGetSelected(config.pickerId)
        : [];

      if (!Array.isArray(values) || values.length === 0) {
        showStatus(statusHost, 'اختر قيمة واحدة على الأقل في فلتر «' + (splitSelect?.selectedOptions?.[0]?.textContent || '') + '» للتقسيم.', 'error');
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
      }

      showStatus(statusHost, 'جاري بدء تحميل ' + values.length + ' ملف ZIP...', 'progress');

      for (let index = 0; index < values.length; index += 1) {
        const value = values[index];
        const label = getPickerLabel(config.pickerId, value);
        showStatus(
          statusHost,
          'تحميل ' + (index + 1) + ' من ' + values.length + ': ' + label,
          'progress'
        );
        triggerDownload(buildSplitUrl(form, splitKey, value, label));
        if (index < values.length - 1) {
          await sleep(1200);
        }
      }

      showStatus(
        statusHost,
        'تم إرسال ' + values.length + ' طلب تحميل. راقب شريط التحميل في المتصفح (أسفل الشاشة).',
        'success'
      );

      if (submitBtn) {
        submitBtn.disabled = false;
      }
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
