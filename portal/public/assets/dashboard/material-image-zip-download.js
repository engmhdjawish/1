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

  let zipDownloadFrame = null;

  function getZipDownloadFrame() {
    if (zipDownloadFrame && document.body.contains(zipDownloadFrame)) {
      return zipDownloadFrame;
    }
    zipDownloadFrame = document.createElement('iframe');
    zipDownloadFrame.name = 'portal-material-zip-download';
    zipDownloadFrame.title = 'Material ZIP download';
    zipDownloadFrame.setAttribute('aria-hidden', 'true');
    zipDownloadFrame.tabIndex = -1;
    zipDownloadFrame.style.cssText = 'position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0);overflow:hidden';
    document.body.appendChild(zipDownloadFrame);
    return zipDownloadFrame;
  }

  function readIframeErrorMessage(frame) {
    try {
      const doc = frame.contentDocument;
      if (!doc?.body) {
        return '';
      }
      const raw = (doc.body.innerText || doc.body.textContent || '').trim();
      if (!raw.startsWith('{')) {
        return '';
      }
      const data = JSON.parse(raw);
      return data?.message ? String(data.message) : '';
    } catch {
      return '';
    }
  }

  function setSubmitBusy(submitButton, busy) {
    if (!submitButton) {
      return;
    }
    submitButton.disabled = busy;
    submitButton.classList.toggle('is-loading', busy);
  }

  function downloadZipFromForm(form, statusHost, submitButton, preparingMessage) {
    const url = buildDownloadUrl(form);
    const frame = getZipDownloadFrame();

    showStatus(
      statusHost,
      preparingMessage + ' عند الجاهزية يظهر التحميل في شريط المتصفح ويمكنك متابعة التصفّح.',
      'preparing'
    );
    setSubmitBusy(submitButton, true);

    const onLoad = () => {
      const errorMessage = readIframeErrorMessage(frame);
      setSubmitBusy(submitButton, false);
      if (errorMessage) {
        showStatus(statusHost, errorMessage, 'error');
        return;
      }
      showStatus(statusHost, 'بدأ التحميل — تابع التقدّم من شريط التحميل في المتصفح.', 'success');
    };

    frame.addEventListener('load', onLoad, { once: true });
    window.setTimeout(() => {
      setSubmitBusy(submitButton, false);
    }, 3000);

    frame.src = 'about:blank';
    window.requestAnimationFrame(() => {
      frame.src = url;
    });
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
