#!/usr/bin/env python3
"""Generate home-vision-v1.html with live jawishco.sy homepage data."""

from __future__ import annotations

import html
import json
import re
import urllib.request
from datetime import datetime
from pathlib import Path

API = "https://www.jawishco.sy/api/home-products.php"
HOME = "https://www.jawishco.sy/"
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

CATEGORIES = [
    ("storefront", "صندل"),
    ("steps", "شحاطة"),
    ("hiking", "بوط"),
    ("footprint", "خفافة"),
    ("local_fire_department", "عروض", "#offers"),
    ("man", "رجالي"),
    ("woman", "نسائي"),
    ("child_care", "أطفال"),
]


def fetch_home_html() -> str:
    with urllib.request.urlopen(HOME, timeout=30) as response:
        return response.read().decode("utf-8", errors="replace")


def fetch_company(page_html: str) -> dict[str, str]:
    about = "متجر إلكتروني لتصفح المواد والطلب بسهولة حسب سياسة حسابك."
    match = re.search(
        r'site-footer-brand[\s\S]*?<p class="text-sm leading-7 text-gray-300">\s*([^<]+)',
        page_html,
    )
    if match:
        about = re.sub(r"\s+", " ", match.group(1)).strip()

    phones = re.findall(r'site-footer-contact-link[^>]*dir="ltr">([^<]+)', page_html)
    phone = phones[0] if len(phones) > 0 else "00963-11-2213299"
    mobile = phones[1] if len(phones) > 1 else "00963932997794"

    address_match = re.search(r"location_on[\s\S]{0,400}?font-bold leading-6[^>]*>([^<]+)", page_html)
    address = address_match.group(1).strip() if address_match else "دمشق - حريقة - شارع المأمون"

    whatsapp_match = re.search(r"wa\.me/(\d+)", page_html)
    whatsapp = whatsapp_match.group(1) if whatsapp_match else "963932997794"

    return {
        "name": "جاويش للتجارة",
        "about": about,
        "phone": phone,
        "mobile": mobile,
        "address": address,
        "whatsapp": whatsapp,
    }


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


def render_categories() -> str:
    rows: list[str] = []
    for icon, label, *anchor in CATEGORIES:
        href = SITE + "/store.php" + (anchor[0] if anchor else "")
        rows.append(
            f'<a href="{href}" class="cat">'
            f'<span class="cat__icon"><span class="material-symbols-outlined">{icon}</span></span>'
            f'<span class="cat__label">{label}</span></a>'
        )
    return "\n      ".join(rows)


def main() -> None:
    page_html = fetch_home_html()
    company = fetch_company(page_html)
    sections = fetch_sections()
    output = Path(__file__).resolve().parents[1] / "public" / "dev-test" / "home-vision-v1.html"
    content = (
        TEMPLATE.replace("__SECTIONS_JSON__", json.dumps(sections, ensure_ascii=False))
        .replace("__LOGO__", LOGO)
        .replace("__SITE__", SITE)
        .replace("__COMPANY_NAME__", company["name"])
        .replace("__COMPANY_ABOUT__", company["about"])
        .replace("__COMPANY_PHONE__", company["phone"])
        .replace("__COMPANY_MOBILE__", company["mobile"])
        .replace("__COMPANY_ADDRESS__", company["address"])
        .replace("__COMPANY_WHATSAPP__", company["whatsapp"])
        .replace("__YEAR__", str(datetime.now().year))
        .replace("__CATEGORIES__", render_categories())
    )
    output.write_text(content, encoding="utf-8")
    print(f"Wrote {output} ({len(sections)} sections)")


