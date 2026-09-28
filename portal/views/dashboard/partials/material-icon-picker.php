<?php

declare(strict_types=1);

use Portal\Services\HomeCategoryService;

if (!function_exists('portal_render_material_icon_picker_field')) {
    /**
     * @param array{key: string, label_ar: string, group: string}[]|null $iconLibrary
     */
    function portal_render_material_icon_picker_field(
        string $fieldId,
        string $inputName,
        string $currentIcon,
        ?array $iconLibrary = null
    ): void {
        $iconLibrary ??= HomeCategoryService::iconLibrary();
        $currentIcon = HomeCategoryService::normalizeIconKey($currentIcon !== '' ? $currentIcon : 'category');
        $currentLabel = $currentIcon;
        foreach ($iconLibrary as $item) {
            if (($item['key'] ?? '') === $currentIcon) {
                $currentLabel = (string) ($item['label_ar'] ?? $currentIcon);
                break;
            }
        }
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
          <span class="text-[11px] text-text-muted mt-1 block">اختر من مجموعة أيقونات Material مع البحث بالعربية أو الإنجليزية.</span>
        </div>
        <?php
    }
}

if (!function_exists('portal_render_material_icon_picker_modal')) {
    /** @param array{key: string, label_ar: string, group: string}[]|null $iconLibrary */
    function portal_render_material_icon_picker_modal(?array $iconLibrary = null): void
    {
        if (defined('PORTAL_MATERIAL_ICON_PICKER_MODAL')) {
            return;
        }
        define('PORTAL_MATERIAL_ICON_PICKER_MODAL', true);

        $iconLibrary ??= HomeCategoryService::iconLibrary();
        $groupLabels = HomeCategoryService::iconGroupLabels();
        ?>
        <div id="portal-material-icon-picker-modal" class="hidden fixed inset-0 z-[80]" aria-hidden="true">
          <div class="absolute inset-0 bg-black/50" data-material-icon-close></div>
          <div class="absolute inset-x-3 top-8 bottom-8 md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-[min(720px,96vw)] bg-white rounded-xl border border-border-subtle shadow-2xl flex flex-col overflow-hidden">
            <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between gap-2">
              <h3 class="font-bold text-sm">اختيار أيقونة</h3>
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
                    placeholder="ابحث: شحاطة، عروض، steps..."
                    autocomplete="off"
                  >
                </span>
              </label>
              <div class="flex flex-wrap gap-1.5" id="portal-material-icon-groups" role="tablist" aria-label="تصفية المجموعة">
                <button type="button" class="h-8 px-3 rounded-lg border border-primary bg-primary text-white text-xs font-bold material-icon-group is-active" data-material-icon-group="all">الكل</button>
                <?php foreach ($groupLabels as $groupKey => $groupLabel): ?>
                  <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold material-icon-group hover:bg-slate-50" data-material-icon-group="<?= h($groupKey) ?>"><?= h($groupLabel) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="flex-1 overflow-y-auto p-3" id="portal-material-icon-grid-wrap">
              <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-2" id="portal-material-icon-grid">
                <?php foreach ($iconLibrary as $item): ?>
                  <?php
                    $key = (string) ($item['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $label = (string) ($item['label_ar'] ?? $key);
                    $group = (string) ($item['group'] ?? 'general');
                  ?>
                  <button
                    type="button"
                    class="material-icon-option flex flex-col items-center gap-1 p-2 rounded-lg border border-border-subtle bg-white hover:border-primary hover:bg-red-50 text-center"
                    data-material-icon-key="<?= h($key) ?>"
                    data-material-icon-label="<?= h($label) ?>"
                    data-material-icon-group-item="<?= h($group) ?>"
                    data-material-icon-search-text="<?= h(strtolower($key . ' ' . $label . ' ' . ($groupLabels[$group] ?? $group))) ?>"
                    title="<?= h($label) ?>"
                  >
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true"><?= h($key) ?></span>
                    <span class="text-[10px] font-bold leading-tight line-clamp-2"><?= h($label) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <p id="portal-material-icon-empty" class="hidden text-center text-sm text-text-muted py-8">لا توجد أيقونة مطابقة للبحث.</p>
            </div>
          </div>
        </div>
        <script>
          (function () {
            var modal = document.getElementById('portal-material-icon-picker-modal');
            if (!modal || modal.dataset.bound === '1') return;
            modal.dataset.bound = '1';

            var activeFieldId = null;
            var searchInput = document.getElementById('portal-material-icon-search');
            var grid = document.getElementById('portal-material-icon-grid');
            var emptyState = document.getElementById('portal-material-icon-empty');
            var groupButtons = Array.from(document.querySelectorAll('.material-icon-group'));
            var options = grid ? Array.from(grid.querySelectorAll('.material-icon-option')) : [];
            var activeGroup = 'all';

            var normalizeIcon = function (value) {
              return String(value || 'category').toLowerCase().replace(/[^a-z0-9_]/g, '') || 'category';
            };

            var applyFilters = function () {
              var query = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
              var visible = 0;
              options.forEach(function (btn) {
                var haystack = btn.getAttribute('data-material-icon-search-text') || '';
                var group = btn.getAttribute('data-material-icon-group-item') || '';
                var matchesGroup = activeGroup === 'all' || group === activeGroup;
                var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
                var show = matchesGroup && matchesQuery;
                btn.classList.toggle('hidden', !show);
                if (show) visible += 1;
              });
              if (emptyState) emptyState.classList.toggle('hidden', visible > 0);
            };

            var setActiveGroup = function (group) {
              activeGroup = group || 'all';
              groupButtons.forEach(function (btn) {
                var isActive = (btn.getAttribute('data-material-icon-group') || '') === activeGroup;
                btn.classList.toggle('is-active', isActive);
                btn.classList.toggle('border-primary', isActive);
                btn.classList.toggle('bg-primary', isActive);
                btn.classList.toggle('text-white', isActive);
                btn.classList.toggle('border-border-subtle', !isActive);
                btn.classList.toggle('bg-white', !isActive);
              });
              applyFilters();
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
              setActiveGroup('all');
              options.forEach(function (btn) {
                var selected = normalizeIcon(btn.getAttribute('data-material-icon-key')) === current;
                btn.classList.toggle('ring-2', selected);
                btn.classList.toggle('ring-primary', selected);
              });
              modal.classList.remove('hidden');
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
              var option = event.target.closest('.material-icon-option');
              if (option && modal.contains(option)) {
                selectIcon(option);
              }
            });

            if (searchInput) {
              searchInput.addEventListener('input', applyFilters);
            }

            groupButtons.forEach(function (btn) {
              btn.addEventListener('click', function () {
                setActiveGroup(btn.getAttribute('data-material-icon-group') || 'all');
              });
            });

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
