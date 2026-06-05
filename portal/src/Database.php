<?php

declare(strict_types=1);

namespace Portal;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::get('PORTAL_DB_HOST', '127.0.0.1');
        $port = Config::get('PORTAL_DB_PORT', '5432');
        $name = Config::get('PORTAL_DB_NAME', 'portal_db');
        $user = Config::get('PORTAL_DB_USER', 'portal');
        $password = Config::get('PORTAL_DB_PASSWORD', 'portal');

        $dsn = "pgsql:host=$host;port=$port;dbname=$name;options='--client_encoding=UTF8'";
        try {
            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('فشل الاتصال بقاعدة بيانات الموقع: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return self::$pdo;
    }

    public static function resetConnection(): void
    {
        self::$pdo = null;
    }

    /** @return array{ok: bool, message: string} */
    public static function testConnection(
        ?string $host = null,
        ?string $port = null,
        ?string $name = null,
        ?string $user = null,
        ?string $password = null
    ): array {
        $host ??= Config::get('PORTAL_DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $port ??= Config::get('PORTAL_DB_PORT', '5432') ?? '5432';
        $name ??= Config::get('PORTAL_DB_NAME', 'portal_db') ?? 'portal_db';
        $user ??= Config::get('PORTAL_DB_USER', 'portal') ?? 'portal';
        $password ??= Config::get('PORTAL_DB_PASSWORD', 'portal') ?? 'portal';

        $dsn = "pgsql:host=$host;port=$port;dbname=$name;options='--client_encoding=UTF8'";
        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');

            return ['ok' => true, 'message' => 'الاتصال بقاعدة البيانات ناجح.'];
        } catch (PDOException $exception) {
            return ['ok' => false, 'message' => 'فشل الاتصال بقاعدة البيانات: ' . $exception->getMessage()];
        }
    }
}
