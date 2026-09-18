# تدقيق منطق التسعير والسلة والطلبات

تاريخ التدقيق: 2026-09-18  
الفرع المرجعي: `main`  
النطاق: مسار المال من عرض المادة حتى حفظ الطلب ومزامنة الأمين، ثم مسارات الكمية والمخزون والعروض المرتبطة.

هذا التقرير يركّز على **منطق الأعمال** بعد أن المراجعة المعمارية الأولى لم تتتبع تحويل السعر سطراً بسطر عند الإضافة للسلة.

---

## لماذا فات خصم السلة في المراجعة الأولى

المراجعة الأولى وزنت الأمان والمعمارية والتكرار، ومرّت على وجود طبقة عروض (`SpecialOfferService`) واختبار `scripts/test-offer-pricing.php` الذي يبدو أنه يغطي «عدم تطبيق الخصم مرتين».

ذلك الاختبار **لا يمر عبر مسار السلة**. هو يستدعي `computePricing` مرتين على نفس المصفوفة بعد أن تكون حقول `original_unit_sale_price_*` موجودة. المسار الحقيقي للإضافة يفعل شيئاً مختلفاً:

1. يطبّق العرض على منتج الأمين ويكتب السعر المخفّض فوق `unitSalePriceSyp`.
2. يحوّل المنتج إلى سطر سلة **بدون** نسخ `original_*` ولا `has_offer`.
3. يطبّق العرض مرة ثانية على السعر المخفّض كأنه سعر قائمة.

النتيجة التي يراها المستخدم: بطاقة المنتج تعرض خصماً واحداً، وبعد «إضافة للسلة» يظهر خصم ثانٍ (مثلاً 20٪ ثم 20٪ أخرى).

الدرس: أي مراجعة منطق مالي يجب أن تتبع **رقم السعر** من API الأمين → العرض → حقول النموذج → جلسة السلة → إعادة التسعير → `order_items` → مزامنة الأمين، لا الاكتفاء بوجود دالة `computePricing` واختبار وحدة معزول.

---

## 1) الخطأ الحرج: الخصم يُطبَّق مرتين بعد الإضافة للسلة

### مثال رقمي

| المرحلة | سعر الطرد ل.س | ماذا حدث |
|---------|----------------|----------|
| أمين (قائمة) | 1000 | `unitSalePriceSyp` |
| كتالوج / بطاقة المنتج (عرض 20٪) | 800 | خصم واحد — هذا ما يراه الزائر قبل الإضافة |
| سطر السلة بعد الإضافة | 640 | خصم ثانٍ على 800 |
| طلب يُحفظ / يُزامَن | 640 | السعر الخاطئ يتثبّت |

إذا كان معامل التحويل 10 قطع/طرد: سعر القطعة يصبح 80 في الواجهة ثم 64 في السلة.

### السلسلة الفعلية (متجر عام)

```
StoreCartApi::add
  → StoreCatalogService::findMaterial($guid)          // بلا slug العرض
       → withOfferPricing()
            → SpecialOfferService::applyPricingOverlays()
                 → computePricing()
                      يكتب unitSalePriceSyp = السعر المخفّض
                      و original_unit_sale_price_sp = سعر القائمة
                      و has_offer = true
  → StoreCartPricingService::lineFromRequest($input, $product)
       → authoritativeLineFromProduct($product)
            → ShareCartService::lineFromApiItem($product)
                 يقرأ unitSalePriceSyp المخفّض فقط
                 لا ينسخ original_* ولا has_offer ولا offer_badge
            → ShareCartService::enrichLineWithOffer($line)
                 السطر بلا has_offer → لا يخرج مبكراً
                 findMaterial() مرة أخرى (عرض مطبّق)
                 pricingOverlay() على المنتج: محمي إن وُجد original_*
                 lineFromApiItem() مرة أخرى من السعر المخفّض
                 SpecialOfferService::applyToCartLine($apiLine, $offer)
                      يبني material من unit_sale_price_sp المخفّض
                      بلا original_unit_sale_price_sp
                      computePricing() يعتبر 800 سعر قائمة ويخصم 20٪ → 640
  → StoreCartService::add(..., skipEnrich=true)
       التخزين في الجلسة بالسعر 640
```

