<?php

declare(strict_types=1);

namespace Portal\Support;

/** تفضيل عملة عرض الأسعار في المتجر العام (جلسة الزائر/العميل). */
final class StorePricePreference
{
    public const SYP = 'syp';
    public const USD = 'usd';

    private const SESSION_KEY = 'store_price_currency';

    /** @param array<string, mixed> $query */
    public static function applyFromRequest(array $query): void
    {
        $currency = strtolower(trim((string) ($query['currency'] ?? '')));
        if ($currency === self::SYP || $currency === self::USD) {
            self::set($currency);
        }
    }

    public static function set(string $currency): void
    {
        $_SESSION[self::SESSION_KEY] = $currency === self::USD ? self::USD : self::SYP;
    }

    public static function current(): string
    {
        $value = $_SESSION[self::SESSION_KEY] ?? self::SYP;

        return $value === self::USD ? self::USD : self::SYP;
    }

    public static function priceModeForDisplay(bool $showPrice): string
    {
        if (!$showPrice) {
            return 'none';
        }

        return self::current();
    }

    public static function label(string $currency): string
    {
        return $currency === self::USD ? 'دولار أمريكي' : 'ليرة سورية';
    }

    public static function shortLabel(string $currency): string
    {
        return $currency === self::USD ? '$' : 'ل.س';
    }
}
