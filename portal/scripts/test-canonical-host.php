<?php

declare(strict_types=1);

/**
 * Canonical host / cookie domain for www vs apex.
 * Usage: php scripts/test-canonical-host.php
 */

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Support\CookieOptions;
use Portal\Support\HttpsGate;

$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "OK: {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$label}\n";
};

$assert(
    'by default www is NOT hard-redirected to apex (PWA-safe)',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/store.php?q=1',
        true,
        'https://jawishco.sy',
        false,
        false
    ) === null
);

$assert(
    'by default apex is NOT hard-redirected to www (PWA-safe)',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy',
        false,
        false
    ) === null
);

$assert(
    'optional FORCE_CANONICAL_HOST still redirects browser tabs',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy',
        false,
        true
    ) === 'https://www.jawishco.sy/index.php'
);

$assert(
    'FORCE_CANONICAL_HOST never bounces standalone PWA',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy',
        true,
        true
    ) === null
);

$assert(
    'http redirects to https on same host',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/',
        false,
        'https://www.jawishco.sy',
        false,
        false
    ) === 'https://www.jawishco.sy/'
);

$assert(
    'already https stays put',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/about.php',
        true,
        'https://www.jawishco.sy',
        false,
        false
    ) === null
);

$assert(
    'localhost is never redirected',
    HttpsGate::redirectTarget('localhost', '/', false, 'https://www.jawishco.sy') === null
);

putenv('PORTAL_APP_URL=https://www.jawishco.sy');
$_ENV['PORTAL_APP_URL'] = 'https://www.jawishco.sy';
putenv('PORTAL_COOKIE_DOMAIN');
unset($_ENV['PORTAL_COOKIE_DOMAIN']);
$assert(
    'cookie domain derived as parent .jawishco.sy from www app url',
    CookieOptions::domain() === '.jawishco.sy'
);

putenv('PORTAL_APP_URL=https://jawishco.sy');
$_ENV['PORTAL_APP_URL'] = 'https://jawishco.sy';
$assert(
    'cookie domain derived as .jawishco.sy from apex app url',
    CookieOptions::domain() === '.jawishco.sy'
);

putenv('PORTAL_COOKIE_DOMAIN=none');
$_ENV['PORTAL_COOKIE_DOMAIN'] = 'none';
$assert('cookie domain none disables sharing', CookieOptions::domain() === '');

putenv('PORTAL_COOKIE_DOMAIN=.custom.sy');
$_ENV['PORTAL_COOKIE_DOMAIN'] = '.custom.sy';
$assert('explicit cookie domain wins', CookieOptions::domain() === '.custom.sy');

$helpers = (string) file_get_contents(dirname(__DIR__) . '/views/helpers.php');
$assert(
    'helpers define canonical PWA install origin (www)',
    str_contains($helpers, 'function portal_pwa_install_origin')
    && str_contains($helpers, 'function portal_pwa_is_install_host')
);

putenv('PORTAL_APP_URL=https://www.jawishco.sy');
$_ENV['PORTAL_APP_URL'] = 'https://www.jawishco.sy';
$_SERVER['HTTP_HOST'] = 'www.jawishco.sy';
$_SERVER['HTTPS'] = 'on';
$assert(
    'install origin stays www when app url is www',
    portal_pwa_install_origin() === 'https://www.jawishco.sy'
);
$assert('www request is install host', portal_pwa_is_install_host() === true);

$_SERVER['HTTP_HOST'] = 'jawishco.sy';
$assert(
    'apex request is NOT the install host',
    portal_pwa_is_install_host() === false
);

putenv('PORTAL_APP_URL=https://jawishco.sy');
$_ENV['PORTAL_APP_URL'] = 'https://jawishco.sy';
$assert(
    'apex PORTAL_APP_URL still prefers www install origin',
    portal_pwa_install_origin() === 'https://www.jawishco.sy'
);

$renderManifest = static function (string $host): array {
    $php = escapeshellarg(PHP_BINARY);
    $code = escapeshellarg(
        '$_SERVER["HTTP_HOST"]=' . var_export($host, true) . ';$_SERVER["HTTPS"]="on";'
        . '$_SERVER["REQUEST_URI"]="/manifest.php";include "manifest.php";'
    );
    $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__) . '/public') . " && {$php} -r {$code} 2>/dev/null");
    $decoded = json_decode((string) $out, true);

    return is_array($decoded) ? $decoded : [];
};
foreach (['jawishco.sy', 'www.jawishco.sy'] as $host) {
    $m = $renderManifest($host);
    $origin = 'https://' . $host;
    $assert("{$host} manifest id is relative (matches old WebAPK id {$origin}/?source=pwa)", ($m['id'] ?? null) === '/?source=pwa');
    $assert("{$host} manifest start_url/scope stay on {$host}", ($m['start_url'] ?? '') === $origin . '/?source=pwa' && ($m['scope'] ?? '') === $origin . '/');
    $assert("{$host} manifest is installable standalone (WebAPK update can apply)", ($m['display'] ?? '') === 'standalone');
    $assert("{$host} manifest has no display_override / related-app preference", !isset($m['display_override']) && empty($m['prefer_related_applications']));
    $sameOrigin = true;
    foreach ($m['icons'] ?? [] as $icon) {
        $src = (string) ($icon['src'] ?? '');
        $sameOrigin = $sameOrigin && str_starts_with($src, $origin . '/') && str_contains($src, 'webapk=' . portal_pwa_icon_revision());
    }
    foreach ($m['shortcuts'] ?? [] as $shortcut) {
        $sameOrigin = $sameOrigin && str_starts_with((string) ($shortcut['url'] ?? ''), $origin . '/');
    }
    $assert("{$host} manifest icons/shortcuts are same-origin with bumped icon hash", $sameOrigin && !empty($m['icons']));
    $assert(
        "{$host} manifest declares same-origin webapp related app (for getInstalledRelatedApps)",
        ($m['related_applications'][0]['platform'] ?? '') === 'webapp'
        && ($m['related_applications'][0]['url'] ?? '') === $origin . '/manifest.php'
    );
}

