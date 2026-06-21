<?php

declare(strict_types=1);

namespace Portal\Support;

final class BarcodeSvg
{
    /** @var array<string, string> */
    private const CODE39 = [
        '0' => '000110100', '1' => '100100001', '2' => '001100001', '3' => '101100000',
        '4' => '000110001', '5' => '100110000', '6' => '001110000', '7' => '000100101',
        '8' => '100100100', '9' => '001100100', 'A' => '100001001', 'B' => '001001001',
        'C' => '101001000', 'D' => '000011001', 'E' => '100011000', 'F' => '001011000',
        'G' => '000001101', 'H' => '100001100', 'I' => '001001100', 'J' => '000011100',
        'K' => '100000011', 'L' => '001000011', 'M' => '101000010', 'N' => '000010011',
        'O' => '100010010', 'P' => '001010010', 'Q' => '000000111', 'R' => '100000110',
        'S' => '001000110', 'T' => '000010110', 'U' => '110000001', 'V' => '011000001',
        'W' => '111000000', 'X' => '010010001', 'Y' => '110010000', 'Z' => '011010000',
        '-' => '010000101', '.' => '110000100', ' ' => '011000100', '$' => '010101000',
        '/' => '010100010', '+' => '010001010', '%' => '000101010', '*' => '010010100',
    ];

    public static function code39(string $text, int $height = 48, int $barWidth = 2, string $foreground = '#000000', string $background = 'transparent'): string
    {
        $text = strtoupper(trim($text));
        if ($text === '') {
            return self::emptySvg($height);
        }

        $encoded = '*';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (!isset(self::CODE39[$char])) {
                continue;
            }
            $encoded .= $char;
        }
        $encoded .= '*';

        $patterns = [];
        $encodedLength = strlen($encoded);
        for ($i = 0; $i < $encodedLength; $i++) {
            $char = $encoded[$i];
            if (!isset(self::CODE39[$char])) {
                continue;
            }
            $patterns[] = self::CODE39[$char];
        }

        if ($patterns === []) {
            return self::emptySvg($height);
        }

        $narrow = max(1, $barWidth);
        $wide = $narrow * 3;
        $x = 0;
        $bars = [];
        foreach ($patterns as $pattern) {
            $isBar = true;
            $patternLength = strlen($pattern);
            for ($i = 0; $i < $patternLength; $i++) {
                $width = $pattern[$i] === '1' ? $wide : $narrow;
                if ($isBar) {
                    $bars[] = sprintf('<rect x="%d" y="0" width="%d" height="%d" fill="%s"/>', $x, $width, $height, htmlspecialchars($foreground, ENT_QUOTES, 'UTF-8'));
                }
                $x += $width;
                $isBar = !$isBar;
            }
            $x += $narrow;
        }

        $totalWidth = max(1, $x);
        $bg = $background !== 'transparent'
            ? sprintf('<rect width="%d" height="%d" fill="%s"/>', $totalWidth, $height, htmlspecialchars($background, ENT_QUOTES, 'UTF-8'))
            : '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="%s">%s%s</svg>',
            $totalWidth,
            $height,
            $totalWidth,
            $height,
            htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
            $bg,
            implode('', $bars)
        );
    }

    private static function emptySvg(int $height): string
    {
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 %d" width="1" height="%d"></svg>', $height, $height);
    }
}
