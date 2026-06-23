<?php

declare(strict_types=1);

/** @var bool $siteOnboardingAutoStart */
$siteOnboardingAutoStart = (bool) ($siteOnboardingAutoStart ?? false);
?>
<div
  id="siteOnboarding"
  class="site-onboarding"
  hidden
  aria-hidden="true"
  role="dialog"
  aria-modal="true"
  aria-labelledby="siteOnboardingStepTitle"
  data-auto-start="<?= $siteOnboardingAutoStart ? '1' : '0' ?>"
>
  <div class="site-onboarding__dialog">
    <div class="site-onboarding__head">
      <p class="site-onboarding__eyebrow">دليل استخدام الموقع</p>
      <h2 class="site-onboarding__title" id="siteOnboardingTitle">الخطوة 1 من 5</h2>
      <div class="site-onboarding__progress" id="siteOnboardingDots" aria-hidden="true"></div>
    </div>
    <div class="site-onboarding__body">
      <div class="site-onboarding__icon" id="siteOnboardingIcon" aria-hidden="true">
        <span class="material-symbols-outlined text-3xl">waving_hand</span>
      </div>
      <h3 class="site-onboarding__step-title" id="siteOnboardingStepTitle">مرحباً بك</h3>
      <p class="site-onboarding__step-text" id="siteOnboardingStepText"></p>
      <div class="site-onboarding__links" id="siteOnboardingLinks"></div>
    </div>
    <div class="site-onboarding__foot">
      <button type="button" class="site-onboarding__skip" id="siteOnboardingSkip">تخطّي — لا تُظهر مرة أخرى</button>
      <div class="site-onboarding__actions">
        <button type="button" class="site-onboarding__btn site-onboarding__btn--ghost" id="siteOnboardingPrev">السابق</button>
        <button type="button" class="site-onboarding__btn site-onboarding__btn--primary" id="siteOnboardingNext">التالي</button>
      </div>
      <button type="button" class="sr-only" id="siteOnboardingDismiss">إغلاق</button>
    </div>
  </div>
</div>
