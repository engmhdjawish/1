/**
 * Site media library picker — works after dashboard AJAX navigation.
 */
(function () {
  'use strict';

  let modalBound = false;
  let mainBound = false;
  let activeFieldId = null;
  let activeCategory = 'banner';

  const qs = (id) => document.getElementById(id);

  const setPreview = (fieldId, url) => {
    const input = qs(fieldId + '-input');
    const preview = qs(fieldId + '-preview');
    if (input) {
      input.value = url || '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (!preview) return;
    if (!url) {
      preview.innerHTML = 'بدون صورة';
      return;
    }
    preview.innerHTML = '';
    const img = document.createElement('img');
    img.src = url;
    img.alt = '';
    img.className = 'h-full w-full object-cover';
    preview.appendChild(img);
  };

  const closeModal = () => {
    qs('portal-media-picker-modal')?.classList.add('hidden');
    activeFieldId = null;
  };

  const renderGrid = (items) => {
    const grid = qs('portal-media-grid');
    const gridStatus = qs('portal-media-grid-status');
    if (!grid) return;
    grid.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
      if (gridStatus) gridStatus.textContent = 'لا توجد صور في هذا التصنيف.';
      return;
    }
    if (gridStatus) gridStatus.textContent = items.length + ' صورة — انقر للاختيار';
    items.forEach((item) => {
      if (!item || !item.url) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'group rounded-lg border border-border-subtle overflow-hidden text-right hover:border-primary focus:border-primary';
      btn.innerHTML =
        '<div class="aspect-[4/3] bg-surface-low overflow-hidden">'
        + '<img src="' + item.url + '" alt="" class="h-full w-full object-cover group-hover:scale-105 transition">'
        + '</div>'
        + '<div class="p-2 text-[11px]">'
        + '<div class="font-bold truncate">' + (item.title_ar || item.file_name || 'صورة') + '</div>'
        + '<div class="text-text-muted">' + (item.category_label || item.category || '') + '</div>'
        + '</div>';
      btn.addEventListener('click', () => {
        if (!activeFieldId) return;
        setPreview(activeFieldId, item.url);
        closeModal();
      });
      grid.appendChild(btn);
    });
  };

  const loadGrid = async (category) => {
    activeCategory = category || 'banner';
    const gridStatus = qs('portal-media-grid-status');
    const uploadCategory = qs('portal-media-upload-category');
    if (gridStatus) gridStatus.textContent = 'جاري التحميل...';
    document.querySelectorAll('.media-picker-cat').forEach((node) => {
      const isActive = node.getAttribute('data-media-category') === activeCategory;
      node.classList.toggle('bg-primary', isActive);
      node.classList.toggle('text-white', isActive);
      node.classList.toggle('border-primary', isActive);
    });
    if (uploadCategory) uploadCategory.value = activeCategory;
    try {
      const response = await fetch('/dashboard/site-media-api.php?category=' + encodeURIComponent(activeCategory), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!data.ok) {
        if (gridStatus) gridStatus.textContent = data.message || 'تعذر تحميل المكتبة.';
        renderGrid([]);
        return;
      }
      renderGrid(data.items || []);
    } catch (_) {
      if (gridStatus) gridStatus.textContent = 'تعذر الاتصال بالخادم.';
      renderGrid([]);
    }
  };

  const openPicker = (fieldId) => {
    const modal = qs('portal-media-picker-modal');
    if (!fieldId || !modal) return;
    activeFieldId = fieldId;
    const wrap = qs(fieldId + '-wrap');
    const defaultCategory = wrap?.getAttribute('data-default-category') || 'banner';
    modal.classList.remove('hidden');
    loadGrid(defaultCategory);
  };

  const bindModalOnce = () => {
    if (modalBound) return;
    modalBound = true;

    const modal = qs('portal-media-picker-modal');
    const uploadForm = qs('portal-media-upload-form');
    const uploadStatus = qs('portal-media-upload-status');
    const uploadCategory = qs('portal-media-upload-category');

    document.querySelectorAll('.media-picker-cat').forEach((button) => {
      button.addEventListener('click', () => {
        loadGrid(button.getAttribute('data-media-category') || 'banner');
      });
    });

    modal?.querySelectorAll('[data-media-close]').forEach((node) => {
      node.addEventListener('click', closeModal);
    });

    uploadForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (uploadStatus) uploadStatus.textContent = 'جاري الرفع...';
      const formData = new FormData(uploadForm);
      formData.append('action', 'upload');
      try {
        const response = await fetch('/dashboard/site-media-api.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const data = await response.json();
        if (!data.ok) {
          if (uploadStatus) uploadStatus.textContent = data.message || 'تعذر الرفع.';
          return;
        }
        if (uploadStatus) uploadStatus.textContent = data.message || 'تم الرفع.';
        uploadForm.reset();
        if (uploadCategory) uploadCategory.value = activeCategory;
        await loadGrid(activeCategory);
        if (activeFieldId && data.asset?.url) {
          setPreview(activeFieldId, data.asset.url);
          closeModal();
        }
      } catch (_) {
        if (uploadStatus) uploadStatus.textContent = 'تعذر الاتصال بالخادم.';
      }
    });
  };

  const bindMainOnce = () => {
    if (mainBound) return;
    mainBound = true;

    document.addEventListener('click', (event) => {
      const openBtn = event.target.closest('[data-media-open]');
      if (openBtn) {
        event.preventDefault();
        openPicker(openBtn.getAttribute('data-media-open'));
        return;
      }
      const clearBtn = event.target.closest('[data-media-clear]');
      if (clearBtn) {
        event.preventDefault();
        const fieldId = clearBtn.getAttribute('data-media-clear');
        if (fieldId) setPreview(fieldId, '');
      }
    });
  };

  window.portalMediaPickerInit = function portalMediaPickerInit() {
    bindModalOnce();
    bindMainOnce();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalMediaPickerInit());
  } else {
    window.portalMediaPickerInit();
  }
})();
