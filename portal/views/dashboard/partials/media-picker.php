<?php

declare(strict_types=1);

use Portal\Services\SiteMediaService;

if (!isset($renderMediaPickerField)) {
    $renderMediaPickerField = static function (
        string $label,
        string $inputName,
        string $currentUrl,
        string $fieldId,
        string $defaultCategory = 'banner'
    ): void {
        $currentUrl = trim($currentUrl);
        $defaultCategory = in_array($defaultCategory, SiteMediaService::CATEGORIES, true) ? $defaultCategory : 'banner';
        $isLogoField = $defaultCategory === 'logo';
        $previewBoxClass = $isLogoField
            ? 'h-24 w-40 rounded-lg border border-border-subtle bg-white overflow-hidden flex items-center justify-center text-[10px] text-text-muted'
            : 'h-16 w-28 rounded-lg border border-border-subtle bg-surface-low overflow-hidden flex items-center justify-center text-[10px] text-text-muted';
        $previewImgClass = $isLogoField ? 'dashboard-logo-preview-img' : 'h-full w-full object-cover';
        ?>
        <div class="text-xs" id="<?= h($fieldId) ?>-wrap" data-media-field="<?= h($fieldId) ?>" data-default-category="<?= h($defaultCategory) ?>" data-media-preview-logo="<?= $isLogoField ? '1' : '0' ?>">
          <span class="text-text-muted block mb-0.5"><?= h($label) ?></span>
          <?php if ($isLogoField): ?>
            <p class="text-[11px] text-text-muted mb-1.5">يفضّل PNG بخلفية شفافة. إن كان الشعار أبيض يُعرض على خلفية رمادية فاتحة ليكون أوضح.</p>
          <?php endif; ?>
          <input type="hidden" name="<?= h($inputName) ?>" id="<?= h($fieldId) ?>-input" value="<?= h($currentUrl) ?>">
          <div class="flex flex-wrap items-center gap-2">
            <div id="<?= h($fieldId) ?>-preview" class="<?= h($previewBoxClass) ?>">
              <?php if ($currentUrl !== ''): ?>
                <img src="<?= h($currentUrl) ?>" alt="" class="<?= h($previewImgClass) ?>">
              <?php else: ?>
                بدون صورة
              <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-1.5">
              <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hover:bg-slate-50" data-media-open="<?= h($fieldId) ?>">اختر من المكتبة</button>
              <a href="/dashboard/site-media.php" target="_blank" class="h-8 px-3 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-600 hover:bg-slate-50">إدارة المكتبة</a>
              <button type="button" class="h-8 px-3 rounded-lg border border-red-200 text-xs font-bold text-red-700 hover:bg-red-50" data-media-clear="<?= h($fieldId) ?>">إزالة</button>
            </div>
          </div>
        </div>
        <?php
    };
}

if (!function_exists('portal_render_media_picker_modal')) {
    function portal_render_media_picker_modal(): void
    {
        if (defined('PORTAL_MEDIA_PICKER_MODAL')) {
            return;
        }
        define('PORTAL_MEDIA_PICKER_MODAL', true);
        $categories = SiteMediaService::CATEGORIES;
        $labels = SiteMediaService::CATEGORY_LABELS;
        ?>
        <div id="portal-media-picker-modal" class="hidden fixed inset-0 z-[80]">
          <div class="absolute inset-0 bg-black/50" data-media-close></div>
          <div class="absolute inset-x-3 top-6 bottom-6 md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-[min(920px,96vw)] bg-white rounded-xl border border-border-subtle shadow-2xl flex flex-col overflow-hidden">
            <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between gap-2">
              <h3 class="font-bold text-sm">مكتبة صور الموقع</h3>
              <button type="button" class="h-8 w-8 rounded-lg hover:bg-slate-100" data-media-close aria-label="إغلاق">×</button>
            </div>
            <div class="px-4 py-2 border-b border-border-subtle flex flex-wrap gap-2 items-center">
              <?php foreach ($categories as $category): ?>
                <button type="button" class="h-8 px-3 rounded-lg border text-xs font-bold media-picker-cat" data-media-category="<?= h($category) ?>"><?= h($labels[$category] ?? $category) ?></button>
              <?php endforeach; ?>
            </div>
            <form id="portal-media-upload-form" class="px-4 py-3 border-b border-border-subtle bg-surface-low grid grid-cols-1 md:grid-cols-4 gap-2 items-end" enctype="multipart/form-data">
              <label class="text-xs md:col-span-2">
                <span class="text-text-muted block mb-0.5">رفع صورة جديدة</span>
                <input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" required class="block w-full text-xs">
              </label>
              <label class="text-xs">
                <span class="text-text-muted block mb-0.5">التصنيف</span>
                <select name="category" id="portal-media-upload-category" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= h($category) ?>"><?= h($labels[$category] ?? $category) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="text-xs">
                <span class="text-text-muted block mb-0.5">عنوان (اختياري)</span>
                <input type="text" name="title_ar" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
              </label>
              <button type="submit" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold md:col-span-4 md:justify-self-end">رفع وإضافة للمكتبة</button>
              <p id="portal-media-upload-status" class="text-xs text-text-muted md:col-span-4 min-h-[1rem]"></p>
            </form>
            <div id="portal-media-grid" class="flex-1 overflow-y-auto p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 content-start"></div>
            <p id="portal-media-grid-status" class="px-4 py-2 text-xs text-text-muted border-t border-border-subtle"></p>
          </div>
        </div>
        <?php
    };
}

if (!function_exists('portal_render_media_picker_script')) {
    function portal_render_media_picker_script(): void
    {
        // Handled by /assets/dashboard/media-picker.js (loaded in dashboard layout).
    }
}
