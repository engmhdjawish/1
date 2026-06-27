<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Minimal SVG → GD rasterizer for simple logos (no external binaries).
 */
final class SvgBasicGdRenderer
{
    /** @return \GdImage|false */
    public static function render(string $sourcePath, int $targetSize = 1024)
    {
        $xml = @file_get_contents($sourcePath);
        if (!is_string($xml) || trim($xml) === '') {
            return false;
        }

        $svg = @simplexml_load_string($xml);
        if ($svg === false) {
            $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml) ?? $xml;
            $svg = @simplexml_load_string($xml);
        }
        if ($svg === false) {
            return false;
        }

        $svg->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');
        $attrs = $svg->attributes();
        $viewBox = self::parseViewBox((string) ($attrs->viewBox ?? ''), (float) ($attrs->width ?? 0), (float) ($attrs->height ?? 0));
        if ($viewBox['width'] <= 0 || $viewBox['height'] <= 0) {
            return false;
        }

        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        if ($canvas === false) {
            return false;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetSize, $targetSize, $transparent);
        imagealphablending($canvas, true);

        $scale = min($targetSize / $viewBox['width'], $targetSize / $viewBox['height']);
        $offsetX = ($targetSize - ($viewBox['width'] * $scale)) / 2;
        $offsetY = ($targetSize - ($viewBox['height'] * $scale)) / 2;

        $ctx = [
            'canvas' => $canvas,
            'scale' => $scale,
            'offsetX' => $offsetX,
            'offsetY' => $offsetY,
            'viewBox' => $viewBox,
            'defaultFill' => '',
            'defaultStroke' => '',
        ];

        self::drawNode($svg, $ctx);

        if (!self::hasVisibleInk($canvas)) {
            imagedestroy($canvas);

            return false;
        }

