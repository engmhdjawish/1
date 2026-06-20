<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Arabic glyph joining for PHP GD (based on ar-php utf8Glyphs, LGPL).
 *
 * @see https://github.com/khaled-alshamaa/ar-php
 */
final class ArabicGlyphsEngine
{
    private const VOWELS = 'ًٌٍَُِّْ';
    private const OPEN_RANGE = ')]>}';
    private const CLOSE_RANGE = '([<{';

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, prevLink: bool, nextLink: bool}>|null */
    private static ?array $glyphs = null;

    public static function shape(string $text, bool $forceRtl = true): string
    {
        $text = trim($text);
        if ($text === '' || !ArabicGdText::containsArabic($text)) {
            return $text;
        }

        self::loadGlyphs();

        $harakat = ['َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ'];
        $pairs = [];
        foreach ($harakat as $haraka) {
            $pairs["ّ{$haraka}"] = "{$haraka}ّ";
        }
        $text = strtr($text, $pairs);

        $segments = self::identifyArabicSegments($text);
        if ($segments === []) {
            return $text;
        }

        $rtl = $forceRtl || ($segments[0] === 0);
        $blocks = [];

        if ($segments[0] !== 0) {
            $blocks[] = substr($text, 0, $segments[0]);
        }

        $max = count($segments);
        if ($rtl) {
            for ($i = 0; $i < $max; $i += 2) {
                $segments[$i] = strlen(preg_replace('/\)\s*$/', '', substr($text, 0, $segments[$i])) ?: '');
            }
        }

        for ($i = 0; $i < $max; $i += 2) {
            $fragment = substr($text, $segments[$i], $segments[$i + 1] - $segments[$i]);
            $blocks[] = self::preConvert($fragment);

            if ($i + 2 < $max) {
                $blocks[] = substr($text, $segments[$i + 1], $segments[$i + 2] - $segments[$i + 1]);
            } elseif ($segments[$i + 1] !== strlen($text)) {
                $blocks[] = substr($text, $segments[$i + 1]);
            }
        }

        if ($rtl) {
            $blocks = array_reverse($blocks);
        }

        return implode('', $blocks);
    }

    /** @return list<int> */
    private static function identifyArabicSegments(string $str): array
    {
        $minAr = 55424;
        $maxAr = 55743;
        $probAr = false;
        $arFlag = false;
        $refs = [];
        $max = strlen($str);
        $ascii = unpack('C*', $str) ?: [];

        $i = -1;
        while (++$i < $max) {
            $cDec = $ascii[$i + 1] ?? 0;

            if ($cDec >= 33 && $cDec <= 58) {
                continue;
            }

            if (!$probAr && ($cDec === 216 || $cDec === 217)) {
                $probAr = true;
                continue;
            }

            $pDec = $i > 0 ? ($ascii[$i] ?? null) : null;

            if ($probAr) {
                $utfDecCode = ((int) $pDec << 8) + $cDec;
                if ($utfDecCode >= $minAr && $utfDecCode <= $maxAr) {
                    if (!$arFlag) {
                        $arFlag = true;
                        $sp = strlen(rtrim(substr($str, 0, max(0, $i - 1)))) - 1;
                        $refs[] = ($sp >= 0 && ($str[$sp] ?? '') === '(') ? $sp : $i - 1;
                    }
                } elseif ($arFlag) {
                    $arFlag = false;
                    $refs[] = $i - 1;
                }

                $probAr = false;
                continue;
            }

            if ($arFlag && !preg_match('/^\s$/u', $str[$i] ?? '')) {
                $arFlag = false;
                $sp = $i - strlen(rtrim(substr($str, 0, $i)));
                $refs[] = $i - $sp;
            }
        }

        if ($arFlag) {
            $refs[] = $max;
        }

        return $refs;
    }

    private static function preConvert(string $str): string
    {
        self::loadGlyphs();

        $output = '';
        $number = '';
        $nextChar = null;
        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $max = count($chars);

        for ($i = $max - 1; $i >= 0; $i--) {
            $current = $chars[$i];
            $form = 0;

            $prevChar = ' ';
            if ($i > 0) {
                $prevChar = $chars[$i - 1];
                if (mb_strpos(self::VOWELS, $prevChar) !== false && $i > 1) {
                    $prevChar = $chars[$i - 2];
                    if (mb_strpos(self::VOWELS, $prevChar) !== false && $i > 2) {
                        $prevChar = $chars[$i - 3];
                    }
                }
            }

            if (is_numeric($current)) {
                $number = $current . $number;
                continue;
            }
            if ($number !== '') {
                $output .= $number;
                $number = '';
            }

            $range = self::OPEN_RANGE . self::CLOSE_RANGE;
            $pos = mb_strpos($range, $current);
            if ($pos !== false) {
                $output .= mb_substr(self::CLOSE_RANGE . self::OPEN_RANGE, $pos, 1);
                continue;
            }

            if (ord($current) < 128) {
                $output .= $current;
                $nextChar = $current;
                continue;
            }

            if ($current === 'ل' && $nextChar !== null && mb_strpos('آأإا', $nextChar) !== false) {
                $ligature = $current . $nextChar;
                if ($nextChar !== '') {
                    $output = mb_substr($output, 0, mb_strlen($output) - mb_strlen($nextChar));
                }
                $prevLinks = self::$glyphs[$prevChar]['prevLink'] ?? false;
                $formIndex = $prevLinks ? 1 : 0;
                $output .= self::$glyphs[$ligature][$formIndex] ?? ($current . $nextChar);
                if ($prevChar === 'ل' && isset($chars[$i - 2])) {
                    $tmpForm = (self::$glyphs[$chars[$i - 2]]['prevLink'] ?? false) ? 3 : 2;
                    $output .= self::$glyphs[$prevChar][$tmpForm] ?? $prevChar;
                    $i--;
                }
                continue;
            }

            if (mb_strpos(self::VOWELS, $current) !== false) {
                $output .= $current;
                continue;
            }

            if (($selfPrev = self::$glyphs[$prevChar]['prevLink'] ?? false) === true) {
                $form++;
            }
            if ($nextChar !== null && (self::$glyphs[$nextChar]['nextLink'] ?? false) === true) {
                $form += 2;
            }

            $output .= self::$glyphs[$current][$form] ?? $current;
            $nextChar = $current;
        }

        if ($number !== '') {
            $output .= $number;
        }

        return $output;
    }

    private static function loadGlyphs(): void
    {
        if (self::$glyphs !== null) {
            return;
        }

        $path = __DIR__ . '/ar_glyphs.json';
        if (!is_file($path)) {
            self::$glyphs = [];

            return;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            self::$glyphs = [];

            return;
        }

        $glyphs = [];
        foreach ($raw as $char => $forms) {
            if (!is_array($forms)) {
                continue;
            }
            $glyphs[$char] = [
                'prevLink' => (bool) ($forms['prevLink'] ?? false),
                'nextLink' => (bool) ($forms['nextLink'] ?? false),
                0 => self::hexChar((string) ($forms['0'] ?? '')),
                1 => self::hexChar((string) ($forms['1'] ?? '')),
                2 => self::hexChar((string) ($forms['2'] ?? '')),
                3 => self::hexChar((string) ($forms['3'] ?? '')),
            ];
        }

        self::$glyphs = $glyphs;
    }

    private static function hexChar(string $hex): string
    {
        $hex = ltrim($hex, '0x');
        if ($hex === '') {
            return '';
        }

        return mb_chr((int) hexdec($hex), 'UTF-8');
    }
}
