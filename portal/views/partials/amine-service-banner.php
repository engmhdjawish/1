<?php

declare(strict_types=1);

use Portal\Services\AmineAvailabilityService;

$amineOnline = AmineAvailabilityService::isAvailable();
$amineMessage = $amineOnline ? '' : AmineAvailabilityService::userMessage();
if ($amineOnline || $amineMessage === '') {
    return;
}
?>
<div class="amine-service-banner" role="status" aria-live="polite">
  <span class="material-symbols-outlined amine-service-banner__icon" aria-hidden="true">cloud_off</span>
  <div class="amine-service-banner__body">
    <strong class="amine-service-banner__title">خدمة مؤقتة — جاري المعالجة</strong>
    <p class="amine-service-banner__text"><?= h($amineMessage) ?></p>
  </div>
</div>
