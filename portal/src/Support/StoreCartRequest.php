<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\CustomerSession;
use Portal\Services\OrderService;
use Portal\Services\ShareCartService;
use Portal\Services\StoreCartPricingService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;

final class StoreCartRequest
{
    /** @return array{ok: bool, message: string}|null */
    public static function handleAddToCartPost(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'add_to_cart') {
            return null;
        }

        $result = StoreCartApi::dispatch('add', $_POST);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /** @return array{ok: bool, message: string, redirect?: string, tracking_url?: string, order_number?: string} */
    public static function handleSubmitOrderPost(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'submit_order') {
            return ['ok' => false, 'message' => ''];
        }

        return self::handleSubmitOrderPostFromInput($_POST, StoreCatalogService::displayOptionsForCartContext($_POST));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $display
     * @return array{ok: bool, message: string, redirect?: string, tracking_url?: string, order_number?: string}
     */
    public static function handleSubmitOrderPostFromInput(array $input, array $display): array
    {
        if (!($display['allow_order'] ?? false)) {
            return ['ok' => false, 'message' => 'سياسة المتجر لا تسمح بإرسال الطلبات.'];
        }

        $guestName = trim((string) ($input['guest_name_ar'] ?? ''));
        $guestPhone = DigitNormalizer::normalizePhone(trim((string) ($input['guest_phone'] ?? '')));
        $notes = trim((string) ($input['notes_ar'] ?? ''));
        $loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
        if ($loggedInCustomer !== null) {
            $guestName = trim((string) ($loggedInCustomer['name_ar'] ?? ''));
            $guestPhone = trim((string) ($loggedInCustomer['phone'] ?? ''));
        }

        $cartItems = array_values(StoreCartService::items());
        if ($guestName === '' || text_length($guestName) < 2) {
            return ['ok' => false, 'message' => 'يرجى إدخال اسم صحيح (حرفان على الأقل).'];
        }
        if ($guestPhone === '' || preg_match('/\d{8,}/', preg_replace('/\D+/', '', $guestPhone)) !== 1) {
            return ['ok' => false, 'message' => 'يرجى إدخال رقم هاتف صحيح (8 أرقام على الأقل).'];
        }
        if ($cartItems === []) {
            return self::recentSubmitReplay() ?? ['ok' => false, 'message' => 'السلة فارغة.'];
        }

        $reconcile = ShareCartService::reconcileStock(StoreCartService::TOKEN);
        StoreCartPricingService::repriceCart(StoreCartService::TOKEN);
        $cartItems = array_values(StoreCartService::items());
        if ($cartItems === []) {
            $notices = is_array($reconcile['notices'] ?? null) ? $reconcile['notices'] : [];
            $message = $notices !== []
                ? implode(' ', $notices)
                : 'لا توجد أصناف متاحة للطلب. راجع قسم «غير المتوفرة» في السلة.';

            return ['ok' => false, 'message' => $message];
        }

        $result = OrderService::createGuestShareOrder(
            '',
            $guestName,
            $guestPhone,
            $notes !== '' ? $notes : null,
            $cartItems,
            $loggedInCustomer !== null ? (string) ($loggedInCustomer['id'] ?? '') : null,
            self::visitorSessionIdFromInput($input)
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
        StoreCartPricingService::clearPriceChangeNotices(StoreCartService::TOKEN);
        unset($_SESSION['store_cart_context']);

        $quoteToken = (string) ($order['quote_access_token'] ?? '');
        $orderNumber = (string) ($order['order_number'] ?? '');

        if (CustomerSession::check()) {
            $response = [
                'ok' => true,
                'message' => 'تم إرسال الطلب بنجاح.',
                'redirect' => '/my-orders.php?order=' . rawurlencode((string) ($order['id'] ?? '')),
                'order_number' => $orderNumber,
                'tracking_url' => $quoteToken !== '' ? order_tracking_url($quoteToken) : '',
            ];
        } else {
            $_SESSION['store_order_success'] = $order;
            $response = [
                'ok' => true,
                'message' => 'تم إرسال الطلب بنجاح.',
                'redirect' => '/store-order-confirmation.php',
                'order_number' => $orderNumber,
                'tracking_url' => $quoteToken !== '' ? order_tracking_url($quoteToken) : '',
            ];
        }
        if (!empty($result['duplicate'])) {
            $response['duplicate'] = true;
        }
        $_SESSION[self::LAST_SUBMIT_KEY] = ['at' => time(), 'order' => $order, 'response' => $response];

        return $response;
    }

    private const LAST_SUBMIT_KEY = 'store_last_order_submit';

    /**
     * A second tap that reaches the server after the first order already emptied the cart.
     *
     * @return array<string, mixed>|null
     */
    private static function recentSubmitReplay(): ?array
    {
        $last = $_SESSION[self::LAST_SUBMIT_KEY] ?? null;
        if (!is_array($last) || !is_array($last['response'] ?? null)) {
            return null;
        }
        if (time() - (int) ($last['at'] ?? 0) > OrderService::DUPLICATE_WINDOW_SECONDS) {
            return null;
        }
        if (is_array($last['order'] ?? null) && !CustomerSession::check()) {
            $_SESSION['store_order_success'] = $last['order'];
        }

        return $last['response'] + ['duplicate' => true];
    }

    /** @param array<string, mixed> $input */
    public static function visitorSessionIdFromInput(array $input): ?string
    {
        $id = trim((string) ($input['visitor_session_id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return substr($id, 0, 120);
    }
}
