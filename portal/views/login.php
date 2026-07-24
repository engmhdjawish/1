<?php

declare(strict_types=1);

/** @var string $type */
/** @var string|null $error */
/** @var string|null $message */
/** @var string|null $redirect */
/** @var string $loginPagePath */

use Portal\Support\PortalUrl;

$redirectQuery = ($redirect ?? null) !== null && ($redirect ?? '') !== ''
    ? '?redirect=' . rawurlencode((string) $redirect)
    : '';
$staffRedirectQuery = ($redirect ?? null) !== null && ($redirect ?? '') !== '' && PortalUrl::isDashboardPath((string) $redirect)
    ? '?redirect=' . rawurlencode((string) $redirect)
    : '';
$staffLoginUrl = PortalUrl::loginPagePath('staff') . $staffRedirectQuery;
$customerLoginUrl = PortalUrl::loginPagePath('customer') . $redirectQuery;
$inputClass = 'w-full border border-gray-300 rounded-lg px-3 py-2 mt-1 text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary';
$labelClass = 'block text-sm font-medium text-gray-700';
?>
<div class="max-w-xl mx-auto">
  <section class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
    <header class="px-6 py-5 border-b bg-gray-50">
      <h1 class="text-2xl font-extrabold text-gray-900">تسجيل الدخول</h1>
      <p class="text-sm text-gray-600 mt-1">اختر نوع الحساب ثم أدخل بيانات الدخول. لا يمكن البقاء مسجّل دخول كموظف وعميل في آن واحد.</p>
    </header>

    <div class="p-6">
      <?php if ($error): ?>
        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
      <?php endif; ?>
      <?php if ($message): ?>
        <p class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-700 px-3 py-2 text-sm"><?= h($message) ?></p>
      <?php endif; ?>

      <div class="grid grid-cols-2 gap-2 mb-5 text-sm">
        <a
          href="<?= h($staffLoginUrl) ?>"
          class="inline-flex items-center justify-center rounded-lg px-3 py-2 font-semibold transition <?= $type === 'staff' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
        >
          موظف
        </a>
        <a
          href="<?= h($customerLoginUrl) ?>"
          class="inline-flex items-center justify-center rounded-lg px-3 py-2 font-semibold transition <?= $type === 'customer' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
        >
          عميل
        </a>
      </div>

      <?php if ($type === 'customer'): ?>
        <form method="post" action="<?= h($loginPagePath) ?>" class="space-y-4" id="login-form-customer" autocomplete="off" data-login-kind="customer">
          <input type="hidden" name="type" value="customer">
          <?php if (!empty($redirect)): ?>
            <input type="hidden" name="redirect" value="<?= h((string) $redirect) ?>">
          <?php endif; ?>
          <div data-customer-login-mount></div>
          <p class="text-sm text-gray-500" data-customer-login-placeholder>جاري تجهيز نموذج الدخول...</p>
          <noscript>
            <label class="<?= h($labelClass) ?>">
              رقم الهاتف
              <input
                name="customer_phone"
                type="tel"
                inputmode="tel"
                autocomplete="section-jawish-customer username"
                dir="ltr"
                data-phone-input
                data-login-phone
                class="<?= h($inputClass) ?> text-left"
                required
                placeholder="09xxxxxxxx"
              >
            </label>
            <label class="<?= h($labelClass) ?>">
              كلمة المرور
              <input
                type="password"
                name="customer_password"
                autocomplete="section-jawish-customer current-password"
                class="<?= h($inputClass) ?>"
                required
                placeholder="••••••••"
              >
            </label>
            <button type="submit" class="w-full bg-primary text-white rounded-lg py-2.5 font-semibold hover:brightness-110 transition">
              دخول
            </button>
          </noscript>
        </form>
      <?php else: ?>
        <form method="post" action="<?= h($loginPagePath) ?>" class="space-y-4" id="login-form-staff" autocomplete="off" data-login-kind="staff">
          <input type="hidden" name="type" value="staff">
          <?php if (!empty($redirect) && PortalUrl::isDashboardPath((string) $redirect)): ?>
            <input type="hidden" name="redirect" value="<?= h((string) $redirect) ?>">
          <?php endif; ?>
          <div class="absolute -left-[9999px] top-auto h-0 w-0 overflow-hidden" aria-hidden="true">
            <input type="text" name="username" tabindex="-1" autocomplete="username">
            <input type="password" name="password" tabindex="-1" autocomplete="current-password">
          </div>
          <label class="<?= h($labelClass) ?>">
            اسم المستخدم
            <input
              id="staff_login_username"
              name="staff_user_name"
              autocomplete="section-jawish-staff username"
              autocapitalize="none"
              spellcheck="false"
              class="<?= h($inputClass) ?>"
              required
              placeholder="admin"
            >
          </label>
          <label class="<?= h($labelClass) ?>">
            كلمة المرور
            <input
              id="staff_login_password"
              type="password"
              name="staff_password"
              autocomplete="section-jawish-staff current-password"
              class="<?= h($inputClass) ?>"
              required
              placeholder="••••••••"
            >
          </label>
          <button type="submit" class="w-full bg-primary text-white rounded-lg py-2.5 font-semibold hover:brightness-110 transition">
            دخول
          </button>
        </form>
      <?php endif; ?>

      <p class="text-sm text-gray-600 mt-5 text-center">
        <?php if ($type === 'customer'): ?>
          ليس لديك حساب؟ <a href="/register.php" class="text-primary font-bold hover:underline">سجّل كعميل جديد</a>
        <?php else: ?>
          <a href="/index.php" class="text-primary font-bold hover:underline">العودة للموقع</a>
        <?php endif; ?>
      </p>
    </div>
  </section>
</div>