### المواقع في الكود

**كتابة سعر العرض فوق سعر الأمين** — هذا يكسر مصدر الحقيقة:

```677:688:portal/src/Services/SpecialOfferService.php
        return [
            'original_unit_sale_price_sp' => $baseUnitSp,
            // ...
            'effective_unit_sale_price_sp' => $effUnitSp,
            'unitSalePriceSyp' => $effUnitSp,
            'unitSalePriceUsd' => $effUnitUsd,
        ];
```

`findMaterial` يدمج هذا الـ overlay دائماً:

```1065:1065:portal/src/Services/StoreCatalogService.php
            return self::withOfferPricing([$data], $offerSlug)[0] ?? $data;
```

**فقدان الحقول عند التحويل إلى سطر سلة:**

```743:763:portal/src/Services/ShareCartService.php
    public static function lineFromApiItem(array $apiItem, bool $capturePrices): array
    {
        // ...
        $unitSp = self::unitSalePriceSp($apiItem);  // يقرأ unitSalePriceSyp المخفّض
        return self::normalizeLine([
            'unit_sale_price_sp' => $capturePrices ? $unitSp : 0.0,
            // لا original_* — لا has_offer
        ]);
    }
```

**التطبيق الثاني في السلة:**

```692:713:portal/src/Services/SpecialOfferService.php
    public static function applyToCartLine(array $line, array $offer): array
    {
        $material = [
            'unitSalePriceSyp' => (float) ($line['unit_sale_price_sp'] ?? 0),
            'unitSalePriceUsd' => (float) ($line['unit_sale_price_usd'] ?? 0),
            'packageConversionFactor' => (float) ($line['packaging'] ?? $line['package_factor'] ?? 1),
        ];
        $pricing = self::computePricing($material, $offer);
```

`computePricing` يستخدم `original_*` إن وُجد، وإلا سعر الوحدة الحالي. `applyToCartLine` لا يمرّر `original_*`، فيُخصم من المخفّض.

**الحماية الموجودة ناقصة.** `pricingOverlay` يخرج مبكراً إذا كان المنتج يحمل `has_offer` + `original_*`. هذا يحمي **عرض الكتالوج** لا تحويل السلة. `enrichLineWithOffer` يخرج مبكراً فقط إذا كان **سطر السلة** يحمل `has_offer` و`offer_badge`. السطر القادم من `lineFromApiItem` لا يحملهما، فيُطبَّق الخصم ثانية.

**الاختبار الموجود يعطي أماناً زائفاً:**

```43:51:portal/scripts/test-offer-pricing.php
$first = SpecialOfferService::computePricing($material, $offer);
$alreadyPriced = array_merge($material, $first, ['has_offer' => true]);
$second = SpecialOfferService::computePricing($alreadyPriced, $offer);
```

هنا `original_unit_sale_price_sp` يبقى على المصفوفة. مسار السلة يحذفه قبل `computePricing`.

### إعادة التسعير تثبّت الخطأ وتعيد إنتاجه

`authoritativeLineFromProduct` يعيد نفس السلسلة في كل `repriceCart`:

- فتح السلة إذا `payload(..., reprice: true)`
- `login.php` بعد دخول العميل
- `StoreCartRequest` و`StoreCartApi::submitOrder` قبل حفظ الطلب

`submitOrder` يعيد التسعير ثم يحفظ `sale_price_sp` / `sale_price_usd` من الجلسة في `order_items`. الطلب والمزامنة إلى الأمين (`amineSyncPackagePriceUsd`) يخرجان بالسعر المضاعف الخصم.

إضافة للسلة تمرّر `reprice: false` في `payload`، لذلك المستخدم يرى الخصم الثاني **فوراً عند الإضافة** من `enrichLineWithOffer` داخل `lineFromRequest`، لا من إعادة التسعير اللاحقة.

### سلة رابط المشاركة: نفس العلة ومسار إضافي من المتصفح

