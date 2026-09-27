#!/usr/bin/env python3
"""Generate home-vision-v1.html with live jawishco.sy homepage data."""

from __future__ import annotations

import html
import json
import re
import urllib.request
from pathlib import Path

API = "https://www.jawishco.sy/api/home-products.php"
LOGO = "https://www.jawishco.sy/media/site.php?id=5007abc6-259f-46ab-8409-383e3331646f"
SITE = "https://www.jawishco.sy"

SECTIONS = [
    ("offers", "تخفيضات خاصة...", "عروض خاصة على بعض الأصناف حتى 20%", "🔥 عروض"),
    ("winter-slippers", "شحاطة فرو", "تشكيلة من الشحاط الشتوي المحلي و المستورد", "❄️ شتوي"),
    ("slippers", "شحاطات صيني", "", "🇨🇳 شحاطات"),
    ("sneakers", "بوط صيني", "", "🇨🇳 بوط"),
    ("local-slippers", "شحاطات وطني", "", "🇸🇾 شحاطات"),
    ("local-shoes", "بوط وطني", "", "🇸🇾 بوط"),
]


def fetch_sections() -> list[dict]:
    data = json.load(urllib.request.urlopen(API, timeout=30))
    strips = data.get("strips", {})
    out: list[dict] = []
    for key, title, subtitle, tab in SECTIONS:
        html_strip = strips.get(key, "")
        products: list[dict] = []
        for match in re.finditer(r'data-preview="([^"]+)"', html_strip):
            raw = html.unescape(match.group(1))
            try:
                p = json.loads(raw.replace("\\/", "/"))
            except json.JSONDecodeError:
                continue
            products.append(
                {
                    "name": p.get("name", ""),
                    "code": p.get("code", ""),
                    "thumb": SITE + (p.get("thumbUrl") or "").replace("\\/", "/"),
                    "unitSaleSp": p.get("unitSaleSp"),
                    "packageSaleSp": p.get("packageSaleSp"),
                    "hasOffer": bool(p.get("hasOffer")),
                    "offerBadge": p.get("offerBadge") or "",
                    "originalUnitSp": p.get("originalUnitSp") or 0,
                    "originalPackSp": p.get("originalPackSp") or 0,
                }
            )
        out.append(
            {
                "id": key,
                "title": title,
                "subtitle": subtitle,
                "tab": tab,
                "products": products,
            }
        )
    return out


def main() -> None:
    sections = fetch_sections()
    sections_json = json.dumps(sections, ensure_ascii=False)
    hero_image = sections[0]["products"][0]["thumb"] if sections[0]["products"] else LOGO

    output = Path(__file__).resolve().parents[1] / "public" / "dev-test" / "home-vision-v1.html"
    output.write_text(
        TEMPLATE.replace("__SECTIONS_JSON__", sections_json)
        .replace("__LOGO__", LOGO)
        .replace("__HERO_IMAGE__", hero_image)
        .replace("__SITE__", SITE),
        encoding="utf-8",
    )
    print(f"Wrote {output} ({len(sections)} sections)")


