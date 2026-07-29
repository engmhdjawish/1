<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Database;
use Portal\Services\PortalSessionService;
use Portal\Support\DigitNormalizer;
use Portal\Support\PortalUrl;
use PDO;

final class CustomerSession
{
    private const SESSION_KEY = 'web_customer';

    public static function customer(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function check(): bool
    {
        $customer = self::customer();

        return $customer !== null
            && ($customer['status'] ?? '') === 'active';
    }

    /** مسجّل دخول (نشط أو بانتظار التفعيل). */
    public static function isLoggedIn(): bool
    {
        $customer = self::customer();
        if ($customer === null) {
            return false;
        }

        return in_array((string) ($customer['status'] ?? ''), ['pending', 'active'], true);
    }

    public static function isPending(): bool
    {
        $customer = self::customer();

        return $customer !== null && ($customer['status'] ?? '') === 'pending';
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . PortalUrl::loginUrl('customer'));
            exit;
        }
    }

    /** @param-out string|null $errorMessage */
    public static function login(string $phone, string $password, ?string &$errorMessage = null): bool
    {
        $errorMessage = null;
        $phone = DigitNormalizer::normalizePhone($phone);
        $password = trim($password);

        if ($phone === '' || $password === '') {
            $errorMessage = 'أدخل رقم الهاتف وكلمة المرور.';

            return false;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT c.*, ap.show_price, ap.show_quantity, ap.allow_cart, ap.allow_order
             FROM web_customers c
             LEFT JOIN access_policies ap ON ap.id = c.access_policy_id
             WHERE c.phone = :phone LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $errorMessage = 'بيانات الدخول غير صحيحة.';

            return false;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'rejected') {
            $errorMessage = 'تم رفض طلب التسجيل.';

            return false;
        }
        if ($status === 'suspended') {
            $errorMessage = 'الحساب موقوف. تواصل مع الإدارة.';

            return false;
        }
        if (empty($row['password_hash'])) {
            $errorMessage = 'حسابك بانتظار موافقة الإدارة.';

            return false;
        }
        if (!Password::verify($password, $row['password_hash'])) {
            $errorMessage = 'بيانات الدخول غير صحيحة.';

            return false;
        }

        if ($status === 'pending') {
            self::establishFromRow($row, $pdo);

            return true;
        }

        if ($status !== 'active' || !(bool) ($row['is_active'] ?? false)) {
            $errorMessage = 'الحساب غير نشط. تواصل مع الإدارة.';

            return false;
        }

        self::establishFromRow($row, $pdo);

        return true;
    }

    public static function logout(): void
    {
        PortalSessionService::revokeCurrent();
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @param array<string, mixed> $row */
    private static function mapCustomer(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $isFullyActive = $status === 'active' && (bool) ($row['is_active'] ?? false);

        return [
            'id' => $row['id'],
            'name_ar' => $row['name_ar'],
            'phone' => $row['phone'],
            'email' => $row['email'] ?? null,
            'status' => $status,
            'access_policy_id' => $isFullyActive ? ($row['access_policy_id'] ?? null) : null,
            'show_price' => $isFullyActive ? (bool) ($row['show_price'] ?? false) : false,
            'show_quantity' => $isFullyActive ? (bool) ($row['show_quantity'] ?? false) : false,
            'allow_cart' => $isFullyActive ? (bool) ($row['allow_cart'] ?? false) : false,
            'allow_order' => $isFullyActive ? (bool) ($row['allow_order'] ?? false) : false,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function establishFromRow(array $row, ?PDO $pdo = null): void
    {
        WebSession::logout();

        $_SESSION[self::SESSION_KEY] = self::mapCustomer($row);

        $pdo ??= Database::pdo();
        $pdo->prepare('UPDATE web_customers SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $row['id']]);

        PortalSessionService::registerCustomer((string) $row['id']);
    }

    public static function refresh(): void
    {
        $customer = self::customer();
        if ($customer === null) {
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT c.*, ap.show_price, ap.show_quantity, ap.allow_cart, ap.allow_order
             FROM web_customers c
             LEFT JOIN access_policies ap ON ap.id = c.access_policy_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $customer['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::logout();

            return;
        }

        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['rejected', 'suspended'], true)) {
            self::logout();

            return;
        }

        if ($status === 'active' && !(bool) ($row['is_active'] ?? false)) {
            self::logout();

            return;
        }

        if (!in_array($status, ['pending', 'active'], true)) {
            self::logout();

            return;
        }

        $_SESSION[self::SESSION_KEY] = self::mapCustomer($row);
    }

    /** @param array<string, mixed> $row */
    public static function restoreFromDatabaseRow(array $row): void
    {
        $_SESSION[self::SESSION_KEY] = self::mapCustomer($row);
    }
}