`StoreCartApi::addShare` يبني السطر من النموذج (`lineFromForm`) حيث الحقول المخفية `unit_sale_price_sp` هي أصلاً السعر المعروض بعد الخصم (`store-add-to-cart-form.php`). ثم `sharePayload` يستدعي `enrichLineWithOffer` على كل سطر في **كل** رد JSON، فيُخصم مرة ثانية عند أول تحديث لواجهة السلة.

```75:77:portal/src/Support/StoreCartApi.php
        $items = array_values(array_map(
            static function (array $line) use ($showPrice): array {
                $enriched = ShareCartService::enrichLineWithOffer($line);
```

ولا يُكتب الناتج Dual-discounted إلى الجلسة إلا إذا مرّ مسار يضيف/يستبدل السطر بعد الإثراء. حتى لو بقيت الجلسة على سعر النموذج المخفّض مرة واحدة، الواجهة JSON تعرض السعر بعد الخصم الثاني. تباين بين ما يُحفظ وما يُعرض.

### إصلاح مطلوب (عقد واحد للسعر)

1. **سعر الأمين لا يُمس.** `computePricing` يعيد `original_*` و`effective_*` فقط. ممنوع كتابة `unitSalePriceSyp = effective`.
2. **عرض الواجهة** يقرأ `effective_*` إن وُجد، وإلا سعر الأمين.
3. **سطر السلة** يُنسخ من `effective` إلى `sale_price_*` ومن `original` إلى `original_sale_price_*` مع `has_offer` و`special_offer_id` في نفس التحويل (`lineFromApiItem` أو دالة جديدة `lineFromPricedProduct`).
4. **`applyToCartLine`** إن بقي، يجب أن يمرّر `original_unit_sale_price_*` إلى `computePricing`، أو يُحذف ويُستبدل بنسخ الحقول الجاهزة.
5. **`enrichLineWithOffer`** لا يعيد حساب خصم على سطر يملك `special_offer_id` أو `original_sale_price_*`.
6. اختبار يلزم: منتج قائمة 1000 + عرض 20٪ → كتالوج 800 → بعد add سلة 800 (لا 640) → بعد reprice 800 → `order_items.sale_price_sp = 800` و`original_sale_price_sp = 1000`.

---

## 2) أخطاء منطق مرتبطة بالسعر والعروض

### 2.1 السلة تتجاهل العرض الذي أضاف منه المستخدم

`StoreCartApi::add` يقرأ `store_offer` من الطلب ويخزّنه على السطر (`added_store_offer`) لكن `findMaterial($guid)` يُستدعى **بدون** هذا الـ slug. `resolveForMaterial` يختار «أرخص سعر فعّال» بين كل العروض النشطة.

أثر: بطاقة عرض 10٪ قد تدخل السلة بعرض آخر 20٪ (ثم تُضاعف كما في §1). إعادة التسعير تكرّر الاختيار العام وتقدر تغيّر عرض السطر بعد الإضافة.

### 2.2 `attachOfferPricing` لا يتحقق من انتماء المادة للعرض

إذا وُجد `contextOffer` (صفحة عرض / `findMaterial` مع slug):

```559:561:portal/src/Services/SpecialOfferService.php
        if ($contextOffer !== null) {
            return self::attachOfferPricing($products, $contextOffer);
        }
```

كل المنتجات في القائمة تُسعَّر بذلك العرض. `pricingOverlay` يفحص `offerIncludesMaterial`، أما مسار السياق فلا. مادة ليست في العرض قد تُخصم لأنها فُتحت برابط `?offer=`.

### 2.3 فلاتر العرض عند تقرير الانتماء ناقصة

`materialMatchesRules` يفحص النوع / الفئة / الشركة / القياس / المنشأ / المجموعة / التوفر / وجود صورة / كلمة مفتاحية فقط.

لا يفحص رغم أنها تُحفظ على العرض وتُرسل لـ API عند جلب قائمة العرض:

- `store_guids`
- حدود الكمية في المستودع
- حدود سعر الوحدة