        return $canvas;
    }

    /** @param \GdImage $canvas */
    private static function hasVisibleInk($canvas): bool
    {
        $width = imagesx($canvas);
        $height = imagesy($canvas);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $stepX = max(1, (int) floor($width / 24));
        $stepY = max(1, (int) floor($height / 24));
        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $alpha = (imagecolorat($canvas, $x, $y) >> 24) & 0x7F;
                if ($alpha < 120) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    private static function parseViewBox(string $viewBox, float $width, float $height): array
    {
        $parts = preg_split('/[\s,]+/', trim($viewBox)) ?: [];
        if (count($parts) === 4) {
            return [
                'x' => (float) $parts[0],
                'y' => (float) $parts[1],
                'width' => max(1.0, (float) $parts[2]),
                'height' => max(1.0, (float) $parts[3]),
            ];
        }

        return [
            'x' => 0.0,
            'y' => 0.0,
            'width' => $width > 0 ? $width : 512.0,
            'height' => $height > 0 ? $height : 512.0,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private static function drawNode(\SimpleXMLElement $node, array $ctx): void
    {
        $name = strtolower($node->getName());
        if ($name === 'g' || $name === 'svg') {
            $attrs = $node->attributes();
            $nextCtx = $ctx;
            $fillAttr = self::styleValue($attrs, 'fill');
            if ($fillAttr === '' && isset($attrs->fill)) {
                $fillAttr = trim((string) $attrs->fill);
            }
            if ($fillAttr !== '' && strtolower($fillAttr) !== 'inherit') {
                $nextCtx['defaultFill'] = $fillAttr;
            }
            $strokeAttr = self::styleValue($attrs, 'stroke');
            if ($strokeAttr === '' && isset($attrs->stroke)) {
                $strokeAttr = trim((string) $attrs->stroke);
            }
            if ($strokeAttr !== '' && strtolower($strokeAttr) !== 'inherit') {
                $nextCtx['defaultStroke'] = $strokeAttr;
            }
            foreach (self::childElements($node) as $child) {
                self::drawNode($child, $nextCtx);
            }

            return;
        }

        $attrs = $node->attributes();
        $fillRaw = self::styleValue($attrs, 'fill');
        if ($fillRaw === '' && isset($attrs->fill)) {
            $fillRaw = trim((string) $attrs->fill);
        }
        if ($fillRaw === '' || strtolower($fillRaw) === 'inherit') {
            $fillRaw = (string) ($ctx['defaultFill'] ?? '');
        }
        $strokeRaw = self::styleValue($attrs, 'stroke');
        if ($strokeRaw === '' && isset($attrs->stroke)) {
            $strokeRaw = trim((string) $attrs->stroke);
        }
        if ($strokeRaw === '' || strtolower($strokeRaw) === 'inherit') {
            $strokeRaw = (string) ($ctx['defaultStroke'] ?? '');
        }

        if (strtolower($fillRaw) === 'none') {
            $fillRaw = '';
        }
        if (strtolower($strokeRaw) === 'none') {
            $strokeRaw = '';
        }

        $fill = self::parseColor($fillRaw);
        $stroke = self::parseColor($strokeRaw);
        if ($fill === null && $stroke === null) {
            return;
        }

        /** @var \GdImage $canvas */
        $canvas = $ctx['canvas'];
        $fillColor = $fill !== null
            ? imagecolorallocatealpha($canvas, $fill[0], $fill[1], $fill[2], $fill[3])
            : null;
        $strokeColor = $stroke !== null
            ? imagecolorallocatealpha($canvas, $stroke[0], $stroke[1], $stroke[2], $stroke[3])
            : null;

        if ($name === 'rect') {
            $x = (float) ($attrs->x ?? 0);
            $y = (float) ($attrs->y ?? 0);
            $w = (float) ($attrs->width ?? 0);
            $h = (float) ($attrs->height ?? 0);
            if ($w > 0 && $h > 0 && $fillColor !== null) {
                imagefilledrectangle(
                    $canvas,
                    (int) self::tx($ctx, $x),
                    (int) self::ty($ctx, $y),
                    (int) self::tx($ctx, $x + $w),
                    (int) self::ty($ctx, $y + $h),
                    $fillColor
                );
            } elseif ($w > 0 && $h > 0 && $strokeColor !== null) {
                imagerectangle(
                    $canvas,
                    (int) self::tx($ctx, $x),
                    (int) self::ty($ctx, $y),
                    (int) self::tx($ctx, $x + $w),
                    (int) self::ty($ctx, $y + $h),
                    $strokeColor
                );
            }

            return;
        }

        if ($name === 'circle') {
            $cx = (float) ($attrs->cx ?? 0);
            $cy = (float) ($attrs->cy ?? 0);
            $r = (float) ($attrs->r ?? 0);
            if ($r > 0 && $fillColor !== null) {
                imagefilledellipse(
                    $canvas,
                    (int) self::tx($ctx, $cx),
                    (int) self::ty($ctx, $cy),
                    (int) max(1, round($r * 2 * $ctx['scale'])),
                    (int) max(1, round($r * 2 * $ctx['scale'])),
                    $fillColor
                );
            }

            return;
        }

        if ($name === 'ellipse') {
            $cx = (float) ($attrs->cx ?? 0);
            $cy = (float) ($attrs->cy ?? 0);
            $rx = (float) ($attrs->rx ?? 0);
            $ry = (float) ($attrs->ry ?? 0);
            if ($rx > 0 && $ry > 0 && $fillColor !== null) {
                imagefilledellipse(
                    $canvas,
                    (int) self::tx($ctx, $cx),
                    (int) self::ty($ctx, $cy),
                    (int) max(1, round($rx * 2 * $ctx['scale'])),
                    (int) max(1, round($ry * 2 * $ctx['scale'])),
                    $fillColor
                );
            }

            return;
        }

        if ($name === 'polygon' || $name === 'polyline') {
            $raw = preg_split('/[\s,]+/', trim((string) ($attrs->points ?? ''))) ?: [];
            $points = [];
            for ($i = 0; $i < count($raw) - 1; $i += 2) {
                $points[] = (int) self::tx($ctx, (float) $raw[$i]);
                $points[] = (int) self::ty($ctx, (float) ($raw[$i + 1] ?? 0));
            }
            if (count($points) >= 6) {
                if ($name === 'polygon' && $fillColor !== null) {
                    imagefilledpolygon($canvas, $points, $fillColor);
                } elseif ($strokeColor !== null) {
                    imagesetthickness($canvas, max(1, (int) round($ctx['scale'])));
                    for ($i = 0; $i < count($points) - 2; $i += 2) {
                        imageline($canvas, $points[$i], $points[$i + 1], $points[$i + 2], $points[$i + 3], $strokeColor);
                    }
                    if ($name === 'polygon') {
                        imageline($canvas, $points[count($points) - 2], $points[count($points) - 1], $points[0], $points[1], $strokeColor);
                    }
                }
            }

            return;
        }

        if ($name === 'path') {
            $polygon = self::pathToPolygon((string) ($attrs->d ?? ''), $ctx);
            if (count($polygon) >= 6) {
                if ($fillColor !== null) {
                    imagefilledpolygon($canvas, $polygon, $fillColor);
                } elseif ($strokeColor !== null) {
                    imagesetthickness($canvas, max(1, (int) round($ctx['scale'])));
                    for ($i = 0; $i < count($polygon) - 2; $i += 2) {
                        imageline($canvas, $polygon[$i], $polygon[$i + 1], $polygon[$i + 2], $polygon[$i + 3], $strokeColor);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $ctx @return list<int> */
    private static function pathToPolygon(string $d, array $ctx): array
    {
        $tokens = preg_split('/((?:[MLHVCSQTAZmlhvcsqtaz])|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?)/', $d, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $x = 0.0;
        $y = 0.0;
        $startX = 0.0;
        $startY = 0.0;
        $points = [];
        $i = 0;
        $cmd = '';

        $push = static function (float $px, float $py) use (&$points, $ctx): void {
            $points[] = (int) self::tx($ctx, $px);
            $points[] = (int) self::ty($ctx, $py);
        };

        while ($i < count($tokens)) {
            $token = $tokens[$i];
            if (preg_match('/^[MLHVCSQTAZmlhvcsqtaz]$/', $token) === 1) {
                $cmd = $token;
                $i++;
                continue;
            }

            $rel = strtolower($cmd) === $cmd && $cmd !== '';
            $upper = strtoupper($cmd);

            if ($upper === 'M') {
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                    $y += $startY;
                }
                $startX = $x;
                $startY = $y;
                $push($x, $y);
                $cmd = $rel ? 'l' : 'L';
                continue;
            }

            if ($upper === 'L') {
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                    $y += $startY;
                }
                $startX = $x;
                $startY = $y;
                $push($x, $y);
                continue;
            }

            if ($upper === 'H') {
                $x = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                }
                $startX = $x;
                $push($x, $startY);
                continue;
            }

            if ($upper === 'V') {
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $y += $startY;
                }
                $startY = $y;
                $push($startX, $y);
                continue;
            }

            if ($upper === 'Z') {
                $push($startX, $startY);
                $i++;
                continue;
            }

            if ($upper === 'C') {
                $x1 = (float) $tokens[$i++];
                $y1 = (float) $tokens[$i++];
                $x2 = (float) $tokens[$i++];
                $y2 = (float) $tokens[$i++];
                $x3 = (float) $tokens[$i++];
                $y3 = (float) $tokens[$i++];
                if ($rel) {
                    $x1 += $startX;
                    $y1 += $startY;
                    $x2 += $startX;
                    $y2 += $startY;
                    $x3 += $startX;
                    $y3 += $startY;
                }
                for ($t = 0.1; $t <= 1.0; $t += 0.1) {
                    $px = (1 - $t) ** 3 * $startX + 3 * (1 - $t) ** 2 * $t * $x1 + 3 * (1 - $t) * $t ** 2 * $x2 + $t ** 3 * $x3;
                    $py = (1 - $t) ** 3 * $startY + 3 * (1 - $t) ** 2 * $t * $y1 + 3 * (1 - $t) * $t ** 2 * $y2 + $t ** 3 * $y3;
                    $push($px, $py);
                }
                $startX = $x3;
                $startY = $y3;
                continue;
            }

            $i++;
        }

        return $points;
    }

    /** @return list<int> */
    private static function parsePoints(string $points): array
    {
        $nums = preg_split('/[\s,]+/', trim($points)) ?: [];
        $out = [];
        for ($i = 0; $i < count($nums) - 1; $i += 2) {
            $out[] = (int) round((float) $nums[$i]);
            $out[] = (int) round((float) $nums[$i + 1]);
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int}|null */
    private static function parseColor(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || $value === 'none' || $value === 'transparent') {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $m) === 1) {
            $hex = $m[1];

            return [
                hexdec(str_repeat($hex[0], 2)),
                hexdec(str_repeat($hex[1], 2)),
                hexdec(str_repeat($hex[2], 2)),
                0,
            ];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m) === 1) {
            $hex = $m[1];

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), 0];
        }

        $named = [
            'black' => [0, 0, 0, 0],
            'white' => [255, 255, 255, 0],
            'red' => [220, 38, 38, 0],
            'green' => [22, 163, 74, 0],
            'blue' => [37, 99, 235, 0],
            'currentcolor' => [17, 24, 39, 0],
        ];
        $lower = strtolower($value);

        return $named[$lower] ?? null;
    }

    /** @param array<string, mixed> $ctx */
    private static function tx(array $ctx, float $x): float
    {
        return $ctx['offsetX'] + (($x - $ctx['viewBox']['x']) * $ctx['scale']);
    }

    /** @param array<string, mixed> $ctx */
    private static function ty(array $ctx, float $y): float
    {
        return $ctx['offsetY'] + (($y - $ctx['viewBox']['y']) * $ctx['scale']);
    }

    /** @return iterable<int, \SimpleXMLElement> */
    private static function childElements(\SimpleXMLElement $node): iterable
    {
        $namespaced = $node->children('http://www.w3.org/2000/svg');
        if (count($namespaced) > 0) {
            return $namespaced;
        }

        return $node->children();
    }

    /** @param \SimpleXMLElement|null $attrs */
    private static function styleValue($attrs, string $key): string
    {
        if ($attrs === null) {
            return '';
        }

        $style = trim((string) ($attrs->style ?? ''));
        if ($style === '') {
            return '';
        }

        foreach (explode(';', $style) as $rule) {
            $parts = explode(':', $rule, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (trim($parts[0]) === $key) {
                return trim($parts[1]);
            }
        }

        return '';
    }
}
