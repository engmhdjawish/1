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
            >اختر أيقونة</button>
          </div>
          <span class="text-[11px] text-text-muted mt-1 block">مكتبة منظمة لمتجر الأحذية — كل التسميات بالعربية.</span>
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

        $payload = HomeCategoryService::iconLibraryPayload();
        $groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
        $icons = is_array($payload['icons'] ?? null) ? $payload['icons'] : [];
        $defaultTab = 'footwear';
        ?>
        <style>
          .hc-icon-modal {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
          }
          .hc-icon-modal.is-open { display: flex; }
          .hc-icon-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
          }
          .hc-icon-modal__panel {
            position: relative;
            z-index: 1;
            width: min(100%, 42rem);
            max-height: min(90vh, 44rem);
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
            overflow: hidden;
          }
          .hc-icon-modal__head,
          .hc-icon-modal__tools {
            flex-shrink: 0;
          }
          .hc-icon-modal__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #e5e7eb;
          }
          .hc-icon-modal__tools {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            display: grid;
            gap: 0.65rem;
          }
          .hc-icon-modal__search {
            position: relative;
          }
          .hc-icon-modal__search .material-symbols-outlined {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1.1rem;
            pointer-events: none;
          }
          .hc-icon-modal__search input {
            width: 100%;
            height: 2.35rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.65rem;
            padding: 0 2.2rem 0 0.75rem;
            font-size: 0.875rem;
          }
          .hc-icon-modal__tabs {
            display: flex;
            gap: 0.4rem;
            overflow-x: auto;
            padding-bottom: 0.15rem;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
          }
          .hc-icon-modal__tab {
            flex: 0 0 auto;
            height: 2rem;
            padding: 0 0.85rem;
            border-radius: 9999px;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 800;
            white-space: nowrap;
            cursor: pointer;
          }
          .hc-icon-modal__tab.is-active {
            border-color: #D81921;
            background: #D81921;
            color: #fff;
          }
          .hc-icon-modal__body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 0.85rem 1rem 1rem;
            -webkit-overflow-scrolling: touch;
          }
          .hc-icon-modal__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.55rem;
          }
          @media (min-width: 640px) {
            .hc-icon-modal__grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
          }
          @media (min-width: 768px) {
            .hc-icon-modal__grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
          }
          .hc-icon-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 0.35rem;
            min-height: 5.5rem;
            padding: 0.55rem 0.35rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            background: #fff;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
          }
          .hc-icon-option:hover {
            border-color: #fecaca;
            background: #fff5f5;
          }
          .hc-icon-option.is-selected {
            border-color: #D81921;
            box-shadow: inset 0 0 0 1px #D81921;
            background: #fff5f5;
          }
          .hc-icon-option .material-symbols-outlined {
            font-size: 1.65rem;
            color: #D81921;
            line-height: 1;
          }
          .hc-icon-option__label {
            font-size: 0.6875rem;
            font-weight: 800;
            line-height: 1.35;
            color: #0f172a;
          }
          .hc-icon-modal__empty {
            text-align: center;
            color: #64748b;
            font-size: 0.875rem;
            padding: 2rem 0.5rem;
          }
        </style>

        <div id="portal-material-icon-picker-modal" class="hc-icon-modal" aria-hidden="true">
          <div class="hc-icon-modal__backdrop" data-material-icon-close></div>
          <div class="hc-icon-modal__panel" role="dialog" aria-modal="true" aria-labelledby="hc-icon-modal-title">
            <div class="hc-icon-modal__head">
              <div>
                <h3 id="hc-icon-modal-title" class="font-bold text-sm">اختيار أيقونة الفئة</h3>
                <p class="text-[11px] text-text-muted mt-0.5">مناسبة لمتجر الأحذية — اختر التبويب ثم اضغط الأيقونة.</p>
              </div>
              <button type="button" class="h-8 w-8 rounded-lg hover:bg-slate-100 text-lg leading-none" data-material-icon-close aria-label="إغلاق">×</button>
            </div>
            <div class="hc-icon-modal__tools">
              <label class="hc-icon-modal__search text-xs">
                <span class="sr-only">بحث</span>
                <span class="material-symbols-outlined">search</span>
                <input type="search" id="portal-material-icon-search" placeholder="ابحث: شحاطة، شتوي، رجالي، عروض..." autocomplete="off">
              </label>
              <div class="hc-icon-modal__tabs" id="portal-material-icon-groups" role="tablist" aria-label="تبويبات الأيقونات">
                <?php foreach ($groups as $groupKey => $groupLabel): ?>
                  <button
                    type="button"
                    class="hc-icon-modal__tab<?= $groupKey === $defaultTab ? ' is-active' : '' ?>"
                    data-material-icon-group="<?= h((string) $groupKey) ?>"
                    role="tab"
                    aria-selected="<?= $groupKey === $defaultTab ? 'true' : 'false' ?>"
                  ><?= h((string) $groupLabel) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="hc-icon-modal__body" id="portal-material-icon-grid-wrap">
              <div class="hc-icon-modal__grid" id="portal-material-icon-grid"></div>
              <p id="portal-material-icon-empty" class="hc-icon-modal__empty hidden">لا توجد أيقونة مطابقة — جرّب كلمة بحث أخرى.</p>
            </div>
          </div>
        </div>
        <script>
          (function () {
            var modal = document.getElementById('portal-material-icon-picker-modal');
            if (!modal || modal.dataset.bound === '1') return;
            modal.dataset.bound = '1';

            var libraryData = <?= json_encode(['groups' => $groups, 'icons' => $icons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var defaultTab = <?= json_encode($defaultTab, JSON_UNESCAPED_UNICODE) ?>;
            var activeFieldId = null;
            var activeGroup = defaultTab;
            var searchInput = document.getElementById('portal-material-icon-search');
            var grid = document.getElementById('portal-material-icon-grid');
            var emptyState = document.getElementById('portal-material-icon-empty');
            var groupButtons = Array.from(document.querySelectorAll('.hc-icon-modal__tab'));

            var normalizeIcon = function (value) {
              return String(value || 'category').toLowerCase().replace(/[^a-z0-9_]/g, '') || 'category';
            };

            var buildHaystack = function (item) {
              var tags = Array.isArray(item.tags) ? item.tags.join(' ') : '';
              var groupLabel = (libraryData.groups && libraryData.groups[item.group]) || '';
              return (item.label_ar + ' ' + groupLabel + ' ' + tags).toLowerCase();
            };

            var renderGrid = function (currentIcon) {
              if (!grid) return;
              var icons = libraryData.icons || [];
              var query = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
              var matched = [];
              for (var i = 0; i < icons.length; i += 1) {
                var item = icons[i];
                if (!item || !item.key) continue;
                if (item.group !== activeGroup) continue;
                if (query !== '' && buildHaystack(item).indexOf(query) === -1) continue;
                matched.push(item);
              }

              grid.innerHTML = '';
              matched.forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'hc-icon-option';
                if (normalizeIcon(item.key) === normalizeIcon(currentIcon)) {
                  btn.classList.add('is-selected');
                }
                btn.setAttribute('data-material-icon-key', item.key);
                btn.setAttribute('data-material-icon-label', item.label_ar || item.key);
                btn.innerHTML =
                  '<span class="material-symbols-outlined" aria-hidden="true">' + item.key + '</span>' +
                  '<span class="hc-icon-option__label">' + (item.label_ar || item.key) + '</span>';
                grid.appendChild(btn);
              });

              if (emptyState) emptyState.classList.toggle('hidden', matched.length > 0);
            };

            var setActiveGroup = function (group) {
              activeGroup = group || defaultTab;
              groupButtons.forEach(function (btn) {
                var isActive = (btn.getAttribute('data-material-icon-group') || '') === activeGroup;
                btn.classList.toggle('is-active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
              });
              var input = activeFieldId ? document.getElementById(activeFieldId + '-input') : null;
              renderGrid(input ? input.value : 'category');
            };

            var closeModal = function () {
              modal.classList.remove('is-open');
              modal.setAttribute('aria-hidden', 'true');
              activeFieldId = null;
              document.body.classList.remove('overflow-hidden');
            };

            var openModal = function (fieldId) {
              activeFieldId = fieldId;
              var input = document.getElementById(fieldId + '-input');
              var current = input ? normalizeIcon(input.value) : 'category';
              if (searchInput) searchInput.value = '';

              var currentItem = (libraryData.icons || []).find(function (item) {
                return normalizeIcon(item.key) === current;
              });
              setActiveGroup(currentItem && currentItem.group ? currentItem.group : defaultTab);
              renderGrid(current);

              modal.classList.add('is-open');
              modal.setAttribute('aria-hidden', 'false');
              document.body.classList.add('overflow-hidden');
              if (searchInput) searchInput.focus();
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
              var groupBtn = event.target.closest('.hc-icon-modal__tab');
              if (groupBtn && modal.contains(groupBtn)) {
                setActiveGroup(groupBtn.getAttribute('data-material-icon-group') || defaultTab);
                return;
              }
              var option = event.target.closest('.hc-icon-option');
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
              if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
              }
            });
          })();
        </script>
        <?php
    }
}
