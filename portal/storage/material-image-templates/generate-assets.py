#!/usr/bin/env python3
"""Generate default template PNG layers without external dependencies."""

from __future__ import annotations

import struct
import zlib
from pathlib import Path

DIR = Path(__file__).resolve().parent


def _chunk(tag: bytes, data: bytes) -> bytes:
    crc = zlib.crc32(tag + data) & 0xFFFFFFFF
    return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", crc)


def write_png(path: Path, width: int, height: int, rgba_pixels: bytes) -> None:
    raw = b"".join(
        b"\x00" + rgba_pixels[y * width * 4 : (y + 1) * width * 4]
        for y in range(height)
    )
    ihdr = struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0)
    png = b"\x89PNG\r\n\x1a\n" + _chunk(b"IHDR", ihdr) + _chunk(b"IDAT", zlib.compress(raw, 9)) + _chunk(b"IEND", b"")
    path.write_bytes(png)


def blank_rgba(width: int, height: int, fill=(0, 0, 0, 0)) -> bytearray:
    r, g, b, a = fill
    px = bytearray(width * height * 4)
    for i in range(0, len(px), 4):
        px[i : i + 4] = bytes((r, g, b, a))
    return px


def set_pixel(px: bytearray, width: int, x: int, y: int, color: tuple[int, int, int, int]) -> None:
    if x < 0 or y < 0:
        return
    idx = (y * width + x) * 4
    if idx < 0 or idx + 3 >= len(px):
        return
    px[idx : idx + 4] = bytes(color)


def draw_thick_line(px: bytearray, width: int, height: int, x0: int, y0: int, x1: int, y1: int, color: tuple[int, int, int, int], thickness: int = 8) -> None:
    steps = max(abs(x1 - x0), abs(y1 - y0), 1)
    for i in range(steps + 1):
        t = i / steps
        x = int(round(x0 + (x1 - x0) * t))
        y = int(round(y0 + (y1 - y0) * t))
        for dx in range(-thickness, thickness + 1):
            for dy in range(-thickness, thickness + 1):
                if dx * dx + dy * dy <= thickness * thickness:
                    set_pixel(px, width, x + dx, y + dy, color)


def generate_accent() -> None:
    w, h = 260, 380
    px = blank_rgba(w, h)
    red = (196, 30, 58, 255)
    lines = [
        (40, 20, 120, 300),
        (55, 40, 170, 95),
        (70, 110, 190, 180),
        (48, 80, 130, 250),
    ]
    for x0, y0, x1, y1 in lines:
        draw_thick_line(px, w, h, x0, y0, x1, y1, red, 7)
    write_png(DIR / "accent.png", w, h, bytes(px))


def generate_logo() -> None:
    w, h = 200, 120
    px = blank_rgba(w, h)
    white = (255, 255, 255, 255)
    red = (196, 30, 58, 255)
    for y in range(18, 58):
        for x in range(18, 78):
            set_pixel(px, w, x, y, white)
    for y in range(70, 88):
        for x in range(18, 120):
            set_pixel(px, w, x, y, white)
    for y in range(92, 108):
        for x in range(18, 110):
            set_pixel(px, w, x, y, red)
    write_png(DIR / "logo.png", w, h, bytes(px))


def generate_footer_overlay() -> None:
    w, h = 1080, 1080
    px = blank_rgba(w, h)
    white = (255, 255, 255, 255)
    divider = (220, 220, 220, 255)
    footer_y = 770
    columns = [
        (40, 250, ["Qty", "Pack"]),
        (330, 280, ["Company", "Maker"]),
        (650, 360, ["Product", "Name"]),
    ]
    for x, col_w, labels in columns:
        cx = x + col_w // 2
        for i, label in enumerate(labels):
            text_x = cx - len(label) * 3
            text_y = footer_y + 36 + i * 24
            for ch_idx, _ch in enumerate(label):
                for dy in range(0, 12):
                    for dx in range(0, 8):
                        set_pixel(px, w, text_x + ch_idx * 8 + dx, text_y + dy, white)
    for x in (322, 642):
        for y in range(footer_y + 24, footer_y + 286):
            set_pixel(px, w, x, y, divider)
            set_pixel(px, w, x + 1, y, divider)
    write_png(DIR / "footer-overlay.png", w, h, bytes(px))


if __name__ == "__main__":
    generate_accent()
    generate_logo()
    generate_footer_overlay()
    print(f"Generated template assets in {DIR}")
