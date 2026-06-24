<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string|null $message */
?>
<div class="max-w-md mx-auto">
  <section class="bg-white rounded-2xl shadow-sm border overflow-hidden">
    <header class="px-6 py-5 border-b bg-gray-50">
      <h1 class="text-2xl font-extrabold">تسجيل عميل جديد</h1>
      <p class="text-sm text-gray-600 mt-1">بعد التسجيل يتم مراجعة طلبك وتفعيل الحساب من الإدارة.</p>
    </header>
    <div class="p-6">
  <?php if ($error): ?><p class="mb-3 text-sm text-red-600"><?= h($error) ?></p><?php endif; ?>
  <?php if ($message): ?><p class="mb-3 text-sm text-green-700"><?= h($message) ?></p><?php endif; ?>
  <form method="post" class="space-y-3">
    <label class="block text-sm">الاسم<input name="name" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <label class="block text-sm">الهاتف<input name="phone" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <label class="block text-sm">البريد (اختياري)<input name="email" type="email" class="w-full border rounded px-3 py-2 mt-1"></label>
    <label class="block text-sm">كلمة المرور<input type="password" name="password" class="w-full border rounded px-3 py-2 mt-1" required minlength="6"></label>
    <button type="submit" class="w-full bg-primary text-white rounded-lg py-2.5 font-semibold">إرسال الطلب</button>
  </form>
  <p class="text-sm text-gray-600 mt-5 text-center">
    لديك حساب مفعّل؟ <a href="<?= h(portal_login_url('customer')) ?>" class="text-primary font-bold hover:underline">تسجيل الدخول</a>
  </p>
    </div>
  </section>
</div>
