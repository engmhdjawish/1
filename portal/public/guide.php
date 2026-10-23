<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;

require dirname(__DIR__) . '/views/helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$companyContext = PortalSettingsService::companySettings();
$companyLogoUrl = PortalSettingsService::companyLogoUrl($companyContext);
$siteName = trim((string) ($companyContext['company_name'] ?? '')) ?: 'جاويش للتجارة';
$whatsapp = preg_replace('/\D+/', '', (string) ($companyContext['company_whatsapp'] ?? '')) ?? '';

$guideHomeUrl = '/home-next.php?tour=1';
$guideStoreUrl = '/store-lab.php?tour=1';
$guideCartUrl = '/store-cart.php?tour=1';

/**
 * @var list<array{id: string, icon: string, title: string, lead: string, link: array{0: string, 1: string}|null,
 *   steps: list<array{icon: string, title: string, text: string}>}>
 */
$chapters = [
    [
        'id' => 'home',
        'icon' => 'home',
        'title' => 'الصفحة الرئيسية',
        'lead' => 'نقطة البداية: ابحث مباشرة، أو ادخل من قسم، أو اختر خطوة بخطوة.',
        'link' => [$guideHomeUrl, 'ابدأ الجولة التفاعلية'],
        'steps' => [
            ['icon' => 'search', 'title' => 'البحث', 'text' => 'اكتب اسم المادة أو الشركة أو الرمز في مربع البحث أعلى الصفحة. تظهر النتائج أثناء الكتابة مقسومة إلى مواد وأقسام وشركات، واضغط أي نتيجة لفتحها. زر الميكروفون للبحث بالصوت إن كان جهازك يدعمه.'],
            ['icon' => 'radio_button_checked', 'title' => 'الدوائر السريعة', 'text' => 'الشريط الدائري تحت البحث يفتح الموسم الحالي والعروض والأقسام والفئات (رجالي، نسائي، ولادي…) بلمسة واحدة. اسحب يميناً ويساراً لرؤية المزيد.'],
            ['icon' => 'touch_app', 'title' => 'تسوّق خطوة بخطوة', 'text' => 'اختر لمن (الفئة)، ثم النوع، ثم القياس، وتنتقل إلى المتجر والنتائج مفلترة على اختيارك. زر «الكل» يتخطى الخطوة.'],
            ['icon' => 'category', 'title' => 'تسوّق حسب', 'text' => 'التبويبات (النوع، الشركة، القياس…) تعرض كل القيم المتاحة. اضغط قيمة لرؤية موادها في المتجر.'],
            ['icon' => 'currency_exchange', 'title' => 'العملة', 'text' => 'زر «ل.س / $» بجانب اسم الشركة يغيّر عملة عرض الأسعار في كل الصفحات. أسعار الليرة تقريبية حسب سعر الصرف وقت الطلب.'],
            ['icon' => 'space_dashboard', 'title' => 'الشريط السفلي في الموبايل', 'text' => 'أسفل الشاشة تجد الرئيسية، الأقسام، البحث، السلة (مع عدد الطرود)، وحسابك. يبقى ظاهراً أثناء التمرير.'],
        ],
    ],
    [
        'id' => 'store',
        'icon' => 'storefront',
        'title' => 'المتجر',
        'lead' => 'كل المواد في مكان واحد، مع فلاتر وترتيب وصور كبيرة.',
        'link' => [$guideStoreUrl, 'ابدأ الجولة التفاعلية'],
        'steps' => [
            ['icon' => 'search', 'title' => 'مربع بحث واحد', 'text' => 'ابحث بالاسم أو الرمز أو اسم الشركة. البحث يعمل مع الفلاتر المختارة.'],
            ['icon' => 'filter_alt', 'title' => 'الفلاتر', 'text' => 'اختر النوع والشركة والقياس والفئة من الشريط أعلى القائمة. لإلغاء فلتر اضغط عليه مرة ثانية.'],
            ['icon' => 'inventory', 'title' => 'المتوفر فقط والترتيب', 'text' => 'فعّل «المتوفر فقط» لإخفاء غير المتوفر. المواد غير المتوفرة تظهر دائماً في آخر القائمة وعليها شريط أحمر «غير متوفر حالياً».'],
            ['icon' => 'grid_view', 'title' => 'شكل العرض', 'text' => 'بدّل بين عرض الشبكة وعرض القائمة من الزرين فوق المواد. في الموبايل تظهر الصورة كبيرة بعرض الشاشة.'],
            ['icon' => 'package_2', 'title' => 'الطرد والقطعة', 'text' => 'البيع بالطرد. على كل بطاقة: عدد القطع في الطرد والقياسات وسعر الطرد (عند ظهور الأسعار لحسابك). أضف المادة إلى السلة بعدد الطرود الذي تريده.'],
            ['icon' => 'share', 'title' => 'مشاركة مادة', 'text' => 'زر المشاركة على البطاقة يرسل رابط المادة مع صورتها واسمها عبر واتساب أو غيره. المعاينة لا تتضمن السعر.'],
        ],
    ],
    [
        'id' => 'cart',
        'icon' => 'shopping_cart',
        'title' => 'السلة وإرسال الطلب',
        'lead' => 'راجع الطرود والمجموع ثم أرسل الطلب مرة واحدة.',
        'link' => [$guideCartUrl, 'ابدأ الجولة التفاعلية'],
        'steps' => [
            ['icon' => 'add_shopping_cart', 'title' => 'الإضافة', 'text' => 'أضف المواد من بطاقاتها أو من صفحة المادة. الرقم على أيقونة السلة يعرض مجموع الطرود.'],
            ['icon' => 'edit', 'title' => 'تعديل الكميات', 'text' => 'في السلة غيّر عدد الطرود بزر + و − أو احذف الصنف. المجموع يتحدّث فوراً.'],
            ['icon' => 'error', 'title' => 'الأصناف غير المتوفرة', 'text' => 'إن نفدت كمية صنف بعد إضافته يظهر تنبيه عليه، ولا يدخل في الطلب. باقي السلة يُرسل عادي.'],
            ['icon' => 'badge', 'title' => 'بياناتك', 'text' => 'اكتب الاسم ورقم الموبايل، وأي ملاحظة على الطلب. يتذكر الجهاز الاسم والرقم للطلب القادم.'],
            ['icon' => 'send', 'title' => 'إرسال الطلب', 'text' => 'اضغط «إرسال الطلب» مرة واحدة وانتظر صفحة التأكيد. الزر يتوقف حتى يكتمل الإرسال، وأي ضغطة إضافية لا تنشئ طلباً ثانياً.'],
            ['icon' => 'local_shipping', 'title' => 'متابعة الطلب', 'text' => 'صفحة التأكيد تعطيك رابط متابعة. العملاء المسجلون يجدون كل طلباتهم في «طلباتي» وتصلهم إشعارات عند تغيّر الحالة.'],
        ],
    ],
    [
        'id' => 'app',
        'icon' => 'install_mobile',
        'title' => 'التطبيق والإشعارات',
        'lead' => 'ثبّت الموقع كتطبيق على الشاشة الرئيسية، وفعّل الإشعارات لتصلك العروض وحالة الطلبات.',
        'link' => null,
        'steps' => [
            ['icon' => 'android', 'title' => 'أندرويد (Chrome)', 'text' => 'افتح الموقع في Chrome (وليس من داخل واتساب أو فيسبوك). عند ظهور زر التثبيت اضغطه، أو من قائمة ⋮ اختر «تثبيت التطبيق».'],
            ['icon' => 'phone_iphone', 'title' => 'آيفون (Safari)', 'text' => 'افتح الموقع في Safari، اضغط زر المشاركة ↗ ثم «إضافة إلى الشاشة الرئيسية».'],
            ['icon' => 'open_in_browser', 'title' => 'الرابط من واتساب', 'text' => 'المتصفح الداخلي في واتساب لا يثبّت التطبيق. اضغط ⋮ واختر «فتح في المتصفح» أولاً.'],
            ['icon' => 'notifications', 'title' => 'الإشعارات', 'text' => 'الجرس أعلى الصفحة يعرض الإشعارات مصنفة: الطلبات، العروض، الحساب، الإعلانات. «كل الإشعارات» يفتح السجل الكامل.'],
        ],
    ],
];

