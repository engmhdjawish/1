<?php

declare(strict_types=1);

/** @var string $workspaceTab */
/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var array{pending: int, syncing: int, synced: int, failed: int, total: int} $syncStats */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var array{ok: bool, message: string} $detailsBanner */
/** @var string|null $flash */
/** @var string $flashType */

$workspaceTab = in_array(($workspaceTab ?? 'link'), ['link', 'upload'], true) ? $workspaceTab : 'link';
$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$syncStats = is_array($syncStats ?? null) ? $syncStats : ['pending' => 0, 'syncing' => 0, 'synced' => 0, 'failed' => 0, 'total' => 0];
$apiHealth = is_array($apiHealth ?? null) ? $apiHealth : ['ok' => false, 'message' => ''];
?>
<section class="mb-6" data-material-images-workspace>
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl leading-relaxed">
        رفع الصور ومزامنتها مع الأمين، ثم ربطها بالمواد — كل ذلك من صفحة واحدة.
      </p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs" id="statsPills">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        على الموقع: <strong id="statLocalCount"><?= (int) ($stats['local_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        بانتظار الأمين: <strong id="statPendingCount"><?= (int) ($syncStats['pending'] ?? 0) ?></strong>
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

  <nav class="mt-4 inline-flex flex-wrap gap-1 rounded-xl border border-border-subtle bg-white p-1 shadow-sm" aria-label="أقسام صور المواد">
    <a
      href="/dashboard/material-images.php?tab=link"
      class="h-10 px-4 inline-flex items-center gap-2 rounded-lg text-sm font-bold transition <?= $workspaceTab === 'link' ? 'bg-primary text-white shadow-sm' : 'text-text-muted hover:bg-surface-low' ?>"
    >
      <span class="material-symbols-outlined text-lg">linked_services</span>
      ربط بالمواد
    </a>
    <a
      href="/dashboard/material-images.php?tab=upload"
      class="h-10 px-4 inline-flex items-center gap-2 rounded-lg text-sm font-bold transition <?= $workspaceTab === 'upload' ? 'bg-primary text-white shadow-sm' : 'text-text-muted hover:bg-surface-low' ?>"
    >
      <span class="material-symbols-outlined text-lg">cloud_upload</span>
      رفع ومزامنة
    </a>
  </nav>
</section>

<?php if ($workspaceTab === 'link'): ?>
  <?php if (empty($detailsBanner['ok'])): ?>
    <p class="mb-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 max-w-3xl">
      <?= h((string) ($detailsBanner['message'] ?? 'البانر السفلي غير متاح على هذا السيرفر.')) ?>
    </p>
  <?php endif; ?>
  <div id="workspace-panel-link">
    <?php require __DIR__ . '/partials/material-image-link-panel.php'; ?>
  </div>
<?php else: ?>
  <div id="workspace-panel-upload">
    <?php require __DIR__ . '/partials/material-image-upload-panel.php'; ?>
  </div>
<?php endif; ?>
