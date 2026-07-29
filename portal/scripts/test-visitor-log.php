<?php

declare(strict_types=1);

/**
 * Smoke tests for visitor log hub + identity features.
 *
 * Usage:
 *   php scripts/test-visitor-log.php
 *   php scripts/test-visitor-log.php --identity   # also checks orders.visitor_session_id
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Database;
use Portal\Services\OrderService;
use Portal\Services\VisitorLogService;

$checkIdentity = in_array('--identity', $argv ?? [], true);

$pass = 0;
$fail = 0;
$warn = 0;

$ok = static function (string $message) use (&$pass): void {
    $pass++;
    echo "  ✓ {$message}\n";
};

$bad = static function (string $message) use (&$fail): void {
    $fail++;
    echo "  ✗ {$message}\n";
};

$note = static function (string $message) use (&$warn): void {
    $warn++;
    echo "  ! {$message}\n";
};

$section = static function (string $title): void {
    echo "\n== {$title} ==\n";
};

echo "=== Visitor log smoke tests ===\n";
echo 'Branch check: identity features ' . ($checkIdentity ? 'enabled' : 'skipped (use --identity)') . "\n";

$section('Schema');
if (VisitorLogService::hasSchema()) {
    $ok('visitor_logs table exists');
} else {
    $bad('visitor_logs missing — run migration 005-visitor-logs.sql');
}

if ($checkIdentity) {
    if (VisitorLogService::ordersHaveVisitorSessionColumn()) {
        $ok('orders.visitor_session_id column exists');
    } else {
        $bad('orders.visitor_session_id missing — run migration 013-orders-visitor-session.sql');
    }
}

$section('Core helpers');
$url = VisitorLogService::mapExternalUrl(33.5138, 36.2765);
if ($url !== null && str_contains($url, 'google.com/maps')) {
    $ok('mapExternalUrl returns Google Maps link');
} else {
    $bad('mapExternalUrl failed');
}

if (VisitorLogService::mapExternalUrl(0.0, 0.0) === null) {
    $ok('mapExternalUrl rejects invalid coordinates');
} else {
    $bad('mapExternalUrl should reject 0,0');
}

$section('Analytics labels');
$labels = ['page_view', 'order_placed', 'add_to_cart'];
foreach ($labels as $action) {
    $label = VisitorLogService::actionLabel($action);
    if ($label !== '' && $label !== $action) {
        $ok("actionLabel({$action}) = {$label}");
    } elseif ($action === 'order_placed' && !$checkIdentity) {
        $note("order_placed label not on hub-only branch (expected on identity branch)");
    } else {
        $bad("actionLabel({$action}) missing Arabic label");
    }
}

if (!VisitorLogService::hasSchema()) {
    echo "\nSkipping DB queries — schema unavailable.\n";
    goto summary;
}

$section('Summary queries');
$summary = VisitorLogService::summaryForDays(7);
foreach (['total_events', 'unique_sessions', 'page_views'] as $key) {
    if (array_key_exists($key, $summary)) {
        $ok("summaryForDays[{$key}] = " . (int) $summary[$key]);
    } else {
        $bad("summaryForDays missing {$key}");
    }
}

$sessions = VisitorLogService::sessionSummaries(7, 5);
$ok('sessionSummaries returned ' . count($sessions) . ' row(s)');

if ($sessions !== []) {
    $first = $sessions[0];
    foreach (['session_id', 'events', 'last_seen_fmt'] as $key) {
        if (array_key_exists($key, $first)) {
            $ok("session row has {$key}");
        } else {
            $bad("session row missing {$key}");
        }
    }
    if ($checkIdentity) {
        if (array_key_exists('display_name', $first)) {
            $ok('session row has display_name: ' . (string) ($first['display_name'] ?? ''));
        } else {
            $bad('session row missing display_name (identity branch expected)');
        }
    }
}

$recent = VisitorLogService::recent(5, null, 7);
$ok('recent returned ' . count($recent) . ' event(s)');

$section('Identity resolution');
if (!$checkIdentity) {
    $note('Skipped — pass --identity on the identity branch');
} else {
    $sampleSessionIds = array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['session_id'] ?? '')),
        $sessions
    )));
    if ($sampleSessionIds === []) {
        $note('No sessions in last 7 days — identity lookup not exercised');
    } else {
        $identities = VisitorLogService::resolveIdentitiesForSessions($sampleSessionIds);
        $ok('resolveIdentitiesForSessions mapped ' . count($identities) . ' known session(s)');
        foreach (array_slice($sampleSessionIds, 0, 3) as $sid) {
            $identity = $identities[$sid] ?? null;
            if ($identity !== null) {
                $ok("session {$sid} → " . (string) ($identity['display_name'] ?? '?'));
            } else {
                $note("session {$sid} → anonymous guest (no linked customer/order yet)");
            }
        }
    }

    $ipRow = Database::pdo()->query(
        "SELECT visitor_ip FROM visitor_logs
         WHERE visitor_ip IS NOT NULL AND visitor_ip <> ''
         ORDER BY created_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($ipRow) && trim((string) ($ipRow['visitor_ip'] ?? '')) !== '') {
        $ip = (string) $ipRow['visitor_ip'];
        $byIp = VisitorLogService::resolveIdentityForIp($ip);
        if ($byIp !== null) {
            $ok("resolveIdentityForIp({$ip}) → " . (string) ($byIp['display_name'] ?? '?'));
        } else {
            $note("resolveIdentityForIp({$ip}) → no linked order/customer");
        }
    } else {
        $note('No visitor IPs logged yet');
    }

    $customerFilter = VisitorLogService::sessionSummaries(30, 3, ['customer_id' => '00000000-0000-0000-0000-000000000000']);
    $ok('customer_id filter query runs (' . count($customerFilter) . ' rows for dummy id)');
}

$section('Order linkage');
if (!$checkIdentity) {
    $note('Skipped order linkage — identity branch only');
} else {
    try {
        $linkedOrders = (int) Database::pdo()->query(
            "SELECT COUNT(*)::int FROM orders
             WHERE visitor_session_id IS NOT NULL AND visitor_session_id <> ''"
        )->fetchColumn();
        $ok("orders linked to visitor sessions: {$linkedOrders}");
        if ($linkedOrders === 0) {
            $note('Place a test order after deploy to verify jawish_vid linkage');
        }
    } catch (Throwable $e) {
        $bad('order linkage query failed: ' . $e->getMessage());
    }
}

$section('Account profile helpers');
$funnel = VisitorLogService::buildFunnel([
    'page_views' => 10,
    'product_views' => 5,
    'cart_adds' => 2,
    'orders' => 1,
]);
if (count($funnel) === 4 && ($funnel[3]['key'] ?? '') === 'order') {
    $ok('buildFunnel returns 4 steps');
} else {
    $bad('buildFunnel shape invalid');
}

$relative = VisitorLogService::formatRelativeTime(date('Y-m-d H:i:s', time() - 120));
if ($relative !== '' && $relative !== '—') {
    $ok('formatRelativeTime works: ' . $relative);
} else {
    $bad('formatRelativeTime failed');
}

$filtered = VisitorLogService::filterAccountGroups([
    ['identity_kind' => 'customer', 'orders' => 0, 'cart_adds' => 0, 'display_name' => 'أ'],
    ['identity_kind' => 'guest', 'orders' => 1, 'cart_adds' => 0, 'display_name' => 'زائر'],
], 'ordered');
if (count($filtered) === 1 && (int) ($filtered[0]['orders'] ?? 0) === 1) {
    $ok('filterAccountGroups(ordered) works');
} else {
    $bad('filterAccountGroups failed');
}

$section('Dashboard route files');
$files = [
    $base . '/public/dashboard/visitor-analytics.php',
    $base . '/views/dashboard/visitor-analytics.php',
    $base . '/views/dashboard/visitor-analytics-log.php',
    $base . '/public/css/visitor-log.css',
];
foreach ($files as $file) {
    if (is_file($file)) {
        $ok(basename($file) . ' exists');
    } else {
        $bad('Missing ' . $file);
    }
}

summary:
echo "\n=== Result: {$pass} passed, {$fail} failed, {$warn} notes ===\n";
exit($fail > 0 ? 1 : 0);
