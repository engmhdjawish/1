<?php

declare(strict_types=1);

/** @var string $workspaceTab */
/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var array{pending: int, syncing: int, synced: int, failed: int, total: int} $syncStats */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array<string, mixed> $imageWorkerSnapshot */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canUploadImages */

$workspaceTab = in_array(($workspaceTab ?? 'link'), ['link', 'upload', 'download'], true) ? $workspaceTab : 'link';
$canUploadImages = (bool) ($canUploadImages ?? true);
$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$syncStats = is_array($syncStats ?? null) ? $syncStats : ['pending' => 0, 'syncing' => 0, 'synced' => 0, 'failed' => 0, 'total' => 0];
$apiHealth = is_array($apiHealth ?? null) ? $apiHealth : ['ok' => false, 'message' => ''];
$imageWorkerSnapshot = is_array($imageWorkerSnapshot ?? null) ? $imageWorkerSnapshot : [];
$worker = is_array($imageWorkerSnapshot['worker'] ?? null) ? $imageWorkerSnapshot['worker'] : [];
$workerEnabled = (bool) ($imageWorkerSnapshot['worker_enabled'] ?? true);
$workerLastRun = trim((string) ($worker['last_run_at'] ?? ''));
$workerMessage = trim((string) ($worker['last_message'] ?? ''));
$missingLocal = $worker['missing_local_count'] ?? null;
$failedCount = (int) ($syncStats['failed'] ?? 0);
$pendingCount = (int) ($syncStats['pending'] ?? 0);
?>
<section class="mb-6" data-material-images-workspace>
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        <?php if ($canUploadImages): ?>
          رفع الصور ومزامنتها مع الأمين، ثم ربطها بالمواد — كل ذلك من صفحة واحدة.
        <?php else: ?>
          تصفّح صور المواد المحلية وتحميلها — صلاحية العرض فقط دون رفع أو تعديل.
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs" id="statsPills">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        على الموقع: <strong id="statLocalCount"><?= (int) ($stats['local_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        بانتظار الأمين: <strong id="statPendingCount"><?= (int) ($syncStats['pending'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        تمت المزامنة: <strong id="statSyncedCount" class="text-status-active"><?= (int) ($syncStats['synced'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        فاشلة: <strong id="statFailedCount" class="text-status-rejected"><?= (int) ($syncStats['failed'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white" id="apiStatusPill">
        API الأمين:
        <?php if (!empty($apiHealth['ok'])): ?>
          <strong class="text-status-active">متصل</strong>
        <?php else: ?>
          <strong class="text-status-rejected">غير متصل</strong>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <?php if ($canUploadImages): ?>
  <div
    id="materialImageWorkerBanner"
    class="mt-4 rounded-xl border border-border-subtle bg-white p-4 text-sm"
    data-worker-api="/dashboard/material-images-api.php?action=worker-status"
  >
    <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <p class="font-bold">المزامنة في الخلفية</p>
        <p class="text-text-muted text-xs mt-1 leading-relaxed" id="materialImageWorkerSummary">
          <?php if (!$workerEnabled): ?>
            العامل معطّل — فعّل <code class="text-[11px]" dir="ltr">PORTAL_MATERIAL_IMAGE_WORKER=1</code> وثبّت cron على السيرفر.
          <?php elseif ($workerLastRun === ''): ?>
            لم يُشغَّل العامل بعد. على Linux: <code class="text-[11px]" dir="ltr">bash deploy/scripts/setup-material-image-sync-worker.sh</code>
          <?php else: ?>
            آخر تشغيل: <span dir="ltr"><?= h($workerLastRun) ?></span>
            <?php if ($workerMessage !== ''): ?> — <?= h($workerMessage) ?><?php endif; ?>
          <?php endif; ?>
        </p>
        <?php if ($missingLocal !== null): ?>
          <p class="text-xs mt-1">
            نسخ محلية ناقصة (أمين ↔ موقع): <strong id="materialImageWorkerMissing"><?= (int) $missingLocal ?></strong>
          </p>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap gap-2 shrink-0">
        <?php if ($failedCount > 0 || $pendingCount > 0): ?>
          <a
            href="/dashboard/material-images.php?tab=upload&amp;queue_status=failed"
            class="inline-flex items-center gap-1 h-9 px-3 rounded-lg border border-red-200 bg-red-50 text-red-800 text-xs font-bold"
          >
            قائمة الفشل (<?= $failedCount ?>)
          </a>
          <a
            href="/dashboard/material-images.php?tab=upload&amp;queue_status=pending"
            class="inline-flex items-center gap-1 h-9 px-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 text-xs font-bold"
          >
            بانتظار الأمين (<?= $pendingCount ?>)
          </a>
        <?php else: ?>
          <span class="inline-flex items-center h-9 px-3 rounded-lg border border-green-200 bg-green-50 text-green-800 text-xs font-bold">
            الطابور نظيف
          </span>
        <?php endif; ?>
      </div>
    </div>
    <ul id="materialImageWorkerFailures" class="mt-3 space-y-1 text-xs text-red-800<?= ($failedCount === 0 ? ' hidden' : '') ?>">
      <?php foreach (is_array($worker['recent_failures'] ?? null) ? $worker['recent_failures'] : [] as $fail): ?>
        <?php if (!is_array($fail)) {
            continue;
        } ?>
        <li class="font-mono" dir="ltr">
          <?= h((string) ($fail['file_name'] ?? '')) ?>
          <?php if (!empty($fail['amine_sync_error_ar'])): ?>
            <span class="font-sans text-red-700" dir="rtl"> — <?= h((string) $fail['amine_sync_error_ar']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <nav class="dash-mi-tabs mt-4" aria-label="أقسام صور المواد">
    <?php if ($canUploadImages): ?>
    <a
      href="/dashboard/material-images.php?tab=link"
      class="dash-mi-tab<?= $workspaceTab === 'link' ? ' is-active' : '' ?>"
    >
      <span class="material-symbols-outlined" aria-hidden="true">linked_services</span>
      ربط بالمواد
    </a>
    <a
      href="/dashboard/material-images.php?tab=upload"
      class="dash-mi-tab<?= $workspaceTab === 'upload' ? ' is-active' : '' ?>"
    >
      <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
      رفع ومزامنة
    </a>
    <?php endif; ?>
    <a
      href="/dashboard/material-images.php?tab=download"
      class="dash-mi-tab<?= $workspaceTab === 'download' ? ' is-active' : '' ?>"
    >
      <span class="material-symbols-outlined" aria-hidden="true">folder_zip</span>
      تحميل ZIP
    </a>
  </nav>
</section>

<?php if ($workspaceTab === 'link'): ?>
  <div id="workspace-panel-link">
    <?php require __DIR__ . '/partials/material-image-link-panel.php'; ?>
  </div>
<?php elseif ($workspaceTab === 'upload'): ?>
  <div id="workspace-panel-upload">
    <?php require __DIR__ . '/partials/material-image-upload-panel.php'; ?>
  </div>
<?php else: ?>
  <div id="workspace-panel-download">
    <?php require __DIR__ . '/partials/material-image-download-panel.php'; ?>
  </div>
<?php endif; ?>
