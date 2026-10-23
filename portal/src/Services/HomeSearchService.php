<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\ResponseCache;

final class HomeSearchService
{
    public const UNAVAILABLE_BADGE = 'غير متوفر حالياً';

    /**
     * @param list<array{label: string, hint: string, href: string, kind: string}> $candidates
     * @return array{guess: ?string, suggestions: list<array{label: string, hint: string, href: string, kind: string}>}
     */
    public static function rank(string $query, array $candidates): array
    {
        $query = trim($query);
        $normalizedQuery = self::normalize($query);
        if ($normalizedQuery === '') {
            $suggestions = [];
            foreach (array_slice($candidates, 0, 6) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $suggestions[] = self::publicCandidate($candidate, 'discover');
            }

            return ['guess' => null, 'suggestions' => $suggestions];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $label = trim((string) ($candidate['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $score = self::score($normalizedQuery, self::normalize($label));
            if ($score < 45 && !empty($candidate['trusted'])) {
                $score = 50;
            }
            if ($score < 45) {
                continue;
            }
            $scored[] = ['score' => $score, 'candidate' => $candidate, 'label' => $label];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']);
        });

        $suggestions = [];
        $seen = [];
        $regular = [];
        $soon = [];
        foreach ($scored as $row) {
            $key = self::normalize($row['label']) . '|' . (string) ($row['candidate']['kind'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ((string) ($row['candidate']['kind'] ?? '') === 'soon') {
                $soon[] = $row;
                continue;
            }
            $regular[] = $row;
        }

        $picked = array_slice($regular, 0, $soon === [] ? 7 : 5);
        if ($soon !== []) {
            $picked = array_merge($picked, array_slice($soon, 0, 2));
            if (count($picked) < 7) {
                $picked = array_merge($picked, array_slice($regular, 5, 7 - count($picked)));
            }
            $picked = array_slice($picked, 0, 7);
        }

        $guess = null;
        foreach ($picked as $row) {
            if ((string) ($row['candidate']['kind'] ?? '') === 'soon') {
                continue;
            }
            if ($row['score'] >= 60 && self::normalize($row['label']) !== $normalizedQuery) {
                $guess = $row['label'];
                break;
            }
        }
        if ($guess === null) {
            foreach ($picked as $row) {
                if ($row['score'] >= 60 && self::normalize($row['label']) !== $normalizedQuery) {
                    $guess = $row['label'];
                    break;
                }
            }
        }
        foreach ($picked as $row) {
            $suggestions[] = self::publicCandidate($row['candidate']);
        }

        $suggestions[] = [
            'kind' => 'search',
            'label' => 'ابحث عن «' . $query . '»',
            'hint' => 'في كل المواد',
            'href' => '/store.php?q=' . rawurlencode($query),
        ];

        return ['guess' => $guess, 'suggestions' => $suggestions];
    }

    /** @return array{ok: bool, query: string, guess: ?string, suggestions: list<array{label: string, hint: string, href: string, kind: string}>} */
    public static function suggest(string $query, string $surface = ''): array
    {
        $query = trim($query);
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 80);
        } else {
            $query = substr($query, 0, 80);
        }
        $surface = $surface === 'lab' ? 'lab' : '';

        $cacheKey = 'home_search_v3_' . sha1($surface . '|' . self::normalize($query));
        $cached = ResponseCache::get($cacheKey);
        if (is_array($cached) && isset($cached['suggestions']) && is_array($cached['suggestions'])) {
            return $cached;
        }

        $ranked = self::rank($query, self::candidates($query, $surface));
        $payload = [
            'ok' => true,
            'query' => $query,
            'guess' => $ranked['guess'],
            'suggestions' => self::applySurface($ranked['suggestions'], $surface),
        ];
        ResponseCache::set($cacheKey, $payload, $query === '' ? 60 : 120);

        return $payload;
    }

    public const GROUP_TITLES = [
        'sections' => ['title' => 'أقسام وعروض', 'icon' => 'grid_view'],
        'manufacturers' => ['title' => 'شركات', 'icon' => 'verified'],
        'ageCategories' => ['title' => 'الفئات', 'icon' => 'groups'],
        'materialTypes' => ['title' => 'الأنواع', 'icon' => 'category'],
        'sizeRanges' => ['title' => 'القياسات', 'icon' => 'straighten'],
        'countryOfOrigins' => ['title' => 'بلد المنشأ', 'icon' => 'public'],
        'products' => ['title' => 'مواد', 'icon' => 'checkroom'],
    ];

    /**
     * Search results for the trial homepage, grouped by kind (sections and facet values as short
     * chip rows, then materials), with the total number of matching materials. Links stay on the trial store.
     *
     * @return array{ok: bool, query: string, total: ?int, all: string, groups: list<array{key: string, title: string, icon: string, items: list<array<string, mixed>>}>}
     */
    public static function grouped(string $query): array
    {
        $query = mb_substr(trim($query), 0, 80);
        $normalized = self::normalize($query);
        $all = '/store.php' . ($query !== '' ? '?q=' . rawurlencode($query) : '');
        if ($normalized === '' || self::charLength($normalized) < 2) {
            return ['ok' => true, 'query' => $query, 'total' => null, 'all' => $all, 'groups' => []];
        }

        return ResponseCache::remember('home_search_grouped_v1_' . sha1($normalized), 120, static function () use ($query, $normalized, $all): array {
            $groups = [];
            [$products, $total] = self::groupedProducts($query);
            if ($products !== []) {
                $groups['products'] = $products;
            }

            $sections = [];
            foreach (self::sectionCandidates() as $candidate) {
                $score = self::score($normalized, self::normalize($candidate['label']));
                if ($score >= 45) {
                    $href = (string) $candidate['href'];
                    if (str_starts_with($href, '/store.php')) {
                        $href = '/store.php' . substr($href, strlen('/store.php'));
                    }
                    $sections[] = ['score' => $score, 'label' => $candidate['label'], 'href' => $href, 'hint' => $candidate['hint']];
                }
            }
            if ($sections !== []) {
                $groups['sections'] = $sections;
            }

            foreach (array_keys(self::GROUP_TITLES) as $param) {
                if ($param === 'products' || $param === 'sections') {
                    continue;
                }
                $values = function_exists('store_easy_facet_values') ? store_easy_facet_values($param) : null;
                foreach ($values ?? [] as $value) {
                    $label = trim((string) $value);
                    $score = self::score($normalized, self::normalize($label));
                    if ($score < 45) {
                        continue;
                    }
                    $groups[$param][] = [
                        'score' => $score,
                        'label' => $label,
                        'href' => function_exists('store_lab_url') ? store_lab_url([$param => [$label]]) : '/store.php',
                    ];
                }
            }

            $out = [];
            foreach (self::GROUP_TITLES as $key => $meta) {
                $items = $groups[$key] ?? [];
                if ($items === []) {
                    continue;
                }
                if ($key !== 'products') {
                    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']));
                    $items = array_map(static function (array $item): array {
                        unset($item['score']);

                        return $item;
                    }, array_slice($items, 0, 4));
                }
                $out[] = ['key' => $key, 'title' => $meta['title'], 'icon' => $meta['icon'], 'items' => $items];
            }

            return [
                'ok' => true,
                'query' => $query,
                'total' => $total,
                'all' => $all,
                'groups' => $out,
            ];
        });
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?int} */
    private static function groupedProducts(string $query): array
    {
        try {
            $catalog = StoreCatalogService::catalogFromRequest(['q' => $query, 'page' => 1]);
        } catch (\Throwable) {
            return [[], null];
        }
        $items = [];
        foreach (array_slice(is_array($catalog['products'] ?? null) ? $catalog['products'] : [], 0, 6) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['name'] ?? ''));
            $guid = function_exists('material_guid') ? material_guid($item) : trim((string) ($item['guid'] ?? ''));
            if ($label === '' || $guid === '') {
                continue;
            }
            $imageGuid = function_exists('material_image_guid') ? material_image_guid($item) : '';
            $row = [
                'label' => $label,
                'href' => function_exists('product_url') ? product_url($guid, '/index.php') : '/product.php?guid=' . rawurlencode($guid),
                'code' => trim((string) ($item['materialCode'] ?? $item['code'] ?? '')),
                'brand' => trim((string) ($item['manufacturer'] ?? '')),
                'image' => $imageGuid !== '' && function_exists('material_image_api_url') ? material_image_api_url($imageGuid, true) : '',
            ];
            if (StoreCatalogService::materialIsUnavailable($item)) {
                $row['badge'] = self::UNAVAILABLE_BADGE;
            }
            $items[] = $row;
        }
        $total = isset($catalog['totalCount']) ? max(0, (int) $catalog['totalCount']) : null;

        return [$items, $items === [] ? ($total ?: 0) : $total];
    }

