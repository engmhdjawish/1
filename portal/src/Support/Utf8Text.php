<?php

declare(strict_types=1);

namespace Portal\Support;

/** UTF-8 helpers that work without the mbstring extension. */
final class Utf8Text
{
    /** @return list<string> */
    public static function chars(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function length(string $text): int
    {
        return count(self::chars($text));
    }

    public static function substr(string $text, int $start, ?int $length = null): string
    {
        $chars = self::chars($text);
        if ($chars === []) {
            return '';
        }

        if ($start < 0) {
            $start = max(0, count($chars) + $start);
        }

        $slice = $length === null
            ? array_slice($chars, $start)
            : array_slice($chars, $start, max(0, $length));

        return implode('', $slice);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return str_contains($haystack, $needle);
    }

    public static function codepoint(string $char): int
    {
        if ($char === '') {
            return 0;
        }

        if (function_exists('mb_ord')) {
            return (int) mb_ord($char, 'UTF-8');
        }

        $utf32 = @iconv('UTF-8', 'UCS-4BE', $char);
        if ($utf32 === false || strlen($utf32) < 4) {
            return ord($char);
        }

        $parts = unpack('N', $utf32);

        return (int) ($parts[1] ?? 0);
    }

    public static function chr(int $codepoint): string
    {
        if ($codepoint <= 0) {
            return '';
        }

        if (function_exists('mb_chr')) {
            return mb_chr($codepoint, 'UTF-8');
        }

        return html_entity_decode(sprintf('&#x%X;', $codepoint), ENT_HTML5, 'UTF-8');
    }
}
