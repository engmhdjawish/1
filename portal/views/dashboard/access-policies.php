<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $policies */
/** @var array<string, mixed>|null $editPolicy */
/** @var string $editId */
/** @var string|null $guestPolicyId */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canManage */
?>
<section class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">سياسات الوصول</h1>
    <p class="text-sm text-text-muted mt-1">تحكم بما يراه الزائر والعميل ورابط المشاركة: السعر، الكمية، السلة، والطلب.</p>
  </div>
  <a href="/dashboard/settings.php" class="inline-flex items-center gap-2 text-sm font-bold text-primary hover:underline">
    <span class="material-symbols-outlined text-base">arrow_forward</span>
    العودة للإعدادات
  </a>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-2xl p-5 mb-5">
  <h2 class="text-base font-extrabold text-slate-900 mb-3">سياسة المتجر العام (الزائر)</h2>
  <p class="text-sm text-text-muted mb-4">اختر السياسة الافتراضية لزوار المتجر العام قبل تسجيل الدخول.</p>
  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
    <input type="hidden" name="action" value="save_guest_policy">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">السياسة الافتراضية</span>
      <select name="access_policy_id" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">اختر السياسة</option>
        <?php foreach ($policies as $policy): ?>
          <?php if ((int) ($policy['is_active'] ?? 0) !== 1) {
              continue;
          } ?>
          <option value="<?= h((string) ($policy['id'] ?? '')) ?>" <?= $guestPolicyId === (string) ($policy['id'] ?? '') ? 'selected' : '' ?>>
            <?= h((string) ($policy['name_ar'] ?? '')) ?> (<?= h((string) ($policy['code'] ?? '')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="flex md:justify-end">
      <button class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">حفظ سياسة الزائر</button>
    </div>
  </form>
</section>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <article class="xl:col-span-1 bg-white border border-border-subtle rounded-2xl p-5">
    <h2 class="text-base font-extrabold text-slate-900 mb-4">
      <?= $editPolicy ? 'تعديل سياسة' : 'إضافة سياسة' ?>
    </h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($editId) ?>">

      <label class="text-sm block">
        <span class="text-text-muted block mb-1">الرمز (إنجليزي)</span>
        <input name="code" required pattern="[a-z0-9_]+" <?= $editPolicy ? 'readonly' : '' ?>
          value="<?= h((string) ($editPolicy['code'] ?? '')) ?>"
          class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary <?= $editPolicy ? 'bg-surface-low' : '' ?>"
          placeholder="share_no_price">
      </label>

      <label class="text-sm block">
        <span class="text-text-muted block mb-1">الاسم بالعربية</span>
        <input name="name_ar" required value="<?= h((string) ($editPolicy['name_ar'] ?? '')) ?>"
          class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>

      <label class="text-sm block">
        <span class="text-text-muted block mb-1">الوصف (اختياري)</span>
        <textarea name="description_ar" rows="2"
          class="w-full rounded-xl border border-border-subtle px-4 py-3 focus:border-primary focus:ring-primary"><?= h((string) ($editPolicy['description_ar'] ?? '')) ?></textarea>
      </label>

      <div class="grid grid-cols-2 gap-3 text-sm">
        <?php
        $flags = [
            'show_price' => 'إظهار السعر',
            'show_quantity' => 'إظهار الكمية',
            'allow_cart' => 'السلة',
            'allow_order' => 'الطلب',
        ];
        foreach ($flags as $field => $label):
            $checked = $editPolicy ? (int) ($editPolicy[$field] ?? 0) === 1 : ($field === 'show_price' || $field === 'allow_cart' || $field === 'allow_order');
            ?>
          <label class="flex items-center gap-2 rounded-xl border border-border-subtle px-3 py-2 bg-surface-low">
            <input type="checkbox" name="<?= h($field) ?>" value="1" <?= $checked ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span><?= h($label) ?></span>
          </label>
        <?php endforeach; ?>
        <label class="flex items-center gap-2 rounded-xl border border-border-subtle px-3 py-2 col-span-2">
          <input type="checkbox" name="is_active" value="1" <?= !$editPolicy || (int) ($editPolicy['is_active'] ?? 0) === 1 ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>السياسة نشطة (تظهر في القوائم)</span>
        </label>
      </div>

      <div class="flex flex-wrap gap-2 pt-2">
        <button type="submit" class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editPolicy ? 'حفظ التعديلات' : 'إضافة السياسة' ?>
        </button>
        <?php if ($editPolicy): ?>
          <a href="/dashboard/access-policies.php" class="h-11 px-6 inline-flex items-center rounded-xl border border-border-subtle font-bold text-text-muted hover:bg-surface-low transition">إلغاء</a>
        <?php endif; ?>
      </div>
    </form>
  </article>

  <article class="xl:col-span-2 bg-white border border-border-subtle rounded-2xl p-5 overflow-auto">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900">كل السياسات</h2>
      <span class="text-xs text-text-muted"><?= count($policies) ?> سياسة</span>
    </div>
    <table class="w-full min-w-[920px] text-sm">
      <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
        <tr>
          <th class="px-3 py-3 text-right font-bold">السياسة</th>
          <th class="px-3 py-3 text-right font-bold">سعر</th>
          <th class="px-3 py-3 text-right font-bold">كمية</th>
          <th class="px-3 py-3 text-right font-bold">سلة</th>
          <th class="px-3 py-3 text-right font-bold">طلب</th>
          <th class="px-3 py-3 text-right font-bold">الاستخدام</th>
          <th class="px-3 py-3 text-right font-bold">إجراءات</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php foreach ($policies as $policy): ?>
          <?php
            $pid = (string) ($policy['id'] ?? '');
            $isGuest = $guestPolicyId === $pid;
            $usage = is_array($policy['usage'] ?? null) ? $policy['usage'] : ['total' => 0];
            $usageTotal = (int) ($usage['total'] ?? 0);
            ?>
          <tr class="<?= $isGuest ? 'bg-primary/5' : '' ?> <?= (int) ($policy['is_active'] ?? 0) !== 1 ? 'opacity-60' : '' ?>">
            <td class="px-3 py-3">
              <div class="font-bold text-slate-900"><?= h((string) ($policy['name_ar'] ?? '')) ?></div>
              <div class="text-xs text-text-muted"><?= h((string) ($policy['code'] ?? '')) ?></div>
              <?php if ($isGuest): ?>
                <span class="inline-flex mt-1 rounded-full bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5">متجر عام</span>
              <?php endif; ?>
              <?php if ((int) ($policy['is_active'] ?? 0) !== 1): ?>
                <span class="inline-flex mt-1 rounded-full bg-slate-200 text-slate-700 text-xs font-bold px-2 py-0.5">معطّلة</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3"><?= (int) ($policy['show_price'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-3 py-3"><?= (int) ($policy['show_quantity'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-3 py-3"><?= (int) ($policy['allow_cart'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-3 py-3"><?= (int) ($policy['allow_order'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-3 py-3 text-xs text-text-muted">
              <?php if ($usageTotal === 0): ?>
                غير مستخدمة
              <?php else: ?>
                <?= (int) ($usage['share_links'] ?? 0) ?> رابط،
                <?= (int) ($usage['web_customers'] ?? 0) ?> عميل
                <?php if ((int) ($usage['guest_store'] ?? 0) > 0): ?>
                  ، متجر عام
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3">
              <div class="flex flex-wrap gap-2">
                <a href="/dashboard/access-policies.php?edit=<?= h($pid) ?>" class="text-xs font-bold text-primary hover:underline">تعديل</a>
                <?php if ($usageTotal === 0): ?>
                  <form method="post" class="inline" onsubmit="return confirm('حذف هذه السياسة نهائياً؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= h($pid) ?>">
                    <button type="submit" class="text-xs font-bold text-red-600 hover:underline">حذف</button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-text-muted" title="غيّر الربط أولاً أو عطّل السياسة">لا حذف</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </article>
</section>
