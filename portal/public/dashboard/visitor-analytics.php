<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\PortalPresenceService;
use Portal\Services\PortalSessionService;
use Portal\Services\VisitorLogService;
use Portal\Services\WebCustomerService;

WebSession::requireAnyPermission(['visitors.view', 'orders.view', 'sessions.manage']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$canManageSessions = WebSession::hasPermission('sessions.manage');
$presenceReady = PortalPresenceService::isEnabled();
$sessionsReady = PortalSessionService::isEnabled();

$tab = trim((string) ($_GET['tab'] ?? 'now'));
if (!in_array($tab, ['now', 'log', 'insights'], true)) {
    $tab = 'now';
}

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}

$sessionId = trim((string) ($_GET['session'] ?? ''));
$customerId = trim((string) ($_GET['customer_id'] ?? ''));
$accountKey = trim((string) ($_GET['account'] ?? ''));
$searchQ = trim((string) ($_GET['q'] ?? ''));
$visitorFilter = trim((string) ($_GET['filter'] ?? 'all'));
if (!in_array($visitorFilter, ['all', 'customers', 'ordered', 'cart', 'known'], true)) {
    $visitorFilter = 'all';
}

$logFilters = array_filter([
    'customer_id' => $customerId,
    'q' => $searchQ,
], static fn ($value) => $value !== null && $value !== '');

$filteredCustomer = $customerId !== '' ? WebCustomerService::getById($customerId) : null;

