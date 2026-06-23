<?php

declare(strict_types=1);

namespace Portal\Support;

/** تفضيل عملة عرض أسعار الطلب في لوحة الموظف. */
final class DashboardOrderPricePreference
{
    public const SYP = 'syp';
    public const USD = 'usd';

    private const SESSION_KEY = 'dashboard_order_price_currency';

    /** @param array<string, mixed> $query */
    public static function applyFromRequest(array $query): void
    {
        $currency = strtolower(trim((string) ($query['order_currency'] ?? '')));
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
        $value = $_SESSION[self::SESSION_KEY] ?? self::USD;

        return $value === self::SYP ? self::SYP : self::USD;
    }

    public static function showSyp(): bool
    {
        return self::current() === self::SYP;
    }

    public static function showUsd(): bool
    {
        return self::current() === self::USD;
    }
}
