<?php

declare(strict_types=1);

namespace Portal\Support;

/** تفضيل عملة عرض الأسعار في المتجر العام (جلسة + cookie للزائر والعميل). */
final class StorePricePreference
{
    public const SYP = 'syp';
    public const USD = 'usd';

    private const SESSION_KEY = 'store_price_currency';
    private const COOKIE_NAME = 'store_price_currency';
    private const COOKIE_MAX_AGE = 31536000;

    /** استرجاع التفضيل المحفوظ من cookie عند بداية الجلسة. */
    public static function bootstrap(): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $cookie = strtolower(trim((string) ($_COOKIE[self::COOKIE_NAME] ?? '')));
        if ($cookie === self::SYP || $cookie === self::USD) {
            $_SESSION[self::SESSION_KEY] = $cookie;
        }
    }

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
        $currency = $currency === self::USD ? self::USD : self::SYP;
        $_SESSION[self::SESSION_KEY] = $currency;
        self::persistCookie($currency);
    }

    /**
     * Ignore an older currency click that arrives after a newer one.
     * A non-positive seq is accepted so older clients still persist.
     */
    public static function claimWrite(int $seq): bool
    {
        if ($seq <= 0) {
            return true;
        }

        $latest = (int) ($_SESSION[self::SESSION_KEY . '_seq'] ?? 0);
        if ($latest > 0 && $seq < $latest) {
            return false;
        }

        $_SESSION[self::SESSION_KEY . '_seq'] = $seq;

        return true;
    }

    public static function current(): string
    {
        self::bootstrap();
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

    private static function persistCookie(string $currency): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $currency, array_merge(CookieOptions::base(true), [
            'expires' => time() + self::COOKIE_MAX_AGE,
        ]));
        $_COOKIE[self::COOKIE_NAME] = $currency;
    }
}
