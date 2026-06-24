<?php

declare(strict_types=1);

/** @var string|null $companyLogoUrl */
/** @var string|null $siteName */

require_once __DIR__ . '/../helpers.php';

$siteName = trim((string) ($siteName ?? '')) !== '' ? (string) $siteName : 'جاويش للتجارة';
$icons = portal_site_icons($companyLogoUrl ?? null);
?>
<link rel="manifest" href="/manifest.php">
<link rel="icon" href="<?= h($icons['favicon_ico']) ?>" sizes="48x48">
<link rel="icon" href="<?= h($icons['favicon_svg']) ?>" type="image/svg+xml">
<link rel="icon" href="<?= h($icons['favicon_png_32']) ?>" type="image/png"<?= $icons['uses_company_logo'] ? '' : ' sizes="32x32"' ?>>
<link rel="apple-touch-icon" href="<?= h($icons['apple_touch']) ?>" sizes="180x180">
<meta name="theme-color" content="#D81921">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