$flash = null;
$flashType = 'success';
if (!empty($_SESSION['visitor_log_flash'])) {
    $flash = (string) $_SESSION['visitor_log_flash'];
    $flashType = (string) ($_SESSION['visitor_log_flash_type'] ?? 'success');
    unset($_SESSION['visitor_log_flash'], $_SESSION['visitor_log_flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageSessions && $sessionsReady) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $postSessionId = trim((string) ($_POST['session_id'] ?? ''));
    $kind = trim((string) ($_POST['kind'] ?? ''));
    $subjectId = trim((string) ($_POST['subject_id'] ?? ''));

    if ($action === 'revoke_one' && $postSessionId !== '' && in_array($kind, ['staff', 'customer'], true)) {
        $ok = PortalSessionService::revokeById($kind, $postSessionId);
        $flash = $ok ? 'تم إنهاء الجلسة.' : 'تعذر إنهاء الجلسة.';
        $flashType = $ok ? 'success' : 'error';
    } elseif ($action === 'revoke_subject' && $subjectId !== '' && in_array($kind, ['staff', 'customer'], true)) {
        $count = $kind === 'customer'
            ? PortalSessionService::revokeAllForCustomer($subjectId)
            : PortalSessionService::revokeAllForStaffUser($subjectId);
        $flash = $count > 0 ? "تم إنهاء {$count} جلسة." : 'لا توجد جلسات نشطة لهذا الحساب.';
        $flashType = $count > 0 ? 'success' : 'error';
    } elseif ($action === 'revoke_all_online' && in_array($kind, ['staff', 'customer', 'all'], true)) {
        if ($kind === 'all') {
            $count = PortalSessionService::revokeAllOnline('staff') + PortalSessionService::revokeAllOnline('customer');
        } else {
            $count = PortalSessionService::revokeAllOnline($kind);
        }
        $flash = $count > 0 ? "تم إنهاء {$count} جلسة متصلة." : 'لا يوجد متصلون حالياً.';
        $flashType = $count > 0 ? 'success' : 'error';
    }

    $_SESSION['visitor_log_flash'] = $flash;
    $_SESSION['visitor_log_flash_type'] = $flashType;
    header('Location: /dashboard/visitor-analytics.php?tab=now', true, 303);
    exit;
}

$schemaReady = VisitorLogService::hasSchema();
$summary = $schemaReady ? VisitorLogService::summaryForDays($days) : [
    'total_events' => 0,
    'page_views' => 0,
    'product_views' => 0,
    'cart_adds' => 0,
    'unique_sessions' => 0,
    'unique_ips' => 0,
    'registered_hits' => 0,
];

$recent = ($schemaReady && $tab === 'log') ? VisitorLogService::recent(80, null, $days, $logFilters) : [];
$topProducts = ($schemaReady && $tab === 'insights') ? VisitorLogService::topProducts($days, 12) : [];
$topPages = ($schemaReady && $tab === 'insights') ? VisitorLogService::topPages($days, 10) : [];
$sessions = ($schemaReady && $tab === 'log') ? VisitorLogService::sessionSummaries($days, 80, $logFilters) : [];
$allAccountGroups = ($schemaReady && $tab === 'log') ? VisitorLogService::groupSessionsByAccount($sessions) : [];
$accountGroups = ($schemaReady && $tab === 'log')
    ? VisitorLogService::filterAccountGroups($allAccountGroups, $visitorFilter)
    : [];

// Resolve account from session or customer_id when not explicitly set
if ($tab === 'log' && $accountKey === '' && $sessionId !== '') {
    foreach ($allAccountGroups as $group) {
        foreach ($group['sessions'] ?? [] as $sess) {
            if ((string) ($sess['session_id'] ?? '') === $sessionId) {
                $accountKey = (string) ($group['account_key'] ?? '');
                break 2;
            }
        }
    }
    if ($accountKey === '') {
        $accountKey = 'session:' . $sessionId;
    }
}
if ($tab === 'log' && $accountKey === '' && $customerId !== '') {
    $accountKey = 'customer:' . $customerId;
}

$selectedAccount = null;
$accountProfile = null;
$sessionDigest = null;
$sessionEvents = [];

if ($schemaReady && $tab === 'log' && $accountKey !== '') {
    foreach ($allAccountGroups as $group) {
        if ((string) ($group['account_key'] ?? '') === $accountKey) {
            $selectedAccount = $group;
            break;
        }
    }
    if ($selectedAccount === null && str_starts_with($accountKey, 'session:')) {
        $fallbackSid = substr($accountKey, 8);
        foreach ($sessions as $sess) {
            if ((string) ($sess['session_id'] ?? '') === $fallbackSid) {
                $selectedAccount = [
                    'account_key' => $accountKey,
                    'display_name' => (string) ($sess['display_name'] ?? 'زائر'),
                    'identity_kind' => (string) ($sess['identity_kind'] ?? 'guest'),
                    'identity_subtitle' => (string) ($sess['identity_subtitle'] ?? ''),
                    'identity_phone' => (string) ($sess['identity_phone'] ?? ''),
                    'web_customer_id' => trim((string) ($sess['web_customer_id'] ?? '')),
                    'sessions' => [$sess],
                    'session_count' => 1,
                    'events' => (int) ($sess['events'] ?? 0),
                    'page_views' => (int) ($sess['page_views'] ?? 0),
                    'product_views' => (int) ($sess['product_views'] ?? 0),
                    'cart_adds' => (int) ($sess['cart_adds'] ?? 0),
                    'cart_removals' => (int) ($sess['cart_removals'] ?? 0),
                    'orders' => (int) ($sess['orders'] ?? 0),
                    'first_seen_fmt' => (string) ($sess['first_seen_fmt'] ?? '—'),
                    'last_seen_fmt' => (string) ($sess['last_seen_fmt'] ?? '—'),
                    'last_seen' => $sess['last_seen'] ?? null,
                    'funnel' => VisitorLogService::buildFunnel([
                        'page_views' => (int) ($sess['page_views'] ?? 0),
                        'product_views' => (int) ($sess['product_views'] ?? 0),
                        'cart_adds' => (int) ($sess['cart_adds'] ?? 0),
                        'orders' => (int) ($sess['orders'] ?? 0),
                    ]),
                ];
                break;
            }
        }
    }

    if ($selectedAccount !== null) {
        $profileSessionIds = array_values(array_filter(array_map(
            static fn (array $s): string => trim((string) ($s['session_id'] ?? '')),
            is_array($selectedAccount['sessions'] ?? null) ? $selectedAccount['sessions'] : []
        )));
        $profileEvents = VisitorLogService::eventsForSessions($profileSessionIds, 250, $days);
        $accountProfile = VisitorLogService::buildAccountProfile($selectedAccount, $profileEvents);
    }
}

if ($schemaReady && $tab === 'log' && $sessionId !== '') {
    $sessionEvents = VisitorLogService::sessionEvents($sessionId, 200);
    $sessionDigest = ($sessionEvents !== []) ? VisitorLogService::buildSessionDigest($sessionEvents) : null;
}
$mapPoints = ($schemaReady && $tab === 'insights') ? VisitorLogService::mapPoints($days, 200) : [];
$locationStats = ($schemaReady && $tab === 'insights') ? VisitorLogService::locationStats($days, 10) : [];

$onlineStaff = ($tab === 'now' && $sessionsReady) ? PortalSessionService::onlineStaff() : [];
$onlineCustomers = ($tab === 'now' && $sessionsReady) ? PortalSessionService::onlineCustomers() : [];
$onlineGuests = ($tab === 'now' && $presenceReady) ? PortalPresenceService::onlineGuests() : [];
if ($tab === 'now' && $onlineGuests !== []) {
    $guestSessionIds = [];
    foreach ($onlineGuests as $guestRow) {
        $key = (string) ($guestRow['presence_key'] ?? '');
        if (str_starts_with($key, 'guest:')) {
            $guestSessionIds[] = substr($key, 6);
        }
    }
    $guestIdentities = VisitorLogService::resolveIdentitiesForSessions($guestSessionIds);
    $onlineGuests = array_map(static function (array $row) use ($guestIdentities): array {
        $key = (string) ($row['presence_key'] ?? '');
        $sessionKey = str_starts_with($key, 'guest:') ? substr($key, 6) : '';
        $row['visitor_session_id'] = $sessionKey;
        if ($sessionKey !== '') {
            $row = VisitorLogService::applyIdentity(['session_id' => $sessionKey, 'visitor_ip' => $row['visitor_ip'] ?? ''], $guestIdentities);
        } else {
            $ipIdentity = VisitorLogService::resolveIdentityForIp((string) ($row['visitor_ip'] ?? ''));
            if ($ipIdentity !== null) {
                $row['display_name'] = (string) ($ipIdentity['display_name'] ?? 'زائر');
                $row['identity_kind'] = (string) ($ipIdentity['identity_kind'] ?? 'guest');
                $row['identity_subtitle'] = (string) ($ipIdentity['identity_subtitle'] ?? '');
                $row['web_customer_id'] = $ipIdentity['web_customer_id'] ?? null;
            } else {
                $row['display_name'] = 'زائر';
                $row['identity_kind'] = 'guest';
            }
        }
        $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
        $row['map_url'] = VisitorLogService::mapExternalUrl($lat, $lng);

        return $row;
    }, $onlineGuests);
}
$onlineCounts = ($tab === 'now' && ($sessionsReady || $presenceReady))
    ? PortalSessionService::onlineCounts()
    : ['staff' => 0, 'customers' => 0, 'guests' => 0, 'total' => 0];

$queryBase = static function (array $params = []) use ($days, $tab, $sessionId, $customerId, $accountKey, $searchQ, $visitorFilter): string {
    $query = array_merge([
        'tab' => $tab,
        'days' => $days,
        'customer_id' => $customerId,
        'account' => $accountKey,
        'filter' => $visitorFilter !== 'all' ? $visitorFilter : '',
        'q' => $searchQ,
    ], $params);
    if ($sessionId !== '' && !array_key_exists('session', $params)) {
        $query['session'] = $sessionId;
    }
    $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');

    return '/dashboard/visitor-analytics.php?' . http_build_query($query);
};

$currentRoute = $queryBase();
$buildUrl = $queryBase;

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/visitor-analytics.php';
$content = ob_get_clean();
$title = 'سجل الزوار';
$extraHead = '<link href="' . h(portal_asset_url('/css/visitor-log.css')) . '" rel="stylesheet">';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
