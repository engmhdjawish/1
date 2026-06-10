<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_money(?float $amount, bool $show): string
{
    if (!$show || $amount === null) {
        return '—';
    }

    return number_format($amount, 0, '.', ',');
}

/** Character count without requiring ext-mbstring (common on Windows PHP builds). */
function text_length(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    if (preg_match_all('/./us', $value, $matches) === 1) {
        return count($matches[0]);
    }

    return strlen($value);
}

/** Lowercase without requiring ext-mbstring (common on Windows PHP builds). */
function str_lower(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}
