/**
 * Material ZIP download — availability tabs, filter summary, split validation.
 */
(function () {
  'use strict';

  const AVAILABILITY_STORAGE_KEY = 'dash-mi-zip-availability';

  const SPLIT_CONFIG = {
    materialTypes: { pickerId: 'mid-material-types', label: 'نوع المادة' },
    ageCategories: { pickerId: 'mid-age-categories', label: 'الفئة العمرية' },
    manufacturers: { pickerId: 'mid-manufacturers', label: 'الشركة المصنعة' },
    sizeRanges: { pickerId: 'mid-size-ranges', label: 'القياس' },
    countryOfOrigins: { pickerId: 'mid-country-origins', label: 'بلد المنشأ' },
    storeGuids: { pickerId: 'mid-store-guids', label: 'المخزن' },
    groupGuids: { pickerId: 'mid-group-guids', label: 'المجموعة' },
  };

  const AVAILABILITY_LABELS = {
    '': 'بدون قيد',
    '1': 'متوفر',
    '0': 'غير متوفر',
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

  function readStoredAvailability() {
    try {
      const stored = localStorage.getItem(AVAILABILITY_STORAGE_KEY);
      if (stored === '' || stored === '1' || stored === '0') {
        return stored;
      }
    } catch {
      /* ignore */
    }
    return '1';
  }

  function writeStoredAvailability(value) {
    try {
      localStorage.setItem(AVAILABILITY_STORAGE_KEY, value);
    } catch {
      /* ignore */
    }
  }

  function countPickerSelections(pickerId) {
    if (typeof window.portalTokenPickerGetSelected !== 'function') {
      return 0;
    }
    const values = window.portalTokenPickerGetSelected(pickerId);
    return Array.isArray(values) ? values.length : 0;
  }

  function updateFilterSummary(form) {
    const summaryEl = form.querySelector('[data-zip-filter-summary]');
    if (!summaryEl) {
      return;
    }

    const parts = [];
    const search = form.querySelector('input[name="search"]')?.value.trim();
    if (search) {
      parts.push('بحث: «' + search + '»');
    }

    const availabilityInput = form.querySelector('[data-zip-availability-input]');
    const availability = availabilityInput instanceof HTMLInputElement ? availabilityInput.value : '1';
    parts.push('التوفر: ' + (AVAILABILITY_LABELS[availability] || 'متوفر'));

    const pickerCounts = [
      ['mid-material-types', 'أنواع'],
      ['mid-age-categories', 'فئات'],
      ['mid-manufacturers', 'مصنّعين'],
      ['mid-size-ranges', 'قياسات'],
      ['mid-country-origins', 'بلدان'],
      ['mid-store-guids', 'مخازن'],
      ['mid-group-guids', 'مجموعات'],
    ];
    pickerCounts.forEach(([id, label]) => {
      const count = countPickerSelections(id);
      if (count > 0) {
        parts.push(label + ': ' + count);
      }
    });

    const splitKey = form.querySelector('[data-zip-split-by]')?.value || '';
    if (splitKey && SPLIT_CONFIG[splitKey]) {
      parts.push('تقسيم: ' + SPLIT_CONFIG[splitKey].label);
    }

    summaryEl.textContent = parts.length
      ? 'الفلاتر النشطة: ' + parts.join(' · ')
      : 'الفلاتر النشطة: التوفر = متوفر (افتراضي)';
  }

  function bindAvailabilityTabs(form) {
    const tabsRoot = form.querySelector('[data-zip-availability-tabs]');
    const input = form.querySelector('[data-zip-availability-input]');
    if (!(tabsRoot instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
      return;
    }

    const applyAvailability = (value) => {
      input.value = value;
      tabsRoot.querySelectorAll('[data-availability]').forEach((btn) => {
        if (!(btn instanceof HTMLButtonElement)) {
          return;
        }
        const active = (btn.getAttribute('data-availability') ?? '') === value;
        btn.classList.toggle('is-active', active);
      });
      writeStoredAvailability(value);
      updateFilterSummary(form);
    };

    const stored = readStoredAvailability();
    applyAvailability(stored);

    tabsRoot.addEventListener('click', (event) => {
      const btn = event.target instanceof HTMLElement
        ? event.target.closest('[data-availability]')
        : null;
      if (!(btn instanceof HTMLButtonElement)) {
        return;
      }
      event.preventDefault();
      applyAvailability(btn.getAttribute('data-availability') ?? '');
    });
  }

  const initForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const splitSelect = form.querySelector('[data-zip-split-by]');

    bindAvailabilityTabs(form);
    updateFilterSummary(form);

    form.addEventListener('input', () => updateFilterSummary(form));
    form.addEventListener('change', () => updateFilterSummary(form));
    form.addEventListener('click', (event) => {
      const target = event.target;
      if (target instanceof HTMLElement && target.closest('.token-picker, [data-zip-split-by]')) {
        window.setTimeout(() => updateFilterSummary(form), 0);
      }
    });

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
