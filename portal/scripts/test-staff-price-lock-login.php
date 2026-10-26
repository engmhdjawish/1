<?php

declare(strict_types=1);

/**
 * Staff must not be sent to customer-login for «إظهار السعر»,
 * and opening customer-login must not wipe the staff session on GET.
 * Usage: php scripts/test-staff-price-lock-login.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/views/helpers.php';

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$helpers = (string) file_get_contents(dirname(__DIR__) . '/views/helpers.php');
$assert(
    'price lock auth recognizes staff',
    str_contains($helpers, "return 'staff'")
    && str_contains($helpers, 'WebSession::check()')
);
$assert(
    'staff price lock hint never points at customer-login',
    str_contains($helpers, 'Staff must never be sent to customer-login')
    && str_contains($helpers, 'السعر مخفي في سياسة الزائر')
);

$loginPhp = (string) file_get_contents(dirname(__DIR__) . '/public/login.php');
$assert(
    'login.php only clears the other role on POST',
    str_contains($loginPhp, "REQUEST_METHOD'] === 'POST'")
    && str_contains($loginPhp, 'Merely opening')
    && str_contains($loginPhp, "Opposite role is already signed in")
);
$assert(
    'login.php no longer logs staff out on every customer-login GET',
    !preg_match('/^if \(\$type === \'customer\' && WebSession::check\(\)\) \{\s*WebSession::logout\(\);/m', $loginPhp)
);

$catalog = (string) file_get_contents(dirname(__DIR__) . '/src/Services/StoreCatalogService.php');
$assert(
    'staff viewers get show_price even when guest policy hides it',
    str_contains($catalog, '$staffViewer')
    && str_contains($catalog, 'customer-login «إظهار السعر» CTA')
);

$previewJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/store-product-preview.js');
$cartJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/store-cart.js');
$assert(
    'preview JS treats staff auth as a note, not a login link',
    str_contains($previewJs, "auth === 'staff'")
    && !preg_match("/auth === 'staff'[\\s\\S]{0,200}customer-login\\.php/", $previewJs)
);
$assert(
    'cart JS treats staff auth as a note, not a login link',
    str_contains($cartJs, "auth === 'staff'")
    && !preg_match("/auth === 'staff'[\\s\\S]{0,200}customer-login\\.php/", $cartJs)
);

$guestHint = portal_price_lock_hint('/store.php');
$assert('guest still gets customer-login CTA', ($guestHint['href'] ?? '') !== '' && str_contains((string) $guestHint['href'], 'customer-login.php'));
$assert('guest auth is guest', portal_price_lock_auth() === 'guest');

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}

echo "\nAll staff price-lock login checks passed.\n";
