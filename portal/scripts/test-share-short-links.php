<?php

declare(strict_types=1);

/**
 * Smoke checks for short share URLs (no DB required for most assertions).
 * Run: php portal/scripts/test-share-short-links.php
 */

$failed = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failed): void {
    if ($actual !== $expected) {
        echo "FAIL {$label}: expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL;
        $failed++;
        return;
    }
    echo "ok   {$label}\n";
};

require dirname(__DIR__) . '/src/Services/ShareLinkService.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Services\ShareLinkService;

$check(
    'normalize short code',
    ShareLinkService::normalizeShortCode('Summer_24'),
    'summer_24'
);
$check(
    'reject bad short code',
    ShareLinkService::normalizeShortCode('ab'),
    null
);
$check(
    'publicPath prefers short',
    ShareLinkService::publicPathForLink([
        'short_code' => 'offer8',
        'public_token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]),
    '/s/offer8'
);
$check(
    'publicPath falls back to token',
    ShareLinkService::publicPathForLink([
        'short_code' => '',
        'public_token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]),
    '/share.php?token=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
);

$check(
    'token from /s/ path',
    share_token_from_return_url('/s/summer24'),
    'summer24'
);
$check(
    'token from share.php?t=',
    share_token_from_return_url('/share.php?t=summer24&q=x'),
    'summer24'
);
$check(
    'token from share.php?token=',
    share_token_from_return_url('/share.php?token=abcdef12'),
    'abcdef12'
);
$check(
    'token from s.php?c=',
    share_token_from_return_url('/s.php?c=xyz9'),
    'xyz9'
);
$check(
    'catalog share return uses /s/',
    catalog_share_return_url([
        'public_path' => '/s/offer8?q=ignored',
        'short_code' => 'offer8',
        'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]),
    '/s/offer8'
);
$check(
    'return label for /s/',
    return_link_label('/s/offer8'),
    'العودة لرابط المشاركة'
);

$sPhp = file_get_contents(dirname(__DIR__) . '/public/s.php') ?: '';
$sharePhp = file_get_contents(dirname(__DIR__) . '/public/share.php') ?: '';
$nginx = file_get_contents(dirname(__DIR__, 2) . '/deploy/templates/portal/nginx-site.conf.template') ?: '';
$migration = file_get_contents(dirname(__DIR__, 2) . '/docs/portal-migrations/027-share-link-short-codes.sql') ?: '';

$check('s.php exists', str_contains($sPhp, 'share.php'), true);
$check('share.php accepts c/t', str_contains($sharePhp, "\$_GET['c']"), true);
$check('share.php resolves access key', str_contains($sharePhp, 'resolvePublicAccessKey'), true);
$check('nginx /s/ location', str_contains($nginx, 'share_code'), true);
$check('migration short_code', str_contains($migration, 'short_code'), true);

$helpers = file_get_contents(dirname(__DIR__) . '/views/helpers.php') ?: '';
$check('helpers share_compact_url', str_contains($helpers, 'function share_compact_url'), true);
$check('helpers catalog_share_return_url', str_contains($helpers, 'function catalog_share_return_url'), true);

$dashForm = file_get_contents(dirname(__DIR__) . '/views/dashboard/partials/share-link-form-panel.php') ?: '';
$check('dashboard short_code field', str_contains($dashForm, 'name="short_code"'), true);

echo $failed === 0 ? "\nshare short links ok\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
