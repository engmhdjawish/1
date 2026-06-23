<?php

declare(strict_types=1);

/** @var string $aboutFieldValue */
/** @var string $aboutFieldName */

$aboutFieldName = $aboutFieldName ?? 'about_us_ar';
$aboutFieldValue = (string) ($aboutFieldValue ?? '');
$aboutEditorDefault = default_about_content();
?>
<div class="about-content-editor rounded-xl border border-border-subtle overflow-hidden bg-white" data-about-editor data-default-content="<?= h($aboutEditorDefault) ?>">
  <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b border-border-subtle bg-surface-low">
    <div class="flex flex-wrap gap-1" role="toolbar" aria-label="أدوات تنسيق المحتوى">
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-insert="## ">عنوان قسم</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-insert="### ">عنوان فرعي</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-insert-card>بطاقة</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-insert="> ">اقتباس</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-wrap="**">عريض</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-wrap="*">مائل</button>
      <button type="button" class="about-editor-tool h-8 px-2.5 rounded-lg border border-border-subtle bg-white text-[11px] font-bold hover:bg-slate-50" data-insert="---">فاصل</button>
    </div>
    <div class="inline-flex rounded-lg border border-border-subtle bg-white p-0.5 text-[11px] font-bold">
      <button type="button" class="about-editor-tab h-7 px-3 rounded-md bg-primary text-white" data-tab="edit">تحرير</button>
      <button type="button" class="about-editor-tab h-7 px-3 rounded-md text-slate-600 hover:bg-slate-50" data-tab="preview">معاينة</button>
      <button type="button" class="about-editor-tab h-7 px-3 rounded-md text-slate-600 hover:bg-slate-50" data-tab="split">مقسّم</button>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 min-h-[22rem]" data-about-panels>
    <div class="border-b lg:border-b-0 lg:border-l border-border-subtle" data-panel="edit">
      <textarea
        name="<?= h($aboutFieldName) ?>"
        id="about-us-content-input"
        rows="16"
        class="block w-full h-full min-h-[22rem] resize-y border-0 px-3 py-3 text-sm leading-7 font-mono focus:outline-none focus:ring-0"
        placeholder="ابدأ بفقرة تمهيدية، ثم استخدم ## لعناوين الأقسام..."
        data-about-input
      ><?= h($aboutFieldValue) ?></textarea>
    </div>
    <div class="hidden bg-slate-50" data-panel="preview">
      <div class="h-full overflow-y-auto p-3" data-about-preview>
        <p class="text-xs text-text-muted">ستظهر المعاينة هنا...</p>
      </div>
    </div>
  </div>

  <div class="px-3 py-2 border-t border-border-subtle bg-white text-[11px] text-text-muted leading-relaxed">
    التنسيق المدعوم:
    <code dir="ltr">##</code> قسم،
    <code dir="ltr">###</code> فرعي،
    <code dir="ltr">*عنوان*</code> ثم سطر للبطاقة،
    <code dir="ltr">- عنوان: وصف</code>،
    <code dir="ltr">&gt;</code> اقتباس،
    <code dir="ltr">**عريض**</code>،
    <code dir="ltr">*مائل*</code>.
    <button type="button" class="mr-2 text-primary font-bold hover:underline" data-about-load-default>استخدام النموذج الافتراضي</button>
    <a href="/about.php" target="_blank" class="text-primary font-bold hover:underline">فتح الصفحة العامة</a>
  </div>
</div>