النتيجة: مادة قد تظهر في شريط العرض عبر فلتر API، ثم `resolveForMaterial` يضمّ مادة أخرى خارج المستودع/النطاق السعري لأنها تطابق النوع فقط — أو العكس.

### 2.4 حدّ الطرود الأدنى/الأقصى للعرض غير مفعّل في السلة

`ShareCartService::add` و`updateQuantity` يستدعيان:

```php
SpecialOfferService::validatePackageQuantity($materialGuid, $targetQty, null);
```

المعامل الثالث `null` يعني تجاهل `min_packages` / `max_packages`. الواجهة (`ProductDisplayService`) تعرض الحدود، والخادم لا يفرضها. يُفرض فقط الحد العام `StorePolicyService::maxPackagesPerMaterial()`.

`store_cart_qty_bounds` أيضاً لا يقرأ حدود العرض.

### 2.5 عرض بنسبة 0٪ أو سعر ثابت مساوٍ للقائمة ما زال `has_offer`

`attachOfferPricing` يضع `has_offer = true` دائماً. شارة «عرض» تظهر بلا فرق سعر. `store_line_has_offer` يعتبر أي `special_offer_id` عرضاً حتى بلا فرق.

### 2.6 `normalizeLine` يعيد بناء سعر الطرد من الوحدة دائماً

```694:695:portal/src/Services/ShareCartService.php
        $line['sale_price_sp'] = $unitSp * $packaging;
        $line['sale_price_usd'] = $unitUsd * $packaging;
```

أي سعر طرد مستقل (سعر ثابت على مستوى الطرد مع كسر في التعبئة) يُسحق. إن وُجدت تعبئة خاطئة (`packaging = 1` بدل 10) يتغيّر السعر المعروض بصمت.

### 2.7 كاش أقسام الرئيسية يجمّد أسعار العروض حتى 10 دقائق

`SpecialOfferService::activeHomeSections` عبر `ResponseCache` مدة 600 ثانية. مفتاح الكاش يعتمد على صفوف العروض لا على أسعار الأمين. تغيّر سعر في الأمين أو انتهاء عرض قد يبقى السعر القديم على الرئيسية حتى انتهاء الكاش، بينما السلة تعيد الجلب حيّاً → فرق سعر بين الرئيسية والسلة حتى بعد إصلاح §1.

### 2.8 بطاقة المنتج وشريط الرئيسية قد يطبّقان overlay مرة إضافية في العرض

`product-card.php` و`home-section-product-strip.php`: إذا `has_offer` فارغ يستدعيان `pricingOverlay` على `$item`. إذا كانت `unitSalePriceSyp` قد خُفّضت سابقاً دون رفع `has_offer` (مسار ناقص)، الواجهة نفسها تعرض خصماً ثانياً قبل السلة.

### 2.9 أسعار مخفية في النموذج تُعامل كمصدر في سلة المشاركة

النموذج يرسل `unit_sale_price_sp` المخفّض. المتجر العام يتجاوزها عبر `findMaterial` (ثم يقع في §1). سلة المشاركة تستخدمها مباشرة (`lineFromForm`). العميل يقدر يلاعب الحقول؛ والحماية الوحيدة لاحقة هي `enrichLineWithOffer` التي تخصم مرة ثانية لا تعيد السعر من الأمين كقائمة.

### 2.10 لقطة السعر `price_snapshot_*` لا تُحدَّث بعد إعادة التسعير

```217:218:portal/src/Services/StoreCartPricingService.php
            $merged['price_snapshot_sp'] = (float) ($line['price_snapshot_sp'] ?? $merged['sale_price_sp'] ?? 0);
```

اللقطة تبقى على أول إدخال. بعد إصلاح أو تغيّر سعر حقيقي، مقارنة `detectPriceChange` تظل تقارن بالسعر الأول لا بالآخر الذي وافق عليه المستخدم. مع §1 اللقطة 640 والسعر «الرسمي» المعاد حسابه 640 فلا تنبيه؛ بعد الإصلاح قد تظهر تنبيهات سعر كاذبة مدى الجلسة.

