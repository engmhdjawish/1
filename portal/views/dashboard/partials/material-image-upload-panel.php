<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $flash */
/** @var string|null $flashType */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array<string, string> $settingsForm */
/** @var list<array<string, mixed>> $queue */
/** @var array<string, mixed> $queuePage */
/** @var int $pendingDeletable */

$statusLabels = [
    'pending' => ['label' => 'بانتظار الأمين', 'class' => 'bg-amber-100 text-amber-800'],
    'syncing' => ['label' => 'جاري المزامنة', 'class' => 'bg-blue-100 text-blue-800'],
    'synced' => ['label' => 'تمت على الأمين', 'class' => 'bg-green-100 text-green-800'],
    'failed' => ['label' => 'فشل', 'class' => 'bg-red-100 text-red-800'],
];

$uploadBootstrap = [
    'statusLabels' => $statusLabels,
    'queue' => $queue,
    'queuePage' => $queuePage,
    'pendingDeletable' => (int) ($pendingDeletable ?? 0),
    'apiOk' => !empty($apiHealth['ok']),
    'apiMessage' => (string) ($apiHealth['message'] ?? ''),
];
?>
<?php if (!empty($flash)): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= ($flashType ?? 'success') === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h((string) $flash) ?>
  </p>
<?php endif; ?>

