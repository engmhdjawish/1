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

$pwa = (string) file_get_contents(dirname(__DIR__) . '/public/assets/pwa.js');
$assert(
    'pwa.js redirects to canonical host',
    str_contains($pwa, 'canonicalHost') || str_contains($pwa, 'wantHost')
);

echo "\n";
if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "canonical host ok\n";
