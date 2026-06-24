<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
/** @var string|null $trackingUrl */
/** @var bool $showTrackingLink */
/** @var bool $allowCustomerCancel */
/** @var string|null $cancelQuoteToken */

$trackingUrl ??= '';
$showTrackingLink = (bool) ($showTrackingLink ?? false);
$allowCustomerCancel = (bool) ($allowCustomerCancel ?? !empty($order['can_customer_cancel']));
$cancelQuoteToken = isset($cancelQuoteToken) ? (string) $cancelQuoteToken : null;
$customerCancelBlockReason = (string) ($order['customer_cancel_block_reason'] ?? '');
$cancelOrderId = (string) ($order['id'] ?? '');
$activeItemCount = (int) ($order['summary']['items_count'] ?? 0);
$canCancelItems = $allowCustomerCancel && $activeItemCount > 1;
$items = is_array($order['items'] ?? null) ? $order['items'] : [];
$timeline = is_array($order['timeline'] ?? null) ? $order['timeline'] : [];
$changes = is_array($order['item_changes'] ?? null) ? $order['item_changes'] : [];
$status = (string) ($order['status'] ?? 'pending');
$showPriceSyp = (float) ($order['total_sp'] ?? 0) > 0;
$showPriceUsd = !$showPriceSyp && (float) ($order['total_usd'] ?? 0) > 0;
$orderImagesDownloadUrl = '/api/order-images-zip.php?order_id=' . rawurlencode((string) ($order['id'] ?? ''));
$quoteToken = trim((string) ($order['quote_access_token'] ?? ''));
if ($quoteToken !== '') {
    $orderImagesDownloadUrl .= '&token=' . rawurlencode($quoteToken);
}
?>
<section class="customer-order-detail">
  <header class="customer-order-detail__header">
    <div>
      <p class="customer-order-detail__kicker">تفاصيل الطلب</p>
      <h2 class="customer-order-detail__title" dir="ltr"><?= h((string) ($order['order_number'] ?? '')) ?></h2>
      <?php if (!empty($order['created_at'])): ?>
        <p class="customer-order-detail__date"><?= h(accounting_format_date($order['created_at'])) ?></p>
      <?php endif; ?>
    </div>
    <div class="flex flex-col items-end gap-2">
      <?php require __DIR__ . '/order-status-badge.php'; ?>
      <a href="<?= h($orderImagesDownloadUrl) ?>" class="store-btn store-btn--secondary text-sm inline-flex items-center gap-1" download>
        <span class="material-symbols-outlined text-base" aria-hidden="true">folder_zip</span>
        تحميل صور الطلب
      </a>
    </div>
  </header>

  <?php if ($allowCustomerCancel): ?>
    <div class="customer-order-cancel-bar">
      <p class="customer-order-cancel-bar__hint">الطلب جديد ولم يراجعه فريقنا بعد — يمكنك إلغاؤه أو إزالة أصناف منه.</p>
      <form
        method="post"
        class="customer-order-cancel-bar__form"
        data-customer-cancel-confirm="هل تريد إلغاء الطلب بالكامل؟ لا يمكن التراجع عن هذا الإجراء."
      >
        <input type="hidden" name="action" value="cancel_order">
        <input type="hidden" name="order_id" value="<?= h($cancelOrderId) ?>">
        <?php if ($cancelQuoteToken !== null && $cancelQuoteToken !== ''): ?>
          <input type="hidden" name="token" value="<?= h($cancelQuoteToken) ?>">
        <?php endif; ?>
        <button type="submit" class="store-btn store-btn--danger">
          <span class="material-symbols-outlined text-base" aria-hidden="true">cancel</span>
          إلغاء الطلب بالكامل
        </button>
      </form>
    </div>
  <?php elseif ($customerCancelBlockReason !== '' && $status === 'pending'): ?>
    <p class="customer-order-cancel-note"><?= h($customerCancelBlockReason) ?></p>
  <?php endif; ?>

  <div class="customer-order-detail__grid">
    <article class="customer-order-card">
      <h3 class="customer-order-card__title">بيانات الطلب</h3>
      <dl class="customer-order-dl">
        <div>
          <dt>الاسم</dt>
          <dd><?= h((string) ($order['display_name'] ?? '')) ?></dd>
        </div>
        <div>
          <dt>الهاتف</dt>
          <dd dir="ltr"><?= h((string) ($order['display_phone'] ?? '')) ?></dd>
        </div>
        <?php if ($showPriceSyp): ?>
          <div>
            <dt>الإجمالي</dt>
            <dd class="customer-order-dl__total store-num" dir="ltr"><?= format_money((float) ($order['total_sp'] ?? 0), true) ?> ل.س</dd>
          </div>
        <?php elseif ($showPriceUsd): ?>
          <div>
            <dt>الإجمالي</dt>
            <dd class="customer-order-dl__total store-num" dir="ltr">$<?= number_format((float) ($order['total_usd'] ?? 0), 2, '.', ',') ?></dd>
          </div>
        <?php endif; ?>
      </dl>
      <?php if (!empty($order['notes_ar'])): ?>
        <div class="customer-order-note">
          <span class="customer-order-note__label">ملاحظاتك</span>
          <p><?= h((string) $order['notes_ar']) ?></p>
        </div>
      <?php endif; ?>
    </article>

    <?php if ($showTrackingLink && $trackingUrl !== ''): ?>
      <article class="customer-order-card customer-order-card--accent">
        <h3 class="customer-order-card__title">رابط المتابعة</h3>
        <p class="customer-order-card__hint">احفظ هذا الرابط لمتابعة طلبك أو شاركه.</p>
        <div class="customer-order-tracking">
          <input type="text" readonly value="<?= h($trackingUrl) ?>" class="store-input text-xs font-mono" dir="ltr" id="orderTrackingUrlField">
          <button type="button" class="store-btn store-btn--secondary shrink-0" data-copy-tracking-url="<?= h($trackingUrl) ?>">نسخ</button>
        </div>
      </article>
    <?php endif; ?>
  </div>

  <?php if ($items !== []): ?>
    <article class="customer-order-card">
      <h3 class="customer-order-card__title">الأصناف (<?= (int) ($order['summary']['items_count'] ?? count($items)) ?>)</h3>
      <div class="store-order-lines">
        <?php foreach ($items as $item): ?>
          <div class="customer-order-line-stack">
            <?php require __DIR__ . '/store-order-line-card.php'; ?>
            <?php if ($canCancelItems && empty($item['is_cancelled'])): ?>
              <form
                method="post"
                class="customer-order-line-cancel"
                data-customer-cancel-confirm="إلغاء «<?= h((string) ($item['material_name_ar'] ?? 'هذا الصنف')) ?>» من الطلب؟"
              >
                <input type="hidden" name="action" value="cancel_order_item">
                <input type="hidden" name="order_id" value="<?= h($cancelOrderId) ?>">
                <input type="hidden" name="item_id" value="<?= h((string) ($item['id'] ?? '')) ?>">
                <?php if ($cancelQuoteToken !== null && $cancelQuoteToken !== ''): ?>
                  <input type="hidden" name="token" value="<?= h($cancelQuoteToken) ?>">
                <?php endif; ?>
                <button type="submit" class="customer-order-line-cancel__btn">
                  <span class="material-symbols-outlined text-sm" aria-hidden="true">remove_shopping_cart</span>
                  إلغاء هذا الصنف
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  <?php endif; ?>

  <?php if ($timeline !== []): ?>
    <article class="customer-order-card">
      <h3 class="customer-order-card__title">سجل التحديثات</h3>
      <ol class="customer-order-timeline">
        <?php foreach ($timeline as $entry): ?>
          <li class="customer-order-timeline__item<?= ($entry['type'] ?? '') === 'staff_edit' ? ' customer-order-timeline__item--edit' : '' ?>">
            <span class="customer-order-timeline__dot" aria-hidden="true"></span>
            <div>
              <div class="customer-order-timeline__label"><?= h((string) ($entry['label'] ?? '')) ?></div>
              <?php if (!empty($entry['detail'])): ?>
                <div class="customer-order-timeline__detail"><?= h((string) $entry['detail']) ?></div>
              <?php endif; ?>
              <?php if (!empty($entry['at'])): ?>
                <div class="customer-order-timeline__at"><?= h(accounting_format_date($entry['at'])) ?></div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </article>
  <?php endif; ?>
</section>

<script>
  (() => {
    document.querySelectorAll('[data-customer-cancel-confirm]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-customer-cancel-confirm') || 'تأكيد الإلغاء؟';
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  })();
</script>
