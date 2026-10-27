<?php

declare(strict_types=1);

/**
 * Router for test-pwa-update-e2e.mjs:  php -S 127.0.0.1:PORT -t portal/public portal/scripts/fixtures/e2e/router.php
 *
 * Everything under portal/public is served as-is (the real sw.js, assets, manifest, offline page, version
 * endpoint). On top of that it provides a few /e2e/* pages and one switch:
 *  - while $E2E_STATE_DIR/legacy-sw.js exists, /sw.js answers with that old generation ("before the deploy")
 *  - deleting the file is the "deploy": /sw.js falls through to the real file
 * No database is needed.
 */

$portalRoot = dirname(__DIR__, 3);
$stateDir = (string) (getenv('E2E_STATE_DIR') ?: sys_get_temp_dir());
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

$respondHtml = static function (string $html): bool {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;

    return true;
};

if ($path === '/sw.js') {
    $legacy = $stateDir . '/legacy-sw.js';
    if (is_file($legacy)) {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile($legacy);

        return true;
    }

    return false;
}

if ($path === '/e2e/legacy.html') {
    return $respondHtml('<!doctype html><html lang="ar"><head><meta charset="utf-8"><title>legacy</title></head><body><h1>legacy page</h1>'
        . '<script>navigator.serviceWorker.register("/sw.js?v=" + (new URLSearchParams(location.search).get("v") || "24"))'
        . '.then(function(){return navigator.serviceWorker.ready}).then(function(){document.title="legacy-ready"});</script></body></html>');
}

if ($path === '/e2e/page.html') {
    define('PORTAL_NO_SESSION', true);
    require $portalRoot . '/bootstrap.php';
    $build = \Portal\Support\PwaBuild::id();
    $pageBuild = $build;
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['stale'] ?? ''));
    if ($token !== '') {
        $flag = $stateDir . '/stale-' . $token;
        if (!is_file($flag)) {
            file_put_contents($flag, '1');
            $pageBuild = str_repeat('a', 12);
        }
    }

    return $respondHtml('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>e2e page</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="portal-build" content="' . htmlspecialchars($pageBuild, ENT_QUOTES) . '">'
        . '<link rel="manifest" href="/manifest.php">'
        . '<script src="/assets/pwa-update.js?v=e2e" defer></script><script src="/assets/pwa-status.js?v=e2e" defer></script></head>'
        . '<body><main><h1 id="marker">page build ' . htmlspecialchars($pageBuild, ENT_QUOTES) . '</h1>'
        . '<input id="note" type="text" autocomplete="off"><a id="sibling" href="https://www.jawishco.sy/s/abc">link</a></main>'
        . '<footer><span data-pwa-status-trigger>© جاويش للتجارة</span></footer></body></html>');
}

if ($path === '/e2e/login') {
    define('PORTAL_NO_SESSION', true);
    require $portalRoot . '/bootstrap.php';
    require $portalRoot . '/views/helpers.php';
    $type = 'customer';
    $error = null;
    $message = null;
    $redirect = '/store.php';
    $loginPagePath = '/e2e/login-post';
    ob_start();
    require $portalRoot . '/views/login.php';
    $content = (string) ob_get_clean();

    return $respondHtml('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>login</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . portal_stylesheet('/css/site-app.min.css')
        . '</head><body class="min-h-screen bg-surface-bg"><main class="max-w-7xl w-full mx-auto px-4 py-6 md:py-8">'
        . $content . '</main>' . portal_defer_script('/assets/phone-input.js') . portal_defer_script('/assets/login-page.js') . '</body></html>');
}

if ($path === '/e2e/login-post') {
    $target = '/e2e/after-login?redirect=' . rawurlencode((string) ($_POST['redirect'] ?? ''))
        . '&phone=' . rawurlencode((string) ($_POST['customer_phone'] ?? ''))
        . '&password_len=' . strlen((string) ($_POST['customer_password'] ?? ''));
    header('Location: ' . $target, true, 302);

    return true;
}

if ($path === '/e2e/after-login') {
    return $respondHtml('<!doctype html><meta charset="utf-8"><title>after-login</title><p id="landed">landed ' . htmlspecialchars((string) ($_SERVER['QUERY_STRING'] ?? ''), ENT_QUOTES) . '</p>');
}

if ($path === '/e2e/probe') {
    $probeRoot = (string) (getenv('E2E_PORTAL_ROOT') ?: $portalRoot);
    require $probeRoot . '/bootstrap.php';
    $action = (string) ($_GET['a'] ?? 'whoami');
    if ($action === 'login') {
        session_regenerate_id(true);
        $_SESSION['probe_user'] = 'customer-1';
        header('Location: /e2e/probe?a=whoami', true, 302);
        exit;
    }

    return $respondHtml('<!doctype html><meta charset="utf-8"><title>probe</title><pre id="who">user='
        . htmlspecialchars((string) ($_SESSION['probe_user'] ?? 'guest'), ENT_QUOTES) . ' sid=' . htmlspecialchars(session_id(), ENT_QUOTES) . '</pre>');
}

return false;