TEMPLATE = r"""<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>معاينة — رؤية الصفحة الرئيسية | __COMPANY_NAME__</title>
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
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      display: flex; flex-direction: column;
      font-family: "IBM Plex Sans Arabic", system-ui, sans-serif;
      background: #fff; color: var(--ink); line-height: 1.5;
    }
    .preview-banner {
      position: sticky; top: 0; z-index: 100;
      background: linear-gradient(90deg, #1e293b, #334155);
      color: #fff; text-align: center;
      padding: 0.55rem 1rem; font-size: 0.8125rem; font-weight: 600;
    }
    .preview-banner strong { color: #fca5a5; }
    .page-main { flex: 1; }
    .container { max-width: 72rem; margin: 0 auto; padding: 0 1rem 2rem; }
    .header {
      position: sticky; top: 2rem; z-index: 50;
      background: rgba(255,255,255,0.94); backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(15,23,42,0.08);
      box-shadow: 0 4px 16px rgba(15,23,42,0.04);
    }
    .header__inner {
      max-width: 72rem; margin: 0 auto; padding: 0.65rem 1rem;
      display: flex; align-items: center; gap: 0.75rem;
    }
    .brand { display: inline-flex; align-items: center; text-decoration: none; flex-shrink: 0; }
    .brand__logo {
      display: block; height: 3.1rem; width: auto; max-width: 8rem;
      object-fit: contain; filter: drop-shadow(0 1px 3px rgba(15,23,42,0.12));
    }
    @media (min-width: 640px) { .brand__logo { height: 3.5rem; max-width: 9rem; } }
    .search {
      flex: 1; max-width: 28rem; display: flex; align-items: center; gap: 0.5rem;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 9999px; padding: 0.45rem 1rem;
    }
    .search input { flex: 1; border: none; background: transparent; font: inherit; outline: none; min-width: 0; }
    .search .material-symbols-outlined { color: var(--muted); font-size: 1.25rem; }
    .header__actions { display: flex; align-items: center; gap: 0.5rem; margin-inline-start: auto; }
    .btn {
      display: inline-flex; align-items: center; gap: 0.35rem;
      height: 2.5rem; padding: 0 1rem; border-radius: 0.75rem;
      font: inherit; font-weight: 700; font-size: 0.875rem;
      text-decoration: none; border: none; cursor: pointer;
      transition: transform 0.15s, box-shadow 0.15s, background 0.15s;
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
      margin-top: 1.25rem; border-radius: 1.75rem; overflow: hidden; color: #fff;
      background:
        radial-gradient(circle at 88% 18%, rgba(255,255,255,0.14), transparent 38%),
        radial-gradient(circle at 12% 88%, rgba(0,0,0,0.16), transparent 42%),
        linear-gradient(135deg, #9f1218 0%, #D81921 42%, #ef4444 100%);
      box-shadow: 0 20px 50px rgba(216,25,33,0.22);
      display: grid; grid-template-columns: 1fr;
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
    .hero__title { margin: 0; font-size: clamp(1.5rem, 4.5vw, 2.35rem); font-weight: 800; line-height: 1.3; }
    .hero__title-line { display: block; }
    @media (min-width: 640px) {
      .hero__title-line { display: inline; }
      .hero__title-line + .hero__title-line::before { content: " "; }
    }
    .hero__lead { margin: 0.75rem 0 0; max-width: 36rem; font-size: 0.875rem; line-height: 1.65; opacity: 0.94; }
    .hero__actions { display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1.15rem; }
    .hero__visual {
      display: none; align-items: center; justify-content: center;
      padding: 1.25rem; position: relative; min-height: 11rem;
    }
    @media (min-width: 768px) { .hero__visual { display: flex; } }
    .hero__orbits {
      position: relative; width: min(100%, 12rem); aspect-ratio: 1;
      display: grid; place-items: center; color: rgba(255,255,255,0.85);
    }
    .hero__orbits-svg { width: 100%; height: 100%; }
    .hero__orbits-layer--a { animation: hero-orbit-spin 28s linear infinite; transform-origin: center; }
    .hero__orbits-layer--b { animation: hero-orbit-spin 20s linear infinite reverse; transform-origin: center; }
    .hero__orbits-layer--c { animation: hero-orbit-spin 14s linear infinite; transform-origin: center; }
    .hero__orbit-core {
      position: absolute; width: 0.85rem; height: 0.85rem; border-radius: 9999px;
      background: rgba(255,255,255,0.92); box-shadow: 0 0 18px rgba(255,255,255,0.45);
      animation: hero-orbit-core 4.8s ease-in-out infinite;
    }
    @keyframes hero-orbit-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    @keyframes hero-orbit-core {
      0%, 100% { transform: scale(1); opacity: 0.92; }
      50% { transform: scale(1.12); opacity: 1; }
    }

    /* Mobile: compact strip — logo already in header, skip heavy visual */
    @media (max-width: 767px) {
      .hero {
        margin-top: 0.65rem;
        border-radius: 1rem;
        box-shadow: 0 8px 22px rgba(216,25,33,0.16);
      }
      .hero__content { padding: 0.85rem 0.9rem 0.95rem; }
      .hero__kicker { display: none; }
      .hero__title {
        font-size: 1.05rem;
        line-height: 1.45;
      }
      .hero__title-line { display: inline; }
      .hero__title-line + .hero__title-line::before { content: " "; }
      .hero__lead {
        margin-top: 0.35rem;
        font-size: 0.75rem;
        line-height: 1.55;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        opacity: 0.92;
      }
      .hero__actions {
        margin-top: 0.65rem;
        gap: 0.45rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
      }
      .hero__actions .btn {
        justify-content: center;
        height: 2.25rem;
        padding: 0 0.55rem;
        font-size: 0.75rem;
        min-width: 0;
      }
      .hero__actions .btn .material-symbols-outlined { font-size: 1rem !important; }
      .section-head { margin-top: 1rem; }
      .categories { gap: 0.45rem; }
      .cat { padding: 0.65rem 0.25rem; }
      .cat__icon { width: 2.35rem; height: 2.35rem; }
      .cat__icon .material-symbols-outlined { font-size: 1.15rem; }
      .cat__label { font-size: 0.6875rem; }
    }
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
    .categories { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.65rem; }
    .cat {
      display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
      padding: 0.85rem 0.35rem; background: #fff; border: 1px solid var(--border);
      border-radius: var(--radius); text-decoration: none; color: var(--ink);
      transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
    }
    .cat:hover { border-color: #fecaca; box-shadow: 0 6px 18px rgba(216,25,33,0.1); transform: translateY(-2px); }
    .cat__icon {
      width: 2.75rem; height: 2.75rem; border-radius: 0.75rem; background: #fef2f2;
      display: grid; place-items: center; color: var(--accent);
    }
    .cat__icon .material-symbols-outlined { font-size: 1.35rem; }
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
    .tab-panel--offer.is-active .strip-wrap { border-color: #fecaca; background: linear-gradient(180deg, #fff5f5, #fff); }
    .strip-wrap {
      position: relative; border: 1px solid var(--border); border-radius: 1rem;
      background: #fff; padding: 0.65rem 0.35rem;
    }
    .strip-nav {
      position: absolute; top: 50%; transform: translateY(-50%);
      width: 2rem; height: 2rem; border: none; border-radius: 9999px;
      background: rgba(255,255,255,0.95); color: var(--ink);
      box-shadow: 0 4px 14px rgba(15,23,42,0.12); cursor: pointer; z-index: 2;
      display: grid; place-items: center;
    }
    .strip-nav--prev { right: 0.35rem; }
    .strip-nav--next { left: 0.35rem; }
    .strip {
      display: flex; gap: 0.75rem; overflow-x: auto; padding: 0.15rem 2rem 0.35rem;
      scroll-snap-type: x mandatory; scrollbar-width: none;
    }
    .strip::-webkit-scrollbar { display: none; }
    .card {
      flex: 0 0 9.5rem; scroll-snap-align: start;
      background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
      overflow: hidden; transition: box-shadow 0.15s, transform 0.15s;
    }
    @media (min-width: 640px) { .card { flex-basis: 11rem; } }
    .card:hover { box-shadow: 0 10px 24px rgba(15,23,42,0.08); transform: translateY(-2px); }
    .card__media { position: relative; height: 8rem; background: #f3f4f6; overflow: hidden; }
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
    .card__add:hover { background: #fee2e2; }
    .cta {
      margin-top: 2rem; padding: 1.5rem; border-radius: 1.25rem;
      background: linear-gradient(135deg, var(--accent-dark), var(--accent)); color: #fff;
      display: flex; flex-direction: column; gap: 1rem; align-items: flex-start;
    }
    @media (min-width: 640px) { .cta { flex-direction: row; align-items: center; justify-content: space-between; } }
    .cta h2 { margin: 0; font-size: 1.25rem; }
    .cta p { margin: 0.35rem 0 0; opacity: 0.9; font-size: 0.875rem; }
    .cta__actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .btn--white { background: #fff; color: var(--accent); }
    .btn--outline-white { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #fff; }

    /* Footer — same structure as live site */
    .site-footer {
      margin-top: auto;
      background: linear-gradient(180deg, #1f2937 0%, #111827 100%);
      color: #e5e7eb;
    }
    .site-footer a { color: #d1d5db; text-decoration: none; transition: color 0.2s; }
    .site-footer a:hover { color: #fff; }
    .site-footer__inner {
      max-width: 72rem; margin: 0 auto; padding: 2.5rem 1rem 2rem;
      display: grid; grid-template-columns: 1fr; gap: 2rem;
    }
    @media (min-width: 768px) { .site-footer__inner { grid-template-columns: 1fr 1fr; } }
    @media (min-width: 1280px) { .site-footer__inner { grid-template-columns: 1.1fr 0.9fr 1fr 1fr; gap: 2rem; } }
    .site-footer-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 0.75rem; }
    .site-footer-brand__logo { height: 3.5rem; width: auto; max-width: 10rem; object-fit: contain; }
    .site-footer-brand h2 { margin: 0; font-size: 1.125rem; font-weight: 800; color: #fff; }
    .site-footer-brand p { margin: 0; font-size: 0.875rem; line-height: 1.75; color: #d1d5db; }
    .site-footer h3 { margin: 0 0 1rem; font-size: 0.875rem; font-weight: 800; color: #fff; }
    .site-footer-links { display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem; }
    .site-footer-contact-item {
      display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.5rem 0;
    }
    .site-footer-contact-icon {
      display: inline-flex; height: 2.25rem; width: 2.25rem;
      align-items: center; justify-content: center; border-radius: 0.65rem;
      background: rgba(255,255,255,0.08); color: #fca5a5; flex-shrink: 0;
    }
    .site-footer-contact-label { margin: 0 0 0.15rem; font-size: 0.75rem; color: #9ca3af; }
    .site-footer-contact-value { margin: 0; font-weight: 800; color: #f9fafb; }
    .site-footer-contact-value:hover { color: #fff; }
    .site-footer-shop p { margin: 0 0 1rem; font-size: 0.875rem; line-height: 1.75; color: #d1d5db; }
    .site-footer-store-btn {
      display: inline-flex; height: 2.75rem; align-items: center; gap: 0.5rem;
      border-radius: 0.85rem; background: var(--accent); color: #fff !important;
      padding: 0 1rem; font-size: 0.875rem; font-weight: 800;
    }
    .site-footer-store-btn:hover { filter: brightness(1.08); color: #fff !important; }
    .site-footer-whatsapp {
      display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 0.75rem;
      padding: 0.65rem 1rem; border-radius: 0.85rem; background: #059669;
      color: #fff !important; font-weight: 800; font-size: 0.875rem;
    }
    .site-footer-whatsapp:hover { background: #047857; color: #fff !important; }
    .site-footer-bottom {
      border-top: 1px solid rgba(255,255,255,0.08); color: #9ca3af;
      padding: 1rem; font-size: 0.75rem;
    }
    .site-footer-bottom__inner {
      max-width: 72rem; margin: 0 auto; padding: 0 1rem;
      display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 0.5rem;
    }
    @media (min-width: 640px) { .site-footer-bottom__inner { flex-direction: row; } }

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
      body { padding-bottom: 4.5rem; }
      .brand__logo { height: 2.45rem; max-width: 6.5rem; }
      .header__inner { padding: 0.5rem 0.85rem; min-height: 3rem; }
      .header__actions .btn--primary { height: 2.25rem; padding: 0 0.75rem; font-size: 0.8125rem; }
    }
    .mobile-bar .btn { justify-content: center; height: 2.75rem; font-size: 0.75rem; width: 100%; }
  </style>
</head>
<body>
  <div class="preview-banner">
    ⚡ <strong>معاينة تصميمية</strong> — بيانات وأسعار من jawishco.sy، التصميم مقترح
  </div>

  <div class="page-main">
    <header class="header">
      <div class="header__inner">
        <a href="__SITE__/" class="brand">
          <img class="brand__logo" src="__LOGO__" alt="__COMPANY_NAME__">
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
            مرحباً بكم في __COMPANY_NAME__
          </p>
          <h1 class="hero__title">
            <span class="hero__title-line">تجربة تسوّق جملة</span>
            <span class="hero__title-line">احترافية وسلسة</span>
          </h1>
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
          <div class="hero__orbits">
            <svg class="hero__orbits-svg" viewBox="0 0 200 200" focusable="false">
              <g class="hero__orbits-layer hero__orbits-layer--a">
                <circle cx="100" cy="100" r="88" fill="none" stroke="currentColor" stroke-width="1.1" stroke-dasharray="175 378" stroke-linecap="round" opacity="0.18"></circle>
                <circle cx="100" cy="12" r="3.6" fill="currentColor" opacity="0.42"></circle>
              </g>
              <g class="hero__orbits-layer hero__orbits-layer--b">
                <circle cx="100" cy="100" r="62" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="120 270" stroke-linecap="round" opacity="0.14"></circle>
                <circle cx="162" cy="100" r="3" fill="currentColor" opacity="0.36"></circle>
              </g>
              <g class="hero__orbits-layer hero__orbits-layer--c">
                <circle cx="100" cy="100" r="38" fill="none" stroke="currentColor" stroke-width="0.9" stroke-dasharray="78 162" stroke-linecap="round" opacity="0.1"></circle>
                <circle cx="100" cy="138" r="2.6" fill="currentColor" opacity="0.32"></circle>
              </g>
            </svg>
            <span class="hero__orbit-core"></span>
          </div>
        </div>
      </section>

      <div class="section-head"><div><h2>تصفّح حسب الفئة</h2></div></div>
      <div class="categories">
      __CATEGORIES__
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
    </main>
  </div>

  <footer class="site-footer">
    <div class="site-footer__inner">
      <div>
        <div class="site-footer-brand">
          <img class="site-footer-brand__logo" src="__LOGO__" alt="__COMPANY_NAME__">
          <h2>__COMPANY_NAME__</h2>
        </div>
        <p class="site-footer-brand" style="margin-top:0.75rem">__COMPANY_ABOUT__</p>
      </div>

      <div>
        <h3>روابط سريعة</h3>
        <div class="site-footer-links">
          <a href="__SITE__/index.php">الرئيسية</a>
          <a href="__SITE__/store.php">المتجر</a>
          <a href="__SITE__/about.php">من نحن</a>
          <a href="__SITE__/customer-login.php">دخول العملاء</a>
          <a href="__SITE__/register.php">إنشاء حساب جديد</a>
        </div>
      </div>

      <div>
        <h3>تواصل معنا</h3>
        <div class="site-footer-contact-item">
          <span class="site-footer-contact-icon"><span class="material-symbols-outlined">call</span></span>
          <div>
            <p class="site-footer-contact-label">الهاتف</p>
            <a href="tel:00963112213299" class="site-footer-contact-value" dir="ltr">__COMPANY_PHONE__</a>
          </div>
        </div>
        <div class="site-footer-contact-item">
          <span class="site-footer-contact-icon"><span class="material-symbols-outlined">smartphone</span></span>
          <div>
            <p class="site-footer-contact-label">الموبايل</p>
            <a href="tel:00963932997794" class="site-footer-contact-value" dir="ltr">__COMPANY_MOBILE__</a>
          </div>
        </div>
        <div class="site-footer-contact-item">
          <span class="site-footer-contact-icon"><span class="material-symbols-outlined">location_on</span></span>
          <div>
            <p class="site-footer-contact-label">العنوان</p>
            <a href="__SITE__/about.php" class="site-footer-contact-value">__COMPANY_ADDRESS__</a>
          </div>
        </div>
      </div>

      <div class="site-footer-shop">
        <h3>ابدأ التسوق</h3>
        <p>تصفّح أحدث المواد واطلب مباشرة من المتجر أو عبر حسابك المفعّل.</p>
        <a href="__SITE__/store.php" class="site-footer-store-btn">
          <span class="material-symbols-outlined">storefront</span>
          تصفّح المتجر
        </a>
        <a href="https://wa.me/__COMPANY_WHATSAPP__" class="site-footer-whatsapp" target="_blank" rel="noopener">
          <span class="material-symbols-outlined">chat</span>
          واتساب
        </a>
      </div>
    </div>
    <div class="site-footer-bottom">
      <div class="site-footer-bottom__inner">
        <span>© __YEAR__ __COMPANY_NAME__. جميع الحقوق محفوظة.</span>
        <a href="__SITE__/about.php">من نحن</a>
      </div>
    </div>
  </footer>

  <nav class="mobile-bar" aria-label="إجراءات سريعة">
    <a href="__SITE__/store.php" class="btn btn--ghost"><span class="material-symbols-outlined">storefront</span> المتجر</a>
    <a href="__SITE__/store.php" class="btn btn--primary"><span class="material-symbols-outlined">shopping_cart</span> السلة</a>
    <a href="tel:00963112213299" class="btn btn--ghost"><span class="material-symbols-outlined">call</span> اتصل</a>
  </nav>

  <script>
    var SECTIONS = __SECTIONS_JSON__;
    var SITE = "__SITE__";

    function fmt(n) { return Number(n).toLocaleString("ar-SY"); }

    function renderCard(p) {
      var badge = p.hasOffer && p.offerBadge ? '<span class="card__badge">' + p.offerBadge + '</span>' : '';
      var oldPrice = p.hasOffer && p.originalUnitSp
        ? '<span class="card__price-old">' + fmt(p.originalUnitSp) + ' ل.س</span>' : '';
      return '<article class="card"><div class="card__media">' + badge +
        '<img src="' + p.thumb + '" alt="' + p.name.replace(/"/g, "&quot;") + '" loading="lazy" decoding="async"></div>' +
        '<div class="card__body"><h3 class="card__name">' + p.name + '</h3><p class="card__code">' + p.code + '</p>' +
        '<div class="card__price"><div class="card__price-main">' + fmt(p.unitSaleSp) + ' ل.س / زوج' + oldPrice + '</div>' +
        '<div class="card__price-sub">' + fmt(p.packageSaleSp) + ' ل.س / طرد</div></div>' +
        '<button class="card__add" type="button">+ أضف للسلة</button></div></article>';
    }

    function bindStripNav(wrap) {
      var strip = wrap.querySelector(".strip");
      var prev = wrap.querySelector(".strip-nav--prev");
      var next = wrap.querySelector(".strip-nav--next");
      if (!strip || !prev || !next) return;
      prev.addEventListener("click", function () { strip.scrollBy({ left: 220, behavior: "smooth" }); });
      next.addEventListener("click", function () { strip.scrollBy({ left: -220, behavior: "smooth" }); });
    }

    function render() {
      var tabs = document.getElementById("tabs");
      var panels = document.getElementById("panels");
      SECTIONS.forEach(function (section, i) {
        var tab = document.createElement("button");
        tab.type = "button";
        tab.className = "tab" + (i === 0 ? " is-active" : "");
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
        panel.className = "tab-panel" + (i === 0 ? " is-active" : "") + (section.id === "offers" ? " tab-panel--offer" : "");
        var subtitle = section.subtitle ? '<p>' + section.subtitle + '</p>' : '';
        var cards = section.products.map(renderCard).join("");
        panel.innerHTML =
          '<div class="section-head"><div><h2>' + section.title + '</h2>' + subtitle + '</div>' +
          '<a href="' + SITE + '/store.php#' + section.id + '">عرض المزيد <span class="material-symbols-outlined" style="font-size:1rem">arrow_back</span></a></div>' +
          '<div class="strip-wrap">' +
          '<button type="button" class="strip-nav strip-nav--prev" aria-label="السابق"><span class="material-symbols-outlined">chevron_right</span></button>' +
          '<button type="button" class="strip-nav strip-nav--next" aria-label="التالي"><span class="material-symbols-outlined">chevron_left</span></button>' +
          '<div class="strip">' + cards + '</div></div>';
        panels.appendChild(panel);
        bindStripNav(panel.querySelector(".strip-wrap"));
      });
    }

    render();
  </script>
</body>
</html>
"""

if __name__ == "__main__":
    main()
