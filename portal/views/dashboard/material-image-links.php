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
        صفحة مستقلة للربط: القائمة والفلاتر تُجلب مباشرة من API الأمين (bm000 + PictureGUID) مع ترقيم صفحات.
        كل صورة تعرض المادة المرتبطة بها فقط — استعلام واحد لكل صفحة بدون فحص صورة صورة.
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
    <h2 class="font-bold">صور أساسية (صفحات)</h2>
    <div class="flex items-center gap-2">
      <button type="button" id="reloadSourcesBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold">تحديث</button>
      <span id="sourcePageLabel" class="text-xs text-text-muted">صفحة 1</span>
    </div>
  </div>
  <div class="p-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3">
      <select id="sourceLinkFilter" class="h-9 rounded-lg border border-border-subtle px-2 text-sm">
        <option value="all">كل الصور</option>
        <option value="linked">المرتبطة فقط</option>
        <option value="unlinked">غير المرتبطة فقط</option>
      </select>
      <input type="search" id="sourceMaterialSearch" class="h-9 rounded-lg border border-border-subtle px-3 text-sm" placeholder="بحث باسم/كود المادة المرتبطة">
      <button type="button" id="applySourceFiltersBtn" class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold">تطبيق الفلاتر</button>
    </div>
    <div id="sourceCards" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"></div>
    <div class="mt-3 flex items-center justify-between">
      <button type="button" id="sourcePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
      <button type="button" id="sourceNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
    </div>
  </div>
</section>

<p id="linkStatus" class="text-sm text-text-muted"></p>

