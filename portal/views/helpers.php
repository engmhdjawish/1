<?php

declare(strict_types=1);

use Portal\Auth\WebSession;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function web_can(string $permission): bool
{
    return WebSession::hasPermission($permission);
}

/** @param list<string> $permissions */
function web_can_any(array $permissions): bool
{
    return WebSession::hasAnyPermission($permissions);
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

/** @param array<string, mixed> $item */
function accounting_material_label(array $item): string
{
    $code = trim((string) ($item['materialCode'] ?? ''));
    $name = trim((string) ($item['materialName'] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }

    return $name !== '' ? $name : ($code !== '' ? $code : '—');
}

/** @param list<array<string, mixed>> $items */
function accounting_invoice_unit_header(array $items, string $field, string $fallback): string
{
    foreach ($items as $item) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
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

function material_guid(array $item): string
{
    return trim((string) ($item['materialGuid'] ?? $item['MaterialGuid'] ?? $item['guid'] ?? $item['Guid'] ?? ''));
}

function material_image_guid(array $item): string
{
    return trim((string) ($item['productImageGuid'] ?? $item['ProductImageGuid'] ?? ''));
}

function product_url(string $guid): string
{
    return '/product.php?guid=' . rawurlencode(trim($guid));
}

/** @param array<string, scalar|null> $params */
function store_url(array $params = []): string
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

    $query = http_build_query($filtered);

    return '/store.php' . ($query !== '' ? '?' . $query : '');
}

function format_packaging(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
}
