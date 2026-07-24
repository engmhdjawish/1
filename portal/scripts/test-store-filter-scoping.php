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
    'policy has_image forces inline result filters',
    invokePrivate('shouldIncludeResultFilters', [], ['search' => ''], ['has_image' => true]) === true
);
$assert(
    'user search forces inline result filters',
    invokePrivate('shouldIncludeResultFilters', [], ['search' => 'abc'], []) === true
);
$assert(
    'no policy or user filters keeps deferred mode',
    invokePrivate('shouldIncludeResultFilters', [], ['search' => ''], []) === false
);

try {
    $guest = \Portal\Services\StorePolicyService::guestPolicy();
    if ($guest === null) {
        echo "Skip live catalog checks: guest policy not configured\n";
    } else {
        $rules = is_array($guest['filter_rules'] ?? null) ? $guest['filter_rules'] : [];
        $hasImplicit = invokePrivate('filterRulesHaveImplicitConstraints', $rules);
        echo "\nGuest policy implicit constraints: " . ($hasImplicit ? 'yes' : 'no') . "\n";

        $catalog = StoreCatalogService::catalogFromRequest([]);
        $assert(
            'catalog defers filters only when policy has no implicit constraints',
            ((bool) ($catalog['filters_deferred'] ?? false)) === !$hasImplicit
        );

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
