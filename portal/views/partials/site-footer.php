<?php

declare(strict_types=1);

/** @var array<string, string> $companyContext */
/** @var string|null $companyLogoUrl */
/** @var string $siteName */
/** @var bool|null $customer */

$companyContext = is_array($companyContext ?? null) ? $companyContext : [];
$siteName = trim((string) ($siteName ?? '')) !== ''
    ? (string) $siteName
    : (trim((string) ($companyContext['company_name'] ?? '')) !== '' ? (string) $companyContext['company_name'] : 'جاويش للتجارة');
$companyLogoUrl = $companyLogoUrl ?? null;
$customer = (bool) ($customer ?? false);

$aboutSnippet = trim((string) ($companyContext['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 160) {
        $aboutSnippet = substr($aboutSnippet, 0, 160) . '...';
    }
}

$whatsapp = preg_replace('/\D+/', '', (string) ($companyContext['company_whatsapp'] ?? ''));
$whatsappLink = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp : '';
$phone = trim((string) ($companyContext['company_phone'] ?? ''));
$mobile = trim((string) ($companyContext['company_mobile'] ?? ''));
$email = trim((string) ($companyContext['company_email'] ?? ''));
$address = trim((string) ($companyContext['company_address'] ?? ''));
?>
<footer class="site-footer mt-auto">
  <div class="max-w-7xl mx-auto px-4 py-10 md:py-12">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8">
      <div class="xl:col-span-1">
        <div class="site-footer-brand mb-4">
          <?php if (!empty($companyLogoUrl)): ?>
            <?php
              $siteLogoVariant = 'footer';
              $siteLogoAlt = $siteName;
              require __DIR__ . '/site-logo.php';
            ?>
          <?php endif; ?>
          <h2 class="text-lg font-extrabold text-white"><?= h($siteName) ?></h2>
        </div>
        <p class="text-sm leading-7 text-gray-300">
          <?= $aboutSnippet !== '' ? h($aboutSnippet) : 'متجر إلكتروني لتصفح المواد والطلب بسهولة حسب سياسة حسابك.' ?>
        </p>
      </div>

      <div>
        <h3 class="text-sm font-extrabold text-white mb-4">روابط سريعة</h3>
        <div class="space-y-2 text-sm">
          <a href="/index.php" class="block">الرئيسية</a>
          <a href="/store.php" class="block">المتجر</a>
          <a href="/about.php" class="block">من نحن</a>
          <?php if (!$customer): ?>
            <a href="/login.php?type=customer" class="block">دخول العملاء</a>
            <a href="/register.php" class="block">تسجيل عميل جديد</a>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <h3 class="text-sm font-extrabold text-white mb-4">تواصل معنا</h3>
        <div class="text-sm">
          <?php if ($phone !== ''): ?>
            <div class="site-footer-contact-item">
              <span class="site-footer-contact-icon"><span class="material-symbols-outlined text-base" aria-hidden="true">call</span></span>
              <div><p class="text-xs text-gray-400 mb-0.5">الهاتف</p><p class="font-bold" dir="ltr"><?= h($phone) ?></p></div>
            </div>
          <?php endif; ?>
          <?php if ($mobile !== ''): ?>
            <div class="site-footer-contact-item">
              <span class="site-footer-contact-icon"><span class="material-symbols-outlined text-base" aria-hidden="true">smartphone</span></span>
              <div><p class="text-xs text-gray-400 mb-0.5">الموبايل</p><p class="font-bold" dir="ltr"><?= h($mobile) ?></p></div>
            </div>
          <?php endif; ?>
          <?php if ($email !== ''): ?>
            <div class="site-footer-contact-item">
              <span class="site-footer-contact-icon"><span class="material-symbols-outlined text-base" aria-hidden="true">mail</span></span>
              <div><p class="text-xs text-gray-400 mb-0.5">البريد</p><a href="mailto:<?= h($email) ?>" class="font-bold" dir="ltr"><?= h($email) ?></a></div>
            </div>
          <?php endif; ?>
          <?php if ($address !== ''): ?>
            <div class="site-footer-contact-item">
              <span class="site-footer-contact-icon"><span class="material-symbols-outlined text-base" aria-hidden="true">location_on</span></span>
              <div><p class="text-xs text-gray-400 mb-0.5">العنوان</p><p class="font-bold leading-6"><?= h($address) ?></p></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <h3 class="text-sm font-extrabold text-white mb-4">ابدأ التسوق</h3>
        <p class="text-sm text-gray-300 leading-7 mb-4">تصفّح أحدث المواد واطلب مباشرة من المتجر أو عبر حسابك المفعّل.</p>
        <a href="/store.php" class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary px-4 text-sm font-extrabold text-white hover:brightness-110 transition">
          <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
          تصفّح المتجر
        </a>
        <?php if ($whatsappLink !== ''): ?>
          <a href="<?= h($whatsappLink) ?>" target="_blank" rel="noopener" class="site-footer-whatsapp">
            <span class="material-symbols-outlined text-base" aria-hidden="true">chat</span>
            واتساب
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="site-footer-bottom py-4 text-center text-xs">
    <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
      <span>© <?= date('Y') ?> <?= h($siteName) ?>. جميع الحقوق محفوظة.</span>
      <a href="/about.php" class="text-gray-400 hover:text-white">من نحن</a>
    </div>
  </div>
</footer>
