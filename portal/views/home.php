<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;

/** @var list<array<string, mixed>> $sections */
?>
<div class="space-y-10">
  <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold mb-2">مرحباً بكم في جاويش للتجارة</h1>
        <p class="text-gray-600">تصفّح الأقسام أدناه أو ادخل <a href="/store.php" class="text-primary font-semibold">المتجر العام</a>.</p>
      </div>
      <a href="/index.php" class="h-10 inline-flex items-center gap-2 rounded-full border border-gray-300 px-4 text-sm font-bold hover:border-primary">
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">refresh</span>
        تحديث العرض
      </a>
    </div>
    <p class="text-xs text-gray-500 mt-2">كل قسم يعرض مواداً مختلفة عشوائياً ضمن الفلاتر المحددة في لوحة التحكم.</p>
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
    <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100" id="<?= h($sectionId) ?>">
      <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
          <h2 class="text-xl font-extrabold"><?= h((string) ($section['title_ar'] ?? '')) ?></h2>
          <?php if (!empty($section['subtitle_ar'])): ?>
            <p class="text-sm text-gray-500 mt-1"><?= h((string) $section['subtitle_ar']) ?></p>
          <?php endif; ?>
        </div>
        <span class="text-xs text-gray-500"><?= count($products) ?> مادة في هذا العرض</span>
      </div>

      <?php if (!empty($section['banner_image_url'])): ?>
        <div class="mb-4 rounded-xl overflow-hidden border border-gray-100 max-h-40">
          <img src="<?= h((string) $section['banner_image_url']) ?>" alt="" class="w-full h-40 object-cover" loading="lazy">
        </div>
      <?php endif; ?>

      <?php if ($products === []): ?>
        <p class="text-gray-500 text-sm">لا توجد منتجات في هذا العرض حالياً (تحقق من الفلاتر أو اتصال API).</p>
      <?php else: ?>
        <div class="relative">
          <div class="home-strip flex gap-4 overflow-x-auto pb-3 snap-x snap-mandatory scroll-smooth -mx-1 px-1">
            <?php foreach ($products as $item): ?>
              <?php if (!is_array($item)) continue;
                $packaging = ShareCartService::packaging($item);
                $primaryUnit = ShareCartService::primaryUnitLabel($item);
                $packageUnit = ShareCartService::packageUnitLabel($item);
                $unitSaleSp = ShareCartService::unitSalePriceSp($item);
                $unitSaleUsd = ShareCartService::unitSalePriceUsd($item);
                $packagePriceSp = ShareCartService::packageSalePriceSp($item);
                $packagePriceUsd = ShareCartService::packageSalePriceUsd($item);
                $imageGuid = trim((string) ($item['productImageGuid'] ?? $item['ProductImageGuid'] ?? ''));
              ?>
              <article class="home-strip-card snap-start shrink-0 w-56 border border-gray-200 rounded-xl bg-white shadow-sm overflow-hidden flex flex-col">
                <?php if ($showImages): ?>
                <div class="h-32 bg-gray-100 flex items-center justify-center">
                  <?php if ($imageGuid !== ''): ?>
                    <img
                      src="/api/image.php?id=<?= urlencode($imageGuid) ?>&thumb=1"
                      alt="<?= h((string) ($item['name'] ?? '')) ?>"
                      class="h-32 w-full object-cover"
                      loading="lazy"
                    >
                  <?php else: ?>
                    <span class="material-symbols-outlined text-gray-300 text-4xl" aria-hidden="true">inventory_2</span>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="p-3 flex flex-col flex-1">
                  <div class="font-bold text-sm line-clamp-2 min-h-[2.5rem]"><?= h((string) ($item['name'] ?? '-')) ?></div>
                  <div class="text-xs text-gray-500 mt-1"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
                  <div class="mt-2 text-xs font-bold text-gray-600 rounded-full bg-gray-100 px-2 py-0.5 inline-block w-fit">
                    تعبئة: <?= h(rtrim(rtrim(number_format($packaging, 2, '.', ','), '0'), '.')) ?>
                    <?= h($primaryUnit) ?>/<?= h($packageUnit) ?>
                  </div>
                  <?php if ($showAnyPrice): ?>
                    <?php if ($showPriceSyp && $packagePriceSp > 0): ?>
                      <div class="text-primary font-extrabold mt-2 text-sm">
                        <?= format_money($packagePriceSp, true) ?> ل.س
                        <span class="text-xs font-normal text-gray-500">/ <?= h($packageUnit) ?></span>
                      </div>
                    <?php elseif ($showPriceSyp && $unitSaleSp > 0): ?>
                      <div class="text-xs text-gray-500 mt-2"><?= format_money($unitSaleSp, true) ?> ل.س / <?= h($primaryUnit) ?></div>
                    <?php endif; ?>
                    <?php if ($showPriceUsd && $packagePriceUsd > 0): ?>
                      <div class="text-emerald-700 font-bold mt-1 text-sm">
                        $<?= number_format($packagePriceUsd, 2, '.', ',') ?>
                        <span class="text-xs font-normal text-gray-500">/ <?= h($packageUnit) ?></span>
                      </div>
                    <?php elseif ($showPriceUsd && $unitSaleUsd > 0): ?>
                      <div class="text-xs text-gray-500 mt-1">$<?= number_format($unitSaleUsd, 2, '.', ',') ?> / <?= h($primaryUnit) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($sections === []): ?>
    <p class="text-center text-gray-500 text-sm">لا توجد أقسام نشطة. فعّل الأقسام من <a href="/dashboard/home-sections.php" class="text-primary font-bold">لوحة التحكم</a>.</p>
  <?php endif; ?>
</div>

<style>
  .home-strip { scrollbar-width: thin; scrollbar-color: #D81921 #f3f4f6; }
  .home-strip::-webkit-scrollbar { height: 8px; }
  .home-strip::-webkit-scrollbar-thumb { background: #D81921; border-radius: 9999px; }
  .home-strip-card { transition: transform 0.15s ease, box-shadow 0.15s ease; }
  .home-strip-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
  .line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
