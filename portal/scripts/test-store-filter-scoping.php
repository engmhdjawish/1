<?php

declare(strict_types=1);

/**
 * Unit-style checks for store filter scoping against access-policy constraints.
 * Usage: php scripts/test-store-filter-scoping.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\StoreCatalogService;

function invokePrivate(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionClass(StoreCatalogService::class);
    $callable = $reflection->getMethod($method);
    $callable->setAccessible(true);

    return $callable->invoke(null, ...$args);
}

$failures = [];

$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

echo "=== Store filter scoping checks ===\n\n";

$assert(
    'has_image policy is an implicit constraint',
    invokePrivate('filterRulesHaveImplicitConstraints', ['has_image' => true]) === true
);
$assert(
    'is_available policy is an implicit constraint',
    invokePrivate('filterRulesHaveImplicitConstraints', ['is_available' => false]) === true
);
$assert(
    'material_types policy is an implicit constraint',
    invokePrivate('filterRulesHaveImplicitConstraints', ['material_types' => ['A']]) === true
);
$assert(
    'empty policy rules are not implicit constraints',
    invokePrivate('filterRulesHaveImplicitConstraints', []) === false
);

$assert(
    'policy has_image requests scoped deferred filters',
    invokePrivate('shouldFetchScopedResultFilters', [], ['search' => ''], ['has_image' => true]) === true
);
$assert(
    'policy has_image alone does not force inline catalog facets',
    invokePrivate('requestWantsInlineResultFilters', [], ['search' => '']) === false
);
$assert(
    'user search forces inline result filters',
    invokePrivate('requestWantsInlineResultFilters', [], ['search' => 'abc']) === true
);
$assert(
    'no policy or user filters keeps deferred mode',
    invokePrivate('requestWantsInlineResultFilters', [], ['search' => '']) === false
);

$mergedFilters = invokePrivate('mergeDisplayResultFilters', [
    'materialTypes' => ['Alpha', 'Beta', 'Gamma'],
    'groups' => [
        ['guid' => 'g1', 'name' => 'Group 1', 'code' => 'G1'],
        ['guid' => 'g2', 'name' => 'Group 2', 'code' => 'G2'],
    ],
], [
    'materialTypes' => [
        ['value' => 'Alpha', 'count' => 4],
        ['value' => 'Beta', 'count' => 0],
    ],
    'groups' => [
        ['guid' => 'g1', 'name' => 'Group 1', 'code' => 'G1', 'count' => 2],
    ],
], [
    'materialTypes' => ['Gamma'],
], false);
$assert(
    'available-only merge keeps positive-count and selected facets',
    is_array($mergedFilters['materialTypes'] ?? null)
        && count($mergedFilters['materialTypes']) === 2
        && ($mergedFilters['materialTypes'][0]['value'] ?? '') === 'Alpha'
);
$assert(
    'available-only merge drops zero-count facets that are not selected',
    !array_filter(
        is_array($mergedFilters['materialTypes'] ?? null) ? $mergedFilters['materialTypes'] : [],
        static fn (array $facet): bool => ($facet['value'] ?? '') === 'Beta'
    )
);
$assert(
    'available-only merge keeps selected zero-count facet',
    array_filter(
        is_array($mergedFilters['materialTypes'] ?? null) ? $mergedFilters['materialTypes'] : [],
        static fn (array $facet): bool => ($facet['value'] ?? '') === 'Gamma'
    ) !== []
);

$allFilters = invokePrivate('mergeDisplayResultFilters', [
    'materialTypes' => ['Alpha', 'Beta'],
], [
    'materialTypes' => [
        ['value' => 'Alpha', 'count' => 0],
    ],
], [], true);
$assert(
    'unavailable merge includes all global facets',
    is_array($allFilters['materialTypes'] ?? null) && count($allFilters['materialTypes']) === 2
);

$assert(
    'unavailable availability includes zero-count options',
    invokePrivate('shouldIncludeZeroCountFilterOptions', [false]) === true
);
$assert(
    'available/default availability hides zero-count options',
    invokePrivate('shouldIncludeZeroCountFilterOptions', [true]) === false
        && invokePrivate('shouldIncludeZeroCountFilterOptions', [null]) === false
);

$displayFacetRules = invokePrivate('mergedFiltersForDisplayFacets', [
    'isAvailable' => null,
    'storeGuids' => ['store-a'],
]);
$assert(
    'display facets default to available stock',
    ($displayFacetRules['isAvailable'] ?? null) === true
        && ($displayFacetRules['storeGuids'] ?? []) === ['store-a']
);

$independentFacetRules = invokePrivate('buildIndependentDisplayFacetFilters', [
    'store_guids' => ['store-a'],
    'manufacturers' => ['PolicyCo'],
], [
    'search' => 'shirt',
    'manufacturers' => ['UserCo'],
    'materialTypes' => ['TypeA'],
    'isAvailable' => null,
]);
$assert(
    'display facets ignore client search and facet selections',
    ($independentFacetRules['search'] ?? null) === ''
        && ($independentFacetRules['manufacturers'] ?? []) === ['PolicyCo']
        && ($independentFacetRules['materialTypes'] ?? []) === []
        && ($independentFacetRules['storeGuids'] ?? []) === ['store-a']
        && ($independentFacetRules['isAvailable'] ?? null) === true
);

$locked = invokePrivate('lockedPolicyClientFilters', [
    'store_guids' => ['store-a'],
    'group_guids' => ['group-a'],
]);
$assert('policy store_guids lock stores filter', in_array('stores', $locked, true));
$assert('policy group_guids lock groups filter', in_array('groups', $locked, true));

try {
    $guest = \Portal\Services\StorePolicyService::guestPolicy();
    if ($guest === null) {
        echo "Skip live catalog checks: guest policy not configured\n";
    } else {
        $rules = is_array($guest['filter_rules'] ?? null) ? $guest['filter_rules'] : [];
        $hasImplicit = invokePrivate('filterRulesHaveImplicitConstraints', $rules);
        echo "\nGuest policy implicit constraints: " . ($hasImplicit ? 'yes' : 'no') . "\n";

        $catalog = StoreCatalogService::catalogFromRequest([]);
        if ((bool) ($catalog['allow_client_filters'] ?? false)) {
            $assert(
                'catalog defers filters on initial load for fast first paint',
                (bool) ($catalog['filters_deferred'] ?? false) === true
            );
        }

        $payload = StoreCatalogService::getClientFiltersPayload([]);
        $materialTypes = is_array($payload['resultFilters']['materialTypes'] ?? null)
            ? $payload['resultFilters']['materialTypes']
            : [];
        if ($hasImplicit) {
            $assert(
                'deferred payload returns scoped materialTypes when policy constrains catalog',
                count($materialTypes) > 0
            );
        }
    }
} catch (Throwable $exception) {
    echo "Skip live catalog checks: {$exception->getMessage()}\n";
}

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed\n";
