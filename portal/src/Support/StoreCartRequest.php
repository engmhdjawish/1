<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\CustomerSession;
use Portal\Services\OrderService;
use Portal\Services\ShareCartService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;

final class StoreCartRequest
{
    /** يعالج إضافة للسلة من نماذج المتجر. يُرجع رسالة للمستخدم أو null. */
    public static function handleAddToCartPost(): ?string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'add_to_cart') {
            return null;
        }

        $display = StoreCatalogService::displayOptions();
        if (!($display['allow_cart'] ?? false)) {
            return 'سياسة المتجر لا تسمح باستخدام السلة.';
        }

        $quantity = max(1, (int) round((float) ($_POST['quantity'] ?? 1)));
        $capturePrices = (bool) ($display['show_price'] ?? false);
        $line = ShareCartService::lineFromForm($_POST, $capturePrices);
        if ($line['material_guid'] === '') {
            return 'تعذر تحديد المادة.';
        }

        $result = StoreCartService::add($line, (float) $quantity);
        if ($result['ok']) {
            return $result['message'] !== '' ? $result['message'] : 'تمت إضافة الطرد إلى السلة.';
        }

        return $result['message'] !== '' ? $result['message'] : 'تعذر الإضافة إلى السلة.';
    }

    /** @return array{ok: bool, message: string, redirect?: string} */
    public static function handleSubmitOrderPost(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'submit_order') {
            return ['ok' => false, 'message' => ''];
        }

        $display = StoreCatalogService::displayOptions();
        if (!($display['allow_order'] ?? false)) {
            return ['ok' => false, 'message' => 'سياسة المتجر لا تسمح بإرسال الطلبات.'];
        }

        $guestName = trim((string) ($_POST['guest_name_ar'] ?? ''));
        $guestPhone = trim((string) ($_POST['guest_phone'] ?? ''));
        $notes = trim((string) ($_POST['notes_ar'] ?? ''));
        $loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
        if ($loggedInCustomer !== null) {
            if ($guestName === '') {
                $guestName = trim((string) ($loggedInCustomer['name_ar'] ?? ''));
            }
            if ($guestPhone === '') {
                $guestPhone = trim((string) ($loggedInCustomer['phone'] ?? ''));
            }
        }

        $cartItems = array_values(StoreCartService::items());
        if ($guestName === '' || text_length($guestName) < 2) {
            return ['ok' => false, 'message' => 'يرجى إدخال اسم صحيح (حرفان على الأقل).'];
        }
        if ($guestPhone === '' || preg_match('/\d{8,}/', preg_replace('/\D+/', '', $guestPhone)) !== 1) {
            return ['ok' => false, 'message' => 'يرجى إدخال رقم هاتف صحيح (8 أرقام على الأقل).'];
        }
        if ($cartItems === []) {
            return ['ok' => false, 'message' => 'السلة فارغة.'];
        }

        $result = OrderService::createGuestShareOrder(
            '',
            $guestName,
            $guestPhone,
            $notes !== '' ? $notes : null,
            $cartItems,
            $loggedInCustomer !== null ? (string) ($loggedInCustomer['id'] ?? '') : null
        );

        if (!$result['ok']) {
            StoreCartService::stashUnavailableLines(
                is_array($result['unavailable_items'] ?? null) ? $result['unavailable_items'] : []
            );

            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'تعذر حفظ الطلب.')];
        }

        $order = is_array($result['order'] ?? null) ? $result['order'] : null;
        if ($order === null) {
            return ['ok' => false, 'message' => 'تعذر حفظ الطلب.'];
        }

        StoreCartService::finalizeAfterSuccessfulOrder(
            is_array($result['submitted_material_guids'] ?? null) ? $result['submitted_material_guids'] : [],
            is_array($result['unavailable_items'] ?? null) ? $result['unavailable_items'] : []
        );

        if (CustomerSession::check()) {
            return [
                'ok' => true,
                'message' => 'تم إرسال الطلب بنجاح.',
                'redirect' => '/account.php?tab=orders&order=' . rawurlencode((string) ($order['id'] ?? '')),
            ];
        }

        if (!isset($_SESSION['store_order_success']) || !is_array($_SESSION['store_order_success'])) {
            $_SESSION['store_order_success'] = [];
        }
        $_SESSION['store_order_success'] = $order;

        return [
            'ok' => true,
            'message' => 'تم إرسال الطلب بنجاح.',
            'redirect' => '/store-order-confirmation.php',
        ];
    }
}