### 2.11 نفس المادة لا تحمل عرضين؛ الدمج عند الإضافة يستبدل السعر

إذا كان السطر موجوداً، `ShareCartService::add` يعمل `array_merge` للسطر الجديد فوق القديم ويزيد الكمية. إضافة ثانية من صفحة عرض مختلفة تستبدل السعر/العرض وتجمع الكمية. لا فصل بين سعرين لنفس المادة (قد يكون مقصوداً) لكنه غير موثّق ويُفاقم §2.1.

---

## 3) الكمية، التعبئة، المخزون

### 3.1 جلب المخزون عند التحقق غير مقيّد بمستودعات السياسة

`StockReservationService::fetchWarehousePrimary` يطلب `/api/materials/{guid}` بدون `storeGuids`. الكمية قد تكون مجموع كل المستودعات. الكتالوج المقيّد يعرض كمية مستودعات السياسة فقط. الموظف يزيد كمية طلب فيُسمح بكمية أكبر من المتاح للبيع على الموقع.

`findMaterial` مع سياسة مستودعات يستخدم القائمة المقيّدة؛ مسار الزيادة من لوحة الطلبات لا.

### 3.2 الحجز يحسب `quantity * pcs_per_box` كوحدات أولية

متسق إن كانت `quantity` طروداً و`pcs_per_box` معامل التعبئة. `normalizeLine` يفرض `pcs_per_box = round(packaging)`. تعبئة 10.5 تصبح 11 في الحجز وتبقى 10.5 في السعر → انحراف مخزون.

### 3.3 طرد جزئي (`partialPackage`) يُقفل التعديل في الواجهة والخادم يقبل كسوراً أو أعداداً صحيحة حسب المسار

الواجهة تقفل +/- إذا `stockAvailable < 1`. الخادم `validatePackageQuantity` لا يعرف الطرد الجزئي. زيادة لاحقة من API السلة قد تتجاوز ما تفرضه البطاقة.

### 3.4 حد السياسة يُحسب في الواجهة كمتبقٍ (`max - cartQty`) وفي الخادم كالهدف الإجمالي

البطاقة تضع `data-effective-max` = المتبقي. الخادم يتحقق من `existing + added`. إن أرسل العميل `quantity` كهدف لا كزيادة، يُرفض أو يُضاعف حسب تفسير `StoreCartApi::add` (يجمع على الموجود). مسارا bump/update يمرّران كمية مطلقة. التداخل بين «إضافة» و«تحديث» مصدر أخطاء كمية.

### 3.5 سباق المخزون مع الأمين

القفل الاستشاري في PostgreSQL يسلسل طلبات البوابة فقط. بيع من الأمين بين فحص السلة وحفظ الطلب يتجاوز الكمية. `AmineAvailabilityService` موجود جزئياً ولا يوقف الإرسال دائماً عند انقطاع الأمين.

### 3.6 `splitCartByAvailability` قبل المعاملة ثم إعادة فحص داخلها

منطقي. لكن الفحص الأول يستخدم كميات جلسة قد تكون بالسعر/التعبئة الخاطئين؛ إن فشل الإعادة تُخلط `unavailable` من الفحصين وقد تُزال مواد من السلة بعد رسالة عامة.

---

## 4) الطلبات والحسابات المعروضة للعميل

### 4.1 الطلب يُحفظ بسعر الجلسة دون إعادة اشتقاق من قائمة الأمين + قواعد العرض

`OrderService` ينسخ `sale_price_sp` / `sale_price_usd` / `original_*` من عناصر السلة. لا يوجد `computePricing` عند الإدراج. أي خطأ في الجلسة (خصم مزدوج، سعر نموذج مشاركة، تلاعب POST) يصبح مستنداً مالياً.

### 4.2 العميل المسجّل يرى طلبات الضيف بنفس الهاتف

`getOrderForCustomer` / قائمة الطلبات: `web_customer_id` **أو** `guest_phone`. طلبات مشاركة/ضيف بنفس الرقم تظهر في «طلباتي» ويمكن إلغاؤها.

