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

        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
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
}
