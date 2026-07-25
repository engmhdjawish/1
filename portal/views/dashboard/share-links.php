<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $links */
/** @var array<string, mixed> $filters */
/** @var array{total: int, active: int, expired: int, protected: int} $stats */
/** @var list<array{id: string, code: string, name_ar: string}> $policies */
/** @var array<string, mixed>|null $editLink */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $isNew */
/** @var string|null $flash */
/** @var string $flashType */
/** @var string $publicBaseUrl */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */

$editLink = is_array($editLink ?? null) ? $editLink : [];
$showForm = $showForm ?? false;
$isNew = $isNew ?? false;

$shareUrlFor = static function (string $token) use ($publicBaseUrl): string {
    return $publicBaseUrl . '/share.php?token=' . rawurlencode($token);
};

$buildShareLinksUrl = static function (array $params = []) use ($filters): string {
    $query = array_merge([
        'q' => (string) ($filters['q'] ?? ''),
        'active' => (string) ($filters['active'] ?? ''),
        'limit' => (string) ($filters['limit'] ?? '100'),
    ], $params);

    return '/dashboard/share-links.php?' . http_build_query(array_filter(
        $query,
        static fn ($value) => $value !== null && $value !== ''
    ));
};

$activeFilter = (string) ($filters['active'] ?? '');
$statusTabs = [
    '' => ['label' => 'الكل', 'count' => (int) $stats['total']],
    '1' => ['label' => 'نشط', 'count' => (int) $stats['active']],
    '0' => ['label' => 'متوقف', 'count' => max(0, (int) $stats['total'] - (int) $stats['active'])],
];
?>
<section class="mb-4">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">روابط المشاركة</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">إنشاء روابط تسويقية مخصّصة مع فلاتر وصلاحيات وصول — الصفحة العامة تستخدم نفس تصميم المتجر.</p>
    </div>
    <?php if (!$showForm): ?>
      <a href="/dashboard/share-links.php?new=1" class="inline-flex items-center justify-center gap-1.5 h-10 px-4 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110 transition shrink-0">
        <span class="material-symbols-outlined text-lg">add_link</span>
        رابط جديد
      </a>
    <?php endif; ?>
  </div>
  <div class="flex flex-wrap gap-2 mt-4 text-xs">
    <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">إجمالي: <strong><?= (int) $stats['total'] ?></strong></span>
    <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">نشط: <strong class="text-emerald-700"><?= (int) $stats['active'] ?></strong></span>
    <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">منتهي: <strong class="text-amber-700"><?= (int) $stats['expired'] ?></strong></span>
    <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">محمي: <strong class="text-blue-700"><?= (int) $stats['protected'] ?></strong></span>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if ($showForm): ?>
  <?php require __DIR__ . '/partials/share-link-form-panel.php'; ?>
<?php endif; ?>

<?php if (!$showForm): ?>
<section class="bg-white border border-border-subtle rounded-xl p-3 mb-4 space-y-3">
  <form method="get" data-dashboard-filter class="grid grid-cols-1 md:grid-cols-[1fr_auto_auto] gap-2 md:items-end">
    <?php if ($activeFilter !== ''): ?>
      <input type="hidden" name="active" value="<?= h($activeFilter) ?>">
    <?php endif; ?>
    <label class="text-sm">
      <span class="text-text-muted block mb-1 text-xs">بحث</span>
      <input type="search" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm" placeholder="اسم الرابط أو التوكن">
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1 text-xs">عدد النتائج</span>
      <select name="limit" class="h-10 w-full md:w-32 rounded-xl border border-border-subtle px-3 text-sm">
        <option value="50" <?= ((int) ($filters['limit'] ?? 100)) === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= ((int) ($filters['limit'] ?? 100)) === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= ((int) ($filters['limit'] ?? 100)) === 200 ? 'selected' : '' ?>>200</option>
      </select>
    </label>
    <button class="h-10 px-5 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110 transition">تطبيق</button>
  </form>
  <nav class="dash-cust-tabs" aria-label="حالة الروابط">
    <?php foreach ($statusTabs as $key => $tab): ?>
      <a href="<?= h($buildShareLinksUrl(['active' => $key])) ?>" class="dash-cust-tab<?= $activeFilter === (string) $key ? ' is-active' : '' ?>">
        <span><?= h($tab['label']) ?></span>
        <span class="dash-cust-tab__count"><?= (int) $tab['count'] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="bg-white border border-border-subtle rounded-2xl shadow-sm overflow-hidden">
  <?php if ($links === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد روابط مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[960px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">الرابط</th>
            <th class="px-4 py-3 text-right font-bold">السياسة</th>
            <th class="px-4 py-3 text-right font-bold">فلاتر</th>
            <th class="px-4 py-3 text-right font-bold">انتهاء</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-right font-bold">المشاركة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($links as $row): ?>
            <?php $rowShareUrl = $shareUrlFor((string) ($row['public_token'] ?? '')); ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($row['name_ar'] ?? '')) ?></div>
                <div class="text-xs font-mono text-text-muted mt-0.5"><?= h((string) ($row['public_token'] ?? '')) ?></div>
                <?php if (!empty($row['require_password'])): ?>
                  <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold">محمي</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs"><?= h((string) ($row['access_policy_name_ar'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <div class="font-bold text-slate-800"><?= (int) ($row['filters_count'] ?? 0) ?></div>
                <?php if (trim((string) ($row['keyword'] ?? '')) !== ''): ?>
                  <div class="text-[11px] text-text-muted"><?= h((string) $row['keyword']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-text-muted"><?= h((string) ($row['expires_at'] ?? '—')) ?></td>
              <td class="px-4 py-3">
                <?= !empty($row['is_active']) ? '<span class="text-emerald-700 font-bold text-xs">نشط</span>' : '<span class="text-xs text-slate-500">متوقف</span>' ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-1">
                  <a href="/share.php?token=<?= urlencode((string) ($row['public_token'] ?? '')) ?>" target="_blank" class="h-7 px-2 inline-flex items-center rounded border border-border-subtle text-[11px] font-bold text-primary hover:bg-slate-50">فتح</a>
                  <button type="button" data-copy-url="<?= h($rowShareUrl) ?>" class="h-7 px-2 rounded border border-border-subtle bg-white text-[11px] font-bold text-slate-700 hover:bg-slate-50">نسخ</button>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5 flex-wrap">
                  <a href="/dashboard/share-links.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                  <form method="post" data-dashboard-ajax data-dashboard-reload>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                    <?php if (!empty($row['is_active'])): ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                    <?php else: ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" data-dashboard-ajax data-dashboard-reload onsubmit="return confirm('حذف الرابط؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <button class="h-8 px-3 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$showForm): ?>
<script>
(() => {
  const copyText = async (text) => {
    if (!text) return false;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) {}
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (_) {}
    textarea.remove();
    return ok;
  };

  document.querySelectorAll('[data-copy-url]').forEach((button) => {
    button.addEventListener('click', async () => {
      const url = button.getAttribute('data-copy-url') || '';
      const ok = await copyText(url);
      const original = button.textContent;
      button.textContent = ok ? 'تم النسخ' : 'فشل';
      setTimeout(() => {
        button.textContent = original;
      }, 1400);
    });
  });
})();
</script>
<?php endif; ?>