### 4.3 أسعار طلب العميل تُخفى ما دام `pending`

`customer_order_shows_prices` يعرض السعر فقط في `confirmed` و`completed`. مع خصم مزدوج، العميل لا يراجع الرقم إلا بعد التأكيد — متأخر على الاكتشاف.

### 4.4 تعديل سعر الصنف من اللوحة لا يعيد حساب `original_*` ولا يفك العرض

`updateItemPrice` يحدّث `sale_price_sp/usd` فقط. الشارة `has_offer` تُشتق لاحقاً من `original > sale`. تعديل يدوي يبقي `original` القديم فيظهر خصم وهمي، أو يزيل الفرق فيختفي العرض في الواجهة مع بقاء `special_offer_id`.

### 4.5 مزامنة الأمين بالدولار فقط

`amineSyncPackagePriceUsd` يرسل `sale_price_usd` ويتجاهل الليرة. إن كان العرض سعر ليرة ثابت دون دولار، تُزامَن 0.

### 4.6 إنشاء جداول من التطبيق

`OrderService::ensureItemEditSchema` ينفّذ SQL ترحيل إن نقص العمود. بيئة بلا صلاحية DDL تفشل بصمت (`hasItemEditSchema = false`) وتُعطَّل تعديلات الأصناف برسالة ترحيل. مصدر حقيقة المخطط يتفرق بين `docs/portal-migrations` وتشغيل حي.

---

## 5) سياسات العرض والكميات على الصفحات

### 5.1 عميل `pending` يُسجَّل دخوله ويُسعَّر كزائر

بعد التسجيل تُفتح جلسة. الكتالوج يستخدم سياسة الضيف لأن `CustomerSession::check()` يشترط `active`. سلة قد تكون أُنشئت تحت سياق قسم/عرض (`store_cart_context`) ثم تتغيّر سياسة الإظهار بعد الموافقة دون إعادة تسعير واضحة إلا عند `login.php`.

### 5.2 `rememberCartDisplayContext` لا يُستدعى إلا إذا وُجد section أو offer

إضافة من المتجر العام بلا قسم لا تحفظ سياق الإظهار. أسطر مختلطة: بعضها من عرض يُظهر السعر وبعضها من متجر يخفيه (`partitionItems` / `has_mixed`). المجموع المعروض (`displayTotals`) يجمع المسعَّر فقط بينما `totals` يجمع الكل — رقمان مختلفان في نفس السلة.

### 5.3 كلمة سر رابط المشاركة على الصفحة لا على JSON السلة

`dispatchShare` / `shareState` لا يستدعيان `SharePageAccess`. من يملك التوكن يضيف ويطلب. منطق الوصول منفصل عن منطق السعر وكلاهما يجب أن يمرا من بوابة واحدة.

---

## 6) خصومات مستندات الأمين (API) — ليست علة السلة لكنها منطق خصم منفصل

`BillsController` يلتقط الخصم من أسماء أعمدة متعددة (`TotalDisc`, `Discount`, `Disc`, …) ثم يقسم على `currencyRate` عبر `ConvertToDocumentCurrency`. إن كان الحقل في الأمين بعملة المستند أصلاً، القسمة تُصغّر الخصم. إن وُجد الخصم في رأس المستند وفي البنود، الواجهة تعرض الاثنين دون تمييز «خصم رأس» مقابل «مجموع خصم البنود». صلاحيات الحقل `TotalDisc` / `Discount` مُبذرة ولا تُطبَّق على هذا المتحكم (الحقول المالية تخرج كاملة).

هذا مسار محاسبي للقراءة، مستقل عن عروض الموقع، لكن اسم «خصم» يتداخل في الدعم عند التشخيص.

---

## 7) مسارات أخرى يجب إعادة تتبع الرقم فيها بعد إصلاح §1

