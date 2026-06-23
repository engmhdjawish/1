<?php

declare(strict_types=1);

/** @var bool $siteOnboardingAutoStart */
/** @var bool $storeAllowCart */
/** @var bool $storeShowPrice */
/** @var array<string, mixed>|null $customer */

$siteOnboardingAutoStart = (bool) ($siteOnboardingAutoStart ?? false);
$storeAllowCart = (bool) ($storeAllowCart ?? false);
$storeShowPrice = (bool) ($storeShowPrice ?? false);
$customer = $customer ?? null;
?>
<div
  id="siteOnboarding"
  class="site-guide"
  hidden
  aria-hidden="true"
  data-auto-start="<?= $siteOnboardingAutoStart ? '1' : '0' ?>"
  data-auth="<?= $customer ? 'customer' : 'guest' ?>"
  data-cart="<?= $storeAllowCart ? '1' : '0' ?>"
  data-currency="<?= $storeShowPrice ? '1' : '0' ?>"
>
  <div class="site-guide__spotlight" id="siteGuideSpotlight" aria-hidden="true">
    <span class="site-guide__pulse" aria-hidden="true"></span>
  </div>
  <div
    class="site-guide__tooltip"
    id="siteGuideTooltip"
    role="dialog"
    aria-modal="true"
    aria-labelledby="siteGuideStepTitle"
  >
    <div class="site-guide__tooltip-head">
      <p class="site-guide__eyebrow" id="siteGuideProgress">الخطوة 1 من 5</p>
      <div class="site-guide__dots" id="siteGuideDots" aria-hidden="true"></div>
    </div>
    <div class="site-guide__tooltip-body">
      <div class="site-guide__icon" id="siteGuideIcon" aria-hidden="true">
        <span class="material-symbols-outlined text-3xl">waving_hand</span>
      </div>
      <h2 class="site-guide__title" id="siteGuideStepTitle">مرحباً بك</h2>
      <p class="site-guide__text" id="siteGuideStepText"></p>
    </div>
    <div class="site-guide__tooltip-foot">
      <button type="button" class="site-guide__skip" id="siteGuideSkip">تخطّي الدليل</button>
      <div class="site-guide__actions">
        <button type="button" class="site-guide__btn site-guide__btn--ghost" id="siteGuidePrev">السابق</button>
        <button type="button" class="site-guide__btn site-guide__btn--primary" id="siteGuideNext">التالي</button>
      </div>
    </div>
  </div>
</div>
