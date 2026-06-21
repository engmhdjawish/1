<?php

declare(strict_types=1);

/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
?>

<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">ربط الصور بالمواد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        اضغط على الصورة للتكبير والتحقق قبل الربط. يمكن اختيار عدة مواد لإنشاء نسخة مستقلة لكل مادة. التفاصيل والشعار تظهر تلقائياً على الموقع عند العرض دون تعديل ملف الصورة.
        <a href="/dashboard/material-image-template.php" class="text-primary font-bold hover:underline">تخصيص قالب العرض</a>
      </p>
    </div>
    <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white text-xs">
      API الأمين:
      <?php if (!empty($apiHealth['ok'])): ?>
        <strong class="text-status-active">متصل</strong>
      <?php else: ?>
        <strong class="text-status-rejected">غير متصل</strong>
      <?php endif; ?>
    </span>
  </div>
</section>

<?php if (!empty($materialFilterOptionsError)): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-sm"><?= h((string) $materialFilterOptionsError) ?></p>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">صور الأمين (صفحات)</h2>
    <div class="flex items-center gap-2">
      <button type="button" id="reloadSourcesBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تحديث</button>
      <span id="sourcePageLabel" class="text-xs text-text-muted">صفحة 1</span>
    </div>
  </div>
  <div class="p-4">
    <div class="flex flex-col gap-3 mb-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-text-muted font-bold">عرض:</span>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-primary bg-primary text-white text-xs font-bold" data-filter="all">كل الصور</button>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-filter="linked">المرتبطة</button>
        <button type="button" class="link-filter-btn h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-filter="unlinked">غير المرتبطة</button>
      </div>
      <div class="flex flex-col sm:flex-row gap-2">
        <input type="search" id="sourceMaterialSearch" class="h-9 flex-1 rounded-lg border border-border-subtle px-3 text-sm" placeholder="بحث مادة (كلمات بأي ترتيب)">
        <button type="button" id="applySourceFiltersBtn" class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold">بحث</button>
        <button type="button" id="deleteAllUnlinkedBtn" class="h-9 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold hidden">حذف كل غير المرتبطة</button>
      </div>
    </div>
    <div id="sourceCards" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"></div>
    <div class="mt-3 flex items-center justify-between">
      <button type="button" id="sourcePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
      <button type="button" id="sourceNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
    </div>
  </div>
</section>

<p id="linkStatus" class="text-sm text-text-muted"></p>

<div id="imageLightbox" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/85 p-4" role="dialog" aria-modal="true">
  <button type="button" id="lightboxCloseBtn" class="absolute top-4 left-4 h-10 w-10 rounded-full bg-white/90 text-lg font-bold" aria-label="إغلاق">×</button>
  <div class="max-w-[96vw] max-h-[92vh] flex flex-col items-center gap-3">
    <img id="lightboxImg" src="" alt="" class="max-w-full max-h-[78vh] object-contain rounded-lg shadow-2xl bg-white transition-transform duration-150">
    <p id="lightboxCaption" class="text-white text-sm text-center max-w-2xl"></p>
    <p class="text-white/70 text-xs text-center">انقر على الصورة للتكبير عند النقطة — انقر مجدداً للعودة</p>
  </div>
</div>

