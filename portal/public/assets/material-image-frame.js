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

  const cssFromElement = (element) => {
    const style = element.style || {};
    const parts = [
      `left:${element.x_pct}%`,
      `top:${element.y_pct}%`,
      `width:${element.width_pct}%`,
      `height:${element.height_pct}%`,
      `z-index:${element.z_index || 1}`,
      `opacity:${style.opacity ?? 1}`,
      `text-align:${element.align === 'end' ? 'right' : (element.align === 'center' ? 'center' : 'left')}`,
      `justify-content:${element.valign === 'end' ? 'flex-end' : (element.valign === 'start' ? 'flex-start' : 'center')}`,
    ];
    if (element.type === 'text') {
      parts.push(`color:${style.color || '#fff'}`);
      parts.push(`font-size:${style.font_size_rem || 0.72}rem`);
      parts.push(`font-weight:${style.font_weight || 700}`);
      if (style.nowrap) {
        parts.push('white-space:nowrap', 'overflow:hidden', 'text-overflow:ellipsis');
      }
      if (style.direction) parts.push(`direction:${style.direction}`);
      if (style.background) parts.push(`background:${style.background}`);
      if (style.border_radius_rem) parts.push(`border-radius:${style.border_radius_rem}rem`);
      if (style.padding_rem) parts.push(`padding:${style.padding_rem}rem`);
    }
    if (element.type === 'image') {
      if (style.background) parts.push(`background:${style.background}`);
      if (style.border_radius_rem) parts.push(`border-radius:${style.border_radius_rem}rem`);
      if (style.padding_rem) parts.push(`padding:${style.padding_rem}rem`);
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
    return null;
  };

  const renderLayer = (elements, region, fields) => {
    const items = elements
      .filter((element) => element.region === region)
      .map((element) => resolveElement(element, fields))
      .filter(Boolean);
    if (!items.length) return '';
    return `<div class="material-image-frame__layer" aria-hidden="true">${items.map((element) => {
      if (element.type === 'text') {
        return `<div class="material-image-frame__el" style="${cssFromElement(element)}"><div class="material-image-frame__el-text">${esc(element.text)}</div></div>`;
      }
      return `<div class="material-image-frame__el" style="${cssFromElement(element)}"><img class="material-image-frame__el-image" src="${esc(element.image_url)}" alt="" style="object-fit:${esc(element.style?.object_fit || 'contain')}"></div>`;
    }).join('')}</div>`;
  };

  window.renderMaterialImageFrame = (product, variant = 'detail') => {
    const fields = product.displayFields || {};
    window.__materialImageFields = fields;
    const elements = Array.isArray(template.elements) ? template.elements : [];
    const resolved = elements
      .map((element) => resolveElement(element, fields))
      .filter(Boolean);
    if (!resolved.length) return null;

    const footer = template.footer || {};
    const photo = template.photo || {};
    const footerEnabled = footer.enabled !== false;
    const frameStyle = [
      `--mif-accent-color:${footer.accent_color || '#d81921'}`,
      `--mif-accent-width:${footer.accent_width_rem || 0.28}rem`,
      `--mif-footer-bg:${footer.background || 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)'}`,
      `--mif-footer-padding:${footer.padding_rem || 0.6}rem`,
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

    return `
      <div class="material-image-frame material-image-frame--template material-image-frame--${esc(variant)}${footerEnabled ? '' : ' material-image-frame--no-footer'}" style="${frameStyle}">
        <div class="material-image-frame__photo">
          ${imageHtml}
          ${renderLayer(elements, 'photo', fields)}
        </div>
        ${footerHtml}
      </div>`;
  };
})();
