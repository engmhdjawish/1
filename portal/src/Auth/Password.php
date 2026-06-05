<?php

declare(strict_types=1);

namespace Portal\Auth;

final class Password
{
    public static function hash(string $plain): string
    {
        $plain = self::normalizePlain($plain);
        if ($plain === '') {
            throw new \InvalidArgumentException('كلمة المرور فارغة.');
        }

        $hash = password_hash($plain, PASSWORD_BCRYPT);
        if ($hash === false) {
            throw new \RuntimeException('تعذر تجزئة كلمة المرور. تحقق من دعم bcrypt في PHP.');
        }

        return $hash;
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        $plain = self::normalizePlain($plain);
        $hash = trim((string) $hash);
        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    private static function normalizePlain(string $plain): string
    {
        return trim($plain);
    }
}
