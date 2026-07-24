<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Services\WebCustomerService;
use Portal\Support\DashboardNavigation;
use Portal\Support\DashboardOrderPricePreference;

WebSession::requirePermission('web_customers.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canApproveCustomers = $isSuper || in_array('web_customers.approve', $permissions, true);
$canManageCustomers = $isSuper || in_array('web_customers.manage', $permissions, true);
$canViewAmineCustomers = DashboardNavigation::canAccessAccounting($user);

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
    } elseif ($action === 'suspend' && $canManageCustomers) {
        $ok = WebCustomerService::suspend($customerId, $adminId, trim((string) ($_POST['suspend_reason'] ?? '')));
        $flash = $ok ? 'تم تعليق الحساب وإنهاء جلساته.' : 'تعذر تعليق الحساب.';
        $flashType = $ok ? 'success' : 'error';
    } elseif ($action === 'reactivate' && $canApproveCustomers) {
        $policyId = trim((string) ($_POST['access_policy_id'] ?? ''));
        $ok = WebCustomerService::reactivate($customerId, $policyId, $adminId);
        $flash = $ok ? 'تم إعادة تفعيل الحساب.' : 'تعذر إعادة التفعيل. تأكد من اختيار سياسة الوصول.';
        $flashType = $ok ? 'success' : 'error';
    } elseif ($action === 'save_customer') {
        if (!$canManageCustomers) {
            $flash = 'لا تملك صلاحية إضافة/تعديل العملاء.';
            $flashType = 'error';
        } else {
            $result = WebCustomerService::saveByAdmin(
                $customerId !== '' ? $customerId : null,
                trim((string) ($_POST['name_ar'] ?? '')),
                portal_normalize_phone(trim((string) ($_POST['phone'] ?? ''))),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['access_policy_id'] ?? '')),
                trim((string) ($_POST['status'] ?? 'pending')),
                trim((string) ($_POST['plain_password'] ?? '')),
                trim((string) ($_POST['notes_ar'] ?? '')),
                trim((string) ($_POST['rejection_reason_ar'] ?? '')),
                $adminId
            );
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                header('Location: /dashboard/customers.php?' . http_build_query(array_filter([
                    'status' => trim((string) ($_GET['status'] ?? 'pending')),
                    'q' => trim((string) ($_GET['q'] ?? '')),
                    'source' => trim((string) ($_GET['source'] ?? '')),
                    'details' => (string) ($result['id'] ?? ''),
                    'saved' => '1',
                ])));
                exit;
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1' && $flash === null) {
    $flash = 'تم حفظ بيانات العميل.';
}

$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$editId = trim((string) ($_GET['edit'] ?? ''));
$detailsId = trim((string) ($_GET['details'] ?? ''));
$orderPriceCurrency = DashboardOrderPricePreference::current();
$listLimit = 120;

$customers = WebCustomerService::listByStatus($statusFilter, $searchFilter, $sourceFilter, $listLimit);
$statusCounts = WebCustomerService::statusCounts();
$policies = WebCustomerService::listAccessPolicies();
$hasPolicies = $policies !== [];

$editCustomer = null;
$showEditPanel = false;
if ($editId === 'new') {
    $editCustomer = [
        'id' => '',
        'name_ar' => '',
        'phone' => '',
        'email' => '',
        'access_policy_id' => '',
        'status' => 'pending',
        'rejection_reason_ar' => '',
        'notes_ar' => '',
    ];
    $showEditPanel = $canManageCustomers;
} elseif ($editId !== '') {
    $editCustomer = WebCustomerService::getById($editId);
    if ($editCustomer === null) {
        $editId = '';
    } else {
        $showEditPanel = $canManageCustomers;
    }
}

$detailsCustomer = ($detailsId !== '' && !$showEditPanel) ? WebCustomerService::getById($detailsId) : null;
$customerOrders = [];
$customerOrderCount = 0;
if ($detailsCustomer !== null) {
    $customerOrderCount = OrderService::countForWebCustomer($detailsId);
    $customerOrders = OrderService::listForWebCustomer($detailsId, ['limit' => 10]);
}
$currentRoute = '/dashboard/customers.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/customers.php';
$content = ob_get_clean();
$title = 'عملاء الموقع';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
