<?php

declare(strict_types=1);

/** @var array<string, mixed> $report */

$report = is_array($report ?? null) ? $report : [];
$severity = (string) ($report['severity'] ?? 'ok');
$headline = (string) ($report['headline_ar'] ?? '');
$checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
$recommendations = is_array($report['recommendations'] ?? null) ? $report['recommendations'] : [];
$durationMs = (int) ($report['duration_ms'] ?? 0);
$generatedAt = (string) ($report['generated_at'] ?? '');

$severityClass = match ($severity) {
    'critical' => 'bg-red-50 border-red-200 text-red-900',
    'warning' => 'bg-amber-50 border-amber-200 text-amber-950',
    default => 'bg-emerald-50 border-emerald-200 text-emerald-950',
};

$severityLabel = match ($severity) {
    'critical' => 'حرج',
    'warning' => 'تحذير',
    default => 'طبيعي',
};

$rowSeverityClass = static function (string $sev): string {
    return match ($sev) {
        'critical' => 'border-red-200 bg-red-50/50',
        'warning' => 'border-amber-200 bg-amber-50/40',
        'info' => 'border-slate-200 bg-slate-50/50',
        default => 'border-border-subtle bg-white',
    };
};
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">تشخيص الأداء</h1>
      <p class="text-sm text-text-muted mt-1 max-w-2xl">
        فحص تلقائي: قاعدة Portal، سرعة API الأمين، طابور الصور، والعمال الخلفيين.
        قد يستغرق حتى دقيقة (يختبر طلب مواد حقيقي).
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a
        href="/dashboard/system-diagnostic.php"
        class="h-10 inline-flex items-center gap-1 rounded-xl bg-primary px-4 text-sm font-bold text-white no-underline"
      >
        <span class="material-symbols-outlined text-lg">refresh</span>
        إعادة الفحص
      </a>
      <a
        href="/dashboard/system-diagnostic.php?format=json"
        class="h-10 inline-flex items-center gap-1 rounded-xl border border-border-subtle bg-white px-4 text-sm font-bold text-slate-800 no-underline"
        target="_blank"
        rel="noopener"
      >
        JSON
      </a>
    </div>
  </div>
</section>

<article class="rounded-2xl border p-5 mb-6 <?= h($severityClass) ?>">
  <div class="flex flex-wrap items-center gap-2 mb-2">
    <span class="text-xs font-bold uppercase tracking-wide opacity-80">النتيجة: <?= h($severityLabel) ?></span>
    <?php if ($generatedAt !== ''): ?>
      <span class="text-xs opacity-70" dir="ltr"><?= h($generatedAt) ?></span>
    <?php endif; ?>
    <?php if ($durationMs > 0): ?>
      <span class="text-xs opacity-70">· مدة الفحص <?= (int) $durationMs ?> ms</span>
    <?php endif; ?>
  </div>
  <p class="text-base font-bold leading-relaxed"><?= h($headline) ?></p>
</article>

<?php if ($recommendations !== []): ?>
<section class="mb-6">
  <h2 class="text-lg font-bold mb-3">ماذا تفعل الآن؟</h2>
  <ol class="list-decimal list-inside space-y-2 text-sm text-slate-800 bg-white border border-border-subtle rounded-2xl p-5">
    <?php foreach ($recommendations as $rec): ?>
      <li><?= h((string) $rec) ?></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<section class="space-y-3">
  <h2 class="text-lg font-bold">تفاصيل الفحوص</h2>
  <?php foreach ($checks as $check): ?>
    <?php
      if (!is_array($check)) {
          continue;
      }
      $sev = (string) ($check['severity'] ?? 'ok');
      $ok = (bool) ($check['ok'] ?? false);
      $ms = $check['duration_ms'] ?? null;
    ?>
    <article class="rounded-xl border p-4 <?= h($rowSeverityClass($sev)) ?>">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <h3 class="font-bold text-slate-900"><?= h((string) ($check['label_ar'] ?? '')) ?></h3>
        <span class="text-xs font-bold <?= $ok ? 'text-emerald-700' : 'text-red-700' ?>">
          <?= $ok ? 'OK' : 'FAIL' ?>
          <?php if ($ms !== null && $ms !== ''): ?>
            · <?= (int) $ms ?> ms
          <?php endif; ?>
        </span>
      </div>
      <p class="text-sm text-text-muted mt-2 leading-relaxed"><?= h((string) ($check['detail_ar'] ?? '')) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<section class="mt-8 text-xs text-text-muted leading-relaxed">
  <p>
    من سطر الأوامر على السيرفر:
    <code dir="ltr" class="bg-slate-100 px-1 rounded">php scripts/run-system-diagnostic.php</code>
  </p>
  <p class="mt-1">
    هذا الفحص لا يستبدل مراقبة Task Manager على سيرفر API (SQL Server) — إن كان «مواد» بطيئاً والـ health سريعاً، ركّز على سيرفر الأمين.
  </p>
</section>