| المسار | لماذا |
|--------|--------|
| `login.php` → `repriceCart` | يعيد إنتاج الخصم المزدوج عند كل دخول |
| `StoreCartApi::payload` الافتراضي `reprice=true` | أي endpoint نسي `reprice: false` يعيد الحساب |
| `StoreCartService::enrichedItems` | إثراء للعرض قد يخصم إن نقص `has_offer` |
| `views/product.php` overlay إن `has_offer` فارغ | عرض صفحة المادة |
| تأكيد الطلب `store-order-confirmation` / `order-confirmation` | يعرض سعر الجلسة المحفوظ |
| لوحة `orders.php` بطاقة الصنف | `store_order_line_prices` من `sale_price_*` |
| ZIP/تصدير غير متعلق | لا |
| فواتير الأمين | مستقل؛ لا يصلحه إصلاح العروض |

---

## 8) ثغرات الاختبار الحالية

| اختبار | ماذا يغطي | ماذا يفوته |
|--------|-----------|------------|
| `scripts/test-offer-pricing.php` | `computePricing` مع بقاء `original_*` | `lineFromApiItem` + `applyToCartLine` + `repriceCart` + add |
| `scripts/test-store-catalog.php` | فلاتر كتالوج | سعر العرض على الناتج |
| لا يوجد اختبار سلة تسعير | — | الرقم 1000 → 800 → السلة يجب أن تبقى 800 |

اختبار انحدار إلزامي (حتى بلا قاعدة) عبر دوال نقية:

1. `computePricing` على سعر أمين خام.
2. دمج overlay ثم `lineFromApiItem` ثم `applyToCartLine` — يجب ألا يتغيّر السعر الفعّال.
3. `repriceCart` على سطر يملك `has_offer` — نفس السعر.
4. عرضان متعارضان: السلة تستخدم slug المُرسل لا «الأرخص عالمياً» إن وُجد سياق.

---

## 9) أولوية الإصلاح المنطقي (تقني لا زمني)

1. **وقف الخصم المزدوج** (§1) — يؤثر على كل طلب جديد فيه عرض.
2. **ربط `findMaterial` / التسعير بـ `store_offer` المخزّن على السطر** (§2.1).
3. **فرض `min_packages` / `max_packages` على الخادم** (§2.4).
4. **مصدر سعر الطلب من الأمين + قواعد العرض عند `create*Order` لا من الجلسة وحدها.**
5. **تقييد `fetchWarehousePrimary` بمستودعات السياسة.**
6. **إكمال `materialMatchesRules` أو توحيد الانتماء مع استعلام API.**
7. **منع `attachOfferPricing` بدون فحص الانتماء.**
8. **توسيع الاختبار بحيث يفشل اليوم على 640 وينجح على 800.**

لا يُنصح بتنظيف `legacy-site` أو تقسيم المتحكمات قبل إغلاق مسار المال. خطأ الخصم يُضاعف في كل طلب مؤكد ويُزامَن إلى الأمين.

---

## 10) ملحق: خريطة الحقول

| المعنى | حقول الأمين/API | بعد overlay (منتج) | سطر السلة | `order_items` |
|--------|-----------------|---------------------|-----------|----------------|
| سعر قائمة وحدة | `unitSalePriceSyp` | يجب أن يبقى كما هو؛ حالياً يُستبدل | `unit_sale_price_sp` (حالياً مخفّض أو مخفّض²) | غير مخزّن مباشرة |
| سعر قائمة طرد | وحدة × تعبئة | `original_package_sale_price_sp` | `original_sale_price_sp` | `original_sale_price_sp` |
| سعر فعّال وحدة | — | `effective_unit_sale_price_sp` + حالياً `unitSalePriceSyp` خطأ | يُشتق | يُشتق عند العرض |
| سعر فعّال طرد | — | `effective_package_sale_price_sp` | `sale_price_sp` | `sale_price_sp` |
| علامة عرض | — | `has_offer`, `offer`, `offer_badge` | غالباً تُفقد ثم تُعاد في `applyToCartLine` | `special_offer_id` |

الإصلاح الجذري: عمودان ذهنيان ثابتان «قائمة» و«فعّال» من أول overlay حتى قاعدة الطلبات، بلا إعادة خصم وبلا الكتابة فوق حقول الأمين.
