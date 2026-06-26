<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\PortalSessionService;

WebSession::requirePermission('sessions.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$schemaReady = PortalSessionService::isEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $sessionId = trim((string) ($_POST['session_id'] ?? ''));
    $kind = trim((string) ($_POST['kind'] ?? ''));
    $subjectId = trim((string) ($_POST['subject_id'] ?? ''));

    if ($action === 'revoke_one' && $sessionId !== '' && in_array($kind, ['staff', 'customer'], true)) {
        $ok = PortalSessionService::revokeById($kind, $sessionId);
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
}

$onlineStaff = $schemaReady ? PortalSessionService::onlineStaff() : [];
$onlineCustomers = $schemaReady ? PortalSessionService::onlineCustomers() : [];
$onlineCounts = $schemaReady ? PortalSessionService::onlineCounts() : ['staff' => 0, 'customers' => 0, 'total' => 0];
$currentRoute = '/dashboard/sessions.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/sessions.php';
$content = ob_get_clean();
$title = 'المتصلون الآن';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
