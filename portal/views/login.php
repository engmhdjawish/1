<?php

declare(strict_types=1);

/** @var string $type */
/** @var string|null $error */
/** @var string|null $message */
?>
<div class="max-w-xl mx-auto">
  <section class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
    <header class="px-6 py-5 border-b bg-gray-50">
      <h1 class="text-2xl font-extrabold text-gray-900">تسجيل الدخول</h1>
      <p class="text-sm text-gray-600 mt-1">اختر نوع الحساب ثم أدخل بيانات الدخول.</p>
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
          href="?type=staff"
          class="inline-flex items-center justify-center rounded-lg px-3 py-2 font-semibold transition <?= $type === 'staff' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
        >
          موظف
        </a>
        <a
          href="?type=customer"
          class="inline-flex items-center justify-center rounded-lg px-3 py-2 font-semibold transition <?= $type === 'customer' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
        >
          عميل
        </a>
      </div>

      <form method="post" class="space-y-4">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <?php if ($type === 'customer'): ?>
          <label class="block text-sm font-medium text-gray-700">
            رقم الهاتف
            <input name="phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 mt-1 text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary" required placeholder="09xxxxxxxx">
          </label>
        <?php else: ?>
          <label class="block text-sm font-medium text-gray-700">
            اسم المستخدم
            <input name="user_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 mt-1 text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary" required placeholder="admin">
          </label>
        <?php endif; ?>
        <label class="block text-sm font-medium text-gray-700">
          كلمة المرور
          <input type="password" name="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 mt-1 text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary" required placeholder="••••••••">
        </label>
        <button type="submit" class="w-full bg-primary text-white rounded-lg py-2.5 font-semibold hover:brightness-110 transition">
          دخول
        </button>
      </form>
    </div>
  </section>
</div>
