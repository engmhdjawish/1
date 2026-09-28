<?php

declare(strict_types=1);

namespace Portal;

final class Bootstrap
{
    private static bool $booted = false;

    public static function init(?string $basePath = null): void
    {
        if (self::$booted) {
            return;
        }

        $basePath ??= dirname(__DIR__);
        $autoload = $basePath . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        } else {
            spl_autoload_register(static function (string $class) use ($basePath): void {
                if (!str_starts_with($class, 'Portal\\')) {
                    return;
                }
                $relative = str_replace('\\', '/', substr($class, strlen('Portal\\')));
                $file = $basePath . '/src/' . $relative . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            });
        }

        self::loadEnv($basePath);
        if (!self::shouldSkipWebRuntime()) {
            \Portal\Support\HttpsGate::redirectIfNeeded();
        }
        date_default_timezone_set('Asia/Damascus');

        if (!self::shouldSkipWebRuntime() && session_status() !== PHP_SESSION_ACTIVE) {
            $lifetimeSeconds = self::configureSessionCookie();
            $sessionName = Config::get('PORTAL_SESSION_NAME', 'portal_session');
            $presentedSessionId = (string) ($_COOKIE[$sessionName] ?? '');
            session_name($sessionName);
            session_start();
            self::refreshSessionCookie($lifetimeSeconds);
            \Portal\Services\PortalSessionService::bootstrap($presentedSessionId);
            if (\Portal\Auth\CustomerSession::isLoggedIn()) {
                \Portal\Auth\CustomerSession::refresh();
            }
        }

        self::$booted = true;
    }

    private static function configureSessionCookie(): int
    {
        $lifetimeDays = (int) Config::get('PORTAL_SESSION_LIFETIME_DAYS', '30');
        $lifetimeDays = max(7, min(365, $lifetimeDays));
        $lifetimeSeconds = $lifetimeDays * 86400;

        ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);
        ini_set('session.cookie_lifetime', (string) $lifetimeSeconds);
        // System session GC can delete the file while the cookie is still valid.
        // Keep that id so the database row can restore the login. Login rotates the id.
        ini_set('session.use_strict_mode', '0');

        session_set_cookie_params([
            'lifetime' => $lifetimeSeconds,
            'path' => '/',
            'secure' => \Portal\Support\HttpsGate::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $lifetimeSeconds;
    }

    private static function refreshSessionCookie(int $lifetimeSeconds): void
    {
        if ($lifetimeSeconds < 1 || headers_sent()) {
            return;
        }

        $name = session_name();
        $id = session_id();
        if ($name === '' || $id === '') {
            return;
        }

        setcookie($name, $id, [
            'expires' => time() + $lifetimeSeconds,
            'path' => '/',
            'secure' => \Portal\Support\HttpsGate::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function shouldSkipWebRuntime(): bool
    {
        if (defined('PORTAL_NO_SESSION') && PORTAL_NO_SESSION) {
            return true;
        }

        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    private static function loadEnv(string $basePath): void
    {
        $envFile = $basePath . '/.env';
        if (!is_file($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}
