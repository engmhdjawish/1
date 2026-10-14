<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\ResponseCache;

final class HomeSearchService
{
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
        $guess = null;
        foreach ($scored as $row) {
            $key = self::normalize($row['label']) . '|' . (string) ($row['candidate']['kind'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($guess === null && $row['score'] >= 60 && self::normalize($row['label']) !== $normalizedQuery) {
                $guess = $row['label'];
            }
            $suggestions[] = self::publicCandidate($row['candidate']);
            if (count($suggestions) >= 7) {
                break;
            }
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
    public static function suggest(string $query): array
    {
        $query = trim($query);
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 80);
        } else {
            $query = substr($query, 0, 80);
        }

        $cacheKey = 'home_search_v1_' . sha1(self::normalize($query));
        $cached = ResponseCache::get($cacheKey);
        if (is_array($cached) && isset($cached['suggestions']) && is_array($cached['suggestions'])) {
            return $cached;
        }

        $ranked = self::rank($query, self::candidates($query));
        $payload = [
            'ok' => true,
            'query' => $query,
            'guess' => $ranked['guess'],
            'suggestions' => $ranked['suggestions'],
        ];
        ResponseCache::set($cacheKey, $payload, $query === '' ? 60 : 120);

        return $payload;
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
    private static function candidates(string $query): array
    {
        $candidates = [];
        foreach (self::sectionCandidates() as $candidate) {
            $candidates[] = $candidate;
        }
        foreach (self::facetCandidates() as $candidate) {
            $candidates[] = $candidate;
        }
        if (self::normalize($query) !== '' && self::charLength($query) >= 2) {
            foreach (self::productCandidates($query) as $candidate) {
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
    private static function productCandidates(string $query): array
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
                ? product_url($guid, '/home-lab.php')
                : '/product.php?guid=' . rawurlencode($guid);
            $candidates[] = [
                'label' => $label,
                'hint' => $code !== '' ? $code : 'مادة',
                'href' => $href,
                'kind' => 'product',
                'trusted' => true,
            ];
        }

        return $candidates;
    }

    /** @param array<string, mixed> $candidate */
    private static function publicCandidate(array $candidate, ?string $kind = null): array
    {
        return [
            'kind' => $kind ?? (string) ($candidate['kind'] ?? 'search'),
            'label' => trim((string) ($candidate['label'] ?? '')),
            'hint' => trim((string) ($candidate['hint'] ?? '')),
            'href' => trim((string) ($candidate['href'] ?? '/store.php')),
        ];
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
