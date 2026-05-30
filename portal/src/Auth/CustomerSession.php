<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Database;
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
        return $customer !== null && ($customer['status'] ?? '') === 'active';
    }

    public static function login(string $phone, string $password): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT c.*, ap.show_price, ap.show_quantity, ap.allow_cart, ap.allow_order
             FROM web_customers c
             LEFT JOIN access_policies ap ON ap.id = c.access_policy_id
             WHERE c.phone = :phone LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active' || empty($row['password_hash'])) {
            return false;
        }
        if (!Password::verify($password, $row['password_hash'])) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = self::mapCustomer($row);
        $pdo->prepare('UPDATE web_customers SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $row['id']]);

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @param array<string, mixed> $row */
    private static function mapCustomer(array $row): array
    {
        return [
            'id' => $row['id'],
            'name_ar' => $row['name_ar'],
            'phone' => $row['phone'],
            'status' => $row['status'],
            'access_policy_id' => $row['access_policy_id'],
            'show_price' => (bool) ($row['show_price'] ?? false),
            'show_quantity' => (bool) ($row['show_quantity'] ?? false),
            'allow_cart' => (bool) ($row['allow_cart'] ?? false),
            'allow_order' => (bool) ($row['allow_order'] ?? false),
        ];
    }
}
