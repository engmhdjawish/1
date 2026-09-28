<?php

declare(strict_types=1);

use Portal\Services\HomeCategoryService;

if (!function_exists('portal_render_material_icon_picker_field')) {
    function portal_render_material_icon_picker_field(
        string $fieldId,
        string $inputName,
        string $currentIcon
    ): void {
        $currentIcon = HomeCategoryService::normalizeIconKey($currentIcon !== '' ? $currentIcon : 'category');
        $currentLabel = HomeCategoryService::iconLabel($currentIcon);
        $libraryCount = HomeCategoryService::iconLibraryCount();
        ?>
        <div class="text-xs" id="<?= h($fieldId) ?>-wrap" data-material-icon-field="<?= h($fieldId) ?>">
          <span class="text-text-muted block mb-0.5">الأيقونة</span>
          <input type="hidden" name="<?= h($inputName) ?>" id="<?= h($fieldId) ?>-input" value="<?= h($currentIcon) ?>">
          <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-lg border border-border-subtle px-3 py-2 bg-slate-50 min-w-[9rem]">
              <span class="material-symbols-outlined text-primary text-xl" id="<?= h($fieldId) ?>-preview" aria-hidden="true"><?= h($currentIcon) ?></span>
              <span class="text-xs font-bold text-slate-700" id="<?= h($fieldId) ?>-label"><?= h($currentLabel) ?></span>
            </span>
            <button
              type="button"
              class="h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hover:bg-slate-50"
              data-material-icon-open="<?= h($fieldId) ?>"
            >اختر من المكتبة</button>
          </div>
          <span class="text-[11px] text-text-muted mt-1 block">
            مكتبة Material Symbols — <?= $libraryCount > 0 ? number_format($libraryCount) : 'آلاف' ?> أيقونة مع بحث وتصفية.
          </span>
        </div>
        <?php
    }
}

