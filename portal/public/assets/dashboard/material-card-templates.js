/**
 * قوالب بطاقة صور المواد — رفع وضبط الحقول بصرياً.
 */
(function () {
  'use strict';

  const bootEl = document.getElementById('mctBootstrap');
  if (!bootEl) return;

  let boot = {};
  try {
    boot = JSON.parse(bootEl.textContent || '{}');
  } catch {
    boot = {};
  }

  const API = boot.api || '/dashboard/material-card-templates-api.php';
  const FIELD_LABELS = boot.fieldLabels || {};
  const FIELD_ORDER = ['photo', 'product_name', 'packaging', 'barcode'];
  const COLORS = {
    photo: '#22c55e',
    product_name: '#3b82f6',
    packaging: '#f59e0b',
    barcode: '#a855f7',
  };

  const listEl = document.getElementById('mctList');
  const statusEl = document.getElementById('mctStatus');
  const imgEl = document.getElementById('mctTemplateImg');
  const overlaysEl = document.getElementById('mctOverlays');
  const fieldTabsEl = document.getElementById('mctFieldTabs');
  const fieldFormEl = document.getElementById('mctFieldForm');
  const nameEl = document.getElementById('mctName');
  const activeEl = document.getElementById('mctActive');
  const defaultEl = document.getElementById('mctDefault');
  const saveBtn = document.getElementById('mctSaveBtn');
  const deleteBtn = document.getElementById('mctDeleteBtn');
  const reloadBtn = document.getElementById('mctReloadBtn');
  const uploadForm = document.getElementById('mctUploadForm');

  let items = Array.isArray(boot.items) ? boot.items : [];
  let current = null;
  let selectedKind = 'photo';
  let dragState = null;

  function setStatus(text, isError) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.className = `text-xs ${isError ? 'text-red-600' : 'text-text-muted'}`;
  }

  async function fetchJson(url, options) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Dashboard-Ajax': '1',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options?.headers || {}),
      },
      ...options,
    });
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(text.slice(0, 180) || 'استجابة غير صالحة');
    }
  }

  function scale() {
    if (!imgEl || !current) return 1;
    const natural = Number(current.canvas_width || imgEl.naturalWidth || 1);
    const shown = imgEl.clientWidth || natural;
    return shown / natural;
  }

  function fieldMap() {
    const map = {};
    (current?.fields || []).forEach((f) => {
      map[f.field_kind] = { ...f };
    });
    FIELD_ORDER.forEach((kind) => {
      if (!map[kind]) {
        map[kind] = {
          field_kind: kind,
          x: 20,
          y: 20,
          w: 120,
          h: 80,
          font_size: kind === 'photo' ? null : 18,
          color_hex: kind === 'packaging' ? '#F5F5F7' : '#1C1C1E',
          align: kind === 'barcode' ? 'center' : 'right',
          z_index: FIELD_ORDER.indexOf(kind),
          meta: {},
        };
      }
    });
    return map;
  }

  function renderList() {
    if (!listEl) return;
    if (!items.length) {
      listEl.innerHTML = '<p class="text-xs text-text-muted">لا توجد قوالب بعد.</p>';
      return;
    }
    listEl.innerHTML = items.map((item) => {
      const active = current && current.id === item.id;
      return `<button type="button" class="mct-list-item w-full text-right rounded-lg border px-2.5 py-2 ${active ? 'border-primary bg-red-50' : 'border-border-subtle bg-white'}" data-id="${item.id}">
        <div class="text-xs font-bold truncate">${escapeHtml(item.name_ar || 'قالب')}</div>
        <div class="text-[10px] text-text-muted mt-0.5">${item.canvas_width}×${item.canvas_height}${item.is_default ? ' · افتراضي' : ''}</div>
      </button>`;
    }).join('');
    listEl.querySelectorAll('[data-id]').forEach((btn) => {
      btn.addEventListener('click', () => selectTemplate(btn.getAttribute('data-id')));
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  async function selectTemplate(id) {
    if (!id) return;
    setStatus('جاري التحميل...');
    const payload = await fetchJson(`${API}?action=get&id=${encodeURIComponent(id)}`);
    if (!payload.ok || !payload.template) {
      setStatus(payload.message || 'تعذر تحميل القالب', true);
      return;
    }
    current = payload.template;
    selectedKind = 'photo';
    nameEl.value = current.name_ar || '';
    nameEl.disabled = false;
    activeEl.checked = !!current.is_active;
    activeEl.disabled = false;
    defaultEl.checked = !!current.is_default;
    defaultEl.disabled = false;
    saveBtn.disabled = false;
    deleteBtn.disabled = false;
    imgEl.onload = () => {
      renderFieldTabs();
      renderOverlays();
      renderFieldForm();
    };
    imgEl.src = current.url;
    renderList();
    setStatus('');
    const url = new URL(window.location.href);
    url.searchParams.set('id', current.id);
    window.history.replaceState({}, '', url.toString());
  }

  function renderFieldTabs() {
    if (!fieldTabsEl) return;
    fieldTabsEl.innerHTML = FIELD_ORDER.map((kind) => {
      const active = selectedKind === kind;
      return `<button type="button" class="h-7 px-2 rounded-md text-[11px] font-bold border ${active ? 'bg-primary text-white border-primary' : 'bg-white border-border-subtle'}" data-kind="${kind}">${escapeHtml(FIELD_LABELS[kind] || kind)}</button>`;
    }).join('');
    fieldTabsEl.querySelectorAll('[data-kind]').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedKind = btn.getAttribute('data-kind');
        renderFieldTabs();
        renderOverlays();
        renderFieldForm();
      });
    });
  }

  function renderOverlays() {
    if (!overlaysEl || !current) return;
    const s = scale();
    const map = fieldMap();
    overlaysEl.innerHTML = '';
    FIELD_ORDER.forEach((kind) => {
      const f = map[kind];
      const box = document.createElement('div');
      box.className = `mct-box${selectedKind === kind ? ' is-active' : ''}`;
      box.dataset.kind = kind;
      box.style.left = `${f.x * s}px`;
      box.style.top = `${f.y * s}px`;
      box.style.width = `${f.w * s}px`;
      box.style.height = `${f.h * s}px`;
      box.style.borderColor = COLORS[kind] || '#fff';
      box.innerHTML = `<span class="mct-box__label">${escapeHtml(FIELD_LABELS[kind] || kind)}</span><span class="mct-box__handle" data-resize="1"></span>`;
      box.addEventListener('pointerdown', (event) => startDrag(event, kind, false));
      box.querySelector('[data-resize]')?.addEventListener('pointerdown', (event) => {
        event.stopPropagation();
        startDrag(event, kind, true);
      });
      overlaysEl.appendChild(box);
    });
  }

  function startDrag(event, kind, resize) {
    event.preventDefault();
    selectedKind = kind;
    renderFieldTabs();
    renderFieldForm();
    const map = fieldMap();
    const f = map[kind];
    const s = scale();
    dragState = {
      kind,
      resize,
      startX: event.clientX,
      startY: event.clientY,
      orig: { ...f },
      scale: s,
    };
    window.addEventListener('pointermove', onDragMove);
    window.addEventListener('pointerup', onDragEnd, { once: true });
  }

  function onDragMove(event) {
    if (!dragState || !current) return;
    const dx = (event.clientX - dragState.startX) / dragState.scale;
    const dy = (event.clientY - dragState.startY) / dragState.scale;
    const map = fieldMap();
    const f = map[dragState.kind];
    if (dragState.resize) {
      f.w = Math.max(20, Math.round(dragState.orig.w + dx));
      f.h = Math.max(20, Math.round(dragState.orig.h + dy));
    } else {
      f.x = Math.max(0, Math.round(dragState.orig.x + dx));
      f.y = Math.max(0, Math.round(dragState.orig.y + dy));
    }
    current.fields = FIELD_ORDER.map((kind) => map[kind]);
    renderOverlays();
    syncFieldFormValues();
  }

  function onDragEnd() {
    dragState = null;
    window.removeEventListener('pointermove', onDragMove);
  }

  function renderFieldForm() {
    if (!fieldFormEl || !current) return;
    const f = fieldMap()[selectedKind];
    const isPhoto = selectedKind === 'photo';
    fieldFormEl.innerHTML = `
      <div class="grid grid-cols-2 gap-2 text-[11px]">
        <label>X<input type="number" data-prop="x" value="${f.x}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2"></label>
        <label>Y<input type="number" data-prop="y" value="${f.y}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2"></label>
        <label>عرض<input type="number" data-prop="w" value="${f.w}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2"></label>
        <label>ارتفاع<input type="number" data-prop="h" value="${f.h}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2"></label>
      </div>
      ${isPhoto ? '<p class="text-[11px] text-text-muted mt-2">منطقة الصورة فقط — بدون خط.</p>' : `
      <label class="block text-[11px] mt-2">حجم الخط
        <input type="number" data-prop="font_size" step="0.5" min="8" max="96" value="${f.font_size ?? 18}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2">
      </label>
      <label class="block text-[11px] mt-2">اللون
        <input type="color" data-prop="color_hex" value="${f.color_hex || '#1C1C1E'}" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-1">
      </label>
      <label class="block text-[11px] mt-2">المحاذاة
        <select data-prop="align" class="mt-0.5 h-8 w-full rounded border border-border-subtle px-2">
          <option value="right" ${f.align === 'right' ? 'selected' : ''}>يمين</option>
          <option value="center" ${f.align === 'center' ? 'selected' : ''}>وسط</option>
          <option value="left" ${f.align === 'left' ? 'selected' : ''}>يسار</option>
        </select>
      </label>`}
    `;
    fieldFormEl.querySelectorAll('[data-prop]').forEach((input) => {
      input.addEventListener('input', () => {
        const prop = input.getAttribute('data-prop');
        const map = fieldMap();
        const field = map[selectedKind];
        if (['x', 'y', 'w', 'h'].includes(prop)) {
          field[prop] = Math.max(prop === 'w' || prop === 'h' ? 1 : 0, Number(input.value || 0));
        } else if (prop === 'font_size') {
          field.font_size = Number(input.value || 18);
        } else if (prop === 'color_hex') {
          field.color_hex = String(input.value || '#1C1C1E').toUpperCase();
        } else if (prop === 'align') {
          field.align = input.value;
        }
        current.fields = FIELD_ORDER.map((kind) => map[kind]);
        renderOverlays();
      });
    });
  }

  function syncFieldFormValues() {
    if (!fieldFormEl || !current) return;
    const f = fieldMap()[selectedKind];
    fieldFormEl.querySelectorAll('[data-prop]').forEach((input) => {
      const prop = input.getAttribute('data-prop');
      if (prop && f[prop] !== undefined && f[prop] !== null && input.type !== 'color') {
        input.value = f[prop];
      }
    });
  }

  async function reload() {
    const payload = await fetchJson(`${API}?action=list`);
    if (!payload.ok) {
      setStatus(payload.message || 'تعذر التحديث', true);
      return;
    }
    items = payload.items || [];
    renderList();
    if (current?.id) {
      await selectTemplate(current.id);
    } else if (boot.selectedId) {
      await selectTemplate(boot.selectedId);
    } else if (items[0]?.id) {
      await selectTemplate(items[0].id);
    }
  }

  uploadForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(uploadForm);
    form.append('action', 'upload');
    setStatus('جاري الرفع...');
    try {
      const payload = await fetchJson(API, { method: 'POST', body: form });
      if (!payload.ok) {
        setStatus(payload.message || 'فشل الرفع', true);
        return;
      }
      setStatus(payload.message || 'تم الرفع');
      uploadForm.reset();
      await reload();
      if (payload.template?.id) {
        await selectTemplate(payload.template.id);
      }
    } catch (error) {
      setStatus(error.message || 'فشل الرفع', true);
    }
  });

  saveBtn?.addEventListener('click', async () => {
    if (!current) return;
    const map = fieldMap();
    const form = new FormData();
    form.append('action', 'save');
    form.append('id', current.id);
    form.append('name_ar', nameEl.value.trim());
    form.append('is_active', activeEl.checked ? '1' : '0');
    form.append('is_default', defaultEl.checked ? '1' : '0');
    form.append('fields', JSON.stringify(FIELD_ORDER.map((kind) => map[kind])));
    setStatus('جاري الحفظ...');
    try {
      const payload = await fetchJson(API, { method: 'POST', body: form });
      if (!payload.ok) {
        setStatus(payload.message || 'فشل الحفظ', true);
        return;
      }
      setStatus(payload.message || 'تم الحفظ');
      current = payload.template;
      items = items.map((item) => (item.id === current.id ? {
        ...item,
        name_ar: current.name_ar,
        is_active: current.is_active,
        is_default: current.is_default,
      } : { ...item, is_default: current.is_default ? false : item.is_default }));
      renderList();
      renderOverlays();
    } catch (error) {
      setStatus(error.message || 'فشل الحفظ', true);
    }
  });

  deleteBtn?.addEventListener('click', async () => {
    if (!current) return;
    if (!window.confirm('حذف هذا القالب نهائياً؟')) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', current.id);
    const payload = await fetchJson(API, { method: 'POST', body: form });
    if (!payload.ok) {
      setStatus(payload.message || 'فشل الحذف', true);
      return;
    }
    current = null;
    saveBtn.disabled = true;
    deleteBtn.disabled = true;
    imgEl.removeAttribute('src');
    overlaysEl.innerHTML = '';
    fieldFormEl.innerHTML = '<p class="text-[11px] text-text-muted">اختر قالباً ثم حقلاً.</p>';
    await reload();
    setStatus(payload.message || 'تم الحذف');
  });

  reloadBtn?.addEventListener('click', () => {
    reload().catch((error) => setStatus(error.message || 'فشل التحديث', true));
  });

  window.addEventListener('resize', () => {
    if (current) renderOverlays();
  });

  // CSS for boxes
  const style = document.createElement('style');
  style.textContent = `
    .mct-box{position:absolute;box-sizing:border-box;border:2px solid #22c55e;background:rgba(34,197,94,.12);cursor:move;user-select:none}
    .mct-box.is-active{background:rgba(34,197,94,.22);box-shadow:0 0 0 1px rgba(255,255,255,.5)}
    .mct-box__label{position:absolute;top:2px;right:4px;font-size:10px;font-weight:800;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.7)}
    .mct-box__handle{position:absolute;left:0;bottom:0;width:12px;height:12px;background:#fff;border:1px solid currentColor;cursor:nwse-resize}
  `;
  document.head.appendChild(style);

  renderList();
  if (boot.selectedId) {
    selectTemplate(boot.selectedId).catch((error) => setStatus(error.message || 'فشل التحميل', true));
  } else if (items[0]?.id) {
    selectTemplate(items[0].id).catch((error) => setStatus(error.message || 'فشل التحميل', true));
  }
})();
