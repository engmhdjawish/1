<?php

declare(strict_types=1);

namespace Portal\Services;

use Throwable;

final class AmineApiAdminService
{
    /** @return array{ok: bool, message: string, enabled?: bool} */
    public static function serviceStatus(): array
    {
        try {
            $response = ApiClient::get('/api/admin/service');
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر جلب حالة الخدمة.', $response);
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];

            return [
                'ok' => true,
                'message' => '',
                'enabled' => (bool) ($data['enabled'] ?? $data['Enabled'] ?? true),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function setServiceEnabled(bool $enabled): array
    {
        try {
            $response = ApiClient::putJson('/api/admin/service', ['enabled' => $enabled]);
            if (!($response['ok'] ?? false)) {
                return self::error($enabled ? 'تعذر تشغيل الخدمة.' : 'تعذر إيقاف الخدمة.', $response);
            }

            return [
                'ok' => true,
                'message' => $enabled ? 'تم تشغيل خدمة API الأمين.' : 'تم إيقاف خدمة API الأمين مؤقتاً.',
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string, images_directory?: string} */
    public static function imageSettings(): array
    {
        try {
            $response = ApiClient::get('/api/material-images/settings');
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر جلب مسار صور الأمين.', $response);
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];

            return [
                'ok' => true,
                'message' => '',
                'images_directory' => (string) ($data['imagesDirectory'] ?? $data['ImagesDirectory'] ?? ''),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function saveImageSettings(string $imagesDirectory): array
    {
        $imagesDirectory = trim($imagesDirectory);
        if ($imagesDirectory === '') {
            return ['ok' => false, 'message' => 'مسار الصور مطلوب.'];
        }

        try {
            $response = ApiClient::putJson('/api/material-images/settings', [
                'imagesDirectory' => $imagesDirectory,
            ]);
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر حفظ مسار صور الأمين.', $response);
            }

            return ['ok' => true, 'message' => 'تم حفظ مسار صور الأمين.'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string, users?: list<array<string, mixed>>} */
    public static function users(): array
    {
        try {
            $response = ApiClient::get('/api/admin/users');
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر جلب مستخدمي API.', $response);
            }

            $rows = is_array($response['data'] ?? null) ? $response['data'] : [];

            return ['ok' => true, 'message' => '', 'users' => array_values($rows)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, user?: array<string, mixed>}
     */
    public static function createUser(array $payload): array
    {
        try {
            $response = ApiClient::postJson('/api/admin/users', $payload);
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر إنشاء مستخدم API.', $response);
            }

            return [
                'ok' => true,
                'message' => 'تم إنشاء مستخدم API.',
                'user' => is_array($response['data'] ?? null) ? $response['data'] : null,
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, user?: array<string, mixed>|null}
     */
    public static function updateUser(string $userId, array $payload): array
    {
        try {
            $response = ApiClient::putJson('/api/admin/users/' . rawurlencode($userId), $payload);
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر تحديث مستخدم API.', $response);
            }

            return [
                'ok' => true,
                'message' => 'تم تحديث مستخدم API.',
                'user' => is_array($response['data'] ?? null) ? $response['data'] : null,
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function resetPassword(string $userId, string $newPassword): array
    {
        try {
            $response = ApiClient::postJson('/api/admin/users/' . rawurlencode($userId) . '/reset-password', [
                'newPassword' => $newPassword,
            ]);
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر إعادة تعيين كلمة المرور.', $response);
            }

            return ['ok' => true, 'message' => 'تم تحديث كلمة المرور.'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string, roles?: list<array<string, mixed>>, permissions?: list<array<string, mixed>>} */
    public static function rolesAndPermissions(): array
    {
        try {
            $rolesResponse = ApiClient::get('/api/admin/roles');
            $permissionsResponse = ApiClient::get('/api/admin/permissions');
            if (!($rolesResponse['ok'] ?? false) || !($permissionsResponse['ok'] ?? false)) {
                return self::error('تعذر جلب الأدوار أو الصلاحيات.', $rolesResponse['ok'] ? $permissionsResponse : $rolesResponse);
            }

            return [
                'ok' => true,
                'message' => '',
                'roles' => array_values(is_array($rolesResponse['data'] ?? null) ? $rolesResponse['data'] : []),
                'permissions' => array_values(is_array($permissionsResponse['data'] ?? null) ? $permissionsResponse['data'] : []),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string, permission_ids?: list<int>} */
    public static function rolePermissions(int $roleId): array
    {
        try {
            $response = ApiClient::get('/api/admin/roles/' . $roleId . '/permissions');
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر جلب صلاحيات الدور.', $response);
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $permissionIds = $data['permissionIds'] ?? $data['PermissionIds'] ?? [];

            return [
                'ok' => true,
                'message' => '',
                'permission_ids' => array_values(array_map('intval', is_array($permissionIds) ? $permissionIds : [])),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @param list<int> $permissionIds */
    public static function saveRolePermissions(int $roleId, array $permissionIds): array
    {
        try {
            $response = ApiClient::putJson('/api/admin/roles/' . $roleId . '/permissions', [
                'permissionIds' => array_values(array_unique(array_map('intval', $permissionIds))),
            ]);
            if (!($response['ok'] ?? false)) {
                return self::error('تعذر حفظ صلاحيات الدور.', $response);
            }

            return ['ok' => true, 'message' => 'تم حفظ صلاحيات الدور.'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @param array<string, mixed> $response */
    private static function error(string $fallback, array $response): array
    {
        $message = trim((string) ($response['error'] ?? ''));
        if ($message === '' && is_array($response['data'] ?? null)) {
            $message = trim((string) ($response['data']['message'] ?? ''));
        }

        return [
            'ok' => false,
            'message' => $message !== '' ? $message : $fallback,
        ];
    }
}
