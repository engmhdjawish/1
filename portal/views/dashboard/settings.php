<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var list<array<string, mixed>> $policies */
/** @var string|null $guestPolicyId */
/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var array{base_url: string, username: string} $apiConfig */
/** @var bool $canManageCompany */
/** @var bool $canManageGuestPolicy */
/** @var bool $canManageAccessPolicies */
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
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
    <div>
      <h2 class="text-base font-extrabold text-slate-900">سياسة المتجر العام (الزائر)</h2>
      <p class="text-sm text-text-muted mt-1">لإضافة وتعديل وحذف السياسات استخدم صفحة سياسات الوصول.</p>
    </div>
    <?php if ($canManageAccessPolicies): ?>
      <a href="/dashboard/access-policies.php" class="inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
        <span class="material-symbols-outlined text-base">policy</span>
        إدارة سياسات الوصول
      </a>
    <?php endif; ?>
  </div>

  <?php if (!$canManageGuestPolicy): ?>
    <span class="inline-flex text-xs rounded-full px-3 py-1 bg-amber-100 text-amber-700 mb-3">قراءة فقط</span>
  <?php endif; ?>

  <form method="post" action="/dashboard/access-policies.php" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
    <input type="hidden" name="action" value="save_guest_policy">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">السياسة الافتراضية للزائر (من القائمة النشطة)</span>
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
      <button <?= $canManageGuestPolicy ? '' : 'disabled' ?> class="h-11 px-6 rounded-xl border border-primary text-primary font-bold hover:bg-primary/5 transition disabled:opacity-50 disabled:cursor-not-allowed">
        حفظ سياسة الزائر
      </button>
    </div>
  </form>
</section>
