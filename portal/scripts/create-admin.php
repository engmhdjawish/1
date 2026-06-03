<?php

declare(strict_types=1);

/**
 * Usage: php scripts/create-admin.php admin mypassword "مدير النظام"
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';
// bootstrap loads autoload

use Portal\Auth\Password;
use Portal\Database;

if ($argc < 4) {
    fwrite(STDERR, "الاستخدام: php scripts/create-admin.php <user_name> <password> <display_name_ar>\n");
    exit(1);
}

[, $userName, $plainPassword, $displayName] = $argv;

$pdo = Database::pdo();
$hash = Password::hash($plainPassword);

$pdo->prepare(
    'INSERT INTO web_users (user_name, display_name_ar, password_hash, is_active)
     VALUES (:u, :d, :h, TRUE)
     ON CONFLICT (user_name) DO UPDATE SET password_hash = EXCLUDED.password_hash, display_name_ar = EXCLUDED.display_name_ar'
)->execute([
    'u' => $userName,
    'd' => $displayName,
    'h' => $hash,
]);

$roleId = $pdo->query("SELECT id FROM web_roles WHERE code = 'super_admin' LIMIT 1")->fetchColumn();
$userId = $pdo->prepare('SELECT id FROM web_users WHERE user_name = :u');
$userId->execute(['u' => $userName]);
$uid = $userId->fetchColumn();

if ($roleId && $uid) {
    $pdo->prepare(
        'INSERT INTO web_user_roles (user_id, role_id) VALUES (:uid, :rid) ON CONFLICT DO NOTHING'
    )->execute(['uid' => $uid, 'rid' => $roleId]);
}

echo "تم إنشاء/تحديث المستخدم: $userName\n";
