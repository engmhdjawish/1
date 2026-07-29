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
    host.classList.remove(
      'hidden',
      'text-emerald-700',
      'text-amber-700',
      'text-red-700',
      'bg-emerald-50',
      'bg-amber-50',
      'bg-red-50',
      'border-emerald-200',
      'border-amber-200',
      'border-red-200',
      'dash-mi-zip-status--preparing'
    );
    host.textContent = message;
    if (tone === 'success') {
      host.classList.add('text-emerald-700', 'bg-emerald-50', 'border-emerald-200');
    } else if (tone === 'error') {
      host.classList.add('text-red-700', 'bg-red-50', 'border-red-200');
    } else if (tone === 'preparing') {
      host.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-200', 'dash-mi-zip-status--preparing');
    } else {
      host.classList.add('text-amber-700', 'bg-amber-50', 'border-amber-200');
    }
  };

  const hideStatus = (host) => {
    if (!host) {
      return;
    }
    host.classList.add('hidden');
    host.textContent = '';
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

  function formActionUrl(form) {
    const raw = form.getAttribute('action');
    if (!raw) {
      return window.location.href;
    }
    return new URL(raw, window.location.href).href;
  }

  function buildDownloadUrl(form) {
    const url = new URL(formActionUrl(form));
    const params = new URLSearchParams(new FormData(form));
    url.search = params.toString();
    return url.toString();
  }

  function parseFilenameFromDisposition(headerValue) {
    if (!headerValue) {
      return '';
    }
    const utfMatch = /filename\*=UTF-8''([^;]+)/i.exec(headerValue);
    if (utfMatch?.[1]) {
      try {
        return decodeURIComponent(utfMatch[1].replace(/"/g, ''));
      } catch {
        return utfMatch[1].replace(/"/g, '');
      }
    }
    const plainMatch = /filename="?([^";]+)"?/i.exec(headerValue);
    return plainMatch?.[1] ? plainMatch[1].trim() : '';
  }

  async function parseZipErrorResponse(response) {
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    const raw = await response.text();
    if (contentType.includes('application/json') || raw.trim().startsWith('{')) {
      try {
        const data = JSON.parse(raw);
        if (data?.message) {
          return String(data.message);
        }
      } catch {
        /* fall through */
      }
    }
    if (raw.trim()) {
      return raw.trim().slice(0, 240);
    }
    return 'تعذر تحضير الملف (رمز ' + response.status + ').';
  }

  function triggerBlobDownload(blob, filename) {
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename || 'download.zip';
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
  }

  async function downloadZipFromForm(form, statusHost, submitButton, preparingMessage) {
    const url = buildDownloadUrl(form);
    showStatus(statusHost, preparingMessage, 'preparing');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
    }

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/zip, application/json;q=0.9, */*;q=0.8' },
      });

      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      if (!response.ok || contentType.includes('application/json')) {
        throw new Error(await parseZipErrorResponse(response));
      }

      const blob = await response.blob();
      if (!blob || blob.size === 0) {
        throw new Error('الملف فارغ — لا توجد صور للتحميل.');
      }

      const filename = parseFilenameFromDisposition(response.headers.get('content-disposition'))
        || 'material-images.zip';
      triggerBlobDownload(blob, filename);
      showStatus(statusHost, 'تم تحضير الملف — بدأ التحميل.', 'success');
    } catch (error) {
      showStatus(statusHost, error?.message || 'تعذر تحضير الملف.', 'error');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.classList.remove('is-loading');
      }
    }
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

  function validateMaterialZipForm(form, statusHost) {
    if (!hasNarrowingFilter(form)) {
      showStatus(
        statusHost,
        'حدّد فلتراً واحداً على الأقل: بحث، نوع مادة، شركة مصنعة، مخزن، أو نطاق مخزون.',
        'error'
      );
      return false;
    }

    const splitKey = form.querySelector('input[name="splitBy"]:checked')?.value || '';
    if (splitKey && SPLIT_CONFIG[splitKey]) {
      const config = SPLIT_CONFIG[splitKey];
      const selectedCount = countChecked(form, config.param);
      if (selectedCount === 0) {
        showStatus(
          statusHost,
          'للتقسيم: حدّد خياراً واحداً على الأقل في فلتر «' + config.label + '».',
          'error'
        );
        return false;
      }
    }

    return true;
  }

  function materialPreparingMessage(form) {
    const splitKey = form.querySelector('input[name="splitBy"]:checked')?.value || '';
    if (splitKey && SPLIT_CONFIG[splitKey]) {
      const selectedCount = countChecked(form, SPLIT_CONFIG[splitKey].param);
      return 'جاري تحضير الملف (' + selectedCount + ' أرشيف داخلي)...';
    }
    return 'جاري تحضير الملف...';
  }

  const initMaterialForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const submitButton = form.querySelector('[type="submit"]');
    const filtersShell = form.closest('[data-store-filters-root]');

    if (filtersShell && typeof window.portalStoreFiltersInit === 'function') {
      window.portalStoreFiltersInit(filtersShell);
    }

    bindAvailabilityPersistence(form);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!validateMaterialZipForm(form, statusHost)) {
        return;
      }
      downloadZipFromForm(form, statusHost, submitButton, materialPreparingMessage(form));
    });
  };

  const initInvoiceForm = (form) => {
    if (!form || form.dataset.zipDownloadInit === '1') {
      return;
    }
    form.dataset.zipDownloadInit = '1';

    const statusHost = form.querySelector('[data-zip-download-status]');
    const submitButton = form.querySelector('[type="submit"]');

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.reportValidity()) {
        return;
      }
      hideStatus(statusHost);
      downloadZipFromForm(form, statusHost, submitButton, 'جاري تحضير ملف صور الفاتورة...');
    });
  };

  window.portalMaterialZipDownloadInit = (root = document) => {
    root.querySelectorAll('[data-material-images-download-panel]').forEach((panel) => {
      bindExclusiveDownloadAccordions(panel);
    });
    root.querySelectorAll('[data-material-zip-form]').forEach((form) => initMaterialForm(form));
    root.querySelectorAll('[data-invoice-zip-form]').forEach((form) => initInvoiceForm(form));
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
