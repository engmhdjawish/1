<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
use Portal\Database;
use PDO;

final class WebCustomerService
{
    private const ALLOWED_STATUSES = ['pending', 'active', 'rejected', 'suspended'];
    private const ALLOWED_SOURCES = ['self_register', 'admin_created'];

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
                :policy, CASE WHEN :active = 1 THEN TRUE ELSE FALSE END, :admin, :approver, :approved_at
             ) RETURNING id'
        );
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'hash' => $password ? Password::hash($password) : null,
            'status' => $status,
            'policy' => $accessPolicyId,
            'active' => $activateImmediately ? 1 : 0,
            'admin' => $adminUserId,
            'approver' => $activateImmediately ? $adminUserId : null,
            'approved_at' => $activateImmediately ? date('c') : null,
        ]);

        return ['ok' => true, 'id' => $stmt->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $customerId): ?array
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT
                wc.id::text AS id,
                wc.name_ar,
                wc.phone,
                wc.email,
                wc.status::text AS status,
                wc.registration_source::text AS registration_source,
                wc.access_policy_id::text AS access_policy_id,
                wc.notes_ar,
                wc.rejection_reason_ar,
                wc.is_active,
                wc.created_at,
                wc.updated_at,
                wc.approved_at,
                ap.name_ar AS access_policy_name_ar
             FROM web_customers wc
             LEFT JOIN access_policies ap ON ap.id = wc.access_policy_id
             WHERE wc.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function saveByAdmin(
        ?string $customerId,
        string $name,
        string $phone,
        ?string $email,
        string $accessPolicyId,
        string $status,
        bool $isActive,
        ?string $plainPassword,
        ?string $notes,
        ?string $rejectionReason,
        string $adminUserId
    ): array {
        $customerId = trim((string) $customerId);
        $name = trim($name);
        $phone = trim($phone);
        $email = trim((string) $email);
        $accessPolicyId = trim($accessPolicyId);
        $status = trim($status);
        $plainPassword = trim((string) $plainPassword);
        $notes = trim((string) $notes);
        $rejectionReason = trim((string) $rejectionReason);

        if ($name === '' || $phone === '') {
            return ['ok' => false, 'message' => 'الاسم ورقم الهاتف مطلوبان.'];
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'pending';
        }

        $pdo = Database::pdo();
        $duplicate = $pdo->prepare(
            "SELECT 1
             FROM web_customers
             WHERE phone = :phone
               AND (:exclude_id = '' OR id::text <> :exclude_id)
             LIMIT 1"
        );
        $duplicate->execute([
            'phone' => $phone,
            'exclude_id' => $customerId,
        ]);
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'رقم الهاتف مسجّل مسبقاً لعميل آخر.'];
        }

        $isActive = $status === 'active' ? true : $isActive;
        $approvedAt = $status === 'active' ? date('c') : null;
        $approvedBy = $status === 'active' ? $adminUserId : null;
        $safePolicyId = $accessPolicyId !== '' ? $accessPolicyId : null;
        $safeEmail = $email !== '' ? $email : null;
        $safeNotes = $notes !== '' ? $notes : null;
        $safeRejectionReason = $status === 'rejected' && $rejectionReason !== '' ? $rejectionReason : null;

        if ($customerId === '') {
            $passwordHash = $plainPassword !== '' ? Password::hash($plainPassword) : null;
            $stmt = $pdo->prepare(
                "INSERT INTO web_customers (
                    name_ar,
                    phone,
                    email,
                    password_hash,
                    status,
                    registration_source,
                    access_policy_id,
                    notes_ar,
                    rejection_reason_ar,
                    is_active,
                    approved_by_web_user_id,
                    approved_at,
                    created_by_web_user_id
                 ) VALUES (
                    :name_ar,
                    :phone,
                    :email,
                    :password_hash,
                    :status,
                    'admin_created',
                    :access_policy_id,
                    :notes_ar,
                    :rejection_reason_ar,
                    CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    :approved_by,
                    :approved_at,
                    :created_by
                 )
                 RETURNING id::text"
            );
            $stmt->execute([
                'name_ar' => $name,
                'phone' => $phone,
                'email' => $safeEmail,
                'password_hash' => $passwordHash,
                'status' => $status,
                'access_policy_id' => $safePolicyId,
                'notes_ar' => $safeNotes,
                'rejection_reason_ar' => $safeRejectionReason,
                'is_active' => $isActive ? 1 : 0,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'created_by' => $adminUserId,
            ]);

            return ['ok' => true, 'message' => 'تم إنشاء العميل بنجاح.', 'id' => (string) $stmt->fetchColumn()];
        }

        if ($plainPassword !== '') {
            $stmt = $pdo->prepare(
                "UPDATE web_customers SET
                    name_ar = :name_ar,
                    phone = :phone,
                    email = :email,
                    password_hash = :password_hash,
                    status = :status,
                    access_policy_id = :access_policy_id,
                    notes_ar = :notes_ar,
                    rejection_reason_ar = :rejection_reason_ar,
                    is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    approved_by_web_user_id = COALESCE(:approved_by, approved_by_web_user_id),
                    approved_at = COALESCE(:approved_at, approved_at),
                    updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $customerId,
                'name_ar' => $name,
                'phone' => $phone,
                'email' => $safeEmail,
                'password_hash' => Password::hash($plainPassword),
                'status' => $status,
                'access_policy_id' => $safePolicyId,
                'notes_ar' => $safeNotes,
                'rejection_reason_ar' => $safeRejectionReason,
                'is_active' => $isActive ? 1 : 0,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE web_customers SET
                    name_ar = :name_ar,
                    phone = :phone,
                    email = :email,
                    status = :status,
                    access_policy_id = :access_policy_id,
                    notes_ar = :notes_ar,
                    rejection_reason_ar = :rejection_reason_ar,
                    is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    approved_by_web_user_id = COALESCE(:approved_by, approved_by_web_user_id),
                    approved_at = COALESCE(:approved_at, approved_at),
                    updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $customerId,
                'name_ar' => $name,
                'phone' => $phone,
                'email' => $safeEmail,
                'status' => $status,
                'access_policy_id' => $safePolicyId,
                'notes_ar' => $safeNotes,
                'rejection_reason_ar' => $safeRejectionReason,
                'is_active' => $isActive ? 1 : 0,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
            ]);
        }

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'لم يتم العثور على العميل المطلوب تحديثه.'];
        }

        return ['ok' => true, 'message' => 'تم تحديث بيانات العميل بنجاح.', 'id' => $customerId];
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
        return self::listByStatus('pending', '', '', 200);
    }

    /** @return list<array<string, mixed>> */
    public static function listByStatus(
        string $status = 'pending',
        string $search = '',
        string $source = '',
        int $limit = 100
    ): array {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'pending';
        }

        $search = trim($search);
        $source = in_array($source, self::ALLOWED_SOURCES, true) ? $source : '';
        $limit = max(1, min(300, $limit));

        $sql = "SELECT
                    wc.id,
                    wc.name_ar,
                    wc.phone,
                    wc.email,
                    wc.status::text AS status,
                    wc.registration_source::text AS registration_source,
                    wc.created_at,
                    wc.is_active,
                    ap.name_ar AS access_policy_name_ar
                FROM web_customers wc
                LEFT JOIN access_policies ap ON ap.id = wc.access_policy_id
                WHERE wc.status = :status";
        $params = ['status' => $status];

        if ($source !== '') {
            $sql .= ' AND wc.registration_source = :source';
            $params['source'] = $source;
        }

        if ($search !== '') {
            $sql .= ' AND (
                wc.name_ar ILIKE :search
                OR wc.phone ILIKE :search
                OR wc.email ILIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY wc.created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{pending: int, active: int, rejected: int, suspended: int} */
    public static function statusCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT status::text AS status, COUNT(*)::int AS count
             FROM web_customers
             GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);

        $counts = [
            'pending' => 0,
            'active' => 0,
            'rejected' => 0,
            'suspended' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['count'];
            }
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public static function listAccessPolicies(): array
    {
        return Database::pdo()->query(
            'SELECT id::text AS id, code, name_ar FROM access_policies WHERE is_active = TRUE ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
