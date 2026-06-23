<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\ShareCartService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;
use Portal\Support\StoreCartRequest;

require dirname(__DIR__) . '/views/helpers.php';

$display = StoreCatalogService::displayOptions();
$allowCart = (bool) ($display['allow_cart'] ?? false);
$allowOrder = (bool) ($display['allow_order'] ?? false);
$showPrice = (bool) ($display['show_price'] ?? false);
$error = null;
$notice = null;

if (!$allowCart) {
    $error = 'سياسة المتجر الحالية لا تسمح باستخدام السلة.';
}

$submitResult = StoreCartRequest::handleSubmitOrderPost();
if ($submitResult['message'] !== '' && !($submitResult['ok'] ?? false)) {
    $error = $submitResult['message'];
}
if (($submitResult['ok'] ?? false) && !empty($submitResult['redirect'])) {
    header('Location: ' . (string) $submitResult['redirect'], true, 303);
    exit;
}

if (!$error && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
    if ($action === 'update_item') {
        $qty = (float) ($_POST['quantity'] ?? 0);
        $result = StoreCartService::updateQuantity($materialGuid, $qty);
        $notice = $result['ok'] ? 'تم تحديث الكمية.' : ($result['message'] ?: 'تعذر التحديث.');
    } elseif ($action === 'remove_item') {
        if (StoreCartService::remove($materialGuid)) {
            $notice = 'تم حذف الصنف من السلة.';
        }
    } elseif ($action === 'clear_cart') {
        StoreCartService::clear();
        $notice = 'تم تفريغ السلة.';
    }
}

$cartItems = array_values(StoreCartService::items());
$unavailableItems = array_values(StoreCartService::unavailableItems());
$totals = StoreCartService::totals();
$loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
$defaultGuestName = (string) ($loggedInCustomer['name_ar'] ?? '');
$defaultGuestPhone = (string) ($loggedInCustomer['phone'] ?? '');
$maxPackagesPerMaterial = StorePolicyService::maxPackagesPerMaterial();

ob_start();
require dirname(__DIR__) . '/views/store-cart.php';
$content = ob_get_clean();
$title = 'سلة المتجر';
require dirname(__DIR__) . '/views/layout.php';
