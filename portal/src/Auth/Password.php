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

        [$algorithm, $label] = self::preferredAlgorithm();
        $hash = password_hash($plain, $algorithm);
        if ($hash === false) {
            throw new \RuntimeException(
                'تعذر تجزئة كلمة المرور باستخدام ' . $label
                . '. تحقق من تفعيل امتداد openssl في php.ini (extension=openssl) ثم أعد تشغيل خادم PHP.'
            );
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

    /** @return array{0: int, 1: string} */
    private static function preferredAlgorithm(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, 'Argon2id'];
        }
        if (defined('PASSWORD_ARGON2I')) {
            return [PASSWORD_ARGON2I, 'Argon2i'];
        }
        if (defined('PASSWORD_BCRYPT')) {
            if (!extension_loaded('openssl')) {
                throw new \RuntimeException(
                    'امتداد openssl غير مفعّل في PHP. تجزئة كلمة المرور (bcrypt) تتطلبه. '
                    . 'فعّل extension=openssl في php.ini ثم أعد تشغيل الخادم.'
                );
            }

            return [PASSWORD_BCRYPT, 'bcrypt'];
        }

        throw new \RuntimeException(
            'لا يتوفر خوارزم تجزئة كلمات مرور في PHP (bcrypt/argon2). '
            . 'ثبّت/فعّل openssl أو استخدم PHP 8.2+ مع الإعدادات الافتراضية.'
        );
    }

    private static function normalizePlain(string $plain): string
    {
        return trim($plain);
    }
}
