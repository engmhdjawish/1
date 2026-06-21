<?php

declare(strict_types=1);

use Portal\Auth\WebSession;
use Portal\Services\CatalogSectionResolver;
use Portal\Services\ShareCartService;

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

function material_image_api_url(string $imageGuid, bool $thumb = true): string
{
    return \Portal\Services\MaterialImageStorageService::imageGuidUrl($imageGuid, $thumb);
}

function product_url(string $guid, ?string $return = null, ?string $offer = null): string
{
    $guid = trim($guid);
    if ($guid === '') {
        return '/store.php';
    }

    $params = ['guid' => $guid];
    if ($return !== null && trim($return) !== '') {
        $params['return'] = safe_return_url($return);
    }
    $offer = trim((string) $offer);
    if ($offer !== '') {
        $params['offer'] = $offer;
    }

    return '/product.php?' . http_build_query($params);
}

function safe_return_url(mixed $return): string
{
    $return = trim((string) $return);
    if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
        return '/store.php';
    }

    return $return;
}

function return_link_label(string $returnUrl): string
{
    if ($returnUrl === '/' || str_starts_with($returnUrl, '/#') || str_contains($returnUrl, 'index.php')) {
        return 'العودة للرئيسية';
    }
    if (str_contains($returnUrl, 'store.php')) {
        return 'العودة للمتجر';
    }

    return 'رجوع';
}

/** @param array<string, mixed> $section */
function home_section_store_url(array $section): string
{
    return store_url(CatalogSectionResolver::storeLinkParams($section));
}

/** @param array<string, mixed> $section */
function home_section_return_url(array $section): string
{
    $slug = trim((string) ($section['slug'] ?? ''));

    return $slug !== '' ? '/#' . $slug : '/';
}

/** @param array<string, mixed> $params */
function store_url(array $params = []): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
            if ($items === []) {
                continue;
            }
            $filtered[$key] = $items;
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

/** @param array<string, mixed> $item */
function packages_available_display(array $item): float
{
    $packaging = ShareCartService::packaging($item);
    if ($packaging <= 0) {
        return 0.0;
    }

    $warehouseQty = (float) (
        $item['warehouseQuantity']
        ?? $item['WarehouseQuantity']
        ?? $item['qty']
        ?? $item['Qty']
        ?? 0
    );

    return max(0.0, floor($warehouseQty / $packaging));
}

function default_about_content(): string
{
    return <<<'TEXT'
جاويش لتجارة الأحذية هي شركة متخصصة في تجارة كافة أنواع الأحذية المحلية والمستوردة. نعمل على تلبية احتياجات السوق من خلال توفير تشكيلة متنوعة تشمل الأحذية الرسمية، الكاجوال، والرياضية، التي تجمع بين التصاميم العملية والجودة المناسبة للاستخدام اليومي.

## أعمالنا وبماذا نلتزم
- تنوع المنتجات: نوفر خيارات متعددة من الأحذية المصنوعة محلياً لدعم الإنتاج الوطني، بالإضافة إلى خطوط الأحذية المستوردة لتلبية كافة الأذواق والمتطلبات.
- الجودة والمواد: نحرص على اختيار بضائعنا بعناية، مع التركيز على جودة الخامات المستخدمة مثل الجلود الطبيعية والنوبوك لضمان راحة العميل ومتانة المنتج.
- الالتزام والموثوقية: نعتمد على تنظيم داخلي دقيق وأنظمة رقمية لبرمجة الطلبيات وإدارة المستودعات، مما يضمن لعملائنا وتجار الجملة دقة في المواعيد وسلاسة في التعامل والتسليم.

## هدفنا
أن نكون المورد الموثوق والشريك الدائم لعملائنا في قطاع الأحذية، من خلال تقديم منتج جيد بسعر عادل، وتعامل قائم على الشفافية والوضوح.
TEXT;
}

/**
 * @return array{
 *   intro: string,
 *   sections: list<array{
 *     title: string,
 *     items: list<array{title: string, body: string}>,
 *     paragraphs: list<string>
 *   }>
 * }
 */
function parse_about_content(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['intro' => '', 'sections' => []];
    }

    $chunks = preg_split('/^##\s+/m', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $intro = trim((string) array_shift($chunks));
    $sections = [];

    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        $lines = preg_split('/\r\n|\n|\r/', $chunk) ?: [];
        $title = trim((string) array_shift($lines));
        $items = [];
        $paragraphs = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                $itemText = trim((string) $matches[1]);
                $colonPos = strpos($itemText, ':');
                if ($colonPos !== false) {
                    $items[] = [
                        'title' => trim(substr($itemText, 0, $colonPos)),
                        'body' => trim(substr($itemText, $colonPos + 1)),
                    ];
                } else {
                    $paragraphs[] = $itemText;
                }
                continue;
            }

            $paragraphs[] = $line;
        }

        if ($title !== '') {
            $sections[] = [
                'title' => $title,
                'items' => $items,
                'paragraphs' => $paragraphs,
            ];
        }
    }

    return [
        'intro' => $intro,
        'sections' => $sections,
    ];
}

/** @return list<string> */
function about_commitment_icons(): array
{
    return ['category', 'verified', 'schedule', 'handshake', 'inventory_2', 'support_agent'];
}
