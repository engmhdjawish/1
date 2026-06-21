<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Support\BarcodeSvg;

$code = trim((string) ($_GET['code'] ?? $_GET['c'] ?? ''));
$height = max(20, min(200, (int) ($_GET['h'] ?? 48)));
$barWidth = max(1, min(4, (int) ($_GET['bw'] ?? 2)));
$foreground = trim((string) ($_GET['fg'] ?? '#000000'));
$background = trim((string) ($_GET['bg'] ?? 'transparent'));

if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $foreground)) {
    $foreground = '#000000';
}
if ($background !== 'transparent' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $background)) {
    $background = 'transparent';
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo BarcodeSvg::code39($code, $height, $barWidth, $foreground, $background);
