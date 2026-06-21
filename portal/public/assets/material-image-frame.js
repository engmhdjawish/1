(() => {
  const template = window.__materialImageTemplate;
  if (!template || !template.enabled) {
    window.renderMaterialImageFrame = null;
    return;
  }

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const resolveQrUrl = (element, fields) => {
    const target = element.qr_target || 'business.whatsapp';
    if (target === 'custom') return String(element.qr_custom_url || '').trim();
    const value = String(fields[target] || '').trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value)) return value;
    if (value.startsWith('/')) return `${window.location.origin}${value}`;
    return value;
  };

  const imageInnerStyle = (style = {}) => {
    const scale = Number(style.image_scale ?? 1) || 1;
    const cropX = Number(style.crop_x_pct ?? 50);
    const cropY = Number(style.crop_y_pct ?? 50);
    return [
      'width:100%',
      'height:100%',
      `object-fit:${style.object_fit || 'contain'}`,
      `object-position:${cropX}% ${cropY}%`,
      `transform:scale(${scale})`,
      `transform-origin:${cropX}% ${cropY}%`,
    ].join(';');
  };

  const cssFromElement = (element) => {
    const style = element.style || {};
    const direction = style.direction || 'rtl';
    const align = element.align || 'start';
    const parts = [
      `left:${element.x_pct}%`,
      `top:${element.y_pct}%`,
      `width:${element.width_pct}%`,
      `height:${element.height_pct}%`,
      `z-index:${element.z_index || 1}`,
      `opacity:${style.opacity ?? 1}`,
      `direction:${direction}`,
      `text-align:${align === 'center' ? 'center' : align}`,
      `align-items:${align === 'end' ? 'flex-end' : (align === 'center' ? 'center' : 'flex-start')}`,
      `justify-content:${element.valign === 'end' ? 'flex-end' : (element.valign === 'start' ? 'flex-start' : 'center')}`,
    ];
    if (element.type === 'text') {
      const fontEm = style.font_size_em ?? style.font_size_rem ?? 0.78;
      parts.push(`--mif-el-font-em:${fontEm}`);
      parts.push(`color:${style.color || '#fff'}`);
      parts.push(`font-weight:${style.font_weight || 700}`);
      if (style.nowrap) parts.push('white-space:nowrap', 'overflow:hidden', 'text-overflow:ellipsis');
      if (style.background) parts.push(`background:${style.background}`);
      if (style.border_radius_rem) parts.push(`border-radius:${style.border_radius_rem}rem`);
      if (style.padding_rem) parts.push(`padding:${style.padding_rem}rem`);
    }
    if (element.type === 'image') {
      if (style.background) parts.push(`background:${style.background}`);
      if (style.border_radius_rem) parts.push(`border-radius:${style.border_radius_rem}rem`);
      if (style.padding_rem) parts.push(`padding:${style.padding_rem}rem`);
    }
    if (element.type === 'barcode' || element.type === 'qrcode') {
      parts.push('display:flex', 'align-items:center', 'justify-content:center');
    }
    return parts.join(';');
  };

  const resolveElement = (element, fields) => {
    if (element.type === 'text') {
      const text = String(fields[element.field] || '').trim();
      return text ? { ...element, text } : null;
    }
    if (element.type === 'image') {
      const url = element.field === 'image.fixed'
        ? String(element.image_url || '').trim()
        : String(fields[element.field] || '').trim();
      return url ? { ...element, image_url: url } : null;
    }
    if (element.type === 'barcode') {
      const code = String(fields[element.field] || fields['material.code'] || '').trim();
      return code ? { ...element, barcode_value: code } : null;
    }
    if (element.type === 'qrcode') {
      const url = resolveQrUrl(element, fields);
      return url ? { ...element, qr_url: url } : null;
    }
    return null;
  };

  const renderElementHtml = (element) => {
    if (element.type === 'text') {
      return `<div class="material-image-frame__el-text">${esc(element.text)}</div>`;
    }
    if (element.type === 'image') {
      return `<div class="material-image-frame__el-imagebox"><img class="material-image-frame__el-image" src="${esc(element.image_url)}" alt="" style="${imageInnerStyle(element.style)}"></div>`;
    }
    if (element.type === 'barcode') {
      const fg = esc(element.style?.foreground || '#000000');
      const bg = esc(element.style?.background || '#ffffff');
      const src = `/api/barcode.php?code=${encodeURIComponent(element.barcode_value)}&h=64&fg=${encodeURIComponent(fg)}&bg=${encodeURIComponent(bg)}`;
      return `<img class="material-image-frame__el-barcode" src="${src}" alt="">`;
    }
    if (element.type === 'qrcode') {
      const fg = esc(element.style?.foreground || '#000000');
      const bg = esc(element.style?.background || '#ffffff');
      const src = `/api/qr.php?d=${encodeURIComponent(element.qr_url)}&s=128&fg=${encodeURIComponent(fg)}&bg=${encodeURIComponent(bg)}`;
      return `<img class="material-image-frame__el-qrcode" src="${src}" alt="" data-mif-qrcode-fallback="${esc(element.qr_url)}">`;
    }
    return '';
  };

  const renderLayer = (elements, region, fields) => {
    const items = elements
      .filter((element) => element.region === region)
      .map((element) => resolveElement(element, fields))
      .filter(Boolean);
    if (!items.length) return '';
    return `<div class="material-image-frame__layer" aria-hidden="true">${items.map((element) => (
      `<div class="material-image-frame__el material-image-frame__el--${esc(element.type)}" style="${cssFromElement(element)}">${renderElementHtml(element)}</div>`
    )).join('')}</div>`;
  };

  window.enhanceMaterialImageQrFallback = (root = document) => {
    if (!window.QRCode) return;
    root.querySelectorAll('img[data-mif-qrcode-fallback]').forEach((img) => {
      if (img.dataset.mifQrEnhanced === '1') return;
      img.addEventListener('error', async () => {
        const url = img.getAttribute('data-mif-qrcode-fallback');
        if (!url) return;
        try {
          const canvas = document.createElement('canvas');
          await window.QRCode.toCanvas(canvas, url, { width: img.clientWidth || 120, margin: 1 });
          img.replaceWith(canvas);
          canvas.className = 'material-image-frame__el-qrcode';
        } catch (_) { /* ignore */ }
      }, { once: true });
      img.dataset.mifQrEnhanced = '1';
    });
  };

  window.renderMaterialImageFrame = (product, variant = 'detail') => {
    const fields = product.displayFields || {};
    const elements = Array.isArray(template.elements) ? template.elements : [];
    const resolved = elements.map((element) => resolveElement(element, fields)).filter(Boolean);
    if (!resolved.length) return null;

    const footer = template.footer || {};
    const photo = template.photo || {};
    const footerEnabled = footer.enabled !== false;
    const frameStyle = [
      `--mif-accent-color:${footer.accent_color || '#d81921'}`,
      `--mif-accent-width:${footer.accent_width_rem || 0.28}rem`,
      `--mif-footer-bg:${footer.background || 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)'}`,
      `--mif-footer-padding:${footer.padding_rem || 0.6}rem`,
      `--mif-footer-min-height:${footer.min_height_rem || 3.2}rem`,
      `--mif-footer-font-base:${footer.font_base_rem || 1}rem`,
      `--mif-photo-bg:${photo.background || '#f3f4f6'}`,
    ].join(';');

    const imageHtml = product.showImages
      ? (product.imageGuid
        ? `<img src="/api/image.php?id=${encodeURIComponent(product.imageGuid)}" alt="${esc(product.name)}">`
        : '<span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>')
      : '';

    const footerHtml = footerEnabled
      ? `<div class="material-image-frame__footer">${renderLayer(elements, 'footer', fields)}</div>`
      : '';

    const html = `
      <div class="material-image-frame material-image-frame--template material-image-frame--${esc(variant)}${footerEnabled ? '' : ' material-image-frame--no-footer'}" style="${frameStyle}">
        <div class="material-image-frame__stack">
          <div class="material-image-frame__photo">
            ${imageHtml}
            ${renderLayer(elements, 'photo', fields)}
          </div>
          ${footerHtml}
          ${renderLayer(elements, 'frame', fields)}
        </div>
      </div>`;

    window.setTimeout(() => window.enhanceMaterialImageQrFallback(), 0);
    return html;
  };

  document.addEventListener('DOMContentLoaded', () => window.enhanceMaterialImageQrFallback());
})();
