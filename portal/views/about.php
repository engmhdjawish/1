<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var string $aboutTitle */
/** @var string $aboutText */
?>
<section class="max-w-3xl mx-auto">
  <header class="mb-8 text-center">
    <?php if (!empty($companyLogoUrl)): ?>
      <img src="<?= h((string) $companyLogoUrl) ?>" alt="<?= h((string) ($company['company_name'] ?? '')) ?>" class="h-20 w-auto mx-auto mb-4 object-contain">
    <?php endif; ?>
    <h1 class="text-3xl font-extrabold text-slate-900"><?= h($aboutTitle) ?></h1>
    <?php if (trim((string) ($company['company_name'] ?? '')) !== ''): ?>
      <p class="text-text-muted mt-2"><?= h((string) $company['company_name']) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($aboutText !== ''): ?>
    <article class="bg-white border border-gray-200 rounded-2xl p-6 mb-6 prose prose-slate max-w-none">
      <?php foreach (preg_split("/\r\n|\n|\r/", $aboutText) ?: [] as $paragraph): ?>
        <?php $paragraph = trim((string) $paragraph); ?>
        <?php if ($paragraph === '') continue; ?>
        <p class="text-slate-700 leading-relaxed mb-4 last:mb-0"><?= nl2br(h($paragraph)) ?></p>
      <?php endforeach; ?>
    </article>
  <?php else: ?>
    <p class="text-center text-text-muted mb-6">لم يُضف محتوى تعريفي بعد. يمكن إدارته من لوحة التحكم → الإعدادات.</p>
  <?php endif; ?>

  <aside class="bg-white border border-gray-200 rounded-2xl p-6">
    <h2 class="font-bold text-lg mb-4">تواصل معنا</h2>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
      <?php if (trim((string) ($company['company_address'] ?? '')) !== ''): ?>
        <div>
          <dt class="text-text-muted text-xs mb-1">العنوان</dt>
          <dd class="font-semibold"><?= h((string) $company['company_address']) ?></dd>
        </div>
      <?php endif; ?>
      <?php if (trim((string) ($company['company_phone'] ?? '')) !== ''): ?>
        <div>
          <dt class="text-text-muted text-xs mb-1">الهاتف</dt>
          <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_phone']) ?></dd>
        </div>
      <?php endif; ?>
      <?php if (trim((string) ($company['company_mobile'] ?? '')) !== ''): ?>
        <div>
          <dt class="text-text-muted text-xs mb-1">الموبايل</dt>
          <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_mobile']) ?></dd>
        </div>
      <?php endif; ?>
      <?php if (trim((string) ($company['company_whatsapp'] ?? '')) !== ''): ?>
        <div>
          <dt class="text-text-muted text-xs mb-1">واتساب</dt>
          <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_whatsapp']) ?></dd>
        </div>
      <?php endif; ?>
      <?php if (trim((string) ($company['company_email'] ?? '')) !== ''): ?>
        <div>
          <dt class="text-text-muted text-xs mb-1">البريد</dt>
          <dd><a href="mailto:<?= h((string) $company['company_email']) ?>" class="font-semibold text-primary hover:underline" dir="ltr"><?= h((string) $company['company_email']) ?></a></dd>
        </div>
      <?php endif; ?>
    </dl>
  </aside>
</section>
