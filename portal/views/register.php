<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string|null $message */
/** @var array{name: string, phone: string, shop_address: string} $old */

$old = is_array($old ?? null) ? $old : ['name' => '', 'phone' => '', 'shop_address' => ''];
$fieldClass = 'w-full h-11 rounded-xl border border-border-subtle px-3 mt-1 text-sm focus:border-primary focus:ring-primary';
?>
<div class="max-w-md mx-auto">
  <section class="bg-white rounded-2xl shadow-sm border overflow-hidden">
    <header class="px-6 py-5 border-b bg-gray-50">
      <h1 class="text-2xl font-extrabold">حساب جملة جديد</h1>
      <p class="text-sm text-gray-600 mt-1">بعد التسجيل تدخل مباشرة بصلاحيات الزائر حتى موافقة الإدارة، ويصلك إشعار عند تفعيل حسابك وظهور أسعارك.</p>
    </header>
    <div class="p-6">
      <?php if ($error): ?><p class="mb-3 rounded-lg bg-red-50 border border-red-100 px-3 py-2 text-sm text-red-700"><?= h($error) ?></p><?php endif; ?>
      <?php if ($message): ?><p class="mb-3 rounded-lg bg-emerald-50 border border-emerald-100 px-3 py-2 text-sm text-emerald-800"><?= h($message) ?></p><?php endif; ?>
      <form method="post" class="space-y-4">
        <label class="block text-sm font-bold">اسم المحل أو صاحبه
          <input name="name" value="<?= h($old['name']) ?>" class="<?= $fieldClass ?>" required maxlength="250" autocomplete="organization">
        </label>
        <label class="block text-sm font-bold">رقم الهاتف
          <input name="phone" value="<?= h($old['phone']) ?>" <?= portal_phone_input_attributes() ?> class="<?= $fieldClass ?> text-left" required placeholder="09xxxxxxxx">
        </label>
        <label class="block text-sm font-bold">عنوان المحل
          <textarea name="shop_address" rows="2" class="<?= $fieldClass ?> h-auto py-2" required maxlength="300" autocomplete="street-address" placeholder="المدينة — المنطقة — الشارع أو أقرب معلم"><?= h($old['shop_address']) ?></textarea>
          <span class="block mt-1 text-xs font-normal text-gray-500">يساعدنا في توصيل الطلبات والتعرّف على محلّك.</span>
        </label>
        <label class="block text-sm font-bold">كلمة المرور
          <input type="password" name="password" class="<?= $fieldClass ?>" required minlength="6" autocomplete="new-password">
        </label>
        <button type="submit" class="w-full h-11 bg-primary text-white rounded-xl font-extrabold">إرسال طلب الحساب</button>
      </form>
      <p class="text-sm text-gray-600 mt-5 text-center">
        لديك حساب مفعّل؟ <a href="<?= h(portal_login_url('customer')) ?>" class="text-primary font-bold hover:underline">تسجيل الدخول</a>
      </p>
    </div>
  </section>
</div>
