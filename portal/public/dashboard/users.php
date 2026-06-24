<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebUserService;
use Portal\Support\DashboardHttp;
use Portal\Support\StaffPermissions;

WebSession::requirePermission('web_users.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$user = WebSession::user();
$currentUserId = isset($user['id']) ? (string) $user['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_user') {
        $postedRoleIds = $_POST['role_ids'] ?? [];
        $roleIds = is_array($postedRoleIds)
            ? array_map('strval', $postedRoleIds)
            : [trim((string) $postedRoleIds)];
        $result = WebUserService::saveUser(
            trim((string) ($_POST['id'] ?? '')) !== '' ? trim((string) ($_POST['id'] ?? '')) : null,
            trim((string) ($_POST['user_name'] ?? '')),
            trim((string) ($_POST['display_name_ar'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            trim((string) ($_POST['plain_password'] ?? '')),
            isset($_POST['is_active']),
            $roleIds
        );

        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $_GET['edit'] = (string) ($result['id'] ?? '');
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($result['ok'], $result['message'], ['reload' => $result['ok'], 'id' => $result['id'] ?? null]);
        }
    } elseif ($action === 'save_role') {
        $postedPermissionIds = $_POST['permission_ids'] ?? [];
        $permissionIds = is_array($postedPermissionIds)
            ? array_map('strval', $postedPermissionIds)
            : [trim((string) $postedPermissionIds)];
        $result = WebUserService::saveRole(
            trim((string) ($_POST['id'] ?? '')) !== '' ? trim((string) ($_POST['id'] ?? '')) : null,
            trim((string) ($_POST['code'] ?? '')),
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['description_ar'] ?? '')),
            $permissionIds
        );

        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $_GET['edit_role'] = (string) ($result['id'] ?? '');
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($result['ok'], $result['message'], ['reload' => $result['ok'], 'id' => $result['id'] ?? null]);
        }
    } elseif ($action === 'toggle_active') {
        $targetId = trim((string) ($_POST['id'] ?? ''));
        $next = ($_POST['next_active'] ?? '0') === '1';
        if ($currentUserId === $targetId && !$next) {
            $flash = 'لا يمكن تعطيل الحساب الحالي أثناء تسجيل الدخول.';
            $flashType = 'error';
        } else {
            $ok = WebUserService::setActive($targetId, $next);
            $flash = $ok ? 'تم تحديث حالة المستخدم.' : 'تعذر تحديث حالة المستخدم.';
            $flashType = $ok ? 'success' : 'error';
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
    } elseif ($action === 'delete_user') {
        $result = WebUserService::deleteUser(trim((string) ($_POST['id'] ?? '')), $currentUserId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok'] && trim((string) ($_GET['edit'] ?? '')) === trim((string) ($_POST['id'] ?? ''))) {
            unset($_GET['edit']);
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($result['ok'], $result['message'], ['reload' => $result['ok']]);
        }
    } elseif ($action === 'delete_role') {
        $result = WebUserService::deleteRole(trim((string) ($_POST['id'] ?? '')));
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok'] && trim((string) ($_GET['edit_role'] ?? '')) === trim((string) ($_POST['id'] ?? ''))) {
            unset($_GET['edit_role']);
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($result['ok'], $result['message'], ['reload' => $result['ok']]);
        }
    }
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'role' => trim((string) ($_GET['role'] ?? '')),
    'active' => trim((string) ($_GET['active'] ?? '')),
];

$roles = WebUserService::listRoles();
$permissions = WebUserService::listPermissions();
$users = WebUserService::listUsers($filters['q'], $filters['role'], $filters['active']);
$stats = WebUserService::stats();

$editId = trim((string) ($_GET['edit'] ?? ''));
$editUser = $editId !== '' ? WebUserService::getUserById($editId) : null;
if ($editUser === null) {
    $editId = '';
}

$editRoleId = trim((string) ($_GET['edit_role'] ?? ''));
$editRole = $editRoleId !== '' ? WebUserService::getRoleById($editRoleId) : null;
if ($editRole === null) {
    $editRoleId = '';
}

$taskRoles = StaffPermissions::taskRoles();
$permissionLabelsByCode = [];
foreach (StaffPermissions::catalog() as $catalogItem) {
    $permissionLabelsByCode[(string) ($catalogItem['code'] ?? '')] = (string) ($catalogItem['name_ar'] ?? '');
}
$roleIdsByCode = [];
foreach ($roles as $role) {
    $code = (string) ($role['code'] ?? '');
    if ($code !== '') {
        $roleIdsByCode[$code] = (string) ($role['id'] ?? '');
    }
}

$permissionsByCategory = [];
foreach ($permissions as $permission) {
    $category = trim((string) ($permission['category_ar'] ?? 'عام'));
    if ($category === '') {
        $category = 'عام';
    }
    $permissionsByCategory[$category][] = $permission;
}
ksort($permissionsByCategory);

$currentRoute = '/dashboard/users.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/users.php';
$content = ob_get_clean();
$title = 'المستخدمون والأدوار';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
