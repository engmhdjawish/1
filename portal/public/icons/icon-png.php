<?php

declare(strict_types=1);

$size = (int) ($_GET['size'] ?? 192);
$size = in_array($size, [192, 512], true) ? $size : 192;

if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/svg+xml; charset=utf-8');
    readfile(__DIR__ . '/app-icon.svg');
    exit;
}

$image = imagecreatetruecolor($size, $size);
if ($image === false) {
    http_response_code(500);
    exit;
}

imagealphablending($image, true);
imagesavealpha($image, true);

$red = imagecolorallocate($image, 216, 25, 33);
$white = imagecolorallocate($image, 255, 255, 255);
imagefilledrectangle($image, 0, 0, $size, $size, $red);

$radius = (int) round($size * 0.18);
for ($y = 0; $y < $radius; $y++) {
    for ($x = 0; $x < $radius; $x++) {
        $dx = $radius - $x;
        $dy = $radius - $y;
        if (($dx * $dx + $dy * $dy) > ($radius * $radius)) {
            imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagesetpixel($image, $size - 1 - $x, $y, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagesetpixel($image, $x, $size - 1 - $y, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagesetpixel($image, $size - 1 - $x, $size - 1 - $y, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
    }
}

$fontSize = (int) round($size * 0.42);
$font = 5;
if ($size >= 512) {
    $font = 5;
} elseif ($size >= 192) {
    $font = 5;
}
$text = 'J';
$textWidth = imagefontwidth($font) * strlen($text);
$textHeight = imagefontheight($font);
imagestring(
    $image,
    $font,
    (int) (($size - $textWidth) / 2),
    (int) (($size - $textHeight) / 2),
    $text,
    $white
);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($image);
imagedestroy($image);
