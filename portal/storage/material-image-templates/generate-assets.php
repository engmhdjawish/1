<?php

declare(strict_types=1);

/**
 * Generates default PNG layers for the material image template.
 * Replace accent.png, logo.png, and footer-overlay.png with your design files when ready.
 */

$dir = __DIR__;
$fontCandidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    'C:\\Windows\\Fonts\\tahoma.ttf',
];
$font = null;
foreach ($fontCandidates as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD extension is required.\n");
    exit(1);
}

function savePng(\GdImage $image, string $path): void
{
    imagesavealpha($image, true);
    if (!imagepng($image, $path)) {
        throw new RuntimeException('Failed to write ' . $path);
    }
    imagedestroy($image);
}

function drawCenteredLabel(
    \GdImage $canvas,
    string $font,
    int $fontSize,
    string $text,
    int $x,
    int $y,
    int $width,
    int $color
): void {
    $box = imagettfbbox($fontSize, 0, $font, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
    $textWidth = abs($box[2] - $box[0]);
    $drawX = $x + (int) floor(($width - $textWidth) / 2);
    imagettftext($canvas, $fontSize, 0, $drawX, $y, $color, $font, $text);
}

// accent.png — red brush stroke on the left
$accentW = 260;
$accentH = 380;
$accent = imagecreatetruecolor($accentW, $accentH);
imagealphablending($accent, false);
imagesavealpha($accent, true);
$transparent = imagecolorallocatealpha($accent, 0, 0, 0, 127);
imagefilledrectangle($accent, 0, 0, $accentW, $accentH, $transparent);
$red = imagecolorallocate($accent, 196, 30, 58);
imagesetthickness($accent, 18);
for ($i = 0; $i < 8; $i++) {
    $x1 = 40 + ($i * 3);
    $y1 = 20 + ($i * 8);
    $x2 = 120 + ($i * 2);
    $y2 = 300 - ($i * 6);
    imageline($accent, $x1, $y1, $x2, $y2, $red);
}
imageline($accent, 55, 40, 170, 95, $red);
imageline($accent, 70, 110, 190, 180, $red);
savePng($accent, $dir . DIRECTORY_SEPARATOR . 'accent.png');

// logo.png — simplified Jawish Trading mark
$logoW = 200;
$logoH = 120;
$logo = imagecreatetruecolor($logoW, $logoH);
imagealphablending($logo, false);
imagesavealpha($logo, true);
$transparent = imagecolorallocatealpha($logo, 0, 0, 0, 127);
imagefilledrectangle($logo, 0, 0, $logoW, $logoH, $transparent);
$white = imagecolorallocate($logo, 255, 255, 255);
$red = imagecolorallocate($logo, 196, 30, 58);
if ($font !== null) {
    imagettftext($logo, 42, -8, 18, 52, $white, $font, 'jw');
    imagettftext($logo, 16, 0, 24, 82, $white, $font, 'JAWISH');
    imagettftext($logo, 13, 0, 24, 104, $red, $font, 'TRADING');
} else {
    imagestring($logo, 5, 20, 20, 'JAWISH', $white);
    imagestring($logo, 3, 20, 70, 'TRADING', $red);
}
savePng($logo, $dir . DIRECTORY_SEPARATOR . 'logo.png');

// footer-overlay.png — static Arabic column headers
$width = 1080;
$height = 1080;
$footerY = 770;
$overlay = imagecreatetruecolor($width, $height);
imagealphablending($overlay, false);
imagesavealpha($overlay, true);
$transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
imagefilledrectangle($overlay, 0, 0, $width, $height, $transparent);
$white = imagecolorallocate($overlay, 255, 255, 255);
$divider = imagecolorallocate($overlay, 220, 220, 220);

if ($font !== null) {
    drawCenteredLabel($overlay, $font, 24, 'التعبئة', 40, $footerY + 52, 340, $white);
    drawCenteredLabel($overlay, $font, 22, 'رمز و اسم المنتج', 420, $footerY + 52, 420, $white);
} else {
    imagestring($overlay, 4, 120, $footerY + 30, 'Packaging', $white);
    imagestring($overlay, 4, 520, $footerY + 30, 'Code + Name', $white);
}

imageline($overlay, 412, $footerY + 24, 412, $footerY + 286, $divider);
savePng($overlay, $dir . DIRECTORY_SEPARATOR . 'footer-overlay.png');

echo "Generated template assets in {$dir}\n";
