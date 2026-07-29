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

  function setSubmitBusy(submitButton, busy) {
    if (!submitButton) {
      return;
    }
    submitButton.disabled = busy;
    submitButton.classList.toggle('is-loading', busy);
  }

  function sleep(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
  }

  async function fetchZipJobJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Dashboard-Ajax': '1',
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await response.json().catch(() => null);
    if (!data) {
      throw new Error('استجابة غير صالحة من الخادم.');
    }
    if (!response.ok || data.ok === false) {
      throw new Error(data.message || ('خطأ ' + response.status));
    }
    return data;
  }

  function triggerBrowserDownload(url) {
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.rel = 'noopener';
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  }

  async function pollZipJob(jobId, statusHost) {
    const startedAt = Date.now();
    const maxWaitMs = 30 * 60 * 1000;

    while (Date.now() - startedAt < maxWaitMs) {
      await sleep(2000);
      const data = await fetchZipJobJson('/api/material-images-zip-jobs.php?jobId=' + encodeURIComponent(jobId));
      const status = String(data.status || '');
      const progressMessage = String(data.progressMessage || 'جاري تحضير الملف...');
      const queuedSeconds = Number(data.queuedSeconds || 0);

      if (status === 'failed') {
        throw new Error(String(data.errorMessage || data.message || 'تعذر تحضير الملف.'));
      }

      if (status === 'ready') {
        showStatus(statusHost, 'الملف جاهز — جاري فتح نافذة التحميل...', 'success');
        const downloadUrl = String(
          data.downloadUrl || ('/api/material-images-zip-jobs.php?jobId=' + encodeURIComponent(jobId) + '&download=1')
        );
        triggerBrowserDownload(downloadUrl);
        showStatus(statusHost, 'بدأ التحميل — تابع التقدّم من شريط التحميل في المتصفح.', 'success');
        return;
      }

      let waitMessage = progressMessage + ' (التحضير يتم على السيرفر دون إيقاف الموقع)';
      if (queuedSeconds >= 20) {
        waitMessage += ' — إذا استمر الانتظار، اطلب تفعيل cron لـ build-material-zip-job.php على السيرفر';
      }
      showStatus(statusHost, waitMessage, 'preparing');
    }

    throw new Error('انتهى وقت الانتظار. جرّب مجدداً أو حدّد فلاتر أضيق.');
  }

  async function downloadZipFromForm(form, statusHost, submitButton, preparingMessage) {
    showStatus(
      statusHost,
      preparingMessage + ' سيتم إرسال الطلب إلى السيرفر دون إيقاف الموقع.',
      'preparing'
    );
    setSubmitBusy(submitButton, true);

    try {
      const body = new FormData(form);
      const created = await fetchZipJobJson('/api/material-images-zip-jobs.php', {
        method: 'POST',
        body,
      });
      const jobId = String(created.jobId || '');
      if (!jobId) {
        throw new Error('تعذر إنشاء طلب التحميل.');
      }
      showStatus(statusHost, String(created.progressMessage || 'في قائمة الانتظار...'), 'preparing');
      await pollZipJob(jobId, statusHost);
    } catch (error) {
      showStatus(statusHost, error?.message || 'تعذر تحضير الملف.', 'error');
    } finally {
      setSubmitBusy(submitButton, false);
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