<script>
(() => {
  const API_URL = '/dashboard/material-images-api.php';
  const sourceCards = document.getElementById('sourceCards');
  const sourcePageLabel = document.getElementById('sourcePageLabel');
  const sourcePrevBtn = document.getElementById('sourcePrevBtn');
  const sourceNextBtn = document.getElementById('sourceNextBtn');
  const reloadSourcesBtn = document.getElementById('reloadSourcesBtn');
  const sourceLinkFilter = document.getElementById('sourceLinkFilter');
  const sourceMaterialSearch = document.getElementById('sourceMaterialSearch');
  const applySourceFiltersBtn = document.getElementById('applySourceFiltersBtn');
  const linkStatus = document.getElementById('linkStatus');
  let page = 1;
  let hasMore = false;
  const pageSize = 12;
  const sourceMaterialMap = new Map();

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c]));
  }

  function chipsHtml(fileName) {
    const items = sourceMaterialMap.get(fileName) || [];
    if (!items.length) return '<span class="text-[11px] text-text-muted">لا توجد مواد مضافة.</span>';
    return items.map((item, index) => `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-surface-low text-[11px]">${escapeHtml(item.label)}<button type="button" data-remove="${index}" class="text-red-600">×</button></span>`).join('');
  }

  async function searchMaterials(keyword) {
    const response = await fetch(`${API_URL}?action=material-search&q=${encodeURIComponent(keyword)}&has_image=0`);
    const payload = await response.json();
    return payload.items || [];
  }

  async function assign(fileName, items, button, statusEl = null) {
    if (!items.length) {
      const message = 'أضف مادة واحدة على الأقل.';
      linkStatus.textContent = message;
      if (statusEl) statusEl.textContent = message;
      return;
    }
    const form = new FormData();
    form.append('action', 'assign-materials');
    form.append('source_file_name', fileName);
    items.forEach((item) => form.append('material_guids[]', item.guid));
    button.disabled = true;
    linkStatus.textContent = 'جاري الربط...';
    if (statusEl) statusEl.textContent = 'جاري الربط...';
    try {
      const response = await fetch(API_URL, { method: 'POST', body: form });
      const payload = await response.json();
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
      if (payload.ok) {
        sourceMaterialMap.set(fileName, []);
        await loadSources(page);
      }
    } catch {
      linkStatus.textContent = 'تعذر تنفيذ الربط.';
      if (statusEl) statusEl.textContent = 'تعذر تنفيذ الربط.';
    } finally {
      button.disabled = false;
    }
  }

  function bindCard(card, fileName) {
    const input = card.querySelector('.material-input');
    const sug = card.querySelector('.suggestions');
    const chips = card.querySelector('.chips');
    const assignBtn = card.querySelector('.assign-btn');
    const cardStatus = card.querySelector('.card-status');
    if (!input || !sug || !chips || !assignBtn || !cardStatus) return;

    input.addEventListener('input', async () => {
      const q = input.value.trim();
      if (q.length < 2) {
        sug.classList.add('hidden');
        sug.innerHTML = '';
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
        const label = `${row.material_code || ''} ${row.name || ''}`.trim();
        return `<button type="button" class="block w-full text-right px-3 py-2 text-xs hover:bg-surface-low add-mat" data-guid="${escapeHtml(guid)}" data-label="${escapeHtml(label)}">${escapeHtml(label)}</button>`;
      }).join('');
      sug.classList.remove('hidden');
    });

    sug.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('.add-mat') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const guid = btn.getAttribute('data-guid') || '';
      const label = btn.getAttribute('data-label') || '';
      if (!guid) return;
      const items = sourceMaterialMap.get(fileName) || [];
      if (!items.some((item) => item.guid === guid)) items.push({ guid, label });
      sourceMaterialMap.set(fileName, items);
      chips.innerHTML = chipsHtml(fileName);
      sug.classList.add('hidden');
      sug.innerHTML = '';
      input.value = '';
    });

    chips.addEventListener('click', (event) => {
      const target = event.target;
      const btn = target instanceof HTMLElement ? target.closest('button[data-remove]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const idx = Number(btn.getAttribute('data-remove') || -1);
      const items = sourceMaterialMap.get(fileName) || [];
      if (idx >= 0) items.splice(idx, 1);
      sourceMaterialMap.set(fileName, items);
      chips.innerHTML = chipsHtml(fileName);
    });

    assignBtn.addEventListener('click', async () => {
      const selected = sourceMaterialMap.get(fileName) || [];
      if (!selected.length) {
        const message = `الصورة «${fileName}»: أضف مادة واحدة على الأقل قبل الربط.`;
        linkStatus.textContent = message;
        cardStatus.textContent = message;
        return;
      }
      await assign(fileName, selected, assignBtn, cardStatus);
    });
  }

  function renderSources(payload) {
    const items = payload.items || [];
    if (!items.length) {
      sourceCards.innerHTML = '<div class="text-xs text-text-muted">لا توجد صور في هذه الصفحة.</div>';
    } else {
      sourceCards.innerHTML = items.map((item) => {
        const fileName = item.file_name || '';
        const linkBadge = item.is_linked_to_material
          ? '<span class="text-[10px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">مرتبطة بمادة</span>'
          : '<span class="text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full">غير مرتبطة</span>';
        const linkedMeta = item.is_linked_to_material
          ? `<p class="text-[11px] text-text-muted">${escapeHtml(item.linked_material_code || '')} ${escapeHtml(item.linked_material_name || '')}</p>`
          : '';
        const preview = item.preview_url
          ? `<img src="${escapeHtml(item.preview_url)}" class="w-full h-44 object-contain rounded-lg border border-border-subtle bg-surface-low" alt="">`
          : '<div class="w-full h-44 rounded-lg border border-border-subtle bg-surface-low"></div>';
        return `<article class="rounded-xl border border-border-subtle p-3 bg-white space-y-2" data-file="${escapeHtml(fileName)}">
          ${preview}
          <p class="text-xs font-mono truncate" dir="ltr">${escapeHtml(fileName)}</p>
          <div>${linkBadge}</div>
          ${linkedMeta}
          <div class="relative">
            <input class="material-input h-9 w-full rounded-lg border border-border-subtle px-3 text-xs" placeholder="ابحث عن مادة...">
            <div class="suggestions hidden absolute z-20 mt-1 w-full bg-white border border-border-subtle rounded-lg shadow"></div>
          </div>
          <div class="chips flex flex-wrap gap-1">${chipsHtml(fileName)}</div>
          <button type="button" class="assign-btn h-8 px-3 rounded-lg bg-emerald-600 text-white text-xs font-bold w-full">ربط المواد المضافة</button>
          <p class="card-status text-[11px] text-text-muted"></p>
        </article>`;
      }).join('');
      sourceCards.querySelectorAll('article[data-file]').forEach((card) => bindCard(card, card.getAttribute('data-file') || ''));
    }

    page = Number(payload.page || 1);
    hasMore = !!payload.has_more;
    const total = Number(payload.total_count || 0);
    sourcePageLabel.textContent = `صفحة ${page} — ${total} صورة`;
    sourcePrevBtn.disabled = page <= 1;
    sourceNextBtn.disabled = !hasMore;
  }

  async function loadSources(targetPage = 1) {
    const linkFilter = sourceLinkFilter?.value || 'all';
    const materialQuery = sourceMaterialSearch?.value.trim() || '';
    const response = await fetch(`${API_URL}?action=link-sources-page&page=${targetPage}&page_size=${pageSize}&link_filter=${encodeURIComponent(linkFilter)}&material_query=${encodeURIComponent(materialQuery)}`);
    const payload = await response.json();
    if (!payload.ok) {
      linkStatus.textContent = 'تعذر تحميل الصور.';
      return;
    }
    renderSources(payload);
  }

  sourcePrevBtn?.addEventListener('click', () => { if (page > 1) loadSources(page - 1); });
  sourceNextBtn?.addEventListener('click', () => { if (hasMore) loadSources(page + 1); });
  reloadSourcesBtn?.addEventListener('click', () => loadSources(page));
  applySourceFiltersBtn?.addEventListener('click', () => loadSources(1));
  sourceMaterialSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      loadSources(1);
    }
  });
  loadSources(1);
})();
</script>
