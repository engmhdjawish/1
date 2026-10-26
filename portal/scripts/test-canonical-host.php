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
    'www visitor redirects to apex when PORTAL_APP_URL is apex',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/store.php?q=1',
        true,
        'https://jawishco.sy'
    ) === 'https://jawishco.sy/store.php?q=1'
);

$assert(
    'apex visitor redirects to www when PORTAL_APP_URL is www',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy'
    ) === 'https://www.jawishco.sy/index.php'
);

$assert(
    'http redirects to https on preferred host',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/',
        false,
        'https://www.jawishco.sy'
    ) === 'https://www.jawishco.sy/'
);

$assert(
    'already canonical https stays put',
    HttpsGate::redirectTarget(
        'www.jawishco.sy',
        '/about.php',
        true,
        'https://www.jawishco.sy'
    ) === null
);

$assert(
    'standalone PWA on apex is not bounced to www',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy',
        true
    ) === null
);

$assert(
    'browser tab on apex still redirects to www',
    HttpsGate::redirectTarget(
        'jawishco.sy',
        '/index.php',
        true,
        'https://www.jawishco.sy',
        false
    ) === 'https://www.jawishco.sy/index.php'
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
    'pwa.js redirects to canonical host',
    str_contains($pwa, 'canonicalHost') || str_contains($pwa, 'wantHost')
);
$assert(
    'pwa.js does not bounce installed apps between www/apex',
    str_contains($pwa, '!isStandalone') && str_contains($pwa, 'portal_pwa_host')
);

$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$assert(
    'layout early redirect skips standalone host bounce',
    str_contains($layout, '!standalone') && str_contains($layout, 'display-mode: standalone')
);

echo "\n";
if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "canonical host ok\n";
