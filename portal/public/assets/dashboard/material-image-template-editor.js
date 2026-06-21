(() => {
  const root = document.getElementById('materialImageTemplateEditor');
  if (!root) return;

  const API_URL = root.dataset.api || '/dashboard/material-image-template-api.php';
  const fieldCatalog = JSON.parse(root.dataset.fieldCatalog || '{}');
  const sampleFields = JSON.parse(root.dataset.sampleFields || '{}');
  const companyLogo = root.dataset.companyLogo || '';

  const state = {
    template: JSON.parse(root.dataset.template || '{}'),
    selectedId: null,
    drag: null,
  };

  const qs = (sel) => root.querySelector(sel) || document.querySelector(sel);
  const flashEl = qs('#mitFlash');
  const canvas = qs('#mitCanvas');
  const inspector = qs('#mitInspector');
  const elementsList = qs('#mitElementsList');
  const fieldSource = qs('#mitFieldSource');
  const fixedImageWrap = qs('#mitFixedImageWrap');
  const fixedImageInput = qs('#mit-fixed-image-input');

  const allFieldOptions = () => {
    const options = [];
    Object.keys(fieldCatalog).forEach((group) => {
      (fieldCatalog[group] || []).forEach((item) => {
        options.push(item);
      });
    });
    options.push({ key: 'image.fixed', label: 'صورة ثابتة من المكتبة', type: 'image' });
    return options;
  };

  const showFlash = (message, ok = true) => {
    if (!flashEl) return;
    flashEl.textContent = message;
    flashEl.className = `rounded-xl border px-4 py-3 text-sm font-semibold ${ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'}`;
    flashEl.classList.remove('hidden');
    window.setTimeout(() => flashEl.classList.add('hidden'), 4000);
  };

  const fetchJson = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      ...options,
    });
    const text = await response.text();
    if (!text.trim()) throw new Error('استجابة فارغة من الخادم.');
    return JSON.parse(text);
  };

  const newId = () => `el_${Math.random().toString(36).slice(2, 9)}`;

  const defaultTextElement = () => ({
    id: newId(),
    type: 'text',
    field: 'material.product_line',
    image_url: '',
    region: 'footer',
    x_pct: 5,
    y_pct: 20,
    width_pct: 50,
    height_pct: 25,
    z_index: 2,
    align: 'start',
    valign: 'center',
    style: {
      color: '#ffffff',
      font_size_rem: 0.78,
      font_weight: 800,
      background: '',
      border_radius_rem: 0,
      padding_rem: 0,
      opacity: 1,
      nowrap: true,
      direction: 'rtl',
    },
  });

  const defaultImageElement = () => ({
    id: newId(),
    type: 'image',
    field: companyLogo ? 'business.company_logo' : 'image.fixed',
    image_url: companyLogo || '',
    region: 'photo',
    x_pct: 5,
    y_pct: 5,
    width_pct: 18,
    height_pct: 18,
    z_index: 3,
    align: 'center',
    valign: 'center',
    style: {
      object_fit: 'contain',
      background: 'rgba(255,255,255,0.9)',
      border_radius_rem: 0.35,
      padding_rem: 0.12,
      opacity: 1,
    },
  });

  const resolveElementContent = (element) => {
    if (element.type === 'text') {
      return sampleFields[element.field] || 'نص تجريبي';
    }
    if (element.field === 'image.fixed') {
      return element.image_url || '';
    }
    return sampleFields[element.field] || companyLogo || '';
  };

  const elementLabel = (element) => {
    const match = allFieldOptions().find((item) => item.key === element.field);
    const base = match ? match.label : element.field;
    return `${element.type === 'image' ? 'صورة' : 'نص'}: ${base}`;
  };

  const cssFromElement = (element) => {
    const style = element.style || {};
    const css = {
      left: `${element.x_pct}%`,
      top: `${element.y_pct}%`,
      width: `${element.width_pct}%`,
      height: `${element.height_pct}%`,
      zIndex: String(element.z_index || 1),
      opacity: String(style.opacity ?? 1),
      textAlign: element.align === 'end' ? 'right' : (element.align === 'center' ? 'center' : 'left'),
      justifyContent: element.valign === 'end' ? 'flex-end' : (element.valign === 'start' ? 'flex-start' : 'center'),
    };
    if (element.type === 'text') {
      css.color = style.color || '#fff';
      css.fontSize = `${style.font_size_rem || 0.72}rem`;
      css.fontWeight = String(style.font_weight || 700);
      if (style.nowrap) {
        css.whiteSpace = 'nowrap';
        css.overflow = 'hidden';
        css.textOverflow = 'ellipsis';
      }
      if (style.direction) css.direction = style.direction;
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

  const applyCanvasTheme = () => {
    const footer = state.template.footer || {};
    const photo = state.template.photo || {};
    canvas.classList.toggle('material-image-frame--no-footer', !footer.enabled);
    canvas.style.setProperty('--mif-accent-color', footer.accent_color || '#d81921');
    canvas.style.setProperty('--mif-accent-width', `${footer.accent_width_rem || 0.28}rem`);
    canvas.style.setProperty('--mif-footer-bg', footer.background || 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)');
    canvas.style.setProperty('--mif-footer-padding', `${footer.padding_rem || 0.6}rem`);
    canvas.style.setProperty('--mif-photo-bg', photo.background || '#f3f4f6');
    canvas.classList.add('material-image-frame--template');
  };

  const renderCanvas = () => {
    applyCanvasTheme();
    ['photo', 'footer'].forEach((region) => {
      const layer = canvas.querySelector(`[data-layer="${region}"]`);
      if (!layer) return;
      layer.innerHTML = '';
      (state.template.elements || [])
        .filter((el) => el.region === region)
        .sort((a, b) => (a.z_index || 0) - (b.z_index || 0))
        .forEach((element) => {
          const node = document.createElement('div');
          node.className = `mit-el${state.selectedId === element.id ? ' is-selected' : ''}`;
          node.dataset.id = element.id;
          Object.assign(node.style, cssFromElement(element));

          const inner = document.createElement('div');
          inner.className = 'mit-el__inner';
          Object.assign(inner.style, {
            alignItems: element.align === 'end' ? 'flex-end' : (element.align === 'center' ? 'center' : 'flex-start'),
            justifyContent: element.valign === 'end' ? 'flex-end' : (element.valign === 'start' ? 'flex-start' : 'center'),
          });

          if (element.type === 'text') {
            const text = document.createElement('div');
            text.className = 'mit-el__text';
            text.textContent = resolveElementContent(element);
            inner.appendChild(text);
          } else {
            const url = resolveElementContent(element);
            if (url) {
              const img = document.createElement('img');
              img.className = 'mit-el__image';
              img.src = url;
              img.alt = '';
              img.style.objectFit = (element.style && element.style.object_fit) || 'contain';
              inner.appendChild(img);
            }
          }

          node.appendChild(inner);
          node.addEventListener('pointerdown', (event) => startDrag(event, element.id));
          layer.appendChild(node);
        });
    });
    renderElementsList();
  };

  const renderElementsList = () => {
    if (!elementsList) return;
    const elements = state.template.elements || [];
    qs('#mitElementCount').textContent = String(elements.length);
    elementsList.innerHTML = elements.map((element) => `
      <button type="button" class="mit-elements-list-item${state.selectedId === element.id ? ' is-active' : ''}" data-select-id="${element.id}">
        <span>${elementLabel(element)}</span>
        <span class="text-[10px] text-text-muted">${element.region === 'photo' ? 'صورة' : 'سفلي'}</span>
      </button>
    `).join('');
    elementsList.querySelectorAll('[data-select-id]').forEach((button) => {
      button.addEventListener('click', () => selectElement(button.dataset.selectId));
    });
  };

  const selectedElement = () => (state.template.elements || []).find((el) => el.id === state.selectedId) || null;

  const selectElement = (id) => {
    state.selectedId = id;
    const element = selectedElement();
    if (!element || !inspector) {
      inspector.classList.add('hidden');
      renderCanvas();
      return;
    }
    inspector.classList.remove('hidden');
    qs('#mitFieldRegion').value = element.region;
    populateFieldSource(element.type);
    fieldSource.value = element.field;
    qs('#mitWidthPct').value = String(element.width_pct);
    qs('#mitHeightPct').value = String(element.height_pct);
    qs('#mitAlign').value = element.align;
    qs('#mitValign').value = element.valign;
    fixedImageWrap.classList.toggle('hidden', element.field !== 'image.fixed');
    if (fixedImageInput) fixedImageInput.value = element.image_url || '';
    const preview = qs('#mit-fixed-image-preview');
    if (preview && element.image_url) {
      preview.innerHTML = `<img src="${element.image_url}" alt="" class="h-full w-full object-cover">`;
    }
    qs('#mitTextStyleWrap').classList.toggle('hidden', element.type !== 'text');
    qs('#mitImageStyleWrap').classList.toggle('hidden', element.type !== 'image');
    if (element.type === 'text') {
      qs('#mitTextColor').value = rgbToHex(element.style.color || '#ffffff');
      qs('#mitFontSize').value = String(element.style.font_size_rem || 0.72);
      qs('#mitFontWeight').value = String(element.style.font_weight || 700);
      qs('#mitNowrap').checked = !!element.style.nowrap;
    } else {
      qs('#mitOpacity').value = String(element.style.opacity ?? 1);
      qs('#mitImageBg').value = element.style.background || '';
    }
    renderCanvas();
  };

  const populateFieldSource = (type) => {
    const options = allFieldOptions().filter((item) => item.type === type || (type === 'image' && item.type === 'image'));
  if (type === 'image') {
      options.push({ key: 'image.fixed', label: 'صورة ثابتة من المكتبة', type: 'image' });
    }
    fieldSource.innerHTML = options.map((item) => `<option value="${item.key}">${item.label}</option>`).join('');
  };

  const rgbToHex = (color) => {
    if (/^#/.test(color)) return color.slice(0, 7);
    const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!match) return '#ffffff';
    const toHex = (n) => Number(n).toString(16).padStart(2, '0');
    return `#${toHex(match[1])}${toHex(match[2])}${toHex(match[3])}`;
  };

  const updateSelected = (patch) => {
    const element = selectedElement();
    if (!element) return;
    Object.assign(element, patch);
    renderCanvas();
  };

  const bindInspector = () => {
    qs('#mitFieldRegion')?.addEventListener('change', (event) => {
      updateSelected({ region: event.target.value });
    });
    fieldSource?.addEventListener('change', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.field = event.target.value;
      fixedImageWrap.classList.toggle('hidden', element.field !== 'image.fixed');
      renderCanvas();
    });
    ['#mitWidthPct', '#mitHeightPct'].forEach((selector) => {
      qs(selector)?.addEventListener('input', (event) => {
        const element = selectedElement();
        if (!element) return;
        const key = selector.includes('Width') ? 'width_pct' : 'height_pct';
        element[key] = Number(event.target.value) || 1;
        renderCanvas();
      });
    });
    qs('#mitAlign')?.addEventListener('change', (event) => updateSelected({ align: event.target.value }));
    qs('#mitValign')?.addEventListener('change', (event) => updateSelected({ valign: event.target.value }));
    qs('#mitTextColor')?.addEventListener('input', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.color = event.target.value;
      renderCanvas();
    });
    qs('#mitFontSize')?.addEventListener('input', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.font_size_rem = Number(event.target.value) || 0.72;
      renderCanvas();
    });
    qs('#mitFontWeight')?.addEventListener('input', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.font_weight = Number(event.target.value) || 700;
      renderCanvas();
    });
    qs('#mitNowrap')?.addEventListener('change', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.nowrap = event.target.checked;
      renderCanvas();
    });
    qs('#mitOpacity')?.addEventListener('input', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.opacity = Number(event.target.value);
      renderCanvas();
    });
    qs('#mitImageBg')?.addEventListener('input', (event) => {
      const element = selectedElement();
      if (!element) return;
      element.style.background = event.target.value;
      renderCanvas();
    });
    qs('#mitDeleteElementBtn')?.addEventListener('click', () => {
      if (!state.selectedId) return;
      state.template.elements = (state.template.elements || []).filter((el) => el.id !== state.selectedId);
      state.selectedId = null;
      inspector.classList.add('hidden');
      renderCanvas();
    });
    fixedImageInput?.addEventListener('input', () => {
      const element = selectedElement();
      if (!element) return;
      element.field = 'image.fixed';
      element.image_url = fixedImageInput.value;
      renderCanvas();
    });
    fixedImageInput?.addEventListener('change', () => {
      const element = selectedElement();
      if (!element) return;
      element.field = 'image.fixed';
      element.image_url = fixedImageInput.value;
      renderCanvas();
    });
  };

  const startDrag = (event, id) => {
    event.preventDefault();
    selectElement(id);
    const element = selectedElement();
    if (!element) return;
    const regionNode = canvas.querySelector(`[data-region="${element.region}"]`);
    if (!regionNode) return;
    const rect = regionNode.getBoundingClientRect();
    state.drag = {
      id,
      region: element.region,
      rect,
      startX: event.clientX,
      startY: event.clientY,
      originX: element.x_pct,
      originY: element.y_pct,
    };
    const node = regionNode.querySelector(`[data-id="${id}"]`);
    node?.classList.add('is-dragging');
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
    if (element) {
      const node = canvas.querySelector(`[data-id="${element.id}"]`);
      node?.classList.remove('is-dragging');
    }
    state.drag = null;
    window.removeEventListener('pointermove', onDrag);
  };

  const collectTemplateFromUi = () => {
    state.template.enabled = !!qs('#mitEnabled')?.checked;
    state.template.footer = state.template.footer || {};
    state.template.footer.enabled = !!qs('#mitFooterEnabled')?.checked;
    state.template.footer.accent_color = qs('#mitAccentColor')?.value || '#d81921';
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
    inspector.classList.add('hidden');
    qs('#mitEnabled').checked = !!state.template.enabled;
    qs('#mitFooterEnabled').checked = !!state.template.footer?.enabled;
    qs('#mitAccentColor').value = state.template.footer?.accent_color || '#d81921';
    renderCanvas();
    showFlash(payload.message || 'تمت الاستعادة.');
  };

  qs('#mitAddTextBtn')?.addEventListener('click', () => {
    const element = defaultTextElement();
    state.template.elements = [...(state.template.elements || []), element];
    selectElement(element.id);
  });

  qs('#mitAddLogoBtn')?.addEventListener('click', () => {
    const element = defaultImageElement();
    state.template.elements = [...(state.template.elements || []), element];
    selectElement(element.id);
  });

  qs('#mitSaveBtn')?.addEventListener('click', () => {
    saveTemplate().catch((error) => showFlash(error.message, false));
  });

  qs('#mitResetBtn')?.addEventListener('click', () => {
    resetTemplate().catch((error) => showFlash(error.message, false));
  });

  if (!Array.isArray(state.template.elements)) {
    state.template.elements = [];
  }

  renderCanvas();
  bindInspector();
})();
