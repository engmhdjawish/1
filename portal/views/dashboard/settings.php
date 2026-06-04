<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var list<array<string, mixed>> $policies */
/** @var string|null $guestPolicyId */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array{base_url: string, username: string} $apiConfig */
/** @var bool $canManageCompany */
/** @var bool $canManageGuestPolicy */
/** @var string|null $flash */
/** @var string $flashType */
?>
<section class="mb-6">
  <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات العامة</h1>
  <p class="text-sm text-text-muted mt-1">هوية الشركة، سياسة الزائر الافتراضية، ومؤشرات الاتصال مع API.</p>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <article class="xl:col-span-2 bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900">هوية الشركة</h2>
      <?php if (!$canManageCompany): ?>
        <span class="text-xs rounded-full px-3 py-1 bg-amber-100 text-amber-700">قراءة فقط</span>
      <?php endif; ?>
    </div>

    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="action" value="save_company">

      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">اسم الشركة</span>
        <input name="company_name" value="<?= h($company['company_name'] ?? '') ?>" <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>

      <label class="text-sm">
        <span class="text-text-muted block mb-1">الهاتف الثابت</span>
        <input name="company_phone" value="<?= h($company['company_phone'] ?? '') ?>" <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>

      <label class="text-sm">
        <span class="text-text-muted block mb-1">الموبايل</span>
        <input name="company_mobile" value="<?= h($company['company_mobile'] ?? '') ?>" <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>

      <label class="text-sm">
        <span class="text-text-muted block mb-1">واتساب</span>
        <input name="company_whatsapp" value="<?= h($company['company_whatsapp'] ?? '') ?>" <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>

      <label class="text-sm">
        <span class="text-text-muted block mb-1">ملف الشعار (اسم/مسار)</span>
        <input name="company_logo" value="<?= h($company['company_logo'] ?? '') ?>" <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="JawishLogo.png">
      </label>

      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">العنوان</span>
        <textarea name="company_address" rows="3" <?= $canManageCompany ? '' : 'disabled' ?> class="w-full rounded-xl border border-border-subtle px-4 py-3 focus:border-primary focus:ring-primary"><?= h($company['company_address'] ?? '') ?></textarea>
      </label>

      <div class="md:col-span-2 flex justify-end">
        <button <?= $canManageCompany ? '' : 'disabled' ?> class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition disabled:opacity-50 disabled:cursor-not-allowed">
          حفظ هوية الشركة
        </button>
      </div>
    </form>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h2 class="text-base font-extrabold text-slate-900 mb-3">اتصال API</h2>
    <div class="space-y-3 text-sm">
      <div class="rounded-xl border border-border-subtle p-3 bg-surface-low">
        <p class="text-text-muted text-xs mb-1">الرابط الحالي</p>
        <p class="font-semibold break-all"><?= h($apiConfig['base_url']) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3 bg-surface-low">
        <p class="text-text-muted text-xs mb-1">حساب الخدمة</p>
        <p class="font-semibold"><?= h($apiConfig['username'] !== '' ? $apiConfig['username'] : 'غير مضبوط') ?></p>
      </div>
      <div class="rounded-xl border p-3 <?= $apiHealth['ok'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
        <p class="text-xs mb-1 <?= $apiHealth['ok'] ? 'text-green-700' : 'text-red-700' ?>">حالة الاتصال</p>
        <p class="font-bold <?= $apiHealth['ok'] ? 'text-green-700' : 'text-red-700' ?>">
          <?= $apiHealth['ok'] ? 'متصل' : 'غير متصل' ?><?= $apiHealth['status'] > 0 ? ' (HTTP ' . (int) $apiHealth['status'] . ')' : '' ?>
        </p>
        <p class="text-xs mt-1 <?= $apiHealth['ok'] ? 'text-green-700' : 'text-red-700' ?>"><?= h($apiHealth['message']) ?></p>
      </div>
    </div>
  </article>
</section>

<section class="bg-white border border-border-subtle rounded-2xl p-5">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-base font-extrabold text-slate-900">سياسة المتجر العام (الزائر)</h2>
    <?php if (!$canManageGuestPolicy): ?>
      <span class="text-xs rounded-full px-3 py-1 bg-amber-100 text-amber-700">قراءة فقط</span>
    <?php endif; ?>
  </div>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end mb-5">
    <input type="hidden" name="action" value="save_guest_policy">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">السياسة الافتراضية للزائر</span>
      <select name="access_policy_id" <?= $canManageGuestPolicy ? '' : 'disabled' ?> class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">اختر السياسة</option>
        <?php foreach ($policies as $policy): ?>
          <option value="<?= h((string) ($policy['id'] ?? '')) ?>" <?= $guestPolicyId === (string) ($policy['id'] ?? '') ? 'selected' : '' ?>>
            <?= h((string) ($policy['name_ar'] ?? '')) ?> (<?= h((string) ($policy['code'] ?? '')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="flex md:justify-end">
      <button <?= $canManageGuestPolicy ? '' : 'disabled' ?> class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition disabled:opacity-50 disabled:cursor-not-allowed">
        حفظ السياسة
      </button>
    </div>
  </form>

  <div class="overflow-auto">
    <table class="w-full min-w-[860px] text-sm">
      <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
        <tr>
          <th class="px-4 py-3 text-right font-bold">السياسة</th>
          <th class="px-4 py-3 text-right font-bold">إظهار السعر</th>
          <th class="px-4 py-3 text-right font-bold">إظهار الكمية</th>
          <th class="px-4 py-3 text-right font-bold">السلة</th>
          <th class="px-4 py-3 text-right font-bold">الطلب</th>
          <th class="px-4 py-3 text-right font-bold">الحالة</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php foreach ($policies as $policy): ?>
          <?php $isDefault = $guestPolicyId === (string) ($policy['id'] ?? ''); ?>
          <tr class="<?= $isDefault ? 'bg-primary/5' : '' ?>">
            <td class="px-4 py-3">
              <div class="font-bold text-slate-900"><?= h((string) ($policy['name_ar'] ?? '')) ?></div>
              <div class="text-xs text-text-muted"><?= h((string) ($policy['code'] ?? '')) ?></div>
            </td>
            <td class="px-4 py-3"><?= (int) ($policy['show_price'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-4 py-3"><?= (int) ($policy['show_quantity'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-4 py-3"><?= (int) ($policy['allow_cart'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-4 py-3"><?= (int) ($policy['allow_order'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            <td class="px-4 py-3">
              <?php if ($isDefault): ?>
                <span class="inline-flex rounded-full bg-green-100 text-green-700 text-xs font-bold px-3 py-1">الافتراضية الحالية</span>
              <?php else: ?>
                <span class="inline-flex rounded-full bg-slate-100 text-slate-700 text-xs font-bold px-3 py-1">متاحة</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
