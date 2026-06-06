<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;
use Portal\Services\ShareCartService;

/** @var list<array<string, mixed>> $sections */

$company = PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 140) {
        $aboutSnippet = substr($aboutSnippet, 0, 140) . '...';
    }
}
?>
<div class="space-y-8">
  <section class="relative overflow-hidden rounded-3xl bg-gradient-to-l from-primary to-red-700 text-white shadow-lg">
    <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 20% 20%, white 1px, transparent 1px); background-size: 24px 24px;"></div>
    <div class="relative px-6 py-10 md:px-10 md:py-14 grid gap-6 md:grid-cols-[1.4fr_1fr] items-center">
      <div>
        <p class="text-white/80 text-sm font-semibold mb-2">مرحباً بكم</p>
        <h1 class="text-3xl md:text-4xl font-extrabold leading-tight"><?= h($siteName) ?></h1>
        <p class="text-white/90 mt-3 leading-relaxed max-w-xl">
          <?= $aboutSnippet !== '' ? h($aboutSnippet) : 'تصفّح أحدث المواد، اطّلع على الأسعار حسب حسابك، واطلب بسهولة.' ?>
        </p>
        <div class="flex flex-wrap gap-3 mt-6">
          <a href="/store.php" class="h-12 inline-flex items-center gap-2 rounded-xl bg-white text-primary px-5 font-extrabold shadow-md hover:brightness-105">
            <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
          <a href="/about.php" class="h-12 inline-flex items-center gap-2 rounded-xl border border-white/40 px-5 font-bold hover:bg-white/10">
            من نحن
          </a>
        </div>
      </div>
      <div class="hidden md:grid grid-cols-2 gap-3">
        <article class="rounded-2xl bg-white/10 backdrop-blur px-4 py-5 border border-white/20">
          <span class="material-symbols-outlined text-3xl" aria-hidden="true">inventory_2</span>
          <p class="font-bold mt-2">تشكيلة واسعة</p>
          <p class="text-sm text-white/80 mt-1">مواد متنوعة مع صور وأسعار</p>
        </article>
        <article class="rounded-2xl bg-white/10 backdrop-blur px-4 py-5 border border-white/20">
          <span class="material-symbols-outlined text-3xl" aria-hidden="true">verified_user</span>
          <p class="font-bold mt-2">حسابات العملاء</p>
          <p class="text-sm text-white/80 mt-1">أسعار وصلاحيات حسب سياستك</p>
        </article>
        <article class="rounded-2xl bg-white/10 backdrop-blur px-4 py-5 border border-white/20 col-span-2">
          <span class="material-symbols-outlined text-3xl" aria-hidden="true">local_shipping</span>
          <p class="font-bold mt-2">طلب سهل</p>
          <p class="text-sm text-white/80 mt-1">سلة وطلب عبر روابط المشاركة أو حسابك المفعّل</p>
        </article>
      </div>
    </div>
  </section>

  <?php foreach ($sections as $section): ?>
    <?php
      $products = is_array($section['products'] ?? null) ? $section['products'] : [];
      $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
      $displayOptions = is_array($section['display_options'] ?? null) ? $section['display_options'] : [];
      $showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
      $priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
      $showPriceSyp = $priceMode === 'both' || $priceMode === 'syp';
      $showPriceUsd = $priceMode === 'both' || $priceMode === 'usd';
      $showAnyPrice = $showPriceSyp || $showPriceUsd;
    ?>
    <section class="bg-white rounded-2xl p-5 md:p-6 shadow-sm border border-gray-100" id="<?= h($sectionId) ?>">
      <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
          <h2 class="text-xl font-extrabold"><?= h((string) ($section['title_ar'] ?? '')) ?></h2>
          <?php if (!empty($section['subtitle_ar'])): ?>
            <p class="text-sm text-gray-500 mt-1"><?= h((string) $section['subtitle_ar']) ?></p>
          <?php endif; ?>
        </div>
        <a href="/store.php" class="text-sm text-primary font-bold">عرض المزيد</a>
      </div>

      <?php if (!empty($section['banner_image_url'])): ?>
        <div class="mb-4 rounded-2xl overflow-hidden border border-gray-100 max-h-44">
          <img src="<?= h((string) $section['banner_image_url']) ?>" alt="" class="w-full h-44 object-cover" loading="lazy">
        </div>
      <?php endif; ?>

      <?php if ($products === []): ?>
        <p class="text-gray-500 text-sm">لا توجد منتجات في هذا العرض حالياً.</p>
      <?php else: ?>
        <div class="home-strip flex gap-4 overflow-x-auto pb-3 snap-x snap-mandatory scroll-smooth -mx-1 px-1">
          <?php foreach ($products as $item): ?>
            <?php
              if (!is_array($item)) continue;
              $guid = material_guid($item);
              $cardUrl = $guid !== '' ? product_url($guid) : '/store.php';
              $packaging = ShareCartService::packaging($item);
              $primaryUnit = ShareCartService::primaryUnitLabel($item);
              $packageUnit = ShareCartService::packageUnitLabel($item);
              $packagePriceSp = ShareCartService::packageSalePriceSp($item);
              $packagePriceUsd = ShareCartService::packageSalePriceUsd($item);
              $imageGuid = material_image_guid($item);
            ?>
            <a href="<?= h($cardUrl) ?>" class="home-strip-card snap-start shrink-0 w-56 border border-gray-200 rounded-2xl bg-white shadow-sm overflow-hidden flex flex-col no-underline text-inherit">
              <?php if ($showImages): ?>
                <div class="h-32 bg-gray-100 flex items-center justify-center overflow-hidden">
                  <?php if ($imageGuid !== ''): ?>
                    <img src="/api/image.php?id=<?= urlencode($imageGuid) ?>&thumb=1" alt="<?= h((string) ($item['name'] ?? '')) ?>" class="h-32 w-full object-cover" loading="lazy">
                  <?php else: ?>
                    <span class="material-symbols-outlined text-gray-300 text-4xl" aria-hidden="true">inventory_2</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="p-3 flex flex-col flex-1">
                <div class="font-bold text-sm line-clamp-2 min-h-[2.5rem]"><?= h((string) ($item['name'] ?? '-')) ?></div>
                <div class="text-xs text-gray-500 mt-1"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
                <div class="mt-2 text-xs font-bold text-gray-600 rounded-full bg-gray-100 px-2 py-0.5 inline-block w-fit">
                  <?= h(format_packaging($packaging)) ?> <?= h($primaryUnit) ?>/<?= h($packageUnit) ?>
                </div>
                <?php if ($showAnyPrice): ?>
                  <?php if ($showPriceSyp && $packagePriceSp > 0): ?>
                    <div class="text-primary font-extrabold mt-2 text-sm"><?= format_money($packagePriceSp, true) ?> ل.س</div>
                  <?php endif; ?>
                  <?php if ($showPriceUsd && $packagePriceUsd > 0): ?>
                    <div class="text-emerald-700 font-bold mt-1 text-sm">$<?= number_format($packagePriceUsd, 2, '.', ',') ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($sections === []): ?>
    <section class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center">
      <p class="text-gray-500">لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php" class="inline-flex mt-4 h-11 items-center rounded-xl bg-primary text-white px-5 font-bold">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>
</div>

<style>
  .home-strip { scrollbar-width: thin; scrollbar-color: #D81921 #f3f4f6; }
  .home-strip::-webkit-scrollbar { height: 8px; }
  .home-strip::-webkit-scrollbar-thumb { background: #D81921; border-radius: 9999px; }
  .home-strip-card { transition: transform 0.15s ease, box-shadow 0.15s ease; }
  .home-strip-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
  .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
