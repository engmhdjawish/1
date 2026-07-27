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
    });
  }

  const initForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const filtersShell = form.closest('[data-store-filters-root]');

    if (filtersShell && typeof window.portalStoreFiltersInit === 'function') {
      window.portalStoreFiltersInit(filtersShell);
    }

    bindAvailabilityPersistence(form);

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

      const splitKey = form.querySelector('input[name="splitBy"]:checked')?.value || '';
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
          'للتقسيم: حدّد خياراً واحداً على الأقل في فلتر «' + config.label + '».',
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
    root.querySelectorAll('[data-material-images-download-panel]').forEach((panel) => {
      bindExclusiveDownloadAccordions(panel);
    });
    root.querySelectorAll('[data-material-zip-form]').forEach((form) => initForm(form));
  };

  function bindExclusiveDownloadAccordions(root) {
    if (!root || root.dataset.downloadAccordionsInit === '1') {
      return;
    }
    root.dataset.downloadAccordionsInit = '1';

    const accordions = root.querySelectorAll('[data-download-accordion]');
    accordions.forEach((accordion) => {
      if (!(accordion instanceof HTMLDetailsElement) || accordion.dataset.downloadAccordionBound === '1') {
        return;
      }
      accordion.dataset.downloadAccordionBound = '1';
      accordion.addEventListener('toggle', () => {
        if (!accordion.open) {
          return;
        }
        accordions.forEach((other) => {
          if (other !== accordion && other instanceof HTMLDetailsElement) {
            other.open = false;
          }
        });
      });
    });
  }
})();
