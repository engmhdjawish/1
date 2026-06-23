<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AmineApiAdminService;

header('Content-Type: application/json; charset=utf-8');

WebSession::requireLogin();

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
if (!$isSuper && !in_array('company_settings.manage', $permissions, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'overview'));

    if ($action === 'overview') {
        $service = AmineApiAdminService::serviceStatus();
        $images = AmineApiAdminService::imageSettings();
        echo json_encode([
            'ok' => ($service['ok'] ?? false) || ($images['ok'] ?? false),
            'service' => $service,
            'images' => $images,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'users') {
        echo json_encode(AmineApiAdminService::users(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'roles') {
        echo json_encode(AmineApiAdminService::rolesAndPermissions(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'role-permissions') {
        $roleId = (int) ($_GET['role_id'] ?? 0);
        if ($roleId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'role_id مطلوب.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(AmineApiAdminService::rolePermissions($roleId), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw !== false && $raw !== '' ? $raw : 'null', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = trim((string) ($payload['action'] ?? $_POST['action'] ?? ''));

    if ($action === 'set-service') {
        $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        echo json_encode(AmineApiAdminService::setServiceEnabled($enabled), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save-images-path') {
        echo json_encode(AmineApiAdminService::saveImageSettings((string) ($payload['images_directory'] ?? '')), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create-user') {
        echo json_encode(AmineApiAdminService::createUser([
            'userName' => (string) ($payload['userName'] ?? $payload['user_name'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'displayName' => (string) ($payload['displayName'] ?? $payload['display_name'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'roleIds' => array_values(array_map('intval', (array) ($payload['roleIds'] ?? $payload['role_ids'] ?? []))),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update-user') {
        $userId = trim((string) ($payload['user_id'] ?? ''));
        if ($userId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'user_id مطلوب.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $body = [];
        if (array_key_exists('is_active', $payload) || array_key_exists('isActive', $payload)) {
            $body['isActive'] = filter_var($payload['is_active'] ?? $payload['isActive'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($payload['display_name']) || isset($payload['displayName'])) {
            $body['displayName'] = (string) ($payload['displayName'] ?? $payload['display_name'] ?? '');
        }
        if (isset($payload['email'])) {
            $body['email'] = (string) $payload['email'];
        }
        if (isset($payload['role_ids']) || isset($payload['roleIds'])) {
            $body['roleIds'] = array_values(array_map('intval', (array) ($payload['roleIds'] ?? $payload['role_ids'] ?? [])));
        }
        echo json_encode(AmineApiAdminService::updateUser($userId, $body), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reset-password') {
        $userId = trim((string) ($payload['user_id'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if ($userId === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'user_id و password مطلوبان.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(AmineApiAdminService::resetPassword($userId, $password), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save-role-permissions') {
        $roleId = (int) ($payload['role_id'] ?? 0);
        $permissionIds = array_values(array_map('intval', (array) ($payload['permission_ids'] ?? [])));
        if ($roleId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'role_id مطلوب.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(AmineApiAdminService::saveRolePermissions($roleId, $permissionIds), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'الطريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