<div data-material-images-upload-panel>
  <nav class="dash-mi-upload-stepper" aria-label="خطوات رفع الصور">
    <div class="dash-mi-upload-step is-active" data-upload-step="upload">
      <span class="dash-mi-upload-step__num">1</span>
      <span class="dash-mi-upload-step__label">رفع على الموقع</span>
    </div>
    <div class="dash-mi-upload-step" data-upload-step="sync">
      <span class="dash-mi-upload-step__num">2</span>
      <span class="dash-mi-upload-step__label">مزامنة الأمين</span>
    </div>
    <a href="/dashboard/material-images.php?tab=link" class="dash-mi-upload-step dash-mi-upload-step--link">
      <span class="dash-mi-upload-step__num">3</span>
      <span class="dash-mi-upload-step__label">ربط بالمواد</span>
      <span class="material-symbols-outlined dash-mi-upload-step__arrow" aria-hidden="true">arrow_back</span>
    </a>
  </nav>

  <section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold text-sm">رفع ومزامنة</h2>
      <p class="text-xs text-text-muted mt-0.5">ارفع الصور للموقع ثم أرسلها للأمين — بعدها انتقل إلى «ربط بالمواد».</p>
    </div>
    <div class="p-4 dash-mi-upload-grid">
      <article class="dash-mi-step-card">
        <h3 class="dash-mi-step-card__title">① رفع على الموقع</h3>
        <p class="dash-mi-step-card__desc">يمكنك اختيار عدة صور أو سحبها — تُرفع بالتوازي مع إمكانية الاستئناف.</p>

        <div id="uploadPickPanel">
          <div id="uploadDropZone" class="dash-mi-dropzone" tabindex="0" role="button" aria-label="اختر صوراً أو اسحبها هنا">
            <span class="material-symbols-outlined dash-mi-dropzone__icon" aria-hidden="true">cloud_upload</span>
            <p class="dash-mi-dropzone__title">اسحب الصور هنا</p>
            <p class="dash-mi-dropzone__hint">JPEG · PNG · GIF · WebP</p>
            <button type="button" id="uploadPickBtn" class="dash-mi-dropzone__btn">اختر ملفات</button>
            <input type="file" id="uploadPicker" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="sr-only">
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3">
            <button type="button" id="startUploadBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold" disabled>بدء الرفع</button>
            <span id="uploadPickSummary" class="text-xs text-text-muted"></span>
          </div>
        </div>

        <div id="uploadActivePanel" class="hidden space-y-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 mt-3">
          <p id="uploadActiveStatus" class="text-xs text-amber-900">جاري رفع الصور...</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" id="pauseUploadBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">إيقاف مؤقت</button>
            <button type="button" id="resumeUploadBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف</button>
            <button type="button" id="discardQueueBtn" class="h-9 px-4 rounded-lg border border-red-200 bg-white text-xs font-bold text-red-700">إلغاء</button>
          </div>
        </div>

        <div id="overallProgressWrap" class="hidden mt-3">
          <div class="flex justify-between text-xs text-text-muted mb-1">
            <span id="overallProgressLabel">0 / 0</span>
            <span id="remainingLabel">متبقي: 0</span>
          </div>
          <div class="h-2 rounded-full bg-surface-low overflow-hidden">
            <div id="overallProgressBar" class="h-full bg-primary transition-all duration-300" style="width:0%"></div>
          </div>
        </div>
      </article>

      <article class="dash-mi-step-card">
        <h3 class="dash-mi-step-card__title">② مزامنة الأمين</h3>
        <p class="dash-mi-step-card__desc">يرسل الطابور صورة واحدة في كل طلب — ابدأ المزامنة بعد الرفع أو بعد «فحص الملفات المحلية».</p>

        <div class="dash-mi-sync-primary mb-3">
          <button type="button" id="startSyncBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">بدء / استئناف المزامنة</button>
          <button type="button" id="pauseSyncBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">إيقاف مؤقت</button>
        </div>

        <label class="dash-mi-auto-sync-toggle">
          <input type="checkbox" id="autoSyncAfterUpload" checked>
          <span>مزامنة تلقائية بعد اكتمال الرفع</span>
        </label>

        <details class="dash-mi-sync-more">
          <summary class="dash-mi-sync-more__toggle">إجراءات إضافية</summary>
          <div class="dash-mi-sync-more__body dash-mi-toolbar__actions">
            <button type="button" id="retryFailedBtn" class="h-9 px-4 rounded-lg border border-amber-200 bg-amber-50 text-xs font-bold text-amber-900">إعادة المحاولة للفاشلة</button>
            <button type="button" id="scanLocalBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">فحص الملفات المحلية</button>
            <button type="button" id="purgeOrphanQueueBtn" class="h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold">تنظيف الطابور</button>
          </div>
        </details>

        <div id="syncProgressWrap" class="hidden mt-3">
          <div class="flex justify-between text-xs text-text-muted mb-1">
            <span id="syncProgressLabel">مزامنة...</span>
          </div>
          <div class="h-2 rounded-full bg-surface-low overflow-hidden">
            <div id="syncProgressBar" class="h-full bg-emerald-600 transition-all" style="width:0%"></div>
          </div>
        </div>
        <p id="syncStatus" class="text-xs text-text-muted mt-2"><?= !empty($apiHealth['ok']) ? 'جاهز للمزامنة.' : h((string) ($apiHealth['message'] ?? 'الأمين غير متصل.')) ?></p>
      </article>
    </div>
  </section>

  <section id="uploadQueueSection" class="hidden rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
      <h2 class="font-bold text-sm">طابور الرفع</h2>
      <span id="uploadQueueSummary" class="text-xs text-text-muted"></span>
    </div>
    <div id="uploadQueueList" class="divide-y divide-border-subtle max-h-[420px] overflow-auto"></div>
  </section>

  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-bold text-sm">طابور المزامنة مع الأمين</h2>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" id="deleteSelectedPendingBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold">حذف المحدد</button>
        <span class="text-xs text-text-muted" id="syncQueueSummary"><?= (int) ($queuePage['total_count'] ?? 0) ?> عنصر</span>
      </div>
    </div>

    <div class="dash-mi-queue-tabs px-4 pt-3" role="tablist" aria-label="تصفية الطابور">
      <button type="button" class="dash-mi-queue-tab is-active" data-queue-filter="" role="tab">الكل</button>
      <button type="button" class="dash-mi-queue-tab" data-queue-filter="pending" role="tab">بانتظار</button>
      <button type="button" class="dash-mi-queue-tab" data-queue-filter="syncing" role="tab">جاري</button>
      <button type="button" class="dash-mi-queue-tab" data-queue-filter="synced" role="tab">تمت</button>
      <button type="button" class="dash-mi-queue-tab" data-queue-filter="failed" role="tab">فاشلة</button>
    </div>

    <details class="dash-mi-danger-zone dash-mi-danger-zone--inline">
      <summary class="dash-mi-danger-zone__toggle">حذف جماعي من الموقع (خطير)</summary>
      <div class="dash-mi-danger-zone__body">
        <p class="dash-mi-danger-zone__hint">يحذف من مجلد الموقع والطابور فقط — لا يمس bm000.</p>
        <div class="dash-mi-toolbar__actions">
          <button type="button" id="deleteAllPendingBtn" class="h-8 px-3 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold">حذف كل غير المزامنة</button>
          <button type="button" id="pauseDeletePendingBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold hidden">إيقاف</button>
          <button type="button" id="resumeDeletePendingBtn" class="h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold hidden">استئناف</button>
        </div>
        <div id="deletePendingProgressWrap" class="hidden">
          <div class="flex justify-between text-xs text-text-muted mb-1">
            <span id="deletePendingProgressLabel">0 / 0</span>
            <span id="deletePendingStatusLabel">جاري الحذف...</span>
          </div>
          <div class="h-2 rounded-full bg-surface-low overflow-hidden mb-3">
            <div id="deletePendingProgressBar" class="h-full bg-red-500 transition-all" style="width:0%"></div>
          </div>
        </div>
      </div>
    </details>

    <div id="syncQueueCards" class="dash-mi-sync-cards"></div>
    <div class="dashboard-table-wrap dash-mi-sync-table">
      <table class="w-full text-sm min-w-[800px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="p-3 w-10"><input type="checkbox" id="syncQueueSelectAll" class="rounded" title="تحديد الكل في هذه الصفحة"></th>
            <th class="text-right p-3 w-14">معاينة</th>
            <th class="text-right p-3">الملف</th>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3">معرف الأمين</th>
            <th class="text-right p-3">ملاحظة</th>
            <th class="text-right p-3">إجراء</th>
          </tr>
        </thead>
        <tbody id="syncQueueBody" class="divide-y divide-border-subtle">
          <?php if ($queue === []): ?>
            <tr><td colspan="7" class="p-6 text-center text-text-muted">لا توجد عناصر في الطابور بعد. ارفع صوراً أو اضغط «فحص الملفات المحلية».</td></tr>
          <?php endif; ?>
          <?php foreach ($queue as $row): ?>
            <?php
              $status = (string) ($row['sync_status'] ?? 'pending');
              $meta = $statusLabels[$status] ?? $statusLabels['pending'];
              $canDeletePending = in_array($status, ['pending', 'failed'], true);
              $fileName = (string) ($row['file_name'] ?? '');
              $previewUrl = $fileName !== '' ? \Portal\Services\MaterialImageStorageService::publicUrl($fileName, true) : '';
            ?>
            <tr>
              <td class="p-3 text-center">
                <?php if ($canDeletePending): ?>
                  <input type="checkbox" class="sync-queue-select rounded" data-queue-id="<?= h((string) ($row['id'] ?? '')) ?>">
                <?php endif; ?>
              </td>
              <td class="p-3">
                <?php if ($previewUrl !== ''): ?>
                  <img src="<?= h($previewUrl) ?>" alt="" class="dash-mi-sync-thumb" loading="lazy" decoding="async">
                <?php endif; ?>
              </td>
              <td class="p-3 font-mono text-xs" dir="ltr"><?= h($fileName) ?></td>
              <td class="p-3"><span class="text-xs px-2 py-0.5 rounded-full <?= h($meta['class']) ?>"><?= h($meta['label']) ?></span></td>
              <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($row['amine_image_guid'] ?? '—')) ?></td>
              <td class="p-3 text-xs text-text-muted"><?= h((string) ($row['amine_sync_error_ar'] ?? '')) ?></td>
              <td class="p-3 text-xs">
                <?php if ($canDeletePending): ?>
                  <button type="button" class="delete-pending-queue-btn h-7 px-2 rounded border border-red-200 bg-red-50 text-red-700 font-bold" data-queue-id="<?= h((string) ($row['id'] ?? '')) ?>" data-file-name="<?= h($fileName) ?>">حذف محلي</button>
                <?php else: ?>
                  <span class="text-text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="syncQueuePagination" class="px-4 py-3 border-t border-border-subtle bg-surface-low/40 flex items-center justify-between gap-2">
      <button type="button" id="syncQueuePrevBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>السابق</button>
      <span class="text-xs text-text-muted" id="syncQueuePageLabel">صفحة 1</span>
      <button type="button" id="syncQueueNextBtn" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold disabled:opacity-40" disabled>التالي</button>
    </div>
  </article>

  <details class="rounded-xl border border-border-subtle bg-white p-4 mb-6">
    <summary class="font-bold cursor-pointer">مسارات التخزين (متقدم)</summary>
    <form method="post" class="grid gap-3 mt-4 lg:grid-cols-2">
      <input type="hidden" name="action" value="save_settings">
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الصور على الموقع</span>
        <input name="material_images_dir" value="<?= h((string) ($settingsForm['material_images_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="اتركه فارغاً للافتراضي">
      </label>
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الثامبنيل</span>
        <input name="material_thumbnails_dir" value="<?= h((string) ($settingsForm['material_thumbnails_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
      </label>
      <div class="lg:col-span-2 text-[11px] text-text-muted font-mono" dir="ltr">
        images: <?= h((string) ($paths['images_dir'] ?? '')) ?> · thumbs: <?= h((string) ($paths['thumbnails_dir'] ?? '')) ?>
      </div>
      <button class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold lg:col-span-2 lg:justify-self-start">حفظ المسارات</button>
    </form>
  </details>
</div>

<script type="application/json" id="material-images-upload-config"><?= json_encode($uploadBootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
