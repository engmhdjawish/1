<?php

declare(strict_types=1);

/**
 * Canonical host / cookie domain for www vs apex.
 * Usage: php scripts/test-canonical-host.php
 */

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

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

$manifest = (string) file_get_contents(dirname(__DIR__) . '/public/manifest.php');
$assert(
    'manifest advertises related_applications webapp',
    str_contains($manifest, 'related_applications') && str_contains($manifest, "'platform' => 'webapp'")
);
$assert(
    'manifest id stays relative (stable app identity)',
    str_contains($manifest, "'id' => \$startPath") || str_contains($manifest, "'id' => '/?source=pwa'")
);
$assert(
    'manifest does not bake absolute start_url into id',
    !str_contains($manifest, "'id' => \$startUrl")
);
$assert(
    'manifest start_url follows the request host (heals apex WebAPK)',
    str_contains($manifest, 'portal_request_origin')
    && str_contains($manifest, "'start_url' => \$startUrl")
);
$assert(
    'manifest related_applications include request host',
    str_contains($manifest, 'siblingOrigin') || str_contains($manifest, 'related_applications')
);
$assert(
    'manifest prefers opening links in the installed app',
    str_contains($manifest, "'handle_links' => 'preferred'")
);
$assert(
    'manifest stays standalone without browser chrome override',
    str_contains($manifest, "'display' => 'standalone'")
    && !str_contains($manifest, "'display_override'")
);

$staticManifest = (string) file_get_contents(dirname(__DIR__) . '/public/manifest.webmanifest');
$decodedStatic = json_decode($staticManifest, true);
$assert(
    'static webmanifest id is relative',
    is_array($decodedStatic) && ($decodedStatic['id'] ?? null) === '/?source=pwa'
);
$assert(
    'static webmanifest handle_links preferred',
    is_array($decodedStatic) && ($decodedStatic['handle_links'] ?? null) === 'preferred'
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
    'pwa.js prompts in-app update when a new service worker waits',
    str_contains($pwa, 'showUpdateBanner')
    && str_contains($pwa, 'SKIP_WAITING')
    && str_contains($pwa, 'يتوفر تحديث جديد')
);
$assert(
    'pwa.js detects broken Custom Tab homescreen (source=pwa, not standalone)',
    str_contains($pwa, 'looksLikeBrokenHomescreenLaunch')
    && str_contains($pwa, "params.get('source') === 'pwa'")
    && str_contains($pwa, 'replaceBrokenShell')
);
$assert(
    'pwa.js migrates broken apex shell to www install (SW cannot heal ×)',
    str_contains($pwa, 'shouldMigrateToWwwSibling')
    && str_contains($pwa, 'openHealthyWwwInstall')
    && str_contains($pwa, 'install=1&migrate=1')
    && str_contains($pwa, 'التطبيق يحتاج إصلاحاً')
);
$assert(
    'pwa.js does not treat empty-referrer browser tabs as broken shells',
    !str_contains($pwa, "(display-mode: browser)').matches && ref === ''")
);
$assert(
    'pwa.js replaces broken old shell (different icon / × bar) instead of SW-only reload',
    str_contains($pwa, 'showShellReplaceBanner')
    && str_contains($pwa, 'replaceBrokenShell')
    && str_contains($pwa, 'التطبيق يحتاج إصلاحاً')
);

$sw = (string) file_get_contents(dirname(__DIR__) . '/public/sw.js');
$assert(
    'service worker waits for user SKIP_WAITING on updates',
    str_contains($sw, "data.type !== 'SKIP_WAITING'")
    && str_contains($sw, '!self.registration.active')
);

$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$assert(
    'layout early script only upgrades http→https same host',
    str_contains($layout, "protocol === 'http:'")
    && str_contains($layout, "'https://' + host")
    && !str_contains($layout, 'needHost')
);

echo "\n";
if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "canonical host ok\n";
