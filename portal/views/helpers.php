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

function format_decimal(mixed $amount, int $decimals = 2): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return (string) $amount;
    }

    return number_format((float) $amount, $decimals, '.', ',');
}

function format_accounting_money(mixed $amount, ?string $symbol = null, ?string $code = null): string
{
    $formatted = format_decimal($amount);
    if ($formatted === '—') {
        return $formatted;
    }

    $symbol = trim((string) $symbol);
    if ($symbol !== '') {
        return $formatted . ' ' . $symbol;
    }

    $code = trim((string) $code);
    if ($code !== '') {
        return $formatted . ' ' . $code;
    }

    return $formatted;
}

/** @param array<string, scalar|null> $params */
function accounting_url(string $path, array $params = []): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $filtered[$key] = $text;
        }
    }

    return $path . ($filtered === [] ? '' : ('?' . http_build_query($filtered)));
}

function accounting_document_kind(?string $reasonType): ?string
{
    $normalized = strtolower(trim((string) $reasonType));
    if ($normalized === 'invoice') {
        return 'invoices';
    }
    if ($normalized === 'payment') {
        return 'vouchers';
    }

    return null;
}

function accounting_format_date(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('Y-m-d', $timestamp);
}