$faq = [
    ['q' => 'لا أرى الأسعار، لماذا؟', 'a' => 'ظهور الأسعار يعتمد على نوع الحساب. سجّل الدخول بحساب العميل، أو تواصل معنا لتفعيل حسابك.'],
    ['q' => 'ضغطت «إرسال الطلب» مرتين، هل وصل طلبان؟', 'a' => 'لا. الطلب نفسه خلال دقائق يُحفظ مرة واحدة فقط.'],
    ['q' => 'هل تبقى السلة إن انتقلت بين الصفحات؟', 'a' => 'نعم، تبقى أثناء تصفحك على نفس الجهاز والمتصفح. أرسل الطلب عند الانتهاء ولا تتركها لوقت طويل.'],
    ['q' => 'السعر بالليرة تغيّر بعد الطلب؟', 'a' => 'أسعار الليرة تقريبية وتُحسب على سعر الصرف وقت تجهيز الطلب.'],
    ['q' => 'الصفحة لا تفتح بدون إنترنت', 'a' => 'تظهر صفحة «لا يوجد اتصال». اضغط «إعادة المحاولة» عند عودة الشبكة.'],
];

ob_start();
?>
<article class="guide">
  <header class="guide__hero">
    <span class="guide__hero-icon material-symbols-outlined" aria-hidden="true">menu_book</span>
    <h1>دليل استخدام <?= h($siteName) ?></h1>
    <p>اختر صفحة وابدأ جولة تفاعلية: نضيء كل زر على الصفحة نفسها ونشرح وظيفته. تبدأ الجولة تلقائياً في أول زيارة، ويمكنك إعادتها من زر «؟» أعلى الصفحة.</p>
  </header>

  <nav class="guide__toc" aria-label="أقسام الدليل">
    <?php foreach ($chapters as $chapter): ?>
      <a href="#<?= h($chapter['id']) ?>">
        <span class="material-symbols-outlined" aria-hidden="true"><?= h($chapter['icon']) ?></span>
        <?= h($chapter['title']) ?>
      </a>
    <?php endforeach; ?>
    <a href="#faq">
      <span class="material-symbols-outlined" aria-hidden="true">help</span>
      أسئلة
    </a>
  </nav>

  <?php foreach ($chapters as $index => $chapter): ?>
    <section class="guide__chapter" id="<?= h($chapter['id']) ?>" aria-labelledby="guide-<?= h($chapter['id']) ?>-title">
      <div class="guide__chapter-head">
        <span class="guide__chapter-num"><?= $index + 1 ?></span>
        <div>
          <h2 id="guide-<?= h($chapter['id']) ?>-title"><?= h($chapter['title']) ?></h2>
          <p><?= h($chapter['lead']) ?></p>
        </div>
      </div>
      <?php if ($chapter['link'] !== null): ?>
      <ul class="guide__tour-stops" aria-label="محطات الجولة">
        <?php foreach ($chapter['steps'] as $step): ?>
          <li>
            <span class="material-symbols-outlined" aria-hidden="true"><?= h($step['icon']) ?></span>
            <?= h($step['title']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <ol class="guide__steps">
        <?php foreach ($chapter['steps'] as $step): ?>
          <li class="guide__step">
            <span class="guide__step-icon material-symbols-outlined" aria-hidden="true"><?= h($step['icon']) ?></span>
            <div>
              <h3><?= h($step['title']) ?></h3>
              <p><?= h($step['text']) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <?php if ($chapter['link'] !== null): ?>
        <a class="guide__cta" href="<?= h($chapter['link'][0]) ?>">
          <span class="material-symbols-outlined" aria-hidden="true">tour</span>
          <?= h($chapter['link'][1]) ?>
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        </a>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <section class="guide__chapter" id="faq" aria-labelledby="guide-faq-title">
    <div class="guide__chapter-head">
      <span class="guide__chapter-num">؟</span>
      <div>
        <h2 id="guide-faq-title">أسئلة متكررة</h2>
      </div>
    </div>
    <div class="guide__faq">
      <?php foreach ($faq as $item): ?>
        <details>
          <summary><?= h($item['q']) ?></summary>
          <p><?= h($item['a']) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
    <?php if ($whatsapp !== ''): ?>
      <a class="guide__cta guide__cta--green" href="https://wa.me/<?= h($whatsapp) ?>" target="_blank" rel="noopener">
        <span class="material-symbols-outlined" aria-hidden="true">chat</span>
        اسألنا على واتساب
      </a>
    <?php endif; ?>
  </section>
</article>
<?php
$content = ob_get_clean();
$title = 'دليل الاستخدام';
$extraHead = portal_stylesheet('/css/guide-page.css');
$enableOnboarding = false;
require dirname(__DIR__) . '/views/layout.php';
