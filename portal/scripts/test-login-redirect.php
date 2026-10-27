<?php

declare(strict_types=1);

/**
 * Where a successful login sends the browser, and what the customer login page renders.
 * Needs no database. Run: php portal/scripts/test-login-redirect.php
 */

define('PORTAL_NO_SESSION', true);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Support\PortalUrl;

$failed = 0;
$assert = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
    if ($condition) {
        echo "ok   {$label}\n";

        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
};

echo "== safeRedirectPath ==\n";
$cases = [
    'plain page' => ['/index.php', '/index.php'],
    'installed-app start url' => ['/?source=pwa', '/?source=pwa'],
    'query with encoded arabic' => ['/store.php?q=%D8%B3&page=2', '/store.php?q=%D8%B3&page=2'],
    'dashboard page' => ['/dashboard/orders.php', '/dashboard/orders.php'],
    'surrounding whitespace is trimmed' => ["  /store.php\n", '/store.php'],
    'encoded backslash stays an inert same-origin path' => ['/%5Cevil.com', '/%5Cevil.com'],
    'empty' => ['', null],
    'only spaces' => ['   ', null],
    'null' => [null, null],
    'relative without slash' => ['index.php', null],
    'javascript scheme' => ['javascript:alert(1)', null],
    'absolute url' => ['https://evil.com/', null],
    'protocol-relative' => ['//evil.com', null],
    'scheme hidden in the path' => ['/a://b', null],
    'backslash host (browsers read it as //)' => ['/\\evil.com', null],
    'backslash later in the path' => ['/store.php\\..\\evil', null],
    'tab inside (browsers delete it, leaving //)' => ["/\t/evil.com", null],
    'newline inside' => ["/\n/evil.com", null],
    'carriage return inside' => ["/\r/evil.com", null],
    'nul byte' => ["/store.php\0.png", null],
    'delete character' => ["/store\x7F.php", null],
    'login page (loop)' => ['/login.php', null],
    'customer login page with query (loop)' => ['/customer-login.php?redirect=/x', null],
    'staff login page (loop)' => ['/staff-login.php', null],
];
foreach ($cases as $label => [$input, $expected]) {
    $actual = PortalUrl::safeRedirectPath($input);
    $assert("safeRedirectPath: {$label}", $actual === $expected, json_encode([$input, $actual], JSON_UNESCAPED_SLASHES));
}

echo "== where a login lands ==\n";
$targets = [
    ['customer', null, '/index.php'],
    ['customer', '', '/index.php'],
    ['customer', '/store.php', '/store.php'],
    ['customer', '/?source=pwa', '/?source=pwa'],
    ['customer', '/\\evil.com', '/index.php'],
    ['customer', 'https://evil.com', '/index.php'],
    ['customer', '/customer-login.php', '/index.php'],
    ['staff', null, '/dashboard/index.php'],
    ['staff', '/store.php', '/dashboard/index.php'],
    ['staff', '/dashboard/orders.php', '/dashboard/orders.php'],
    ['staff', '/\\evil.com', '/dashboard/index.php'],
];
foreach ($targets as [$type, $raw, $expected]) {
    $actual = PortalUrl::loginRedirectTarget($type, $raw);
    $assert("loginRedirectTarget({$type}, " . json_encode($raw) . ") = {$expected}", $actual === $expected, $actual);
    $assert("  ...and it is a same-origin path", str_starts_with($actual, '/') && !str_starts_with($actual, '//') && !str_contains($actual, '://'));
}
$assert('loginUrl keeps a safe return path', PortalUrl::loginUrl('customer', '/store.php?x=1') === '/customer-login.php?redirect=' . rawurlencode('/store.php?x=1'));
$assert('loginUrl drops an unsafe return path', PortalUrl::loginUrl('customer', '//evil.com') === '/customer-login.php');
$assert('loginUrl drops a backslash return path', PortalUrl::loginUrl('staff', '/\\evil.com') === '/staff-login.php');

echo "== login endpoints answer with relative redirects only ==\n";
$loginSource = (string) file_get_contents(dirname(__DIR__) . '/public/login.php');
preg_match_all("/header\('Location: '[^;]*;/", $loginSource, $locations);
$assert('login.php sets Location headers', count($locations[0]) >= 6, (string) count($locations[0]));
$absolute = array_filter($locations[0], static fn (string $line): bool => preg_match('#Location: https?://#i', $line) === 1);
$assert('no Location header in login.php hard-codes a host', $absolute === [], implode(' | ', $absolute));
$assert('successful logins redirect through loginRedirectTarget()', substr_count($loginSource, 'PortalUrl::loginRedirectTarget(') >= 4);

echo "== customer login page markup ==\n";
$render = static function (?string $redirect) use (&$loginPagePath, &$type, &$error, &$message): string {
    $type = 'customer';
    $error = null;
    $message = null;
    $loginPagePath = '/customer-login.php';
    ob_start();
    include dirname(__DIR__) . '/views/login.php';

    return (string) ob_get_clean();
};
$html = $render('/store.php');
$assert('form posts to the relative customer login path', str_contains($html, 'action="/customer-login.php"'));
$assert('hidden redirect field carries the return path', str_contains($html, 'name="redirect" value="/store.php"'));
$assert('customer form has the JS mount point', str_contains($html, 'data-customer-login-mount'));
$noscript = '';
if (preg_match('#<noscript>(.*?)</noscript>#s', $html, $m) === 1) {
    $noscript = $m[1];
}
$assert('<noscript> block holds a complete form body (phone, password, submit)', str_contains($noscript, 'name="customer_phone"') && str_contains($noscript, 'name="customer_password"') && str_contains($noscript, 'type="submit"'));
$assert('inline fallback reveals that markup when login-page.js did not mount', str_contains($html, "mount.innerHTML = fallback.textContent") && str_contains($html, "getAttribute('data-ready') === '1'"));
$assert('fallback never fights login-page.js: it marks the mount ready like the script does', str_contains($html, "mount.setAttribute('data-ready', '1')"));
$assert('page without a return path has no hidden redirect field', !str_contains($render(null), 'name="redirect"'));
$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$assert('layout loads login-page.js through the versioned deferred loader', str_contains($layout, "portal_defer_script('/assets/login-page.js')"));

echo $failed === 0 ? "\nAll login redirect checks passed.\n" : "\n{$failed} login redirect check(s) FAILED.\n";
exit($failed === 0 ? 0 : 1);
