#!/usr/bin/env python3
"""
Fetch Legacy of the Void 1v1 ladder map list from Liquipedia timeline,
merge into map-veto/data/maps.json, and download infobox thumbnails locally.

Run from repo root:
  python3 scripts/sync_liquipedia_lotv_maps.py > /tmp/sync_lotv_maps.log 2>&1
"""

from __future__ import annotations

import html as html_lib
import json
import re
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAPS_JSON = ROOT / "map-veto" / "data" / "maps.json"
IMG_DIR = ROOT / "map-veto" / "data" / "images"
LADDER_URL = "https://liquipedia.net/starcraft2/Maps/Ladder_Maps/Legacy_of_the_Void"
BASE = "https://liquipedia.net"
UA = "Mozilla/5.0 (compatible; FSL-map-catalog/1.1; +https://liquipedia.net)"

DEFAULT_DESC = "Legacy of the Void 1v1 ladder map (Liquipedia timeline)."


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=90) as resp:
        return resp.read().decode("utf-8", errors="replace")


def fetch_bytes(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=90) as resp:
        return resp.read()


def slug_to_map_id(slug: str) -> str:
    s = urllib.parse.unquote(slug)
    s = s.replace("'", "").replace("-", "_")
    s = re.sub(r"[^a-zA-Z0-9_]", "_", s)
    s = re.sub(r"_+", "_", s).strip("_").lower()
    return "mv_lotv_" + s


def parse_timeline_maps(html: str) -> list[tuple[str, str]]:
    sec = re.search(
        r'<h3[^>]*id="1v1"[^>]*>.*?</h3>(.*?)<h3[^>]*id="2v2"',
        html,
        re.DOTALL | re.IGNORECASE,
    )
    if not sec:
        raise RuntimeError("Could not find 1v1 timeline section on ladder page.")
    part = sec.group(1)
    blocks = re.findall(
        r'href="/starcraft2/([^"]+)"[^>]*title="([^"]+)"',
        part,
    )
    seen: set[str] = set()
    out: list[tuple[str, str]] = []
    for slug, title in blocks:
        if "/" in slug or ":" in slug:
            continue
        if slug in seen:
            continue
        seen.add(slug)
        out.append((slug, html_lib.unescape(title)))
    return out


def _thumb_pixel_width(url: str) -> int:
    m = re.search(r"/(\d+)px-", url)
    if m:
        try:
            return int(m.group(1))
        except ValueError:
            return 0
    return 0


def extract_infobox_thumb_path(page_html: str) -> str | None:
    """Prefer largest URL from infobox map image src/srcset."""
    blk = re.search(
        r'infobox-image-wrapper[\s\S]{0,25000}?<img\s+([^>]+)>',
        page_html,
        re.IGNORECASE,
    )
    if blk:
        tag_inner = blk.group(1)
        srcset_m = re.search(r'srcset="([^"]+)"', tag_inner)
        candidates: list[tuple[int, str]] = []
        if srcset_m:
            for chunk in srcset_m.group(1).split(","):
                chunk = chunk.strip()
                if not chunk:
                    continue
                parts = chunk.split()
                url = parts[0]
                if not url.startswith("/commons/images/thumb/"):
                    continue
                wpx = _thumb_pixel_width(url)
                candidates.append((wpx, url))
        src_m = re.search(r'src="(/commons/images/thumb/[^"]+)"', tag_inner)
        if src_m:
            u = src_m.group(1)
            candidates.append((_thumb_pixel_width(u), u))
        if candidates:
            candidates.sort(key=lambda x: (-x[0], -len(x[1])))
            return candidates[0][1]

    m = re.search(
        r'infobox-image-wrapper[\s\S]{0,20000}?<img[^>]+src="(/commons/images/thumb/[^"]+)"',
        page_html,
        re.IGNORECASE,
    )
    if m:
        return m.group(1)
    for path in re.findall(
        r'src="(/commons/images/thumb/[^"]+\.(?:jpg|jpeg|png|webp))"', page_html
    ):
        if "px-" not in path:
            continue
        low = path.lower()
        if any(x in low for x in ("race_icon", "logo", "/icons/", "_icon.png")):
            continue
        return path
    return None


def ext_from_url(path: str) -> str:
    base = path.split("/")[-1].split("?")[0]
    for ext in (".jpg", ".jpeg", ".png", ".webp"):
        if base.lower().endswith(ext):
            return ".jpg" if ext == ".jpeg" else ext
    return ".jpg"


def main() -> int:
    IMG_DIR.mkdir(parents=True, exist_ok=True)

    print("Fetching ladder timeline page...", flush=True)
    ladder_html = fetch(LADDER_URL)
    pairs = parse_timeline_maps(ladder_html)
    print(f"Found {len(pairs)} 1v1 maps in timeline.", flush=True)

    existing: dict[str, dict] = {}
    if MAPS_JSON.is_file():
        with open(MAPS_JSON, "r", encoding="utf-8") as f:
            for row in json.load(f):
                existing[str(row["id"])] = row

    merged: list[dict] = []
    errors: list[str] = []

    for i, (slug, display_title) in enumerate(pairs):
        map_id = slug_to_map_id(slug)
        page_url = f"{BASE}/starcraft2/{urllib.parse.quote(slug, safe='()%27')}"
        prev = existing.get(map_id)

        record = {
            "id": map_id,
            "name": display_title,
            "description": DEFAULT_DESC,
            "image_url": f"/fsl/map-veto/data/images/{map_id}.jpg",
            "is_active": True,
            "is_overflow_eligible": False,
        }
        if prev:
            record["name"] = prev.get("name") or display_title
            record["description"] = prev.get("description") or DEFAULT_DESC
            record["is_active"] = bool(prev.get("is_active", True))
            record["is_overflow_eligible"] = bool(prev.get("is_overflow_eligible", False))

        try:
            print(f"[{i+1}/{len(pairs)}] {map_id} ...", flush=True)
            page_html = fetch(page_url)
            thumb = extract_infobox_thumb_path(page_html)
            if not thumb:
                errors.append(f"{map_id}: no infobox thumb ({page_url})")
                merged.append(record)
                time.sleep(0.35)
                continue

            thumb_use = thumb
            ext = ext_from_url(thumb_use)
            img_name = f"{map_id}{ext}"
            dest = IMG_DIR / img_name
            img_url_full = BASE + thumb_use
            data = fetch_bytes(img_url_full)
            dest.write_bytes(data)
            record["image_url"] = f"/fsl/map-veto/data/images/{img_name}"
        except Exception as e:  # noqa: BLE001 - collect and continue
            errors.append(f"{map_id}: {e} ({page_url})")

        merged.append(record)
        time.sleep(0.35)

    merged.sort(key=lambda r: r["name"].lower())

    with open(MAPS_JSON, "w", encoding="utf-8") as f:
        json.dump(merged, f, indent=4, ensure_ascii=False)
        f.write("\n")

    print(f"Wrote {len(merged)} maps to {MAPS_JSON.relative_to(ROOT)}", flush=True)
    if errors:
        print(f"{len(errors)} warnings/errors:", flush=True)
        for e in errors[:40]:
            print("  ", e, flush=True)
        if len(errors) > 40:
            print(f"  ... and {len(errors) - 40} more", flush=True)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