if (!function_exists('portal_render_material_icon_picker_modal')) {
    function portal_render_material_icon_picker_modal(): void
    {
        if (defined('PORTAL_MATERIAL_ICON_PICKER_MODAL')) {
            return;
        }
        define('PORTAL_MATERIAL_ICON_PICKER_MODAL', true);

        $assetUrl = HomeCategoryService::iconLibraryAssetUrl();
        ?>
        <div id="portal-material-icon-picker-modal" class="hidden fixed inset-0 z-[80]" aria-hidden="true">
          <div class="absolute inset-0 bg-black/50" data-material-icon-close></div>
          <div class="absolute inset-x-3 top-8 bottom-8 md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-[min(760px,96vw)] bg-white rounded-xl border border-border-subtle shadow-2xl flex flex-col overflow-hidden">
            <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between gap-2">
              <div>
                <h3 class="font-bold text-sm">اختيار أيقونة</h3>
                <p class="text-[11px] text-text-muted mt-0.5" id="portal-material-icon-status">جاري تحميل المكتبة...</p>
              </div>
              <button type="button" class="h-8 w-8 rounded-lg hover:bg-slate-100 text-lg leading-none" data-material-icon-close aria-label="إغلاق">×</button>
            </div>
            <div class="px-4 py-3 border-b border-border-subtle space-y-2">
              <label class="block text-xs">
                <span class="sr-only">بحث</span>
                <span class="relative block">
                  <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-text-muted text-base pointer-events-none">search</span>
                  <input
                    type="search"
                    id="portal-material-icon-search"
                    class="h-9 w-full rounded-lg border border-border-subtle pr-9 pl-3 text-sm"
                    placeholder="ابحث بالعربية أو الإنجليزية: steps, shoe, عروض..."
                    autocomplete="off"
                    disabled
                  >
                </span>
              </label>
              <div class="flex flex-wrap gap-1.5" id="portal-material-icon-groups" role="tablist" aria-label="تصفية المجموعة"></div>
            </div>
            <div class="flex-1 overflow-y-auto p-3" id="portal-material-icon-grid-wrap">
              <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-2" id="portal-material-icon-grid"></div>
              <p id="portal-material-icon-empty" class="hidden text-center text-sm text-text-muted py-8">لا توجد أيقونة مطابقة للبحث.</p>
              <p id="portal-material-icon-limit" class="hidden text-center text-[11px] text-text-muted py-2"></p>
            </div>
          </div>
        </div>
        <script>
          (function () {
            var modal = document.getElementById('portal-material-icon-picker-modal');
            if (!modal || modal.dataset.bound === '1') return;
            modal.dataset.bound = '1';

            var libraryUrl = <?= json_encode($assetUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var maxRender = 120;
            var activeFieldId = null;
            var activeGroup = 'all';
            var libraryPromise = null;
            var libraryData = null;
            var searchInput = document.getElementById('portal-material-icon-search');
            var groupsWrap = document.getElementById('portal-material-icon-groups');
            var grid = document.getElementById('portal-material-icon-grid');
            var emptyState = document.getElementById('portal-material-icon-empty');
            var limitState = document.getElementById('portal-material-icon-limit');
            var statusEl = document.getElementById('portal-material-icon-status');

            var normalizeIcon = function (value) {
              return String(value || 'category').toLowerCase().replace(/[^a-z0-9_]/g, '') || 'category';
            };

            var loadLibrary = function () {
              if (libraryPromise) return libraryPromise;
              libraryPromise = fetch(libraryUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) {
                  if (!response.ok) throw new Error('load failed');
                  return response.json();
                })
                .then(function (data) {
                  libraryData = data && Array.isArray(data.icons) ? data : { icons: [], groups: {} };
                  renderGroupButtons(libraryData.groups || {});
                  if (searchInput) searchInput.disabled = false;
                  if (statusEl) {
                    statusEl.textContent = (libraryData.icons.length || 0).toLocaleString('ar-SY') + ' أيقونة — ابحث أو صفِّ حسب المجموعة';
                  }
                  return libraryData;
                })
                .catch(function () {
                  if (statusEl) statusEl.textContent = 'تعذر تحميل المكتبة.';
                  throw new Error('library unavailable');
                });
              return libraryPromise;
            };

            var renderGroupButtons = function (groups) {
              if (!groupsWrap) return;
              groupsWrap.innerHTML = '';
              var allBtn = document.createElement('button');
              allBtn.type = 'button';
              allBtn.className = 'h-8 px-3 rounded-lg border border-primary bg-primary text-white text-xs font-bold material-icon-group is-active';
              allBtn.setAttribute('data-material-icon-group', 'all');
              allBtn.textContent = 'الكل';
              groupsWrap.appendChild(allBtn);
              Object.keys(groups).forEach(function (groupKey) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold material-icon-group hover:bg-slate-50';
                btn.setAttribute('data-material-icon-group', groupKey);
                btn.textContent = groups[groupKey] || groupKey;
                groupsWrap.appendChild(btn);
              });
            };

            var buildHaystack = function (item, groups) {
              var tags = Array.isArray(item.tags) ? item.tags.join(' ') : '';
              var groupLabel = groups[item.group] || item.group || '';
              return (item.key + ' ' + (item.label_ar || '') + ' ' + groupLabel + ' ' + tags).toLowerCase();
            };

            var renderGrid = function (currentIcon) {
              if (!grid || !libraryData) return;
              var icons = libraryData.icons || [];
              var groups = libraryData.groups || {};
              var query = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
              var matched = [];
              for (var i = 0; i < icons.length; i += 1) {
                var item = icons[i];
                if (!item || !item.key) continue;
                if (activeGroup !== 'all' && item.group !== activeGroup) continue;
                var haystack = buildHaystack(item, groups);
                if (query !== '' && haystack.indexOf(query) === -1) continue;
                matched.push(item);
              }

              grid.innerHTML = '';
              var slice = matched.slice(0, maxRender);
              slice.forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'material-icon-option flex flex-col items-center gap-1 p-2 rounded-lg border border-border-subtle bg-white hover:border-primary hover:bg-red-50 text-center';
                btn.setAttribute('data-material-icon-key', item.key);
                btn.setAttribute('data-material-icon-label', item.label_ar || item.key);
                btn.title = item.label_ar || item.key;
                if (normalizeIcon(item.key) === normalizeIcon(currentIcon)) {
                  btn.classList.add('ring-2', 'ring-primary');
                }
                btn.innerHTML =
                  '<span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">' + item.key + '</span>' +
                  '<span class="text-[10px] font-bold leading-tight line-clamp-2">' + (item.label_ar || item.key) + '</span>';
                grid.appendChild(btn);
              });

              if (emptyState) emptyState.classList.toggle('hidden', matched.length > 0);
              if (limitState) {
                if (matched.length > maxRender) {
                  limitState.textContent = 'يُعرض ' + maxRender + ' من ' + matched.length + ' — ضيّق البحث لإيجاد أيقونة محددة.';
                  limitState.classList.remove('hidden');
                } else {
                  limitState.classList.add('hidden');
                }
              }
            };

            var setActiveGroup = function (group) {
              activeGroup = group || 'all';
              Array.from(document.querySelectorAll('.material-icon-group')).forEach(function (btn) {
                var isActive = (btn.getAttribute('data-material-icon-group') || '') === activeGroup;
                btn.classList.toggle('is-active', isActive);
                btn.classList.toggle('border-primary', isActive);
                btn.classList.toggle('bg-primary', isActive);
                btn.classList.toggle('text-white', isActive);
                btn.classList.toggle('border-border-subtle', !isActive);
                btn.classList.toggle('bg-white', !isActive);
              });
              var input = activeFieldId ? document.getElementById(activeFieldId + '-input') : null;
              renderGrid(input ? input.value : 'category');
            };

            var closeModal = function () {
              modal.classList.add('hidden');
              modal.setAttribute('aria-hidden', 'true');
              activeFieldId = null;
              document.body.classList.remove('overflow-hidden');
            };

            var openModal = function (fieldId) {
              activeFieldId = fieldId;
              var input = document.getElementById(fieldId + '-input');
              var current = input ? normalizeIcon(input.value) : 'category';
              if (searchInput) searchInput.value = '';
              activeGroup = 'all';
              modal.classList.remove('hidden');
              modal.setAttribute('aria-hidden', 'false');
              document.body.classList.add('overflow-hidden');
              loadLibrary()
                .then(function () {
                  setActiveGroup('all');
                  renderGrid(current);
                  if (searchInput) searchInput.focus();
                })
                .catch(function () {});
            };

            var selectIcon = function (btn) {
              if (!activeFieldId || !btn) return;
              var key = normalizeIcon(btn.getAttribute('data-material-icon-key'));
              var label = btn.getAttribute('data-material-icon-label') || key;
              var input = document.getElementById(activeFieldId + '-input');
              var preview = document.getElementById(activeFieldId + '-preview');
              var labelEl = document.getElementById(activeFieldId + '-label');
              if (input) input.value = key;
              if (preview) preview.textContent = key;
              if (labelEl) labelEl.textContent = label;
              closeModal();
            };

            document.addEventListener('click', function (event) {
              var openBtn = event.target.closest('[data-material-icon-open]');
              if (openBtn) {
                openModal(openBtn.getAttribute('data-material-icon-open') || '');
                return;
              }
              if (event.target.closest('[data-material-icon-close]')) {
                closeModal();
                return;
              }
              var groupBtn = event.target.closest('.material-icon-group');
              if (groupBtn && modal.contains(groupBtn)) {
                setActiveGroup(groupBtn.getAttribute('data-material-icon-group') || 'all');
                return;
              }
              var option = event.target.closest('.material-icon-option');
              if (option && modal.contains(option)) {
                selectIcon(option);
              }
            });

            if (searchInput) {
              searchInput.addEventListener('input', function () {
                var input = activeFieldId ? document.getElementById(activeFieldId + '-input') : null;
                renderGrid(input ? input.value : 'category');
              });
            }

            document.addEventListener('keydown', function (event) {
              if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
              }
            });
          })();
        </script>
        <?php
    }
}