$pwaId = (string) file_get_contents(dirname(__DIR__) . '/public/pwa-id.php');
$assert(
    'pwa-id.php diagnostic is noindex, links /manifest.php and mentions chrome://webapks',
    str_contains($pwaId, 'noindex') && str_contains($pwaId, 'href="/manifest.php"') && str_contains($pwaId, 'chrome://webapks')
);

$staticManifest = (string) file_get_contents(dirname(__DIR__) . '/public/manifest.webmanifest');
$decodedStatic = json_decode($staticManifest, true);
$assert(
    'static webmanifest is host-relative and standalone',
    is_array($decodedStatic)
    && ($decodedStatic['id'] ?? null) === '/?source=pwa'
    && ($decodedStatic['start_url'] ?? null) === '/?source=pwa'
    && ($decodedStatic['display'] ?? null) === 'standalone'
    && !str_contains($staticManifest, 'https://')
);

$pwa = (string) file_get_contents(dirname(__DIR__) . '/public/assets/pwa.js');
$assert(
    'pwa.js upgrades http to https on same host only',
    str_contains($pwa, 'upgradeToHttpsSameHost')
    && str_contains($pwa, "'https://' + host")
);
$assert(
    'pwa.js never soft-bounces www↔apex (heals Custom Tab × bar)',
    !str_contains($pwa, 'needHost')
    && !str_contains($pwa, 'wantHost')
    && str_contains($pwa, 'portal_pwa_host')
);
$assert(
    'pwa.js no longer owns service worker registration, reload-on-controllerchange or a tap-to-update banner',
    !str_contains($pwa, 'showUpdateBanner')
    && !str_contains($pwa, 'SKIP_WAITING')
    && !str_contains($pwa, 'controllerchange')
    && !str_contains($pwa, 'serviceWorker')
    && !str_contains($pwa, 'يتوفر تحديث جديد')
);
$pwaUpdate = (string) file_get_contents(dirname(__DIR__) . '/public/assets/pwa-update.js');
$assert(
    'pwa-update.js is the single registration point: /sw.js?b=<build>, updateViaCache none, zero-touch reload',
    str_contains($pwaUpdate, "'/sw.js?b='")
    && str_contains($pwaUpdate, "updateViaCache: 'none'")
    && str_contains($pwaUpdate, 'PORTAL_SW_ACTIVATED')
    && str_contains($pwaUpdate, '/api/app-version.php')
    && !str_contains($pwaUpdate, 'يتوفر تحديث جديد')
);
$assert(
    'pwa.js has no reinstall/migrate-to-www flow (would leave WebAPK scope)',
    !str_contains($pwa, 'openHealthyWwwInstall')
    && !str_contains($pwa, 'migrate=1')
    && !str_contains($pwa, 'intent://')
    && !str_contains($pwa, 'showShellReplaceBanner')
    && !str_contains($pwa, 'showRemoveOldIconBanner')
);

$sw = (string) file_get_contents(dirname(__DIR__) . '/public/sw.js');
$assert(
    'service worker skips waiting on every install (zero-touch updates, no tap)',
    preg_match("/addEventListener\\('install'.*?self\\.skipWaiting\\(\\)/s", $sw) === 1
    && !str_contains($sw, '!self.registration.active')
    && str_contains($sw, "data.type === 'SKIP_WAITING'")
);
$assert(
    'service worker announces activation and answers GET_BUILD',
    str_contains($sw, 'PORTAL_SW_ACTIVATED')
    && str_contains($sw, "data.type === 'GET_BUILD'")
    && str_contains($sw, 'includeUncontrolled: true')
);

$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$assert(
    'layout early script only upgrades http→https same host',
    str_contains($layout, "protocol === 'http:'")
    && str_contains($layout, "'https://' + host")
    && !str_contains($layout, 'needHost')
);

$assert(
    'sw.js rewrites www↔apex notification URLs onto its own origin',
    str_contains($sw, 'function isSiblingHost')
    && str_contains($sw, 'isSiblingHost(url.hostname, self.location.hostname)')
);
$assert(
    'layout rewrites sibling-host links/forms to current origin',
    str_contains($layout, "'www.' + here")
    && str_contains($layout, "closest('a[href]')")
);
$helpers = (string) file_get_contents(dirname(__DIR__) . '/views/helpers.php');
$assert('share_page_url stays relative', str_contains($helpers, "return \$path . \$separator . http_build_query(\$filtered);") && str_contains($helpers, "\$path = '/s/' . rawurlencode(\$short);"));
$assert('HttpsGate never bounces standalone app host', str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Support/HttpsGate.php'), '&& !$isStandalone'));

echo "\n";
if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "canonical host ok\n";
