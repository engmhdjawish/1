<?php

declare(strict_types=1);

namespace Portal\Support;

final class VapidKeyGenerator
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function create(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('امتداد OpenSSL غير مفعّل في PHP.');
        }

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            throw new \RuntimeException('تعذر إنشاء مفاتيح VAPID عبر OpenSSL.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['ec']) || !is_array($details['ec'])) {
            throw new \RuntimeException('تعذر قراءة تفاصيل مفتاح EC.');
        }

        $ec = $details['ec'];
        $x = self::pad32((string) ($ec['x'] ?? ''));
        $y = self::pad32((string) ($ec['y'] ?? ''));
        $d = self::pad32((string) ($ec['d'] ?? ''));

        return [
            'publicKey' => self::base64UrlEncode("\x04" . $x . $y),
            'privateKey' => self::base64UrlEncode($d),
        ];
    }

    private static function pad32(string $value): string
    {
        if ($value === '') {
            throw new \RuntimeException('مفتاح EC غير مكتمل.');
        }

        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
