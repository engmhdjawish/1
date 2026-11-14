<?php

declare(strict_types=1);

/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var array<string, mixed> $storeMaterialZipSeed */

$storeMaterialZipSeed = is_array($storeMaterialZipSeed ?? null) ? $storeMaterialZipSeed : [];
$materialZipSeed = $storeMaterialZipSeed;
$includeInvoiceZipDownload = false;
$zipMaterialsAccordionOpen = true;
?>
<div
  class="se-zip-dialog"
  data-store-staff-zip-dialog
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="se-zip-dialog-title"
>
  <div class="se-zip-dialog__backdrop" data-store-staff-zip-close tabindex="-1"></div>
  <div class="se-zip-dialog__panel">
    <header class="se-zip-dialog__head">
      <div>
        <h2 id="se-zip-dialog-title" class="se-zip-dialog__title">تحميل صور المواد (ZIP)</h2>
        <p class="se-zip-dialog__subtitle">نفس خيارات لوحة التحكم — مُزامَن مع فلاتر المتجر الحالية</p>
      </div>
      <button type="button" class="se-zip-dialog__close" data-store-staff-zip-close aria-label="إغلاق">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>
    <div class="se-zip-dialog__body">
      <?php require __DIR__ . '/../dashboard/partials/material-image-download-panel.php'; ?>
    </div>
  </div>
</div>
