<?php

declare(strict_types=1);

/** @var array<string, mixed> $config */
/** @var list<string> $allowedIps */
/** @var string $publicUrl */
/** @var string $legacyUrl */
/** @var string $viewerIp */
/** @var bool $ipAllowedForViewer */
/** @var string $flashOk */
/** @var string $flashError */
/** @var array<string, array<int, mixed>> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var list<array<string, mixed>> $specialOffers */
/** @var list<array<string, mixed>> $manualProducts */

require __DIR__ . '/partials/token-picker.php';
?>
<section class="mb-6">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">فاحص الأسعار في المحل</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">
        شاشة مسح الباركود للزبائن في المحل. الوصول محصور بعناوين IP محددة — بدون كلمة مرور.
        افتح الرابط على جهاز الشاشة بعد إضافة IP المحل إلى القائمة أدناه.
      </p>
    </div>
    <a href="/dashboard/site-content.php" class="h-10 px-4 inline-flex items-center gap-2 rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-lg">arrow_forward</span>
      محتوى الموقع
    </a>
  </div>
</section>

<?php if ($flashOk !== ''): ?>
  <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= h($flashOk) ?></p>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
  <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($flashError) ?></p>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
  <div class="xl:col-span-2 bg-white border border-border-subtle rounded-2xl p-5">
    <h2 class="text-lg font-extrabold text-slate-900 mb-1">روابط الشاشة</h2>
    <p class="text-sm text-text-muted mb-4">استخدم أحد الرابطين على جهاز الشاشة في المحل (وضع ملء الشاشة F11).</p>
    <div class="space-y-3">
      <div>
        <p class="text-xs font-bold text-text-muted mb-1">الرابط الرئيسي</p>
        <div class="flex flex-wrap gap-2 items-center">
          <code class="text-sm bg-slate-50 border border-border-subtle rounded-lg px-3 py-2 font-mono break-all" dir="ltr"><?= h($publicUrl) ?></code>
          <a href="/price-checker.php" target="_blank" rel="noopener" class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle text-sm font-bold text-primary hover:bg-slate-50">فتح</a>
        </div>
      </div>
      <div>
        <p class="text-xs font-bold text-text-muted mb-1">رابط قديم (متوافق)</p>
        <code class="text-sm bg-slate-50 border border-border-subtle rounded-lg px-3 py-2 font-mono break-all block" dir="ltr"><?= h($legacyUrl) ?></code>
      </div>
    </div>
  </div>

  <div class="bg-white border border-border-subtle rounded-2xl p-5">
    <h2 class="text-lg font-extrabold text-slate-900 mb-1">حالة الوصول</h2>
    <dl class="space-y-3 text-sm mt-4">
      <div>
        <dt class="text-text-muted font-bold">عنوانك الحالي (لوحة التحكم)</dt>
        <dd class="font-mono mt-0.5" dir="ltr"><?= $viewerIp !== '' ? h($viewerIp) : '—' ?></dd>
      </div>
      <div>
        <dt class="text-text-muted font-bold">IPs مسموحة</dt>
        <dd class="mt-0.5"><?= count($allowedIps) ?> عنوان</dd>
      </div>
      <div>
        <dt class="text-text-muted font-bold">هل يمكنك فتح الشاشة الآن؟</dt>
        <dd class="mt-0.5 font-bold <?= $ipAllowedForViewer ? 'text-emerald-700' : 'text-amber-700' ?>">
          <?= $ipAllowedForViewer ? 'نعم' : 'لا — أضف IP المحل أولاً' ?>
        </dd>
      </div>
      <div>
        <dt class="text-text-muted font-bold">الصفحة</dt>
        <dd class="mt-0.5"><?= !empty($config['enabled']) ? 'مفعّلة' : 'معطّلة' ?></dd>
      </div>
    </dl>
  </div>
</div>