<script>
(() => {
  const API_URL = '/dashboard/material-images-api.php';
  const API_HEADERS = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      headers: { ...API_HEADERS, ...(options.headers || {}) },
    });
    const text = await response.text();
    if (!text.trim()) {
      throw new Error('استجابة فارغة من الخادم. تحقق من GD والخط (Tahoma) أو سجل أخطاء PHP.');
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(`استجابة غير صالحة من الخادم: ${text.slice(0, 180)}`);
    }
  }

  const sourceCards = document.getElementById('sourceCards');
  const sourcePageLabel = document.getElementById('sourcePageLabel');
  const sourcePrevBtn = document.getElementById('sourcePrevBtn');
  const sourceNextBtn = document.getElementById('sourceNextBtn');
  const reloadSourcesBtn = document.getElementById('reloadSourcesBtn');
  const sourceMaterialSearch = document.getElementById('sourceMaterialSearch');
  const applySourceFiltersBtn = document.getElementById('applySourceFiltersBtn');
  const deleteAllUnlinkedBtn = document.getElementById('deleteAllUnlinkedBtn');
  const linkFilterButtons = document.querySelectorAll('.link-filter-btn');
  const linkStatus = document.getElementById('linkStatus');
  const imageLightbox = document.getElementById('imageLightbox');
  const lightboxImg = document.getElementById('lightboxImg');
  const lightboxCaption = document.getElementById('lightboxCaption');
  const lightboxCloseBtn = document.getElementById('lightboxCloseBtn');
  let page = 1;
  let hasMore = false;
  let totalCount = 0;
  const pageSize = 12;
  const sourceMaterialMap = new Map();

  function currentLinkFilter() {
    const active = document.querySelector('.link-filter-btn.border-primary.bg-primary');
    return active?.getAttribute('data-filter') || 'all';
  }

  function setLinkFilter(filter) {
    linkFilterButtons.forEach((btn) => {
      const isActive = btn.getAttribute('data-filter') === filter;
      btn.classList.toggle('border-primary', isActive);
      btn.classList.toggle('bg-primary', isActive);
      btn.classList.toggle('text-white', isActive);
      btn.classList.toggle('border-border-subtle', !isActive);
      btn.classList.toggle('bg-white', !isActive);
    });
    syncBulkDeleteButton();
  }

  function syncBulkDeleteButton() {
    if (!deleteAllUnlinkedBtn) return;
    deleteAllUnlinkedBtn.classList.toggle('hidden', currentLinkFilter() !== 'unlinked');
  }

  function updatePageLabel() {
    if (!sourcePageLabel) return;
    sourcePageLabel.textContent = `صفحة ${page} — ${totalCount} صورة`;
  }

  function ensureCardsPlaceholder() {
    if (!sourceCards) return;
    if (!sourceCards.querySelector('article')) {
      sourceCards.innerHTML = '<div class="text-xs text-text-muted">لا توجد صور في هذه الصفحة.</div>';
    }
  }

  function removeCard(card, item) {
    if (!card) return;
    sourceMaterialMap.delete(cardKey(item));
    card.remove();
    totalCount = Math.max(0, totalCount - 1);
    updatePageLabel();
    ensureCardsPlaceholder();
  }

  function updateCardToUnlinked(card, item) {
    item.is_linked_to_material = false;
    item.linked_material_guid = '';
    item.linked_material_name = '';
    item.linked_material_code = '';

    const badge = card.querySelector('.link-badge');
    if (badge) {
      badge.className = 'link-badge text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full';
      badge.textContent = 'غير مرتبطة';
    }
    const title = card.querySelector('.material-title');
    if (title) {
      title.innerHTML = '<span class="text-text-muted">غير مرتبطة بمادة</span>';
    }
    card.querySelector('.material-code')?.remove();
    card.querySelector('.reassign-btn')?.remove();
    card.querySelector('.unlink-btn')?.remove();
  }

  function handleCardAfterDelete(card, item) {
    removeCard(card, item);
  }

  function handleCardAfterUnlink(card, item) {
    const filter = currentLinkFilter();
    if (filter === 'linked') {
      removeCard(card, item);
      return;
    }
    updateCardToUnlinked(card, item);
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c]));
  }

  function cardKey(item) {
    return item.amine_image_guid || item.file_name || '';
  }

  function itemImageGuid(item, card = null) {
    const fromItem = String(item?.amine_image_guid || '').trim();
    if (fromItem) return fromItem;
    const fromCard = String(card?.dataset?.imageGuid || '').trim();
    return fromCard;
  }

  function chipsHtml(key) {
    const items = sourceMaterialMap.get(key) || [];
    if (!items.length) {
      return '<span class="text-[11px] text-text-muted">لم تُضف مواد بعد — يمكن اختيار أكثر من مادة.</span>';
    }
    return items.map((item, index) => (
      `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-surface-low text-[11px]">`
      + `${escapeHtml(item.label)}`
      + `<button type="button" data-remove="${index}" class="text-red-600" title="إزالة">×</button>`
      + `</span>`
    )).join('');
  }

  function closeSuggestions(card) {
    const sug = card?.querySelector('.suggestions');
    if (!sug) return;
    sug.classList.add('hidden');
    sug.innerHTML = '';
  }

  function openLightbox(url, caption) {
    if (!url || !imageLightbox || !lightboxImg) return;
    lightboxImg.src = url;
    lightboxImg.alt = caption || '';
    lightboxImg.style.transform = '';
    lightboxImg.style.transformOrigin = 'center center';
    lightboxImg.style.cursor = 'zoom-in';
    lightboxImg.dataset.zoomed = '0';
    if (lightboxCaption) lightboxCaption.textContent = caption || '';
    imageLightbox.classList.remove('hidden');
    imageLightbox.classList.add('flex');
  }

  function closeLightbox() {
    if (!imageLightbox || !lightboxImg) return;
    imageLightbox.classList.add('hidden');
    imageLightbox.classList.remove('flex');
    lightboxImg.src = '';
    lightboxImg.style.transform = '';
    lightboxImg.dataset.zoomed = '0';
  }

  function toggleLightboxZoom(event) {
    if (!lightboxImg || lightboxImg.src === '') return;
    const rect = lightboxImg.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 100;
    const y = ((event.clientY - rect.top) / rect.height) * 100;
    if (lightboxImg.dataset.zoomed === '1') {
      lightboxImg.style.transform = '';
      lightboxImg.style.transformOrigin = 'center center';
      lightboxImg.style.cursor = 'zoom-in';
      lightboxImg.dataset.zoomed = '0';
      return;
    }
    lightboxImg.style.transformOrigin = `${x}% ${y}%`;
    lightboxImg.style.transform = 'scale(2.5)';
    lightboxImg.style.cursor = 'zoom-out';
    lightboxImg.dataset.zoomed = '1';
  }

  async function searchMaterials(keyword) {
    const payload = await fetchJson(`${API_URL}?action=material-search&q=${encodeURIComponent(keyword)}&page_size=40`);
    return payload.items || [];
  }

  async function postAction(action, fields) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(fields).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((entry) => form.append(`${key}[]`, entry));
      } else if (value !== undefined && value !== null && value !== '') {
        form.append(key, value);
      }
    });
    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  async function postAssignForm(action, item, items) {
    const form = new FormData();
    form.append('action', action);
    form.append('source_file_name', item.file_name || '');
    form.append('amine_image_guid', item.amine_image_guid || '');
    items.forEach((row) => form.append('material_guids[]', row.guid));

    if (action === 'reassign-materials') {
      form.append('image_guid', item.amine_image_guid || '');
      form.append('material_guid', item.linked_material_guid || '');
    }

    return fetchJson(API_URL, { method: 'POST', body: form });
  }

  function handleCardAfterAssign(card, item) {
    sourceMaterialMap.set(cardKey(item), []);
    if (currentLinkFilter() === 'unlinked' || !item.is_linked_to_material) {
      removeCard(card, item);
      return;
    }
    loadSources(page);
  }

  async function assign(item, items, card, button, statusEl = null) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
      return;
    }
    button.disabled = true;
    linkStatus.textContent = 'جاري الربط...';
    if (statusEl) statusEl.textContent = 'جاري الربط...';
    try {
      const payload = await postAssignForm('assign-materials', item, items);
      linkStatus.textContent = payload.message || '';
      if (statusEl) statusEl.textContent = payload.message || '';
      if (payload.items && payload.items.length && !payload.ok) {
        const firstFail = payload.items.find((row) => row && row.ok === false);
        if (firstFail?.message) {
          const extra = `${firstFail.material_code || ''} ${firstFail.material_name || ''}`.trim();
          const detail = extra ? `${extra}: ${firstFail.message}` : firstFail.message;
          linkStatus.textContent = detail;
          if (statusEl) statusEl.textContent = detail;
        }
      }
      if (payload.ok) handleCardAfterAssign(card, item);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الربط.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
    } finally {
      button.disabled = false;
    }
  }

  async function reassign(item, items, card, button, statusEl) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل للاستبدال.';
      if (statusEl) statusEl.textContent = message;
      return;
    }
    if (!confirm('سيتم فك الربط الحالي ثم ربط الصورة بالمواد المختارة. متابعة؟')) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري الاستبدال...';
    try {
      const payload = await postAssignForm('reassign-materials', item, items);
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterAssign(card, item);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'تعذر تنفيذ الاستبدال.';
      if (statusEl) statusEl.textContent = message;
    } finally {
      button.disabled = false;
    }
  }

  async function unlink(item, card, button, statusEl) {
    if (!confirm('فك ربط هذه الصورة بالمادة الحالية؟')) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري فك الربط...';
    try {
      const payload = await postAction('unlink-image', {
        image_guid: item.amine_image_guid,
        material_guid: item.linked_material_guid,
      });
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterUnlink(card, item);
    } catch {
      if (statusEl) statusEl.textContent = 'تعذر فك الربط.';
    } finally {
      button.disabled = false;
    }
  }

  async function deleteImage(item, card, button, statusEl) {
    const imageGuid = itemImageGuid(item, card);
    const linkedNote = item.is_linked_to_material
      ? ' سيتم أيضاً فك ربطها بالمادة وحذف سجلها من قواعد البيانات.'
      : ' سيتم أيضاً حذف سجلها من قواعد البيانات.';
    if (!confirm(`حذف الصورة من bm000 والموقع نهائياً؟${linkedNote}`)) return;
    button.disabled = true;
    if (statusEl) statusEl.textContent = 'جاري الحذف...';
    try {
      const payload = await postAction('delete-image', {
        image_guid: imageGuid,
        file_name: item.file_name,
        material_guid: item.linked_material_guid,
      });
      if (statusEl) statusEl.textContent = payload.message || '';
      linkStatus.textContent = payload.message || '';
      if (payload.ok) handleCardAfterDelete(card, item);
    } catch {
      if (statusEl) statusEl.textContent = 'تعذر حذف الصورة.';
    } finally {
      button.disabled = false;
    }
  }

  async function deleteAllUnlinked() {
    if (currentLinkFilter() !== 'unlinked') return;
    if (!confirm('حذف جميع الصور غير المرتبطة من bm000 والموقع؟ لا يمكن التراجع.')) return;
    if (deleteAllUnlinkedBtn) deleteAllUnlinkedBtn.disabled = true;
    linkStatus.textContent = 'جاري حذف الصور غير المرتبطة...';
    try {
      const payload = await postAction('delete-unlinked-batch', { max_images: 500 });
      linkStatus.textContent = payload.message || '';
      if (payload.ok || (payload.deleted || 0) > 0) {
        await loadSources(1);
      }
    } catch {
      linkStatus.textContent = 'تعذر حذف الصور غير المرتبطة.';
    } finally {
      if (deleteAllUnlinkedBtn) deleteAllUnlinkedBtn.disabled = false;
    }
  }

  function bindCard(card, item) {
    const key = cardKey(item);
    const input = card.querySelector('.material-input');
    const sug = card.querySelector('.suggestions');
    const chips = card.querySelector('.chips');
    const assignBtn = card.querySelector('.assign-btn');
    const reassignBtn = card.querySelector('.reassign-btn');
    const unlinkBtn = card.querySelector('.unlink-btn');
    const deleteBtn = card.querySelector('.delete-btn');
    const cardStatus = card.querySelector('.card-status');
    const previewBtn = card.querySelector('.preview-btn');
    if (!input || !sug || !chips || !assignBtn || !cardStatus) return;

    previewBtn?.addEventListener('click', () => {
      openLightbox(item.preview_full_url || item.preview_url, item.linked_material_name || '');
    });

    input.addEventListener('input', async () => {
      const q = input.value.trim();
      if (q.length < 2) {
        closeSuggestions(card);
        return;
      }
      const rows = await searchMaterials(q);
      if (!rows.length) {
        sug.innerHTML = '<div class="p-2 text-xs text-text-muted">لا نتائج</div>';
        sug.classList.remove('hidden');
        return;
      }
      sug.innerHTML = rows.map((row) => {
        const guid = row.material_guid || '';
        const code = row.material_code || '';
        const name = row.name || '';
        const label = `${code} ${name}`.trim();
        return `<button type="button" class="block w-full text-right px-3 py-2 text-xs hover:bg-surface-low add-mat" data-guid="${escapeHtml(guid)}" data-label="${escapeHtml(label)}" data-code="${escapeHtml(code)}" data-name="${escapeHtml(name)}">${escapeHtml(label)}</button>`;
      }).join('');
      sug.classList.remove('hidden');
    });

    sug.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('.add-mat') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      event.preventDefault();
      event.stopPropagation();
      const guid = btn.getAttribute('data-guid') || '';
      const label = btn.getAttribute('data-label') || '';
      const code = btn.getAttribute('data-code') || '';
      const name = btn.getAttribute('data-name') || '';
      if (!guid) return;
      const selected = sourceMaterialMap.get(key) || [];
      if (!selected.some((row) => row.guid === guid)) {
        selected.push({ guid, label, code, name });
      }
      sourceMaterialMap.set(key, selected);
      chips.innerHTML = chipsHtml(key);
      closeSuggestions(card);
      input.value = '';
      input.blur();
    });

    chips.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('button[data-remove]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      event.stopPropagation();
      closeSuggestions(card);
      const idx = Number(btn.getAttribute('data-remove') || -1);
      const selected = sourceMaterialMap.get(key) || [];
      if (idx >= 0) selected.splice(idx, 1);
      sourceMaterialMap.set(key, selected);
      chips.innerHTML = chipsHtml(key);
    });

    assignBtn.addEventListener('click', async () => {
      closeSuggestions(card);
      const selected = sourceMaterialMap.get(key) || [];
      if (!selected.length) {
        const message = 'أضف مادة واحدة على الأقل قبل الربط.';
        linkStatus.textContent = message;
        cardStatus.textContent = message;
        return;
      }
      await assign(item, selected, card, assignBtn, cardStatus);
    });

    reassignBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      const selected = sourceMaterialMap.get(key) || [];
      await reassign(item, selected, card, reassignBtn, cardStatus);
    });

    unlinkBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      await unlink(item, card, unlinkBtn, cardStatus);
    });

    deleteBtn?.addEventListener('click', async () => {
      closeSuggestions(card);
      await deleteImage(item, card, deleteBtn, cardStatus);
    });
  }

  function renderSources(payload) {
    const items = payload.items || [];
    if (!items.length) {
      sourceCards.innerHTML = '<div class="text-xs text-text-muted">لا توجد صور في هذه الصفحة.</div>';
    } else {
      sourceCards.innerHTML = items.map((item) => {
        const key = cardKey(item);
        const isLinked = !!item.is_linked_to_material;
        const materialTitle = isLinked
          ? escapeHtml(item.linked_material_name || 'مادة مرتبطة')
          : '<span class="text-text-muted">غير مرتبطة بمادة</span>';
        const materialCode = item.linked_material_code
          ? `<span class="material-code text-xs text-text-muted font-mono" dir="ltr">${escapeHtml(item.linked_material_code)}</span>`
          : '';
        const linkBadge = isLinked
          ? '<span class="link-badge text-[10px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">مرتبطة</span>'
          : '<span class="link-badge text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full">غير مرتبطة</span>';
        const preview = item.preview_url
          ? `<button type="button" class="preview-btn group relative w-full h-48 rounded-lg border border-border-subtle bg-surface-low overflow-hidden" title="تكبير الصورة">
              <img src="${escapeHtml(item.preview_url)}" class="w-full h-full object-contain" alt="">
              <span class="absolute bottom-2 left-2 rounded-md bg-black/60 text-white text-[10px] px-2 py-1 opacity-90 group-hover:bg-black/80">🔍 تكبير</span>
            </button>`
          : '<div class="w-full h-48 rounded-lg border border-border-subtle bg-surface-low"></div>';
        const reassignBlock = isLinked
          ? `<button type="button" class="reassign-btn h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold w-full">استبدال الربط بمواد جديدة</button>`
          : '';
        const unlinkBlock = isLinked
          ? `<button type="button" class="unlink-btn h-8 px-3 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-xs font-bold w-full">فك الربط</button>`
          : '';

        return `<article class="rounded-xl border border-border-subtle p-3 bg-white space-y-2" data-key="${escapeHtml(key)}" data-image-guid="${escapeHtml(item.amine_image_guid || '')}" data-file-name="${escapeHtml(item.file_name || '')}" data-linked="${isLinked ? '1' : '0'}">
          ${preview}
          <div class="space-y-1">
            <div class="flex items-center justify-between gap-2">${linkBadge}</div>
            <p class="material-title text-sm font-bold leading-snug">${materialTitle}</p>
            ${materialCode}
          </div>
          <div class="relative">
            <input class="material-input h-9 w-full rounded-lg border border-border-subtle px-3 text-xs" placeholder="ابحث عن مادة (كلمات بأي ترتيب)...">
            <div class="suggestions hidden absolute z-20 mt-1 w-full bg-white border border-border-subtle rounded-lg shadow max-h-48 overflow-auto"></div>
          </div>
          <div class="chips flex flex-wrap gap-1">${chipsHtml(key)}</div>
          <p class="text-[10px] text-text-muted leading-relaxed rounded-lg border border-border-subtle bg-surface-low/40 px-2 py-1.5">على الموقع تُعرض الصورة مع إطار يتضمن رمز المادة واسمها والتعبئة وشعار الشركة والهاتف.</p>
          <button type="button" class="assign-btn h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold w-full">ربط المواد المضافة</button>
          ${reassignBlock}
          ${unlinkBlock}
          <button type="button" class="delete-btn h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold w-full">حذف الصورة</button>
          <p class="card-status text-[11px] text-text-muted"></p>
        </article>`;
      }).join('');

      items.forEach((item, index) => {
        const card = sourceCards.children[index];
        if (card) bindCard(card, item);
      });
    }

    page = Number(payload.page || 1);
    hasMore = !!payload.has_more;
    totalCount = Number(payload.total_count || 0);
    updatePageLabel();
    sourcePrevBtn.disabled = page <= 1;
    sourceNextBtn.disabled = !hasMore;
    syncBulkDeleteButton();
  }

  async function loadSources(targetPage = 1) {
    const linkFilter = currentLinkFilter();
    const materialQuery = sourceMaterialSearch?.value.trim() || '';
    const payload = await fetchJson(`${API_URL}?action=link-sources-page&page=${targetPage}&page_size=${pageSize}&link_filter=${encodeURIComponent(linkFilter)}&material_query=${encodeURIComponent(materialQuery)}`);
    if (!payload.ok) {
      linkStatus.textContent = 'تعذر تحميل الصور.';
      return;
    }
    renderSources(payload);
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (!target.closest('.material-input') && !target.closest('.suggestions')) {
      sourceCards?.querySelectorAll('article').forEach((card) => closeSuggestions(card));
    }
  });

  lightboxCloseBtn?.addEventListener('click', closeLightbox);
  lightboxImg?.addEventListener('click', (event) => {
    event.stopPropagation();
    toggleLightboxZoom(event);
  });
  imageLightbox?.addEventListener('click', (event) => {
    if (event.target === imageLightbox) closeLightbox();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeLightbox();
  });

  sourcePrevBtn?.addEventListener('click', () => { if (page > 1) loadSources(page - 1); });
  sourceNextBtn?.addEventListener('click', () => { if (hasMore) loadSources(page + 1); });
  reloadSourcesBtn?.addEventListener('click', () => loadSources(page));
  applySourceFiltersBtn?.addEventListener('click', () => loadSources(1));
  deleteAllUnlinkedBtn?.addEventListener('click', deleteAllUnlinked);
  linkFilterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const filter = btn.getAttribute('data-filter') || 'all';
      setLinkFilter(filter);
      loadSources(1);
    });
  });
  sourceMaterialSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      loadSources(1);
    }
  });
  setLinkFilter('all');
  loadSources(1);
})();
</script>
