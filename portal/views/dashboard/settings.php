<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var list<array<string, mixed>> $policies */
/** @var string|null $guestPolicyId */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array{ok: bool, message: string} $dbHealth */
/** @var array<string, string> $integration */
/** @var bool $canManageCompany */
/** @var bool $canManageGuestPolicy */
/** @var bool $canManagePolicies */
/** @var bool $canManageIntegration */
/** @var string $tab */
/** @var bool $policyShowForm */
/** @var string $policyEditId */
/** @var bool $policyIsNew */
/** @var array<string, mixed>|null $editPolicy */
/** @var array<string, array{guest_default: bool, share_links: int, customers: int}> $policyUsage */
/** @var string|null $flash */
/** @var string $flashType */

require __DIR__ . '/partials/media-picker.php';

$tab = $tab ?? 'company';
$policyShowForm = $policyShowForm ?? false;
$editPolicy = is_array($editPolicy ?? null) ? $editPolicy : [];

$tabs = [];
if ($canManageCompany) {
    $tabs['company'] = 'الشركة ومن نحن';
}
if ($canManageIntegration) {
    $tabs['integration'] = 'الاتصال';
}
if ($canManageGuestPolicy || $canManagePolicies) {
    $tabs['policies'] = 'السياسات';
}
if (!isset($tabs[$tab])) {
    $tab = (string) array_key_first($tabs);
}

