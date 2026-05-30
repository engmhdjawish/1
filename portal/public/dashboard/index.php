<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebCustomerService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$pendingCount = count(WebCustomerService::listPending());
$user = WebSession::user();

ob_start();
?>
<h1 class="text-xl font-bold mb-4">مرحباً <?= h($user['display_name_ar'] ?? '') ?></h1>
<ul class="list-disc mr-6 text-gray-700 space-y-1">
  <li>طلبات عملاء بانتظار الموافقة: <strong><?= (int) $pendingCount ?></strong></li>
  <li><a href="/dashboard/customers.php" class="text-primary">إدارة عملاء الويب</a></li>
</ul>
<p class="mt-6 text-sm text-gray-500">فعّل أقسام الرئيسية من قاعدة البيانات (home_sections.is_active) بعد ضبط API.</p>
<?php
$content = ob_get_clean();
$title = 'لوحة التحكم';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