    /**
     * Trial-store search keeps section and catalog links on /store.php.
     * Product pages stay on /product.php. The live homepage surface is unchanged.
     *
     * @param list<array<string, mixed>> $suggestions
     * @return list<array<string, mixed>>
     */
    public static function applySurface(array $suggestions, string $surface): array
    {
        if ($surface !== 'lab') {
            return array_values(array_filter(
                $suggestions,
                static fn (mixed $suggestion): bool => !is_array($suggestion) || (string) ($suggestion['kind'] ?? '') !== 'soon'
            ));
        }

        $rewritten = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $href = (string) ($suggestion['href'] ?? '');
            if (str_starts_with($href, '/store.php')) {
                $suggestion['href'] = '/store.php' . substr($href, strlen('/store.php'));
            }
            $suggestion['hint'] = '';
            $rewritten[] = $suggestion;
        }

        return $rewritten;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = strtr($value, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @return list<array{label: string, hint: string, href: string, kind: string}> */
    private static function candidates(string $query, string $surface = ''): array
    {
        $candidates = [];
        foreach (self::sectionCandidates() as $candidate) {
            $candidates[] = $candidate;
        }
        foreach (self::facetCandidates() as $candidate) {
            $candidates[] = $candidate;
        }
        if (self::normalize($query) !== '' && self::charLength($query) >= 2) {
            foreach (self::productCandidates($query, $surface === 'lab') as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @return list<array{label: string, hint: string, href: string, kind: string}> */
    private static function sectionCandidates(): array
    {
        try {
            $sections = HomePageService::mergedSectionShells();
        } catch (\Throwable) {
            return [];
        }

        $candidates = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $label = trim((string) ($section['title_ar'] ?? ''));
            if ($label === '') {
                continue;
            }
            $href = function_exists('home_section_store_url')
                ? home_section_store_url($section)
                : '/store.php';
            $candidates[] = [
                'label' => $label,
                'hint' => !empty($section['is_offer_section']) ? 'عرض' : 'قسم',
                'href' => $href,
                'kind' => 'section',
            ];
            $subtitle = trim((string) ($section['subtitle_ar'] ?? ''));
            if ($subtitle !== '' && self::normalize($subtitle) !== self::normalize($label)) {
                $candidates[] = [
                    'label' => $subtitle,
                    'hint' => $label,
                    'href' => $href,
                    'kind' => 'section',
                ];
            }
        }

        return $candidates;
    }

    /** @return list<array{label: string, hint: string, href: string, kind: string}> */
    private static function facetCandidates(): array
    {
        try {
            $options = StoreCatalogService::getCachedFilterOptions();
        } catch (\Throwable) {
            return [];
        }

        $groups = [
            'materialTypes' => ['hint' => 'نوع المادة', 'kind' => 'type'],
            'ageCategories' => ['hint' => 'فئة', 'kind' => 'age'],
            'manufacturers' => ['hint' => 'شركة', 'kind' => 'brand'],
            'sizeRanges' => ['hint' => 'قياس', 'kind' => 'size'],
            'countryOfOrigins' => ['hint' => 'منشأ', 'kind' => 'origin'],
        ];
        $candidates = [];
        foreach ($groups as $param => $meta) {
            $values = $options[$param] ?? [];
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                $label = trim((string) $value);
                if ($label === '') {
                    continue;
                }
                $href = function_exists('store_url')
                    ? store_url([$param => $label])
                    : '/store.php?' . rawurlencode($param) . '=' . rawurlencode($label);
                $candidates[] = [
                    'label' => $label,
                    'hint' => $meta['hint'],
                    'href' => $href,
                    'kind' => $meta['kind'],
                ];
            }
        }

        return $candidates;
    }

    /** @return list<array{label: string, hint: string, href: string, kind: string}> */
    private static function productCandidates(string $query, bool $includeComingSoon = false): array
    {
        try {
            $catalog = StoreCatalogService::catalogFromRequest(['q' => $query, 'page' => 1]);
        } catch (\Throwable) {
            return [];
        }

        $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
        $candidates = [];
        foreach (array_slice($products, 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['name'] ?? ''));
            $guid = function_exists('material_guid') ? material_guid($item) : trim((string) ($item['guid'] ?? ''));
            if ($label === '' || $guid === '') {
                continue;
            }
            $code = trim((string) ($item['materialCode'] ?? $item['code'] ?? ''));
            $href = function_exists('product_url')
                ? product_url($guid, '/index.php')
                : '/product.php?guid=' . rawurlencode($guid);
            $candidates[] = [
                'label' => $label,
                'hint' => $code !== '' ? $code : 'مادة',
                'href' => $href,
                'kind' => 'product',
                'trusted' => true,
            ];
        }
        if ($includeComingSoon) {
            foreach (self::comingSoonProductCandidates($query) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @return list<array{label: string, hint: string, href: string, kind: string, trusted: bool}> */
    private static function comingSoonProductCandidates(string $query): array
    {
        try {
            $catalog = StoreCatalogService::comingSoonFromRequest(['q' => $query, 'soon' => 1], 8);
        } catch (\Throwable) {
            return [];
        }

        $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
        $candidates = [];
        foreach (array_slice($products, 0, 4) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $code = trim((string) ($item['materialCode'] ?? $item['code'] ?? ''));
            $guid = function_exists('material_guid') ? material_guid($item) : trim((string) ($item['guid'] ?? $item['materialGuid'] ?? ''));
            $href = $guid !== '' && function_exists('product_url')
                ? product_url($guid, '/store.php?q=' . rawurlencode($label))
                : '/store.php?q=' . rawurlencode($label);
            $candidates[] = [
                'label' => $label,
                'hint' => '',
                'href' => $href,
                'kind' => 'soon',
                'badge' => self::UNAVAILABLE_BADGE,
                'trusted' => true,
            ];
        }

        return $candidates;
    }

    /** @param array<string, mixed> $candidate */
    private static function publicCandidate(array $candidate, ?string $kind = null): array
    {
        $public = [
            'kind' => $kind ?? (string) ($candidate['kind'] ?? 'search'),
            'label' => trim((string) ($candidate['label'] ?? '')),
            'hint' => trim((string) ($candidate['hint'] ?? '')),
            'href' => trim((string) ($candidate['href'] ?? '/store.php')),
        ];
        $badge = trim((string) ($candidate['badge'] ?? ''));
        if ($badge !== '') {
            $public['badge'] = $badge;
        }

        return $public;
    }

    private static function score(string $query, string $label): int
    {
        if ($query === '' || $label === '') {
            return 0;
        }
        if ($query === $label) {
            return 100;
        }
        if (str_starts_with($label, $query)) {
            return 86;
        }
        if (str_contains($label, $query)) {
            return 68;
        }

        $queryTokens = explode(' ', $query);
        $labelTokens = explode(' ', $label);
        $matched = 0;
        foreach ($queryTokens as $token) {
            if ($token === '') {
                continue;
            }
            foreach ($labelTokens as $labelToken) {
                if ($labelToken === $token || str_starts_with($labelToken, $token)) {
                    $matched++;
                    break;
                }
            }
        }
        if ($matched === count(array_filter($queryTokens, static fn (string $token): bool => $token !== ''))) {
            return 62;
        }

        if (self::charLength($query) < 3 || abs(self::charLength($query) - self::charLength($label)) > 3) {
            return 0;
        }
        $distance = self::editDistance($query, $label);
        if ($distance <= 2) {
            return 74 - ($distance * 8);
        }

        return 0;
    }

    private static function charLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function editDistance(string $left, string $right): int
    {
        $a = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0 || $n > 40 || $m > 40) {
            return 99;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $curr = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $curr[$j] = min($curr[$j - 1] + 1, $prev[$j] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $curr;
        }

        return $prev[$m];
    }
}
