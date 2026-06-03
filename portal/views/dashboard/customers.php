<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $pending */
/** @var list<array<string, mixed>> $policies */
/** @var string|null $flash */
?>
<h1 class="text-xl font-bold mb-4">عملاء الويب — بانتظار الموافقة</h1>
<?php if ($flash): ?><p class="mb-4 text-sm text-green-700"><?= h($flash) ?></p><?php endif; ?>

<?php if ($pending === []): ?>
  <p class="text-gray-600">لا يوجد طلبات تسجيل معلّقة.</p>
<?php else: ?>
  <div class="space-y-4">
    <?php foreach ($pending as $row): ?>
      <div class="bg-white border rounded-lg p-4">
        <div class="font-semibold"><?= h($row['name_ar']) ?></div>
        <div class="text-sm text-gray-600"><?= h($row['phone']) ?> — <?= h($row['registration_source']) ?></div>
        <div class="text-xs text-gray-400"><?= h($row['created_at']) ?></div>
        <form method="post" class="mt-3 flex flex-wrap gap-2 items-end">
          <input type="hidden" name="customer_id" value="<?= h($row['id']) ?>">
          <label class="text-sm">سياسة الوصول
            <select name="access_policy_id" class="border rounded px-2 py-1 mr-2" required>
              <?php foreach ($policies as $policy): ?>
                <option value="<?= h($policy['id']) ?>"><?= h($policy['name_ar']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button name="action" value="approve" class="bg-primary text-white px-4 py-1 rounded">موافقة وتفعيل</button>
          <button name="action" value="reject" class="bg-gray-200 px-4 py-1 rounded">رفض</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
