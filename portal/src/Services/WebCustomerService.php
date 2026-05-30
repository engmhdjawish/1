<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
use Portal\Database;
use PDO;

final class WebCustomerService
{
    public static function registerSelf(string $name, string $phone, string $password, ?string $email = null): array
    {
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM web_customers WHERE phone = :phone');
        $exists->execute(['phone' => $phone]);
        if ($exists->fetchColumn()) {
            return ['ok' => false, 'message' => 'رقم الهاتف مسجّل مسبقاً.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO web_customers (
                name_ar, phone, email, password_hash, status, registration_source, is_active
             ) VALUES (
                :name, :phone, :email, :hash, \'pending\', \'self_register\', FALSE
             ) RETURNING id'
        );
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'hash' => Password::hash($password),
        ]);

        return ['ok' => true, 'message' => 'تم التسجيل. سيتم تفعيل حسابك بعد موافقة الإدارة.', 'id' => $stmt->fetchColumn()];
    }

    public static function createByAdmin(
        string $name,
        string $phone,
        string $accessPolicyId,
        string $adminUserId,
        ?string $password = null,
        bool $activateImmediately = true
    ): array {
        $pdo = Database::pdo();
        $status = $activateImmediately ? 'active' : 'pending';
        $stmt = $pdo->prepare(
            'INSERT INTO web_customers (
                name_ar, phone, password_hash, status, registration_source,
                access_policy_id, is_active, created_by_web_user_id,
                approved_by_web_user_id, approved_at
             ) VALUES (
                :name, :phone, :hash, :status, \'admin_created\',
                :policy, :active, :admin, :approver, :approved_at
             ) RETURNING id'
        );
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'hash' => $password ? Password::hash($password) : null,
            'status' => $status,
            'policy' => $accessPolicyId,
            'active' => $activateImmediately,
            'admin' => $adminUserId,
            'approver' => $activateImmediately ? $adminUserId : null,
            'approved_at' => $activateImmediately ? date('c') : null,
        ]);

        return ['ok' => true, 'id' => $stmt->fetchColumn()];
    }

    public static function approve(string $customerId, string $accessPolicyId, string $adminUserId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE web_customers SET
                status = \'active\', is_active = TRUE,
                access_policy_id = :policy,
                approved_by_web_user_id = :admin,
                approved_at = NOW(),
                rejection_reason_ar = NULL,
                updated_at = NOW()
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([
            'policy' => $accessPolicyId,
            'admin' => $adminUserId,
            'id' => $customerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function reject(string $customerId, string $reason, string $adminUserId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE web_customers SET
                status = \'rejected\', is_active = FALSE,
                rejection_reason_ar = :reason,
                approved_by_web_user_id = :admin,
                approved_at = NOW(),
                updated_at = NOW()
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([
            'reason' => $reason,
            'admin' => $adminUserId,
            'id' => $customerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public static function listPending(): array
    {
        $pdo = Database::pdo();
        return $pdo->query(
            "SELECT id, name_ar, phone, email, registration_source, created_at
             FROM web_customers WHERE status = 'pending' ORDER BY created_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function listAccessPolicies(): array
    {
        return Database::pdo()->query(
            'SELECT id, code, name_ar FROM access_policies WHERE is_active = TRUE ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
