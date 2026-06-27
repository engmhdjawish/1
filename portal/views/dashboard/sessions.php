<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $onlineStaff */
/** @var list<array<string, mixed>> $onlineCustomers */
/** @var list<array<string, mixed>> $onlineGuests */
/** @var array{staff: int, customers: int, guests: int, total: int} $onlineCounts */
/** @var bool $schemaReady */
/** @var string|null $flash */
/** @var string $flashType */

$formatSeen = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $ts = strtotime($value);

    return $ts === false ? $value : date('Y-m-d H:i', $ts);
};
?>
<section class="mb-6">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">المتصلون الآن</h1>
      <p class="text-sm text-text-muted mt-1">
        من يستخدم الموقع أو لوحة التحكم حالياً (آخر 5 دقائق). تسجيل دخول جديد يُنهي الجلسات الأخرى لنفس الحساب.
      </p>
      <p class="text-xs text-amber-700 mt-2">تنبيه: إنهاء جلستك الحالية من هذه الصفحة يُخرجك فوراً من لوحة التحكم.</p>
    </div>
    <?php if ($schemaReady): ?>
      <div class="flex flex-wrap gap-2">
        <form method="post" onsubmit="return confirm('إنهاء كل جلسات الموظفين المتصلين؟');">
          <input type="hidden" name="action" value="revoke_all_online">
          <input type="hidden" name="kind" value="staff">
          <button class="h-10 px-4 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-surface-low">إنهاء جلسات الموظفين</button>
        </form>
        <form method="post" onsubmit="return confirm('إنهاء كل جلسات العملاء المتصلين؟');">
          <input type="hidden" name="action" value="revoke_all_online">
          <input type="hidden" name="kind" value="customer">
          <button class="h-10 px-4 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-surface-low">إنهاء جلسات العملاء</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<?php if (!$schemaReady): ?>
  <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    تتبع الجلسات غير مفعّل بعد. شغّل:
    <code class="font-mono text-xs">docs/portal-migrations/009-web-sessions-tracking.sql</code>
  </div>
<?php else: ?>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <p class="text-xs font-bold text-text-muted">الإجمالي الآن</p>
      <p class="text-3xl font-extrabold text-slate-900 mt-1"><?= (int) ($onlineCounts['total'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <p class="text-xs font-bold text-text-muted">موظفون</p>
      <p class="text-3xl font-extrabold text-indigo-700 mt-1"><?= (int) ($onlineCounts['staff'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <p class="text-xs font-bold text-text-muted">عملاء</p>
      <p class="text-3xl font-extrabold text-emerald-700 mt-1"><?= (int) ($onlineCounts['customers'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <p class="text-xs font-bold text-text-muted">زوار (غير مسجّلين)</p>
      <p class="text-3xl font-extrabold text-sky-700 mt-1"><?= (int) ($onlineCounts['guests'] ?? 0) ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <?php
    $sections = [
        ['title' => 'موظفو الموقع', 'rows' => $onlineStaff, 'kind' => 'staff', 'empty' => 'لا يوجد موظفون متصلون حالياً.'],
        ['title' => 'عملاء الموقع', 'rows' => $onlineCustomers, 'kind' => 'customer', 'empty' => 'لا يوجد عملاء متصلون حالياً.'],
    ];
    foreach ($sections as $section):
    ?>
      <article class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between">
          <h2 class="text-lg font-extrabold text-slate-900"><?= h($section['title']) ?></h2>
          <span class="text-xs font-bold text-text-muted"><?= count($section['rows']) ?> متصل</span>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-low text-text-muted">
              <tr>
                <th class="px-4 py-3 text-right font-bold">الاسم</th>
                <th class="px-4 py-3 text-right font-bold">آخر نشاط</th>
                <th class="px-4 py-3 text-right font-bold">IP</th>
                <th class="px-4 py-3 text-left font-bold">إجراء</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <?php if ($section['rows'] === []): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-text-muted"><?= h($section['empty']) ?></td></tr>
              <?php endif; ?>
              <?php foreach ($section['rows'] as $row): ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3">
                    <div class="font-bold text-slate-900"><?= h((string) ($row['display_name'] ?? '—')) ?></div>
                    <div class="text-xs text-text-muted mt-0.5" dir="ltr"><?= h((string) ($row['login_name'] ?? '')) ?></div>
                  </td>
                  <td class="px-4 py-3 text-xs text-text-muted"><?= h($formatSeen((string) ($row['last_seen_at'] ?? ''))) ?></td>
                  <td class="px-4 py-3 text-xs text-text-muted" dir="ltr"><?= h((string) ($row['created_ip'] ?? '—')) ?></td>
                  <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-2">
                      <form method="post" onsubmit="return confirm('إنهاء هذه الجلسة؟');">
                        <input type="hidden" name="action" value="revoke_one">
                        <input type="hidden" name="kind" value="<?= h($section['kind']) ?>">
                        <input type="hidden" name="session_id" value="<?= h((string) ($row['session_id'] ?? '')) ?>">
                        <button class="h-8 px-3 rounded-lg bg-red-600 text-white text-xs font-bold">إنهاء</button>
                      </form>
                      <form method="post" onsubmit="return confirm('إنهاء كل جلسات هذا الحساب؟');">
                        <input type="hidden" name="action" value="revoke_subject">
                        <input type="hidden" name="kind" value="<?= h($section['kind']) ?>">
                        <input type="hidden" name="subject_id" value="<?= h((string) ($row['subject_id'] ?? '')) ?>">
                        <button class="h-8 px-3 rounded-lg border border-border-subtle text-xs font-bold text-slate-700">كل الجلسات</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if (($onlineGuests ?? []) !== []): ?>
    <article class="mt-6 rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between">
        <div>
          <h2 class="text-lg font-extrabold text-slate-900">زوار غير مسجّلين</h2>
          <p class="text-xs text-text-muted mt-0.5">تقدير من نشاط الصفحة خلال آخر 5 دقائق</p>
        </div>
        <span class="text-xs font-bold text-text-muted"><?= count($onlineGuests) ?> زائر</span>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-low text-text-muted">
            <tr>
              <th class="px-4 py-3 text-right font-bold">الموقع التقريبي</th>
              <th class="px-4 py-3 text-right font-bold">آخر نشاط</th>
              <th class="px-4 py-3 text-right font-bold">IP</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <?php foreach ($onlineGuests as $row): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <div class="font-bold text-slate-900"><?= h(trim((string) (($row['city_ar'] ?? '') !== '' ? $row['city_ar'] : 'مدينة غير معروفة') . ' · ' . ($row['country_ar'] ?? 'بلد غير معروف'))) ?></div>
                </td>
                <td class="px-4 py-3 text-xs text-text-muted"><?= h($formatSeen((string) ($row['last_seen_at'] ?? ''))) ?></td>
                <td class="px-4 py-3 text-xs text-text-muted" dir="ltr"><?= h((string) ($row['visitor_ip'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  <?php endif; ?>
<?php endif; ?>

<script>
  if (<?= $schemaReady ? 'true' : 'false' ?>) {
    window.setInterval(() => window.location.reload(), 60000);
  }
</script>