$tabUrl = static function (string $key) use ($tab): string {
    return '/dashboard/settings.php?tab=' . rawurlencode($key);
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-3 mb-4">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات</h1>
    <p class="text-sm text-text-muted mt-1">هوية الشركة، صفحة من نحن، اتصال API وقاعدة البيانات، وسياسات الوصول.</p>
  </div>
  <?php if ($canManageCompany): ?>
    <a href="/about.php" target="_blank" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">معاينة من نحن</a>
  <?php endif; ?>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<nav class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= h($tabUrl($key)) ?>" class="h-9 px-4 inline-flex items-center rounded-lg text-xs font-bold <?= $tab === $key ? 'bg-primary text-white' : 'border border-border-subtle bg-white text-slate-700 hover:bg-slate-50' ?>">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'company' && $canManageCompany): ?>
<form method="post" id="settings-company-form" class="space-y-3">
  <input type="hidden" name="action" value="save_company">

  <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base">الشركة وصفحة من نحن</h2>
    <button type="submit" class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ</button>
  </div>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <h3 class="font-bold text-sm mb-2">هوية الشركة</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">اسم الشركة *</span>
        <input name="company_name" required value="<?= h($company['company_name'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">الهاتف الثابت</span>
        <input name="company_phone" value="<?= h($company['company_phone'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">الموبايل</span>
        <input name="company_mobile" value="<?= h($company['company_mobile'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">واتساب</span>
        <input name="company_whatsapp" value="<?= h($company['company_whatsapp'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs md:col-span-2">
        <span class="text-text-muted block mb-0.5">البريد الإلكتروني</span>
        <input type="email" name="company_email" value="<?= h($company['company_email'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="info@example.com">
      </label>
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">العنوان</span>
        <textarea name="company_address" rows="2" class="w-full rounded-lg border border-border-subtle px-3 py-2 text-sm"><?= h($company['company_address'] ?? '') ?></textarea>
      </label>
      <div class="md:col-span-3">
        <?php $renderMediaPickerField('شعار الشركة', 'company_logo', (string) ($company['company_logo'] ?? ''), 'settings-company-logo', 'logo'); ?>
      </div>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <h3 class="font-bold text-sm mb-2">محتوى صفحة «من نحن»</h3>
    <div class="grid grid-cols-1 gap-2">
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">عنوان الصفحة</span>
        <input name="about_us_title_ar" value="<?= h($company['about_us_title_ar'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="من نحن">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">النص التعريفي</span>
        <textarea name="about_us_ar" rows="8" class="w-full rounded-lg border border-border-subtle px-3 py-2 text-sm" placeholder="اكتب نبذة عن الشركة، رؤيتها، وخدماتها..."><?= h($company['about_us_ar'] ?? '') ?></textarea>
      </label>
      <p class="text-[11px] text-text-muted">يظهر هذا المحتوى في صفحة <a href="/about.php" target="_blank" class="text-primary font-bold hover:underline">/about.php</a> مع بيانات التواصل والشعار.</p>
    </div>
  </article>
</form>
<?php endif; ?>

<?php if ($tab === 'integration' && $canManageIntegration): ?>
<form method="post" id="settings-integration-form" class="space-y-3">
  <input type="hidden" name="action" value="save_integration">

  <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base">اتصال API وقاعدة البيانات</h2>
    <button type="submit" class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ الاتصال</button>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
    <article class="bg-white border border-border-subtle rounded-xl p-3 space-y-2">
      <div class="flex items-center justify-between gap-2">
        <h3 class="font-bold text-sm">Amine API</h3>
        <span class="text-[11px] font-bold px-2 py-1 rounded-full <?= $apiHealth['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
          <?= $apiHealth['ok'] ? 'متصل' : 'غير متصل' ?>
        </span>
      </div>
      <p class="text-xs text-text-muted"><?= h($apiHealth['message']) ?></p>
      <label class="text-xs block">
        <span class="text-text-muted block mb-0.5">رابط API *</span>
        <input name="amine_api_base_url" required value="<?= h($integration['AMINE_API_BASE_URL'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
      </label>
      <label class="text-xs block">
        <span class="text-text-muted block mb-0.5">اسم مستخدم الخدمة *</span>
        <input name="amine_api_username" required value="<?= h($integration['AMINE_API_USERNAME'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
      </label>
      <label class="text-xs block">
        <span class="text-text-muted block mb-0.5">كلمة مرور الخدمة</span>
        <input type="password" name="amine_api_password" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="اتركه فارغًا للإبقاء على القيمة الحالية" autocomplete="new-password">
      </label>
    </article>

    <article class="bg-white border border-border-subtle rounded-xl p-3 space-y-2">
      <div class="flex items-center justify-between gap-2">
        <h3 class="font-bold text-sm">قاعدة بيانات الموقع (PostgreSQL)</h3>
        <span class="text-[11px] font-bold px-2 py-1 rounded-full <?= $dbHealth['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
          <?= $dbHealth['ok'] ? 'متصل' : 'غير متصل' ?>
        </span>
      </div>
      <p class="text-xs text-text-muted"><?= h($dbHealth['message']) ?></p>
      <div class="grid grid-cols-2 gap-2">
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">المضيف *</span>
          <input name="portal_db_host" required value="<?= h($integration['PORTAL_DB_HOST'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">المنفذ *</span>
          <input name="portal_db_port" required value="<?= h($integration['PORTAL_DB_PORT'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">اسم القاعدة *</span>
          <input name="portal_db_name" required value="<?= h($integration['PORTAL_DB_NAME'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">المستخدم *</span>
          <input name="portal_db_user" required value="<?= h($integration['PORTAL_DB_USER'] ?? '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr">
        </label>
      </div>
      <label class="text-xs block">
        <span class="text-text-muted block mb-0.5">كلمة مرور القاعدة</span>
        <input type="password" name="portal_db_password" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="اتركه فارغًا للإبقاء على القيمة الحالية" autocomplete="new-password">
      </label>
    </article>
  </div>

  <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
    تُحفظ هذه القيم في ملف <code dir="ltr">portal/.env</code> على الخادم. بعد تغيير قاعدة البيانات قد تحتاج لإعادة تحميل الصفحة للتأكد من الاتصال الجديد.
  </p>
</form>
<?php endif; ?>

<?php if ($tab === 'policies' && ($canManageGuestPolicy || $canManagePolicies)): ?>

<?php if ($canManageGuestPolicy): ?>
<article class="bg-white border border-border-subtle rounded-xl p-3 mb-3">
  <form method="post" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-[1fr_1fr_auto] gap-2 items-end">
    <input type="hidden" name="action" value="save_guest_policy">
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">السياسة الافتراضية للزائر (المتجر العام)</span>
      <select name="access_policy_id" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
        <option value="">اختر السياسة</option>
        <?php foreach ($policies as $policy): ?>
          <?php if ((int) ($policy['is_active'] ?? 0) !== 1) continue; ?>
          <option value="<?= h((string) ($policy['id'] ?? '')) ?>" <?= $guestPolicyId === (string) ($policy['id'] ?? '') ? 'selected' : '' ?>>
            <?= h((string) ($policy['name_ar'] ?? '')) ?> (<?= h((string) ($policy['code'] ?? '')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">الحد الأقصى للطرد لكل مادة (المتجر)</span>
      <input
        type="number"
        name="max_packages_per_material"
        min="1"
        step="1"
        class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm"
        value="<?= $maxPackagesPerMaterial !== null ? h((string) $maxPackagesPerMaterial) : '' ?>"
        placeholder="بدون حد"
      >
    </label>
    <button class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ إعدادات المتجر</button>
  </form>
  <p class="text-[11px] text-text-muted mt-2">يُطبَّق على الطلبات في المتجر والسلة. اترك الحقل فارغًا لإلغاء الحد.</p>
</article>
<?php endif; ?>

<?php if ($canManagePolicies): ?>
<?php if ($policyShowForm): ?>
  <?php if ($policyEditId !== ''): ?>
    <form method="post" id="policy-delete-form" class="hidden" onsubmit="return confirm('حذف هذه السياسة؟')">
      <input type="hidden" name="action" value="delete_policy">
      <input type="hidden" name="id" value="<?= h($policyEditId) ?>">
    </form>
  <?php endif; ?>

  <form method="post" id="policy-form" class="space-y-3 mb-3">
    <input type="hidden" name="action" value="save_policy">
    <input type="hidden" name="id" value="<?= h((string) ($editPolicy['id'] ?? '')) ?>">

    <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-bold text-base"><?= $policyEditId !== '' ? 'تعديل السياسة' : 'سياسة جديدة' ?></h2>
      <div class="flex flex-wrap items-center gap-2">
        <a href="/dashboard/settings.php?tab=policies" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">إلغاء</a>
        <?php if ($policyEditId !== ''): ?>
          <button type="submit" form="policy-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
        <?php endif; ?>
        <button type="submit" id="policy-save-btn" class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">
          <?= $policyEditId !== '' ? 'حفظ' : 'إنشاء' ?>
        </button>
      </div>
    </div>

    <article class="bg-white border border-border-subtle rounded-xl p-3">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">الاسم بالعربية *</span>
          <input name="name_ar" required value="<?= h((string) ($editPolicy['name_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">الرمز (إنجليزي) *</span>
          <input name="code" required value="<?= h((string) ($editPolicy['code'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="custom_policy">
        </label>
        <label class="text-xs md:col-span-2">
          <span class="text-text-muted block mb-0.5">وصف مختصر</span>
          <input name="description_ar" value="<?= h((string) ($editPolicy['description_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
        </label>
        <div class="md:col-span-2 flex flex-wrap gap-4 text-xs pt-1">
          <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="show_price" <?= !empty($editPolicy['show_price']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary"> إظهار السعر</label>
          <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="show_quantity" <?= !empty($editPolicy['show_quantity']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary"> إظهار الكمية</label>
          <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="allow_cart" <?= !array_key_exists('allow_cart', $editPolicy) || !empty($editPolicy['allow_cart']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary"> السلة</label>
          <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="allow_order" <?= !array_key_exists('allow_order', $editPolicy) || !empty($editPolicy['allow_order']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary"> الطلب</label>
          <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="is_active" <?= $policyIsNew || !empty($editPolicy['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary"> نشطة</label>
        </div>
      </div>
    </article>
  </form>
  <script>
  (() => {
    const form = document.getElementById('policy-form');
    const saveBtn = document.getElementById('policy-save-btn');
    let explicitSave = false;
    saveBtn?.addEventListener('click', () => { explicitSave = true; });
    form?.addEventListener('submit', (event) => {
      if (!explicitSave) { event.preventDefault(); return false; }
      explicitSave = false;
    });
    form?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      const target = event.target;
      if (!target || target.tagName === 'TEXTAREA') return;
      event.preventDefault();
    }, true);
  })();
  </script>
<?php else: ?>
  <div class="mb-3 flex justify-end">
    <a href="/dashboard/settings.php?tab=policies&policy_new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">سياسة جديدة</a>
  </div>
<?php endif; ?>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($policies === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد سياسات.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">السياسة</th>
            <th class="px-4 py-3 text-right font-bold">السعر</th>
            <th class="px-4 py-3 text-right font-bold">الكمية</th>
            <th class="px-4 py-3 text-right font-bold">السلة</th>
            <th class="px-4 py-3 text-right font-bold">الطلب</th>
            <th class="px-4 py-3 text-right font-bold">الاستخدام</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <?php if ($canManagePolicies): ?>
              <th class="px-4 py-3 text-left font-bold">إجراءات</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($policies as $policy): ?>
            <?php
              $pid = (string) ($policy['id'] ?? '');
              $usage = $policyUsage[$pid] ?? ['guest_default' => false, 'share_links' => 0, 'customers' => 0];
              $isDefault = $guestPolicyId === $pid;
              $usageTotal = (int) $usage['share_links'] + (int) $usage['customers'];
            ?>
            <tr class="hover:bg-slate-50 <?= $isDefault ? 'bg-primary/5' : '' ?>">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($policy['name_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted font-mono" dir="ltr"><?= h((string) ($policy['code'] ?? '')) ?></div>
                <?php if (trim((string) ($policy['description_ar'] ?? '')) !== ''): ?>
                  <div class="text-[11px] text-text-muted mt-0.5"><?= h((string) $policy['description_ar']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs"><?= (int) ($policy['show_price'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
              <td class="px-4 py-3 text-xs"><?= (int) ($policy['show_quantity'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
              <td class="px-4 py-3 text-xs"><?= (int) ($policy['allow_cart'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
              <td class="px-4 py-3 text-xs"><?= (int) ($policy['allow_order'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
              <td class="px-4 py-3 text-xs text-text-muted">
                <?php if ($isDefault): ?><div class="text-primary font-bold">افتراضية زائر</div><?php endif; ?>
                <?php if ($usageTotal > 0): ?><div><?= $usageTotal ?> مرتبط</div><?php else: ?>—<?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?= (int) ($policy['is_active'] ?? 0) === 1 ? '<span class="text-emerald-700 font-bold text-xs">نشطة</span>' : '<span class="text-xs text-slate-500">متوقفة</span>' ?>
              </td>
              <?php if ($canManagePolicies): ?>
                <td class="px-4 py-3">
                  <div class="flex justify-end gap-1.5 flex-wrap">
                    <a href="/dashboard/settings.php?tab=policies&policy_edit=<?= urlencode($pid) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                    <form method="post">
                      <input type="hidden" name="action" value="toggle_policy">
                      <input type="hidden" name="id" value="<?= h($pid) ?>">
                      <input type="hidden" name="next_active" value="<?= (int) ($policy['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                      <?php if ((int) ($policy['is_active'] ?? 0) === 1): ?>
                        <button class="h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                      <?php else: ?>
                        <button class="h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                      <?php endif; ?>
                    </form>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
