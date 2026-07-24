/**
 * Material ZIP download — store-style filters, validation, availability persistence.
 */
(function () {
  'use strict';

  const AVAILABILITY_STORAGE_KEY = 'dash-mi-zip-availability';

  const FILTER_PARAMS = {
    materialTypes: { param: 'materialTypes[]', label: 'نوع المادة' },
    ageCategories: { param: 'ageCategories[]', label: 'الفئة العمرية' },
    manufacturers: { param: 'manufacturers[]', label: 'الشركة المصنعة' },
    sizeRanges: { param: 'sizeRanges[]', label: 'القياس' },
    countryOfOrigins: { param: 'countryOfOrigins[]', label: 'بلد المنشأ' },
    storeGuids: { param: 'storeGuids[]', label: 'المخزن' },
    groupGuids: { param: 'groupGuids[]', label: 'المجموعة' },
  };

  const SPLIT_CONFIG = {
    materialTypes: { param: 'materialTypes[]', label: 'نوع المادة' },
    ageCategories: { param: 'ageCategories[]', label: 'الفئة العمرية' },
    manufacturers: { param: 'manufacturers[]', label: 'الشركة المصنعة' },
    sizeRanges: { param: 'sizeRanges[]', label: 'القياس' },
    countryOfOrigins: { param: 'countryOfOrigins[]', label: 'بلد المنشأ' },
    storeGuids: { param: 'storeGuids[]', label: 'المخزن' },
    groupGuids: { param: 'groupGuids[]', label: 'المجموعة' },
  };

  const AVAILABILITY_LABELS = {
    '': 'الكل',
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

  function countChecked(form, paramName) {
    return form.querySelectorAll(`input[name="${paramName}"]:checked`).length;
  }

  function hasNarrowingFilter(form) {
    const search = form.querySelector('input[name="search"]')?.value.trim();
    if (search) {
      return true;
    }
    const minQty = form.querySelector('input[name="minWarehouseQuantity"]')?.value.trim();
    const maxQty = form.querySelector('input[name="maxWarehouseQuantity"]')?.value.trim();
    if (minQty || maxQty) {
      return true;
    }
    return Object.values(FILTER_PARAMS).some(({ param }) => countChecked(form, param) > 0);
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

    const availabilityInput = form.querySelector('input[name="isAvailable"]:checked');
    const availability = availabilityInput instanceof HTMLInputElement ? availabilityInput.value : '1';
    parts.push('التوفر: ' + (AVAILABILITY_LABELS[availability] || 'متوفر'));

    Object.entries(FILTER_PARAMS).forEach(([key, config]) => {
      const count = countChecked(form, config.param);
      if (count > 0) {
        parts.push(config.label + ': ' + count);
      }
      void key;
    });

    const splitKey = form.querySelector('[data-zip-split-by]')?.value || '';
    if (splitKey && SPLIT_CONFIG[splitKey]) {
      parts.push('تقسيم: ' + SPLIT_CONFIG[splitKey].label);
    }

    const minQty = form.querySelector('input[name="minWarehouseQuantity"]')?.value.trim();
    const maxQty = form.querySelector('input[name="maxWarehouseQuantity"]')?.value.trim();
    if (minQty) {
      parts.push('مخزون ≥ ' + minQty);
    }
    if (maxQty) {
      parts.push('مخزون ≤ ' + maxQty);
    }

    summaryEl.textContent = parts.length
      ? parts.join(' · ')
      : 'حدّد فلتراً (بحث، نوع، شركة، …) قبل التحميل';

    summaryEl.classList.toggle('dash-mi-zip-summary--warn', !hasNarrowingFilter(form));
  }

  function bindAvailabilityPersistence(form) {
    const shell = form.closest('[data-store-filters-root]');
    const defaultValue = shell?.getAttribute('data-store-filters-default-availability') || '1';

    const applyAvailability = (value) => {
      form.querySelectorAll('input[name="isAvailable"]').forEach((node) => {
        if (node instanceof HTMLInputElement) {
          node.checked = node.value === value;
        }
      });
      writeStoredAvailability(value);
      updateFilterSummary(form);
      if (typeof window.portalStoreFiltersRefreshPending === 'function') {
        window.portalStoreFiltersRefreshPending();
      }
    };

    applyAvailability(readStoredAvailability() || defaultValue);

    form.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || target.name !== 'isAvailable') {
        return;
      }
      writeStoredAvailability(target.value);
      updateFilterSummary(form);
    });
  }

  const initForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const splitSelect = form.querySelector('[data-zip-split-by]');
    const filtersShell = form.closest('[data-store-filters-root]');

    if (filtersShell && typeof window.portalStoreFiltersInit === 'function') {
      window.portalStoreFiltersInit(filtersShell);
    }

    bindAvailabilityPersistence(form);
    updateFilterSummary(form);

    form.addEventListener('input', () => updateFilterSummary(form));
    form.addEventListener('change', () => updateFilterSummary(form));

    form.addEventListener('submit', (event) => {
      if (!hasNarrowingFilter(form)) {
        event.preventDefault();
        showStatus(
          statusHost,
          'حدّد فلتراً واحداً على الأقل: بحث، نوع مادة، شركة مصنعة، مخزن، أو نطاق مخزون.',
          'error'
        );
        return;
      }

      const splitKey = splitSelect?.value || '';
      if (!splitKey || !SPLIT_CONFIG[splitKey]) {
        showStatus(statusHost, '', 'success');
        if (statusHost) {
          statusHost.classList.add('hidden');
        }
        return;
      }

      const config = SPLIT_CONFIG[splitKey];
      const selectedCount = countChecked(form, config.param);

      if (selectedCount === 0) {
        event.preventDefault();
        showStatus(
          statusHost,
          'للتقسيم: أضف خياراً واحداً على الأقل في فلتر «' + config.label + '».',
          'error'
        );
        return;
      }

      showStatus(
        statusHost,
        'جاري تجهيز أرشيف يحتوي ' + selectedCount + ' ملف ZIP داخلي...',
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
