<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebCustomerService;
use Portal\Support\DashboardHttp;

WebSession::requirePermission('web_customers.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canApproveCustomers = $isSuper || in_array('web_customers.approve', $permissions, true);
$canManageCustomers = $isSuper || in_array('web_customers.manage', $permissions, true);

$flash = null;
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $customerId = trim((string) ($_POST['customer_id'] ?? ''));
    $adminId = (string) ($user['id'] ?? '');

    if ($action === 'approve' || $action === 'reject') {
        if (!$canApproveCustomers) {
            $flash = 'لا تملك صلاحية الموافقة/الرفض.';
            $flashType = 'error';
        } elseif ($action === 'approve') {
            $ok = WebCustomerService::approve($customerId, trim((string) ($_POST['access_policy_id'] ?? '')), $adminId);
            $flash = $ok ? 'تم تفعيل العميل.' : 'تعذر تفعيل العميل.';
            $flashType = $ok ? 'success' : 'error';
        } else {
            $rejectReason = trim((string) ($_POST['reject_reason'] ?? ''));
            if ($rejectReason === '') {
                $rejectReason = 'مرفوض من الإدارة';
            }
            $ok = WebCustomerService::reject($customerId, $rejectReason, $adminId);
            $flash = $ok ? 'تم رفض الطلب.' : 'تعذر رفض الطلب.';
            $flashType = $ok ? 'success' : 'error';
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
    } elseif ($action === 'save_customer') {
        if (!$canManageCustomers) {
            $flash = 'لا تملك صلاحية إضافة/تعديل العملاء.';
            $flashType = 'error';
        } else {
            $result = WebCustomerService::saveByAdmin(
                $customerId !== '' ? $customerId : null,
                trim((string) ($_POST['name_ar'] ?? '')),
                trim((string) ($_POST['phone'] ?? '')),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['access_policy_id'] ?? '')),
                trim((string) ($_POST['status'] ?? 'pending')),
                ((string) ($_POST['is_active'] ?? '0')) === '1',
                trim((string) ($_POST['plain_password'] ?? '')),
                trim((string) ($_POST['notes_ar'] ?? '')),
                trim((string) ($_POST['rejection_reason_ar'] ?? '')),
                $adminId
            );
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                $_GET['edit'] = (string) ($result['id'] ?? '');
            }
            if (DashboardHttp::wantsJson()) {
                DashboardHttp::json(
                    $result['ok'],
                    $result['message'],
                    ['reload' => $result['ok'], 'id' => $result['id'] ?? null]
                );
            }
        }
    } elseif ($action === 'suspend') {
        if (!$canManageCustomers) {
            $flash = 'لا تملك صلاحية تعليق الحسابات.';
            $flashType = 'error';
        } else {
            $ok = WebCustomerService::suspend(
                $customerId,
                $adminId,
                trim((string) ($_POST['suspend_reason'] ?? ''))
            );
            $flash = $ok ? 'تم تعليق الحساب.' : 'تعذر تعليق الحساب (قد يكون معلقاً أو مرفوضاً مسبقاً).';
            $flashType = $ok ? 'success' : 'error';
            if ($ok) {
                $statusFilter = 'suspended';
            }
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
    } elseif ($action === 'reactivate') {
        if (!$canApproveCustomers) {
            $flash = 'لا تملك صلاحية إعادة تفعيل الحسابات.';
            $flashType = 'error';
        } else {
            $policyId = trim((string) ($_POST['access_policy_id'] ?? ''));
            $ok = WebCustomerService::reactivate($customerId, $policyId, $adminId);
            $flash = $ok ? 'تم إعادة تفعيل الحساب.' : 'تعذر إعادة التفعيل. تأكد من اختيار سياسة وصول.';
            $flashType = $ok ? 'success' : 'error';
            if ($ok) {
                $statusFilter = 'active';
            }
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
    } elseif ($action === 'toggle_login') {
        if (!$canManageCustomers) {
            $flash = 'لا تملك صلاحية تعديل حالة الدخول.';
            $flashType = 'error';
        } else {
            $enable = ((string) ($_POST['enable_login'] ?? '0')) === '1';
            $ok = WebCustomerService::setLoginActive($customerId, $enable);
            $flash = $ok
                ? ($enable ? 'تم تفعيل دخول العميل.' : 'تم إيقاف دخول العميل.')
                : 'تعذر تعديل حالة الدخول (يجب أن يكون الحساب نشطاً).';
            $flashType = $ok ? 'success' : 'error';
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$editId = isset($_GET['new']) ? '' : trim((string) ($_GET['edit'] ?? ''));
$detailsId = trim((string) ($_GET['details'] ?? ''));

$customers = WebCustomerService::listByStatus($statusFilter, $searchFilter, $sourceFilter, 120);
$statusCounts = WebCustomerService::statusCounts();
$policies = WebCustomerService::listAccessPolicies();
$editCustomer = $editId !== '' ? WebCustomerService::getById($editId) : null;
if ($editCustomer === null) {
    $editId = '';
}
$detailsCustomer = $detailsId !== '' ? WebCustomerService::getById($detailsId) : null;
$currentRoute = '/dashboard/customers.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/customers.php';
$content = ob_get_clean();
$title = 'عملاء الويب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
