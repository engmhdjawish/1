#!/usr/bin/env python3
"""Build bundled Material Symbols library for dashboard icon picker."""

from __future__ import annotations

import json
import re
import urllib.request
from pathlib import Path

METADATA_URL = "https://fonts.google.com/metadata/icons?key=material_symbols&incomplete=true"
OUTPUT = Path(__file__).resolve().parents[1] / "public" / "assets" / "material-icon-library.json"

CATEGORY_MAP = {
    "business": "business",
    "shopping": "business",
    "social": "social",
    "maps": "travel",
    "travel": "travel",
    "transit": "travel",
    "home": "home",
    "household": "home",
    "actions": "actions",
    "ui actions": "actions",
    "activities": "actions",
    "hardware": "devices",
    "android": "devices",
    "images": "media",
    "audio&video": "media",
    "av": "media",
    "communicate": "communication",
    "text": "communication",
    "brand": "brand",
    "privacy": "general",
}

GROUP_LABELS = {
    "business": "تسوّق وأعمال",
    "social": "أشخاص واجتماعي",
    "travel": "مواقع وسفر",
    "home": "منزل",
    "actions": "إجراءات",
    "devices": "أجهزة",
    "media": "صور ووسائط",
    "communication": "تواصل",
    "brand": "علامات",
    "general": "عام",
}

# Common icons with Arabic labels for homepage categories.
AR_LABELS = {
    "storefront": "متجر",
    "store": "محل",
    "shopping_bag": "حقيبة",
    "shopping_cart": "سلة",
    "shopping_basket": "سلة مشتريات",
    "sell": "بيع",
    "local_offer": "عرض",
    "local_fire_department": "عروض",
    "percent": "تخفيض",
    "redeem": "كوبون",
    "new_releases": "جديد",
    "star": "مميز",
    "favorite": "مفضل",
    "steps": "شحاطة / صندل",
    "hiking": "بوط",
    "footprint": "خفافة",
    "snowflake": "شتوي",
    "man": "رجالي",
    "woman": "نسائي",
    "boy": "ولد",
    "girl": "بنت",
    "child_care": "أطفال",
    "family_restroom": "عائلة",
    "elderly": "كبار",
    "category": "فئة",
    "inventory_2": "مخزون",
    "flag": "وطني",
    "factory": "محلي",
    "warehouse": "مستودع",
    "local_shipping": "توصيل",
    "payments": "أسعار",
    "price_check": "فحص سعر",
    "barcode_scanner": "باركود",
    "palette": "ألوان",
    "checkroom": "ملابس",
    "watch": "ساعة",
    "backpack": "حقيبة ظهر",
    "beach_access": "صيفي",
    "wb_sunny": "صيف",
    "ac_unit": "بارد",
    "home": "رئيسية",
    "apps": "كل الفئات",
    "link": "رابط",
}


def humanize(name: str) -> str:
    return re.sub(r"_+", " ", name.strip()).strip()


def map_group(categories: list[str]) -> str:
    for category in categories:
        key = CATEGORY_MAP.get(category.lower().strip())
        if key:
            return key
    return "general"


def fetch_icons() -> list[dict]:
    with urllib.request.urlopen(METADATA_URL, timeout=120) as response:
        raw = response.read().decode("utf-8", errors="replace")
    if raw.startswith(")]}'"):
        raw = raw[4:]

    payload = json.loads(raw)
    by_name: dict[str, dict] = {}

    for icon in payload.get("icons", []):
        unsupported = set(icon.get("unsupported_families") or [])
        if "Material Symbols Outlined" in unsupported:
            continue

        name = str(icon.get("name") or "").strip()
        if not re.fullmatch(r"[a-z0-9_]+", name):
            continue

        categories = [str(c) for c in (icon.get("categories") or []) if str(c).strip()]
        tags = [str(t).lower() for t in (icon.get("tags") or []) if str(t).strip()]
        popularity = int(icon.get("popularity") or 0)
        group = map_group(categories)

        existing = by_name.get(name)
        if existing is None or popularity > int(existing.get("_popularity") or 0):
            by_name[name] = {
                "key": name,
                "label_ar": AR_LABELS.get(name, humanize(name)),
                "group": group,
                "tags": sorted(set(tags))[:12],
                "_popularity": popularity,
            }

    icons = list(by_name.values())
    icons.sort(key=lambda item: (-int(item.pop("_popularity", 0)), item["key"]))
    return icons


def main() -> None:
    icons = fetch_icons()
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(
        json.dumps(
            {
                "version": 1,
                "count": len(icons),
                "groups": GROUP_LABELS,
                "icons": icons,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {OUTPUT} ({len(icons)} icons)")


if __name__ == "__main__":
    main()
