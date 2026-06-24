<?php

declare(strict_types=1);

/**
 * تشخيص سريع بعد النشر على IIS.
 * Usage: php scripts/diagnose-portal.php [admin_user_name]
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Config;
use Portal\Database;
use Portal\Services\ApiClient;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\PortalSettingsService;

$adminUser = $argv[1] ?? 'admin';

$report = [
    'portal_root' => $base,
    'env_file' => is_file($base . '/.env') ? $base . '/.env' : null,
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'extensions' => [
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'curl' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            'gd' => extension_loaded('gd'),
        ],
    ],
    'config' => [
        'PORTAL_DB_NAME' => Config::get('PORTAL_DB_NAME'),
        'PORTAL_DB_USER' => Config::get('PORTAL_DB_USER'),
        'AMINE_API_BASE_URL' => Config::get('AMINE_API_BASE_URL'),
        'AMINE_API_USERNAME' => Config::get('AMINE_API_USERNAME'),
        'AMINE_API_PASSWORD_set' => trim((string) Config::get('AMINE_API_PASSWORD', '')) !== '',
        'PORTAL_APP_URL' => Config::get('PORTAL_APP_URL'),
        'PORTAL_REPO_DOCS_PATH' => Config::get('PORTAL_REPO_DOCS_PATH'),
    ],
    'database' => ['ok' => false, 'error' => null],
    'tables' => [],
    'admin_user' => null,
    'storage' => [],
    'api' => ['health' => null, 'login' => null],
    'docs' => [
        'schema' => is_file($base . '/docs/portal-db-schema.sql'),
        'migrations_dir' => is_dir($base . '/docs/portal-migrations'),
    ],
    'fixes' => [],
];

try {
    $pdo = Database::pdo();
    $pdo->query('SELECT 1');
    $report['database']['ok'] = true;

    foreach (['web_users', 'web_roles', 'web_permissions', 'company_settings', 'home_sections', 'material_image_sync_queue'] as $table) {
        $exists = $pdo->query(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = " . $pdo->quote($table)
        )->fetchColumn();
        $report['tables'][$table] = (bool) $exists;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.user_name, u.is_active FROM web_users u WHERE u.user_name = :name LIMIT 1'
    );
    $stmt->execute(['name' => $adminUser]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $roleStmt = $pdo->prepare(
            'SELECT r.code FROM web_roles r
             INNER JOIN web_user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :id'
        );
        $roleStmt->execute(['id' => $row['id']]);
        $roleList = array_values(array_filter($roleStmt->fetchAll(PDO::FETCH_COLUMN)));
        $permStmt = $pdo->prepare(
            'SELECT DISTINCT p.code
             FROM web_permissions p
             INNER JOIN web_role_permissions rp ON rp.permission_id = p.id
             INNER JOIN web_user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id'
        );
        $permStmt->execute(['id' => $row['id']]);
        $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('super_admin', $roleList, true)) {
            $permissions = ['*'];
        }
        $report['admin_user'] = [
            'user_name' => $row['user_name'],
            'is_active' => (bool) $row['is_active'],
            'roles' => $roleList,
            'permissions_count' => count($permissions),
            'has_super_admin' => in_array('super_admin', $roleList, true),
            'sample_permissions' => array_slice($permissions, 0, 12),
        ];
        if (!$report['admin_user']['has_super_admin']) {
            $report['fixes'][] = "Grant super_admin to {$adminUser}: php scripts/create-admin.php {$adminUser} \"PASSWORD\" \"مدير النظام\"";
            $report['fixes'][] = 'Or assign roles in /dashboard/users.php after fixing permissions';
        }
    } else {
        $report['admin_user'] = ['error' => "User not found: {$adminUser}"];
        $report['fixes'][] = "Create admin: php scripts/create-admin.php {$adminUser} \"PASSWORD\" \"مدير النظام\"";
    }
} catch (Throwable $e) {
    $report['database']['error'] = $e->getMessage();
}

$paths = MaterialImageStorageService::settings();
$storageRoot = Config::storagePath();
foreach ([
    'storage_root' => $storageRoot,
    'material_images' => $paths['images_dir'],
    'thumbnails' => $paths['thumbnails_dir'],
    'site_media' => $storageRoot . DIRECTORY_SEPARATOR . 'site-media',
] as $key => $path) {
    $report['storage'][$key] = [
        'path' => $path,
        'exists' => is_dir($path),
        'writable' => is_dir($path) && is_writable($path),
    ];
    if (is_dir($path) && !is_writable($path)) {
        $report['fixes'][] = "Grant IIS AppPool write access: icacls \"{$path}\" /grant \"IIS AppPool\\JawishPortal:(OI)(CI)M\" /T";
    }
}

try {
    $health = PortalSettingsService::apiHealth();
    $report['api']['health'] = $health;
} catch (Throwable $e) {
    $report['api']['health'] = ['ok' => false, 'error' => $e->getMessage()];
}

try {
    $materials = ApiClient::get('/api/materials', ['page' => 1, 'pageSize' => 1]);
    $report['api']['login'] = [
        'ok' => (bool) ($materials['ok'] ?? false),
        'message' => ($materials['ok'] ?? false) ? 'Service login + materials.read OK' : ($materials['error'] ?? 'materials request failed'),
    ];
} catch (Throwable $e) {
    $report['api']['login'] = ['ok' => false, 'error' => $e->getMessage()];
    $report['fixes'][] = 'Health alone is not enough. Fix AMINE_API_USERNAME/PASSWORD — use ApiManagementDb user (e.g. portal-service) with materials.read';
    $report['fixes'][] = 'Test: php scripts/test-amine-api-login.php';
    $report['fixes'][] = 'Amine API admin page also needs admin.permissions.read, admin.roles.manage, admin.users.manage on that API user';
}

if (!$report['docs']['schema']) {
    $report['fixes'][] = 'Copy docs: xcopy /E /I /Y C:\\JawishDeploy\\docs D:\\JawishPortal\\docs';
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(($report['database']['ok'] ?? false) && ($report['api']['login']['ok'] ?? false) ? 0 : 1);
