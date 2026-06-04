<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $links */
/** @var array<string, mixed> $filters */
/** @var array{total: int, active: int, expired: int, protected: int} $stats */
/** @var list<array{id: string, code: string, name_ar: string}> $policies */
/** @var array<string, mixed>|null $editLink */
/** @var string $editId */
/** @var string|null $flash */
/** @var string $flashType */
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">إدارة روابط المشاركة</h1>
    <p class="text-sm text-text-muted mt-1">إنشاء الروابط التسويقية وضبط صلاحيات الوصول والفلاتر المرتبطة بها.</p>
  </div>
  <div class="flex flex-wrap gap-3">
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold"><?= (int) $stats['total'] ?></p>
      <p class="text-xs text-text-muted">إجمالي الروابط</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-green-700"><?= (int) $stats['active'] ?></p>
      <p class="text-xs text-text-muted">روابط نشطة</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-amber-700"><?= (int) $stats['expired'] ?></p>
      <p class="text-xs text-text-muted">منتهية</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-blue-700"><?= (int) $stats['protected'] ?></p>
      <p class="text-xs text-text-muted">محمي بكلمة مرور</p>
    </article>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-5 mb-6">
  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-bold text-lg"><?= $editId !== '' ? 'تعديل رابط المشاركة' : 'إضافة رابط مشاركة جديد' ?></h2>
      <?php if ($editId !== ''): ?>
        <a href="/dashboard/share-links.php" class="text-sm text-text-muted hover:text-primary">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string) ($editLink['id'] ?? '')) ?>">
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">اسم الرابط</span>
        <input name="name_ar" required value="<?= h((string) ($editLink['name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="مثال: عروض الصيف - العملاء المميزون">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">سياسة الوصول</span>
        <select name="access_policy_id" required class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          <option value="">اختر السياسة</option>
          <?php foreach ($policies as $policy): ?>
            <option value="<?= h($policy['id']) ?>" <?= (string) ($editLink['access_policy_id'] ?? '') === (string) $policy['id'] ? 'selected' : '' ?>>
              <?= h($policy['name_ar']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">كلمة مفتاحية</span>
        <input name="keyword" value="<?= h((string) ($editLink['keyword'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="new-arrivals">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أقل كمية مطلوبة</span>
        <input name="min_quantity" type="number" min="0" step="0.01" value="<?= h((string) ($editLink['min_quantity'] ?? '0')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">ينتهي بتاريخ</span>
        <input name="expires_at" type="datetime-local" value="<?= h(isset($editLink['expires_at']) && $editLink['expires_at'] ? str_replace(' ', 'T', substr((string) $editLink['expires_at'], 0, 16)) : '') ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="require_password" <?= !empty($editLink['require_password']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>حماية الرابط بكلمة مرور</span>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">اسم مستخدم الوصول</span>
        <input name="access_username" value="<?= h((string) ($editLink['access_username'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="guest-username">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">كلمة مرور الوصول <?= $editId !== '' ? '(اختياري للتغيير)' : '' ?></span>
        <input name="plain_password" type="password" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="••••••••">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" <?= $editLink === null || !empty($editLink['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>الرابط نشط</span>
      </label>
      <div class="md:col-span-2 flex justify-end">
        <button class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء رابط' ?>
        </button>
      </div>
    </form>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h3 class="font-bold mb-3">ملاحظات الاستخدام</h3>
    <ul class="space-y-2 text-sm text-text-muted">
      <li>• لكل رابط token مستقل يمكن مشاركته على المسوّقين أو العملاء.</li>
      <li>• عند تفعيل كلمة المرور، يصبح الدخول عبر user/pass للرابط.</li>
      <li>• كلمة مفتاحية وأدنى كمية تُستخدم كفلتر أولي للمنتجات.</li>
      <li>• استخدم الإيقاف المؤقت للرابط بدل الحذف للحفاظ على الإحصاءات.</li>
    </ul>
  </article>
</section>

<section class="bg-white border border-border-subtle rounded-2xl p-5 mb-5">
  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">بحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="اسم الرابط أو التوكن">
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">الحالة</span>
      <select name="active" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <option value="1" <?= ($filters['active'] ?? '') === '1' ? 'selected' : '' ?>>نشط</option>
        <option value="0" <?= ($filters['active'] ?? '') === '0' ? 'selected' : '' ?>>متوقف</option>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">عدد النتائج</span>
      <select name="limit" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="50" <?= ((int) ($filters['limit'] ?? 100)) === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= ((int) ($filters['limit'] ?? 100)) === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= ((int) ($filters['limit'] ?? 100)) === 200 ? 'selected' : '' ?>>200</option>
      </select>
    </label>
    <button class="h-11 rounded-xl bg-primary text-white font-bold px-5 hover:brightness-110 transition">تطبيق</button>
  </form>
</section>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <?php if ($links === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد روابط مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1050px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-5 py-4 font-bold">الاسم</th>
            <th class="text-right px-5 py-4 font-bold">التوكن</th>
            <th class="text-right px-5 py-4 font-bold">السياسة</th>
            <th class="text-right px-5 py-4 font-bold">الفلاتر الأولية</th>
            <th class="text-right px-5 py-4 font-bold">انتهاء الصلاحية</th>
            <th class="text-right px-5 py-4 font-bold">الحالة</th>
            <th class="text-left px-5 py-4 font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($links as $row): ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4 font-bold"><?= h((string) ($row['name_ar'] ?? '')) ?></td>
              <td class="px-5 py-4 text-xs">
                <div class="font-mono text-slate-700"><?= h((string) ($row['public_token'] ?? '')) ?></div>
                <?php if (!empty($row['require_password'])): ?>
                  <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[11px]">محمي</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4"><?= h((string) ($row['access_policy_name_ar'] ?? '')) ?></td>
              <td class="px-5 py-4 text-sm text-text-muted">
                <div>keyword: <?= h((string) ($row['keyword'] ?? '—')) ?></div>
                <div>minQty: <?= number_format((float) ($row['min_quantity'] ?? 0), 0, '.', ',') ?></div>
              </td>
              <td class="px-5 py-4 text-xs text-text-muted"><?= h((string) ($row['expires_at'] ?? 'غير محدد')) ?></td>
              <td class="px-5 py-4">
                <?php if (!empty($row['is_active'])): ?>
                  <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-xs font-bold">نشط</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-slate-100 text-slate-700 px-3 py-1 text-xs font-bold">متوقف</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a href="/dashboard/share-links.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-9 px-3 rounded-lg border border-border-subtle text-xs text-text-muted bg-white hover:bg-surface-low">تعديل</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold <?= !empty($row['is_active']) ? 'bg-slate-800 text-white' : 'bg-primary text-white' ?>">
                      <?= !empty($row['is_active']) ? 'إيقاف' : 'تفعيل' ?>
                    </button>
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