<form method="post" class="space-y-6">
  <input type="hidden" name="action" value="save">

  <section class="bg-white border border-border-subtle rounded-2xl p-5 space-y-4">
    <h2 class="text-lg font-extrabold text-slate-900">عام</h2>

    <label class="flex items-center gap-3 cursor-pointer">
      <input type="checkbox" name="enabled" value="1" class="rounded border-border-subtle" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
      <span class="text-sm font-bold text-slate-800">تفعيل فاحص الأسعار</span>
    </label>

    <div>
      <label class="block text-sm font-bold text-slate-800 mb-1" for="page_title_ar">عنوان الصفحة</label>
      <input id="page_title_ar" name="page_title_ar" value="<?= h((string) ($config['page_title_ar'] ?? '')) ?>" class="h-11 w-full max-w-md rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary">
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-xl">
      <div>
        <label class="block text-sm font-bold text-slate-800 mb-1" for="display_seconds">مدة عرض المنتج (ثانية)</label>
        <input type="number" min="2" max="120" id="display_seconds" name="display_seconds" value="<?= (int) ($config['display_seconds'] ?? 5) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 text-sm">
      </div>
      <div>
        <label class="block text-sm font-bold text-slate-800 mb-1" for="error_display_seconds">مدة رسالة الخطأ (ثانية)</label>
        <input type="number" min="2" max="60" id="error_display_seconds" name="error_display_seconds" value="<?= (int) ($config['error_display_seconds'] ?? 5) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 text-sm">
      </div>
    </div>
  </section>

  <section class="bg-white border border-border-subtle rounded-2xl p-5 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-extrabold text-slate-900">عناوين IP المسموحة</h2>
        <p class="text-sm text-text-muted mt-1">سطر واحد لكل عنوان. فقط هذه الأجهزة يمكنها فتح الشاشة — بدون كلمة مرور.</p>
      </div>
    </div>

    <textarea
      id="allowed_ips"
      name="allowed_ips"
      rows="6"
      dir="ltr"
      class="w-full rounded-xl border border-border-subtle px-4 py-3 text-sm font-mono focus:border-primary focus:ring-primary"
      placeholder="203.0.113.45&#10;198.51.100.12"
    ><?= h((string) ($config['allowed_ips'] ?? '')) ?></textarea>
    <p class="text-xs text-text-muted">
      أضف <strong>الـ IP العام</strong> لشبكة المحل (ما يظهر لموقع jawishco.sy عند فتحه من المحل).
      إذا كان الموقع خلف Cloudflare، استخدم IP العام للمحل وليس IP داخلي مثل 192.168.x.x.
    </p>
    <?php if ($viewerIp !== ''): ?>
      <button
        type="submit"
        formaction="/dashboard/price-checker.php"
        name="action"
        value="add_current_ip"
        class="h-10 px-4 rounded-xl border border-primary/30 bg-primary/5 text-sm font-bold text-primary hover:bg-primary/10"
      >
        إضافة IP الحالي (<?= h($viewerIp) ?>)
      </button>
    <?php endif; ?>
  </section>

  <section class="bg-white border border-border-subtle rounded-2xl p-5 space-y-4">
    <h2 class="text-lg font-extrabold text-slate-900">إعلانات الشاشة (شريط الدعاية)</h2>

    <label class="flex items-center gap-3 cursor-pointer">
      <input type="checkbox" name="slideshow_enabled" value="1" class="rounded border-border-subtle" <?= !empty($config['slideshow_enabled']) ? 'checked' : '' ?>>
      <span class="text-sm font-bold text-slate-800">تفعيل عرض المنتجات أثناء الانتظار</span>
    </label>

    <label class="flex items-center gap-3 cursor-pointer">
      <input type="checkbox" name="slideshow_show_price" value="1" class="rounded border-border-subtle" <?= !empty($config['slideshow_show_price']) ? 'checked' : '' ?>>
      <span class="text-sm font-bold text-slate-800">عرض الأسعار في الإعلانات</span>
    </label>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-bold text-slate-800 mb-1" for="slideshow_count">عدد المنتجات في الدفعة</label>
        <input type="number" min="1" max="20" id="slideshow_count" name="slideshow_count" value="<?= (int) ($config['slideshow_count'] ?? 5) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 text-sm">
      </div>
      <div>
        <label class="block text-sm font-bold text-slate-800 mb-1" for="slideshow_interval_ms">مدة كل إعلان (مللي ثانية)</label>
        <input type="number" min="3000" max="120000" step="1000" id="slideshow_interval_ms" name="slideshow_interval_ms" value="<?= (int) ($config['slideshow_interval_ms'] ?? 20000) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 text-sm">
        <p class="text-xs text-text-muted mt-1">20000 = 20 ثانية</p>
      </div>
      <div>
        <label class="block text-sm font-bold text-slate-800 mb-1" for="slideshow_cache_seconds">مدة التخزين المؤقت (ثانية)</label>
        <input type="number" min="30" max="3600" id="slideshow_cache_seconds" name="slideshow_cache_seconds" value="<?= (int) ($config['slideshow_cache_seconds'] ?? 300) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 text-sm">
      </div>
    </div>

    <?php require __DIR__ . '/partials/price-checker-slideshow-settings.php'; ?>
  </section>

  <div class="flex flex-wrap gap-3">
    <button type="submit" class="h-11 px-6 rounded-xl bg-primary text-white text-sm font-extrabold hover:bg-primary/90">
      حفظ الإعدادات
    </button>
    <button
      type="submit"
      formaction="/dashboard/price-checker.php"
      name="action"
      value="clear_slideshow_cache"
      class="h-11 px-6 rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50"
    >
      مسح ذاكرة الإعلانات
    </button>
    <a href="/price-checker.php" target="_blank" rel="noopener" class="h-11 px-6 inline-flex items-center rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50">
      معاينة الشاشة
    </a>
  </div>
</form>
