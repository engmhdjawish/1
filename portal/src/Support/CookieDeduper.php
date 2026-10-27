<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Heals sites that hold two cookies with the same name.
 *
 * Until CookieOptions started adding Domain=.example.com, every cookie was host-only. Browsers that
 * visited before that change still hold the old host-only copy next to the new Domain copy. Both are
 * sent on every request, oldest first (RFC 6265 section 5.4), and PHP keeps the FIRST value of a
 * repeated cookie. So the stale host-only session id shadowed each new login: the login succeeded,
 * session_regenerate_id() issued a new id into the Domain cookie, and the very next request was read
 * with the old id again, which meant the customer was anonymous and never reached the home page.
 *
 * normalizeRequest() makes PHP read the newest copy (the last one sent, i.e. the Domain cookie) and
 * expireHostOnly() deletes the legacy host-only copy so the browser stops sending it. A Set-Cookie
 * without a Domain attribute only touches host-only cookies; the Domain cookie is left alone.
 */
final class CookieDeduper
{
    /**
     * Cookies this app writes with CookieOptions::base() (the Domain cookie), so a legacy host-only twin can exist.
     *
     * @return list<string>
     */
    public static function appCookieNames(string $sessionName): array
    {
        return array_values(array_unique(array_filter([
            $sessionName,
            StaffDevice::COOKIE,
            StorePricePreference::COOKIE_NAME,
        ], static fn (string $name): bool => $name !== '')));
    }

    /**
     * Every name=value pair of a Cookie header, in the order the browser sent them.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseHeader(string $header): array
    {
        $pairs = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            // PHP url-decodes cookie values when it fills $_COOKIE; match that.
            $pairs[] = [$name, urldecode($value)];
        }

        return $pairs;
    }

    /**
     * Names (from $names) that the browser sent more than once, with their values in header order.
     *
     * @param list<string> $names
     * @return array<string, list<string>>
     */
    public static function duplicates(string $header, array $names): array
    {
        $wanted = array_flip($names);
        $seen = [];
        foreach (self::parseHeader($header) as [$name, $value]) {
            if (isset($wanted[$name])) {
                $seen[$name][] = $value;
            }
        }

        return array_filter($seen, static fn (array $values): bool => count($values) > 1);
    }

    /**
     * Make $_COOKIE carry the NEWEST copy of every duplicated cookie (the last one in the header).
     * Must run before session_start(), which reads the session id from $_COOKIE.
     *
     * @param list<string> $names
     * @return list<string> names that were duplicated (pass them to expireHostOnly)
     */
    public static function normalizeRequest(array $names, ?string $header = null): array
    {
        $header ??= (string) ($_SERVER['HTTP_COOKIE'] ?? '');
        if ($header === '' || !str_contains($header, ';')) {
            return [];
        }

        $duplicated = self::duplicates($header, $names);
        foreach ($duplicated as $name => $values) {
            $_COOKIE[$name] = $values[count($values) - 1];
        }

        return array_map('strval', array_keys($duplicated));
    }

    /**
     * Expire the legacy host-only copy of each cookie. Only meaningful (and only done) when the app
     * writes Domain cookies, otherwise the "host-only" cookie is the real one.
     *
     * @param list<string> $names
     */
    public static function expireHostOnly(array $names, ?bool $secure = null): int
    {
        if ($names === [] || headers_sent() || CookieOptions::domain() === '') {
            return 0;
        }

        $secure ??= HttpsGate::isHttpsRequest();
        $sent = 0;
        foreach ($names as $name) {
            // No 'domain' key on purpose: this addresses the host-only cookie and nothing else.
            setcookie($name, '', [
                'expires' => 1,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $sent++;
        }

        return $sent;
    }
}