TEMPLATE = r"""<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>معاينة — رؤية الصفحة الرئيسية | جاويش للتجارة</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0" rel="stylesheet">
  <style>
    :root {
      --accent: #D81921;
      --accent-dark: #9f1218;
      --ink: #0f172a;
      --muted: #64748b;
      --border: #e5e7eb;
      --surface: #f8fafc;
      --radius: 1rem;
      --shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "IBM Plex Sans Arabic", system-ui, sans-serif;
      background: #fff;
      color: var(--ink);
      line-height: 1.5;
    }
    .preview-banner {
      position: sticky; top: 0; z-index: 100;
      background: linear-gradient(90deg, #1e293b, #334155);
      color: #fff; text-align: center;
      padding: 0.55rem 1rem; font-size: 0.8125rem; font-weight: 600;
    }
    .preview-banner strong { color: #fca5a5; }
    .container { max-width: 72rem; margin: 0 auto; padding: 0 1rem 5rem; }
    .header {
      position: sticky; top: 2rem; z-index: 50;
      background: rgba(255,255,255,0.94);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(15,23,42,0.08);
      box-shadow: 0 4px 16px rgba(15,23,42,0.04);
    }
    .header__inner {
      max-width: 72rem; margin: 0 auto; padding: 0.65rem 1rem;
      display: flex; align-items: center; gap: 0.75rem;
    }
    .brand {
      display: inline-flex; align-items: center; text-decoration: none;
      flex-shrink: 0; min-width: 0;
    }
    .brand__logo {
      display: block; height: 3rem; width: auto;
      max-width: 7.5rem; object-fit: contain;
      filter: drop-shadow(0 1px 3px rgba(15,23,42,0.12));
    }
    @media (min-width: 640px) { .brand__logo { height: 3.35rem; max-width: 8.5rem; } }
    .search {
      flex: 1; max-width: 28rem; display: flex; align-items: center; gap: 0.5rem;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 9999px; padding: 0.45rem 1rem;
    }
    .search input { flex: 1; border: none; background: transparent; font: inherit; outline: none; min-width: 0; }
    .search .material-symbols-outlined { color: var(--muted); font-size: 1.25rem; }
    .header__actions { display: flex; align-items: center; gap: 0.5rem; margin-inline-start: auto; }
    .icon-btn {
      width: 2.5rem; height: 2.5rem; border-radius: 0.75rem;
      border: 1px solid var(--border); background: #fff;
      display: grid; place-items: center; cursor: pointer;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 0.35rem;
      height: 2.5rem; padding: 0 1rem; border-radius: 0.75rem;
      font: inherit; font-weight: 700; font-size: 0.875rem;
      text-decoration: none; border: none; cursor: pointer;
      transition: transform 0.15s, box-shadow 0.15s;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn--primary {
      background: linear-gradient(135deg, var(--accent-dark), var(--accent));
      color: #fff; box-shadow: 0 6px 16px rgba(216,25,33,0.25);
    }
    .btn--ghost { background: #fff; border: 1px solid var(--border); color: var(--ink); }
    .btn--light { background: #fff; color: var(--accent); box-shadow: 0 8px 20px rgba(15,23,42,0.12); }
    .btn--ghost-light { border: 1px solid rgba(255,255,255,0.45); color: #fff; background: rgba(255,255,255,0.08); }
    .hero {
      margin-top: 1.25rem; border-radius: 1.75rem; overflow: hidden;
      color: #fff;
      background:
        radial-gradient(circle at 88% 18%, rgba(255,255,255,0.14), transparent 38%),
        radial-gradient(circle at 12% 88%, rgba(0,0,0,0.16), transparent 42%),
        linear-gradient(135deg, #9f1218 0%, #D81921 42%, #ef4444 100%);
      box-shadow: 0 20px 50px rgba(216,25,33,0.22);
      display: grid; grid-template-columns: 1fr; gap: 0;
    }
    @media (min-width: 768px) { .hero { grid-template-columns: 1.15fr 0.85fr; min-height: 15rem; } }
    .hero__content { padding: 1.5rem 1.25rem; display: flex; flex-direction: column; justify-content: center; }
    @media (min-width: 768px) { .hero__content { padding: 2rem 1.75rem; } }
    .hero__kicker {
      display: inline-flex; align-items: center; gap: 0.45rem;
      margin: 0 0 0.65rem; font-size: 0.8125rem; font-weight: 800; opacity: 0.95;
    }
    .hero__kicker-dot {
      width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #fff;
      box-shadow: 0 0 0 4px rgba(255,255,255,0.2);
    }
    .hero__title {
      margin: 0; font-size: clamp(1.5rem, 4.5vw, 2.35rem);
      font-weight: 800; line-height: 1.3;
    }
    .hero__lead {
      margin: 0.75rem 0 0; max-width: 36rem;
      font-size: 0.875rem; line-height: 1.65; opacity: 0.94;
    }
    .hero__actions { display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1.15rem; }
    .hero__visual {
      display: flex; align-items: center; justify-content: center;
      padding: 1.25rem; position: relative; min-height: 10rem;
    }
    .hero__logo-wrap {
      display: flex; align-items: center; justify-content: center;
      width: min(100%, 14rem); aspect-ratio: 1;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.22);
      border-radius: 1.25rem; padding: 1rem;
      backdrop-filter: blur(6px);
    }
    .hero__logo { width: 100%; height: auto; max-height: 7rem; object-fit: contain; filter: drop-shadow(0 8px 20px rgba(0,0,0,0.2)); }
    .section-head {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 0.75rem; margin: 1.5rem 0 0.75rem;
    }
    .section-head h2 { margin: 0; font-size: 1.125rem; font-weight: 800; }
    .section-head p { margin: 0.25rem 0 0; font-size: 0.8125rem; color: var(--muted); }
    .section-head a {
      color: var(--accent); font-size: 0.8125rem; font-weight: 700;
      text-decoration: none; display: inline-flex; align-items: center; gap: 0.2rem; flex-shrink: 0;
    }
    .categories {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.65rem;
    }
    .cat {
      display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
      padding: 0.85rem 0.35rem; background: #fff; border: 1px solid var(--border);
      border-radius: var(--radius); text-decoration: none; color: var(--ink);
      transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
    }
    .cat:hover { border-color: #fecaca; box-shadow: 0 6px 18px rgba(216,25,33,0.1); transform: translateY(-2px); }
    .cat__icon {
      width: 2.75rem; height: 2.75rem; border-radius: 0.75rem; background: #fef2f2;
      display: grid; place-items: center; font-size: 1.35rem;
    }
    .cat__label { font-size: 0.75rem; font-weight: 700; text-align: center; }
    .tabs {
      display: flex; gap: 0.4rem; overflow-x: auto; padding: 0.35rem;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 9999px; margin-top: 1.25rem; scrollbar-width: none;
    }
    .tabs::-webkit-scrollbar { display: none; }
    .tab {
      flex-shrink: 0; height: 2.25rem; padding: 0 0.9rem;
      border: none; border-radius: 9999px; background: transparent;
      font: inherit; font-size: 0.78rem; font-weight: 700; color: var(--muted); cursor: pointer;
    }
    .tab.is-active { background: var(--accent); color: #fff; box-shadow: 0 4px 12px rgba(216,25,33,0.25); }
    .tab-panel { display: none; }
    .tab-panel.is-active { display: block; }
    .strip {
      display: flex; gap: 0.75rem; overflow-x: auto; padding: 0.25rem 0 0.75rem;
      scroll-snap-type: x mandatory; scrollbar-width: thin; scrollbar-color: var(--accent) #f3f4f6;
    }
    .card {
      flex: 0 0 9.5rem; scroll-snap-align: start;
      background: #fff; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden;
    }
    @media (min-width: 640px) { .card { flex-basis: 11rem; } }
    .card__media { position: relative; aspect-ratio: 1; background: #f3f4f6; overflow: hidden; }
    .card__media img { width: 100%; height: 100%; object-fit: contain; padding: 0.35rem; }
    .card__badge {
      position: absolute; top: 0.4rem; right: 0.4rem;
      background: var(--accent); color: #fff; font-size: 0.6875rem; font-weight: 800;
      padding: 0.15rem 0.45rem; border-radius: 9999px;
    }
    .card__body { padding: 0.65rem; }
    .card__name {
      margin: 0; font-size: 0.75rem; font-weight: 700; line-height: 1.4;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.1rem;
    }
    .card__code { margin: 0.25rem 0 0; font-size: 0.6875rem; color: var(--muted); font-weight: 600; }
    .card__price { margin-top: 0.45rem; }
    .card__price-main { font-size: 0.875rem; font-weight: 800; color: var(--accent); }
    .card__price-old { font-size: 0.6875rem; color: var(--muted); text-decoration: line-through; margin-inline-start: 0.35rem; }
    .card__price-sub { font-size: 0.6875rem; color: var(--muted); margin-top: 0.1rem; }
    .card__add {
      width: 100%; margin-top: 0.5rem; height: 2rem; border: none; border-radius: 0.5rem;
      background: #fef2f2; color: var(--accent); font: inherit; font-size: 0.75rem; font-weight: 800; cursor: pointer;
    }
    .cta {
      margin-top: 2rem; padding: 1.5rem; border-radius: 1.25rem;
      background: linear-gradient(135deg, var(--accent-dark), var(--accent)); color: #fff;
      display: flex; flex-direction: column; gap: 1rem; align-items: flex-start;
    }
    @media (min-width: 640px) {
      .cta { flex-direction: row; align-items: center; justify-content: space-between; }
    }
    .cta h2 { margin: 0; font-size: 1.25rem; }
    .cta p { margin: 0.35rem 0 0; opacity: 0.9; font-size: 0.875rem; }
    .cta__actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .btn--white { background: #fff; color: var(--accent); }
    .btn--outline-white { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #fff; }
    .contact {
      margin-top: 1.25rem; display: grid; grid-template-columns: 1fr; gap: 0.65rem;
    }
    @media (min-width: 640px) { .contact { grid-template-columns: repeat(3, 1fr); } }
    .contact__item {
      display: flex; align-items: center; gap: 0.65rem; padding: 0.85rem 1rem;
      border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface);
      text-decoration: none; color: var(--ink); font-size: 0.8125rem; font-weight: 700;
    }
    .contact__item .material-symbols-outlined { color: var(--accent); }
    .mobile-bar {
      display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 60;
      background: rgba(255,255,255,0.96); backdrop-filter: blur(10px);
      border-top: 1px solid var(--border);
      padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom)); gap: 0.5rem;
    }
    @media (max-width: 767px) {
      .mobile-bar { display: grid; grid-template-columns: 1fr 1fr 1fr; }
      .search, .header__actions .btn--ghost { display: none; }
      .header { top: 2rem; }
    }
    .mobile-bar .btn { justify-content: center; height: 2.75rem; font-size: 0.75rem; width: 100%; }
  </style>
</head>
<body>
  <div class="preview-banner">
    ⚡ <strong>معاينة تصميمية</strong> — بيانات وأسعار من jawishco.sy، التصميم مقترح
  </div>

  <header class="header">
    <div class="header__inner">
      <a href="__SITE__/" class="brand">
        <img class="brand__logo" src="__LOGO__" alt="جاويش للتجارة">
      </a>
      <div class="search">
        <span class="material-symbols-outlined">search</span>
        <input type="search" placeholder="ابحث بالكود أو اسم المنتج..." aria-label="بحث">
      </div>
      <div class="header__actions">
        <a href="__SITE__/customer-login.php" class="btn btn--ghost">تسجيل الدخول</a>
        <a href="__SITE__/store.php" class="btn btn--primary">
          <span class="material-symbols-outlined" style="font-size:1.1rem">storefront</span>
          المتجر
        </a>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="hero" aria-label="ترحيب">
      <div class="hero__content">
        <p class="hero__kicker">
          <span class="hero__kicker-dot" aria-hidden="true"></span>
          مرحباً بكم في جاويش للتجارة
        </p>
        <h1 class="hero__title">تجربة تسوّق جملة<br>احترافية وسلسة</h1>
        <p class="hero__lead">تصفّح أحدث المواد بأسعار واضحة، أضف للسلة، وتابع طلبك خطوة بخطوة.</p>
        <div class="hero__actions">
          <a href="__SITE__/store.php" class="btn btn--light">
            <span class="material-symbols-outlined" style="font-size:1.1rem">storefront</span>
            تصفّح المتجر
          </a>
          <a href="__SITE__/register.php" class="btn btn--ghost-light">
            <span class="material-symbols-outlined" style="font-size:1.1rem">person_add</span>
            حساب جديد
          </a>
        </div>
      </div>
      <div class="hero__visual" aria-hidden="true">
        <div class="hero__logo-wrap">
          <img class="hero__logo" src="__LOGO__" alt="">
        </div>
      </div>
    </section>

    <div class="section-head">
      <div><h2>تصفّح حسب الفئة</h2></div>
    </div>
    <div class="categories">
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">👡</span><span class="cat__label">صندل</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">🥿</span><span class="cat__label">شحاطة</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">👢</span><span class="cat__label">بوط</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">🩴</span><span class="cat__label">خفافة</span></a>
      <a href="__SITE__/store.php#offers" class="cat"><span class="cat__icon">🔥</span><span class="cat__label">عروض</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">👞</span><span class="cat__label">رجالي</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">👠</span><span class="cat__label">نسائي</span></a>
      <a href="__SITE__/store.php" class="cat"><span class="cat__icon">🧒</span><span class="cat__label">أطفال</span></a>
    </div>

    <div class="tabs" id="tabs" role="tablist"></div>
    <div id="panels"></div>

    <section class="cta" aria-label="ابدأ التسوق">
      <div>
        <h2>جاهز لبدء طلبك؟</h2>
        <p>استكشف المتجر كاملاً أو سجّل حسابك للحصول على أسعار وصلاحيات مخصصة.</p>
      </div>
      <div class="cta__actions">
        <a href="__SITE__/store.php" class="btn btn--white">
          <span class="material-symbols-outlined" style="font-size:1.1rem">storefront</span>
          فتح المتجر
        </a>
        <a href="__SITE__/about.php" class="btn btn--outline-white">
          <span class="material-symbols-outlined" style="font-size:1.1rem">groups</span>
          من نحن
        </a>
      </div>
    </section>

    <div class="contact">
      <a href="tel:00963112213299" class="contact__item">
        <span class="material-symbols-outlined">call</span>
        <span dir="ltr">00963-11-2213299</span>
      </a>
      <a href="tel:00963932997794" class="contact__item">
        <span class="material-symbols-outlined">smartphone</span>
        <span dir="ltr">00963932997794</span>
      </a>
      <a href="__SITE__/about.php" class="contact__item">
        <span class="material-symbols-outlined">location_on</span>
        <span>العنوان — من نحن</span>
      </a>
    </div>
  </main>

  <nav class="mobile-bar" aria-label="إجراءات سريعة">
    <a href="__SITE__/store.php" class="btn btn--ghost"><span class="material-symbols-outlined">search</span> المتجر</a>
    <a href="__SITE__/store.php" class="btn btn--primary"><span class="material-symbols-outlined">shopping_cart</span> السلة</a>
    <a href="tel:00963112213299" class="btn btn--ghost"><span class="material-symbols-outlined">call</span> اتصل</a>
  </nav>

  <script>
    var SECTIONS = __SECTIONS_JSON__;
    var SITE = "__SITE__";

    function fmt(n) {
      return Number(n).toLocaleString("ar-SY");
    }

    function renderCard(p) {
      var badge = p.hasOffer && p.offerBadge
        ? '<span class="card__badge">' + p.offerBadge + '</span>' : '';
      var oldPrice = p.hasOffer && p.originalUnitSp
        ? '<span class="card__price-old">' + fmt(p.originalUnitSp) + ' ل.س</span>' : '';
      return '<article class="card">' +
        '<div class="card__media">' + badge +
          '<img src="' + p.thumb + '" alt="' + p.name.replace(/"/g, "&quot;") + '" loading="lazy" decoding="async">' +
        '</div>' +
        '<div class="card__body">' +
          '<h3 class="card__name">' + p.name + '</h3>' +
          '<p class="card__code">' + p.code + '</p>' +
          '<div class="card__price">' +
            '<div class="card__price-main">' + fmt(p.unitSaleSp) + ' ل.س / زوج' + oldPrice + '</div>' +
            '<div class="card__price-sub">' + fmt(p.packageSaleSp) + ' ل.س / طرد</div>' +
          '</div>' +
          '<button class="card__add" type="button">+ أضف للسلة</button>' +
        '</div></article>';
    }

    function render() {
      var tabs = document.getElementById("tabs");
      var panels = document.getElementById("panels");
      SECTIONS.forEach(function (section, i) {
        var tab = document.createElement("button");
        tab.type = "button";
        tab.className = "tab" + (i === 0 ? " is-active" : "");
        tab.setAttribute("data-tab", section.id);
        tab.setAttribute("role", "tab");
        tab.textContent = section.tab;
        tab.addEventListener("click", function () {
          document.querySelectorAll(".tab").forEach(function (t) { t.classList.remove("is-active"); });
          document.querySelectorAll(".tab-panel").forEach(function (p) { p.classList.remove("is-active"); });
          tab.classList.add("is-active");
          document.getElementById("panel-" + section.id).classList.add("is-active");
        });
        tabs.appendChild(tab);

        var panel = document.createElement("div");
        panel.id = "panel-" + section.id;
        panel.className = "tab-panel" + (i === 0 ? " is-active" : "");
        var subtitle = section.subtitle
          ? '<p>' + section.subtitle + '</p>' : '';
        var cards = section.products.map(renderCard).join("");
        panel.innerHTML =
          '<div class="section-head">' +
            '<div><h2>' + section.title + '</h2>' + subtitle + '</div>' +
            '<a href="' + SITE + '/store.php#' + section.id + '">عرض المزيد <span class="material-symbols-outlined" style="font-size:1rem">arrow_back</span></a>' +
          '</div>' +
          '<div class="strip">' + cards + '</div>';
        panels.appendChild(panel);
      });
    }

    render();
  </script>
</body>
</html>
"""

if __name__ == "__main__":
    main()
