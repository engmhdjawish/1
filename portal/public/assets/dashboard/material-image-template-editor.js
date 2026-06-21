(() => {
  const root = document.getElementById('materialImageTemplateEditor');
  if (!root) return;

  const API_URL = root.dataset.api || '/dashboard/material-image-template-api.php';
  const fieldCatalog = JSON.parse(root.dataset.fieldCatalog || '{}');
  const qrTargetCatalog = JSON.parse(root.dataset.qrTargets || '[]');
  const sampleFields = JSON.parse(root.dataset.sampleFields || '{}');
  const companyLogo = root.dataset.companyLogo || '';

  const state = { template: JSON.parse(root.dataset.template || '{}'), selectedId: null, drag: null };
  const qs = (sel) => root.querySelector(sel) || document.querySelector(sel);

  const allFieldOptions = () => {
    const options = [];
    Object.keys(fieldCatalog).forEach((group) => {
      (fieldCatalog[group] || []).forEach((item) => options.push(item));
    });
    options.push({ key: 'image.fixed', label: 'صورة ثابتة من المكتبة', type: 'image' });
    return options;
  };

  const regionLabel = (region) => ({ frame: 'إطار كامل', photo: 'صورة', footer: 'سفلي' }[region] || region);

  const showFlash = (message, ok = true) => {
    const flashEl = qs('#mitFlash');
    if (!flashEl) return;
    flashEl.textContent = message;
    flashEl.className = `rounded-xl border px-4 py-3 text-sm font-semibold ${ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'}`;
    flashEl.classList.remove('hidden');
    window.setTimeout(() => flashEl.classList.add('hidden'), 4000);
  };

  const fetchJson = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
    const text = await response.text();
    if (!text.trim()) throw new Error('استجابة فارغة من الخادم.');
    return JSON.parse(text);
  };

  const newId = () => `el_${Math.random().toString(36).slice(2, 9)}`;

  const defaultTextElement = () => ({
    id: newId(), type: 'text', field: 'material.product_line', image_url: '', qr_target: 'business.whatsapp', qr_custom_url: '',
    region: 'footer', x_pct: 5, y_pct: 20, width_pct: 50, height_pct: 25, z_index: 2, align: 'start', valign: 'center',
    style: { color: '#ffffff', font_size_em: 0.95, font_weight: 800, background: '', border_radius_rem: 0, padding_rem: 0, opacity: 1, nowrap: true, direction: 'rtl' },
  });

  const defaultImageElement = () => ({
    id: newId(), type: 'image', field: companyLogo ? 'business.company_logo' : 'image.fixed', image_url: companyLogo || '',
    qr_target: 'business.whatsapp', qr_custom_url: '', region: 'photo', x_pct: 5, y_pct: 5, width_pct: 18, height_pct: 18, z_index: 3,
    align: 'center', valign: 'center',
    style: { object_fit: 'contain', background: 'rgba(255,255,255,0.9)', border_radius_rem: 0.35, padding_rem: 0.12, opacity: 1, image_scale: 1, crop_x_pct: 50, crop_y_pct: 50 },
  });

  const defaultBarcodeElement = () => ({
    id: newId(), type: 'barcode', field: 'material.code', image_url: '', qr_target: 'business.whatsapp', qr_custom_url: '',
    region: 'footer', x_pct: 3, y_pct: 15, width_pct: 35, height_pct: 70, z_index: 2, align: 'center', valign: 'center',
    style: { foreground: '#000000', background: '#ffffff', opacity: 1 },
  });

  const defaultQrElement = () => ({
    id: newId(), type: 'qrcode', field: '', qr_target: 'business.whatsapp', qr_custom_url: '',
    region: 'photo', x_pct: 72, y_pct: 68, width_pct: 24, height_pct: 24, z_index: 5, align: 'center', valign: 'center',
    style: { foreground: '#000000', background: '#ffffff', opacity: 1 },
  });

  const resolveQrUrl = (element) => {
    const target = element.qr_target || 'business.whatsapp';
    if (target === 'custom') return String(element.qr_custom_url || '').trim();
    const value = String(sampleFields[target] || '').trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value)) return value;
    if (value.startsWith('/')) return `${window.location.origin}${value}`;
    return value;
  };

  const resolveElementContent = (element) => {
    if (element.type === 'text') return sampleFields[element.field] || 'نص تجريبي';
    if (element.type === 'barcode') return sampleFields[element.field] || sampleFields['material.code'] || 'A-1024';
    if (element.type === 'qrcode') return resolveQrUrl(element);
    if (element.field === 'image.fixed') return element.image_url || '';
    return sampleFields[element.field] || companyLogo || '';
  };

  const elementLabel = (element) => {
    if (element.type === 'barcode') return `باركود: ${element.field}`;
    if (element.type === 'qrcode') {
      const match = qrTargetCatalog.find((item) => item.key === element.qr_target);
      return `QR: ${match ? match.label : element.qr_target}`;
    }
    const match = allFieldOptions().find((item) => item.key === element.field);
    return `${element.type === 'image' ? 'صورة' : 'نص'}: ${match ? match.label : element.field}`;
  };

  const imageInnerStyle = (style = {}) => {
    const scale = Number(style.image_scale ?? 1) || 1;
    const cropX = Number(style.crop_x_pct ?? 50);
    const cropY = Number(style.crop_y_pct ?? 50);
    return {
      width: '100%', height: '100%', objectFit: style.object_fit || 'contain',
      objectPosition: `${cropX}% ${cropY}%`, transform: `scale(${scale})`, transformOrigin: `${cropX}% ${cropY}%`,
    };
  };

  const cssFromElement = (element) => {
    const style = element.style || {};
    const css = {
      left: `${element.x_pct}%`, top: `${element.y_pct}%`, width: `${element.width_pct}%`, height: `${element.height_pct}%`,
      zIndex: String(element.z_index || 1), opacity: String(style.opacity ?? 1), direction: style.direction || 'rtl',
      textAlign: element.align || 'start',
      alignItems: element.align === 'end' ? 'flex-end' : (element.align === 'center' ? 'center' : 'flex-start'),
      justifyContent: element.valign === 'end' ? 'flex-end' : (element.valign === 'start' ? 'flex-start' : 'center'),
    };
    if (element.type === 'text') {
      css.color = style.color || '#fff';
      css.fontSize = `calc(${(style.font_size_em ?? style.font_size_rem ?? 0.78)} * 1em)`;
      css.fontWeight = String(style.font_weight || 700);
      if (style.nowrap) { css.whiteSpace = 'nowrap'; css.overflow = 'hidden'; css.textOverflow = 'ellipsis'; }
      if (style.background) css.background = style.background;
      if (style.border_radius_rem) css.borderRadius = `${style.border_radius_rem}rem`;
      if (style.padding_rem) css.padding = `${style.padding_rem}rem`;
    }
    if (element.type === 'image') {
      if (style.background) css.background = style.background;
      if (style.border_radius_rem) css.borderRadius = `${style.border_radius_rem}rem`;
      if (style.padding_rem) css.padding = `${style.padding_rem}rem`;
    }
    return css;
  };

  const canvas = qs('#mitCanvas');

  const applyCanvasTheme = () => {
    const footer = state.template.footer || {};
    const photo = state.template.photo || {};
    canvas.classList.toggle('material-image-frame--no-footer', !footer.enabled);
    canvas.style.setProperty('--mif-accent-color', footer.accent_color || '#d81921');
    canvas.style.setProperty('--mif-accent-width', `${footer.accent_width_rem || 0.28}rem`);
    canvas.style.setProperty('--mif-footer-bg', footer.background || 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)');
    canvas.style.setProperty('--mif-footer-padding', `${footer.padding_rem || 0.6}rem`);
    canvas.style.setProperty('--mif-footer-min-height', `${footer.min_height_rem || 3.2}rem`);
    canvas.style.setProperty('--mif-footer-font-base', `${footer.font_base_rem || 1}rem`);
    canvas.style.setProperty('--mif-photo-bg', photo.background || '#f3f4f6');
    canvas.classList.add('material-image-frame--template', 'material-image-frame--detail');
  };

  const appendElementNode = (layer, element) => {
    const node = document.createElement('div');
    node.className = `mit-el${state.selectedId === element.id ? ' is-selected' : ''}`;
    node.dataset.id = element.id;
    Object.assign(node.style, cssFromElement(element));
    const inner = document.createElement('div');
    inner.className = 'mit-el__inner';
    if (element.type === 'text') {
      const text = document.createElement('div');
      text.className = 'mit-el__text';
      text.textContent = resolveElementContent(element);
      inner.appendChild(text);
    } else if (element.type === 'barcode') {
      const code = resolveElementContent(element);
      const img = document.createElement('img');
      img.className = 'mit-el__image';
      img.alt = '';
      img.src = `/api/barcode.php?code=${encodeURIComponent(code)}&h=48&fg=${encodeURIComponent(element.style?.foreground || '#000')}&bg=${encodeURIComponent(element.style?.background || '#fff')}`;
      inner.appendChild(img);
    } else if (element.type === 'qrcode') {
      const url = resolveElementContent(element);
      const img = document.createElement('img');
      img.className = 'mit-el__image';
      img.alt = '';
      if (url) img.src = `/api/qr.php?d=${encodeURIComponent(url)}&s=100&fg=${encodeURIComponent(element.style?.foreground || '#000')}&bg=${encodeURIComponent(element.style?.background || '#fff')}`;
      inner.appendChild(img);
    } else {
      const url = resolveElementContent(element);
      if (url) {
        const box = document.createElement('div');
        box.className = 'mit-el__imagebox';
        box.style.overflow = 'hidden';
        box.style.width = '100%';
        box.style.height = '100%';
        const img = document.createElement('img');
        img.className = 'mit-el__image';
        img.src = url;
        img.alt = '';
        Object.assign(img.style, imageInnerStyle(element.style));
        box.appendChild(img);
        inner.appendChild(box);
      }
    }
    node.appendChild(inner);
    node.addEventListener('pointerdown', (event) => startDrag(event, element.id));
    layer.appendChild(node);
  };

  const renderCanvas = () => {
    applyCanvasTheme();
    ['photo', 'footer', 'frame'].forEach((region) => {
      const layer = canvas.querySelector(`[data-layer="${region}"]`);
      if (!layer) return;
      layer.innerHTML = '';
      (state.template.elements || [])
        .filter((el) => el.region === region)
        .sort((a, b) => (a.z_index || 0) - (b.z_index || 0))
        .forEach((element) => appendElementNode(layer, element));
    });
    renderElementsList();
  };

  const renderElementsList = () => {
    const elementsList = qs('#mitElementsList');
    if (!elementsList) return;
    const elements = state.template.elements || [];
    qs('#mitElementCount').textContent = String(elements.length);
    elementsList.innerHTML = elements.map((element) => `
      <button type="button" class="mit-elements-list-item${state.selectedId === element.id ? ' is-active' : ''}" data-select-id="${element.id}">
        <span>${elementLabel(element)}</span>
        <span class="text-[10px] text-text-muted">${regionLabel(element.region)}</span>
      </button>`).join('');
    elementsList.querySelectorAll('[data-select-id]').forEach((button) => {
      button.addEventListener('click', () => selectElement(button.dataset.selectId));
    });
  };

  const selectedElement = () => (state.template.elements || []).find((el) => el.id === state.selectedId) || null;

  const populateFieldSource = (type) => {
    const fieldSource = qs('#mitFieldSource');
    let options = allFieldOptions().filter((item) => item.type === type || (type === 'image' && item.type === 'image'));
    if (type === 'image') options.push({ key: 'image.fixed', label: 'صورة ثابتة من المكتبة', type: 'image' });
    if (type === 'barcode') options = allFieldOptions().filter((item) => item.type === 'barcode' || item.key === 'material.code');
    fieldSource.innerHTML = options.map((item) => `<option value="${item.key}">${item.label}</option>`).join('');
    fieldSource.closest('label')?.classList.toggle('hidden', type === 'qrcode');
  };

  const populateQrTargets = () => {
    const select = qs('#mitQrTarget');
    if (!select) return;
    select.innerHTML = qrTargetCatalog.map((item) => `<option value="${item.key}">${item.label}</option>`).join('');
  };

  const rgbToHex = (color) => {
    if (/^#/.test(color)) return color.slice(0, 7);
    const match = String(color).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!match) return '#ffffff';
    const toHex = (n) => Number(n).toString(16).padStart(2, '0');
    return `#${toHex(match[1])}${toHex(match[2])}${toHex(match[3])}`;
  };

  const selectElement = (id) => {
    state.selectedId = id;
    const element = selectedElement();
    const inspector = qs('#mitInspector');
    if (!element || !inspector) { inspector?.classList.add('hidden'); renderCanvas(); return; }
    inspector.classList.remove('hidden');
    qs('#mitFieldRegion').value = element.region;
    populateFieldSource(element.type);
    if (element.type !== 'qrcode') qs('#mitFieldSource').value = element.field;
    qs('#mitWidthPct').value = String(element.width_pct);
    qs('#mitHeightPct').value = String(element.height_pct);
    qs('#mitAlign').value = element.align;
    qs('#mitValign').value = element.valign;
    qs('#mitFixedImageWrap').classList.toggle('hidden', element.type !== 'image' || element.field !== 'image.fixed');
    qs('#mitQrWrap').classList.toggle('hidden', element.type !== 'qrcode');
    qs('#mitTextStyleWrap').classList.toggle('hidden', element.type !== 'text');
    qs('#mitImageStyleWrap').classList.toggle('hidden', element.type !== 'image');
    const fixedImageInput = qs('#mit-fixed-image-input');
    if (fixedImageInput) fixedImageInput.value = element.image_url || '';
    if (element.type === 'text') {
      qs('#mitTextColor').value = rgbToHex(element.style.color || '#ffffff');
      qs('#mitFontSize').value = String(element.style.font_size_em ?? element.style.font_size_rem ?? 0.78);
      qs('#mitFontWeight').value = String(element.style.font_weight || 700);
      qs('#mitNowrap').checked = !!element.style.nowrap;
      qs('#mitTextDirection').value = element.style.direction || 'rtl';
    } else if (element.type === 'image') {
      qs('#mitOpacity').value = String(element.style.opacity ?? 1);
      qs('#mitImageBg').value = element.style.background || '';
      qs('#mitImageScale').value = String(element.style.image_scale ?? 1);
      qs('#mitCropX').value = String(element.style.crop_x_pct ?? 50);
      qs('#mitCropY').value = String(element.style.crop_y_pct ?? 50);
    } else if (element.type === 'qrcode') {
      qs('#mitQrTarget').value = element.qr_target || 'business.whatsapp';
      qs('#mitQrCustomUrl').value = element.qr_custom_url || '';
      qs('#mitQrCustomWrap').classList.toggle('hidden', element.qr_target !== 'custom');
    }
    renderCanvas();
  };

  const bindInspector = () => {
    qs('#mitFieldRegion')?.addEventListener('change', (e) => updateSelected({ region: e.target.value }));
    qs('#mitFieldSource')?.addEventListener('change', (e) => {
      const element = selectedElement();
      if (!element) return;
      element.field = e.target.value;
      qs('#mitFixedImageWrap').classList.toggle('hidden', element.field !== 'image.fixed');
      renderCanvas();
    });
    ['#mitWidthPct', '#mitHeightPct'].forEach((selector) => {
      qs(selector)?.addEventListener('input', (e) => {
        const element = selectedElement();
        if (!element) return;
        element[selector.includes('Width') ? 'width_pct' : 'height_pct'] = Number(e.target.value) || 1;
        renderCanvas();
      });
    });
    qs('#mitAlign')?.addEventListener('change', (e) => updateSelected({ align: e.target.value }));
    qs('#mitValign')?.addEventListener('change', (e) => updateSelected({ valign: e.target.value }));
    qs('#mitTextColor')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.color = e.target.value; renderCanvas(); } });
    qs('#mitFontSize')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.font_size_em = Number(e.target.value) || 0.78; renderCanvas(); } });
    qs('#mitFontWeight')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.font_weight = Number(e.target.value) || 700; renderCanvas(); } });
    qs('#mitNowrap')?.addEventListener('change', (e) => { const el = selectedElement(); if (el) { el.style.nowrap = e.target.checked; renderCanvas(); } });
    qs('#mitTextDirection')?.addEventListener('change', (e) => { const el = selectedElement(); if (el) { el.style.direction = e.target.value; renderCanvas(); } });
    qs('#mitOpacity')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.opacity = Number(e.target.value); renderCanvas(); } });
    qs('#mitImageBg')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.background = e.target.value; renderCanvas(); } });
    qs('#mitImageScale')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.image_scale = Number(e.target.value); renderCanvas(); } });
    qs('#mitCropX')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.crop_x_pct = Number(e.target.value); renderCanvas(); } });
    qs('#mitCropY')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.style.crop_y_pct = Number(e.target.value); renderCanvas(); } });
    qs('#mitQrTarget')?.addEventListener('change', (e) => {
      const el = selectedElement();
      if (!el) return;
      el.qr_target = e.target.value;
      qs('#mitQrCustomWrap').classList.toggle('hidden', el.qr_target !== 'custom');
      renderCanvas();
    });
    qs('#mitQrCustomUrl')?.addEventListener('input', (e) => { const el = selectedElement(); if (el) { el.qr_custom_url = e.target.value; renderCanvas(); } });
    qs('#mitDeleteElementBtn')?.addEventListener('click', () => {
      if (!state.selectedId) return;
      state.template.elements = (state.template.elements || []).filter((el) => el.id !== state.selectedId);
      state.selectedId = null;
      qs('#mitInspector').classList.add('hidden');
      renderCanvas();
    });
    const fixedImageInput = qs('#mit-fixed-image-input');
    const onFixedImage = () => {
      const el = selectedElement();
      if (!el) return;
      el.field = 'image.fixed';
      el.image_url = fixedImageInput?.value || '';
      renderCanvas();
    };
    fixedImageInput?.addEventListener('input', onFixedImage);
    fixedImageInput?.addEventListener('change', onFixedImage);
  };

  const updateSelected = (patch) => {
    const element = selectedElement();
    if (!element) return;
    Object.assign(element, patch);
    renderCanvas();
  };

  const getRegionNode = (region) => canvas.querySelector(`[data-region="${region}"]`) || canvas.querySelector(`[data-layer="${region}"]`);

  const startDrag = (event, id) => {
    event.preventDefault();
    selectElement(id);
    const element = selectedElement();
    if (!element) return;
    const regionNode = getRegionNode(element.region);
    if (!regionNode) return;
    const rect = regionNode.getBoundingClientRect();
    state.drag = { id, rect, startX: event.clientX, startY: event.clientY, originX: element.x_pct, originY: element.y_pct };
    regionNode.querySelector(`[data-id="${id}"]`)?.classList.add('is-dragging');
    window.addEventListener('pointermove', onDrag);
    window.addEventListener('pointerup', endDrag, { once: true });
  };

  const onDrag = (event) => {
    if (!state.drag) return;
    const element = selectedElement();
    if (!element || element.id !== state.drag.id) return;
    const dx = ((event.clientX - state.drag.startX) / state.drag.rect.width) * 100;
    const dy = ((event.clientY - state.drag.startY) / state.drag.rect.height) * 100;
    element.x_pct = Math.max(0, Math.min(100 - element.width_pct, state.drag.originX + dx));
    element.y_pct = Math.max(0, Math.min(100 - element.height_pct, state.drag.originY + dy));
    renderCanvas();
  };

  const endDrag = () => {
    const element = selectedElement();
    if (element) getRegionNode(element.region)?.querySelector(`[data-id="${element.id}"]`)?.classList.remove('is-dragging');
    state.drag = null;
    window.removeEventListener('pointermove', onDrag);
  };

  const collectTemplateFromUi = () => {
    state.template.enabled = !!qs('#mitEnabled')?.checked;
    state.template.footer = state.template.footer || {};
    state.template.footer.enabled = !!qs('#mitFooterEnabled')?.checked;
    state.template.footer.accent_color = qs('#mitAccentColor')?.value || '#d81921';
    state.template.footer.min_height_rem = Number(qs('#mitFooterHeight')?.value) || 3.2;
    return state.template;
  };

  const saveTemplate = async () => {
    const form = new FormData();
    form.append('action', 'save');
    form.append('template', JSON.stringify(collectTemplateFromUi()));
    const payload = await fetchJson(API_URL, { method: 'POST', body: form });
    if (!payload.ok) throw new Error(payload.message || 'فشل الحفظ.');
    state.template = payload.template;
    showFlash(payload.message || 'تم الحفظ.');
  };

  const resetTemplate = async () => {
    if (!window.confirm('استعادة القالب الافتراضي؟')) return;
    const form = new FormData();
    form.append('action', 'reset');
    const payload = await fetchJson(API_URL, { method: 'POST', body: form });
    if (!payload.ok) throw new Error(payload.message || 'فشلت الاستعادة.');
    state.template = payload.template;
    state.selectedId = null;
    qs('#mitInspector').classList.add('hidden');
    qs('#mitEnabled').checked = !!state.template.enabled;
    qs('#mitFooterEnabled').checked = !!state.template.footer?.enabled;
    qs('#mitAccentColor').value = state.template.footer?.accent_color || '#d81921';
    qs('#mitFooterHeight').value = String(state.template.footer?.min_height_rem || 3.2);
    renderCanvas();
    showFlash(payload.message || 'تمت الاستعادة.');
  };

  qs('#mitAddTextBtn')?.addEventListener('click', () => { const el = defaultTextElement(); state.template.elements = [...(state.template.elements || []), el]; selectElement(el.id); });
  qs('#mitAddLogoBtn')?.addEventListener('click', () => { const el = defaultImageElement(); state.template.elements = [...(state.template.elements || []), el]; selectElement(el.id); });
  qs('#mitAddBarcodeBtn')?.addEventListener('click', () => { const el = defaultBarcodeElement(); state.template.elements = [...(state.template.elements || []), el]; selectElement(el.id); });
  qs('#mitAddQrBtn')?.addEventListener('click', () => { const el = defaultQrElement(); state.template.elements = [...(state.template.elements || []), el]; selectElement(el.id); });
  qs('#mitSaveBtn')?.addEventListener('click', () => saveTemplate().catch((e) => showFlash(e.message, false)));
  qs('#mitResetBtn')?.addEventListener('click', () => resetTemplate().catch((e) => showFlash(e.message, false)));

  if (!Array.isArray(state.template.elements)) state.template.elements = [];
  populateQrTargets();
  renderCanvas();
  bindInspector();
})();
