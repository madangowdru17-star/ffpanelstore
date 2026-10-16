"""Full product catalog CRUD for the authenticated shop admin."""
from __future__ import annotations

import json
import math
import re
import sqlite3
import time
from pathlib import Path
from typing import Any

from aiohttp import web

IMAGE_LIMIT = 5 * 1024 * 1024


def _auth(request: web.Request):
    from website_core import require_auth
    require_auth(request)


def _slug(value: Any) -> str:
    text = str(value or "").strip().lower()
    if not re.fullmatch(r"[a-z0-9_-]{2,60}", text):
        raise web.HTTPBadRequest(text="Product key must be 2–60 lowercase letters, numbers, underscores, or hyphens")
    return text


def _json_body(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise web.HTTPBadRequest(text="A JSON object is required")
    return payload


def _price(raw: Any, symbol: str, allow_empty: bool = False) -> str:
    text = str(raw or "").replace(",", "").replace("₹", "").replace("$", "").strip()
    if not text and allow_empty:
        return ""
    try:
        amount = float(text)
    except (TypeError, ValueError) as exc:
        raise web.HTTPBadRequest(text="Every duration needs a valid numeric price") from exc
    if not math.isfinite(amount) or amount <= 0 or amount > 1_000_000:
        raise web.HTTPBadRequest(text="Prices must be greater than zero and no more than 1,000,000")
    return f"{symbol}{amount:.2f}"


def _duration_list(raw: Any) -> list[str]:
    if not isinstance(raw, list):
        raise web.HTTPBadRequest(text="Durations must be a list")
    durations = [str(x).strip() for x in raw]
    if not durations or len(durations) > 30 or any(not d or len(d) > 100 for d in durations) or len(set(durations)) != len(durations):
        raise web.HTTPBadRequest(text="Enter 1–30 unique, non-empty durations of at most 100 characters")
    return durations


def _mapping(raw: Any, durations: list[str], existing: dict[str, Any] | None = None, *, prices: bool = False, usd: bool = False, stock: bool = False) -> dict[str, Any]:
    if raw is None:
        raw = {}
    if not isinstance(raw, dict):
        raise web.HTTPBadRequest(text="Duration mappings must be JSON objects")
    result: dict[str, Any] = {}
    for duration in durations:
        value = raw.get(duration, (existing or {}).get(duration, ""))
        if prices:
            result[duration] = _price(value, "₹")
        elif usd:
            formatted = _price(value, "$", allow_empty=True)
            if formatted:
                result[duration] = formatted
        elif stock:
            if value in (None, ""):
                result[duration] = 999999
            else:
                try:
                    n = int(str(value).replace(",", "").strip())
                except ValueError as exc:
                    raise web.HTTPBadRequest(text="Stock must be a non-negative whole number") from exc
                if n < 0 or n > 2_147_483_647:
                    raise web.HTTPBadRequest(text="Stock must be a non-negative whole number")
                result[duration] = n
        else:
            result[duration] = str(value or duration).strip()[:100] or duration
    return result


def _features(raw: Any, existing: list[Any] | None = None) -> list[list[str]]:
    old = list(existing or [])
    if raw is None:
        return old
    if not isinstance(raw, list):
        raise web.HTTPBadRequest(text="Features must be a list of text labels")
    labels = [str(x).strip() for x in raw]
    if len(labels) > 40 or any(not text or len(text) > 160 for text in labels):
        raise web.HTTPBadRequest(text="Features must be non-empty labels no longer than 160 characters")
    if old and len(labels) != len(old):
        raise web.HTTPBadRequest(text="Feature text must match the existing number of features")
    if not old:
        return [[label, ""] for label in labels]
    return [[label, str(old[i][1]) if isinstance(old[i], (list, tuple)) and len(old[i]) > 1 else ""] for i, label in enumerate(labels)]


def _save_product(key: str, incoming: dict[str, Any], product: dict[str, Any], *, creating: bool = False) -> dict[str, Any]:
    from website_core import BUILTIN_PRODUCTS, db_rows, effective_product, save_setting

    allowed = {"id", "name", "category", "durations", "prices", "usd_prices", "stock", "api_duration", "feature_names", "enabled", "maintenance", "demo_enabled", "demo_url", "icon", "duration_icon"}
    if set(incoming) - allowed:
        raise web.HTTPBadRequest(text="Unsupported product fields")
    old_durations = list(product.get("durations") or [])
    durations = _duration_list(incoming["durations"]) if "durations" in incoming else old_durations
    if not durations:
        raise web.HTTPBadRequest(text="This product has no durations; add at least one")
    if "id" in incoming:
        product_id = str(incoming["id"]).strip()
        if not product_id or len(product_id) > 80 or not re.fullmatch(r"[A-Za-z0-9_-]+", product_id):
            raise web.HTTPBadRequest(text="Reseller product ID must use letters, numbers, hyphens, or underscores")
        product["id"] = product_id
    if "name" in incoming:
        name = str(incoming["name"]).strip()
        if not name or len(name) > 120:
            raise web.HTTPBadRequest(text="Product name must be 1–120 characters")
        product["name"] = name
    if "category" in incoming:
        category = str(incoming["category"]).strip()
        if len(category) > 100:
            raise web.HTTPBadRequest(text="Category must be at most 100 characters")
        product["category"] = category
    product["durations"] = durations
    if "prices" in incoming or creating:
        product["prices"] = _mapping(incoming.get("prices"), durations, product.get("prices"), prices=True)
    if "usd_prices" in incoming or creating:
        product["usd_prices"] = _mapping(incoming.get("usd_prices"), durations, product.get("usd_prices"), usd=True)
    if "stock" in incoming or creating:
        product["stock"] = _mapping(incoming.get("stock"), durations, product.get("stock"), stock=True)
    if "api_duration" in incoming or creating:
        product["api_duration"] = _mapping(incoming.get("api_duration"), durations, product.get("api_duration"))
    if "feature_names" in incoming:
        product["features"] = _features(incoming["feature_names"], product.get("features"))
    for field in ("enabled", "maintenance", "demo_enabled"):
        if field in incoming:
            if not isinstance(incoming[field], bool):
                raise web.HTTPBadRequest(text=f"{field} must be true or false")
            product[field] = incoming[field]
        elif creating:
            product[field] = field == "enabled"
    if "demo_url" in incoming:
        url = str(incoming["demo_url"] or "").strip()
        if len(url) > 2048 or (url and not re.match(r"^https://", url, re.I)):
            raise web.HTTPBadRequest(text="Demo URL must be an HTTPS URL no longer than 2048 characters")
        product["demo_url"] = url
    for field in ("icon", "duration_icon"):
        if field in incoming:
            value = str(incoming[field] or "").strip()
            if value and (not value.isdigit() or len(value) > 30):
                raise web.HTTPBadRequest(text=f"{field} must be a numeric custom icon ID")
            product[field] = value
    product["key"] = key
    save_setting(f"catalog_product_{key}", json.dumps(product, ensure_ascii=False))
    save_setting(f"product_name_{key}", product["name"])
    for field, prefix in (("enabled", "enabled"), ("maintenance", "maintenance"), ("demo_enabled", "product_demo_enabled")):
        save_setting(f"{prefix}_{key}", str(bool(product.get(field))).lower())
    save_setting(f"product_demo_url_{key}", product.get("demo_url", ""))
    for duration in durations:
        if duration in product.get("prices", {}):
            save_setting(f"price_{key}_{duration}", product["prices"][duration])
        save_setting(f"price_usd_{key}_{duration}", product.get("usd_prices", {}).get(duration, ""))
    from products import refresh_products_from_db
    refresh_products_from_db()
    return effective_product(key)


async def product_list(request: web.Request) -> web.Response:
    _auth(request)
    from website_core import BUILTIN_PRODUCTS, db_rows, effective_product
    from storefront import ensure_tables
    ensure_tables()
    keys = set(BUILTIN_PRODUCTS)
    for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'"):
        keys.add(str(row["key"]).removeprefix("catalog_product_"))
    with sqlite3.connect(__import__("storefront").DB_PATH) as conn:
        images = {row[0] for row in conn.execute("SELECT product_key FROM store_product_images").fetchall()}
    products = []
    for key in sorted(keys):
        product = effective_product(key)
        product["is_builtin"] = key in BUILTIN_PRODUCTS
        product["image_url"] = f"/api/store/images/{key}" if key in images else ""
        products.append(product)
    return web.json_response({"products": products})


async def product_create(request: web.Request) -> web.Response:
    _auth(request)
    from website_core import BUILTIN_PRODUCTS, db_rows
    try:
        payload = _json_body(await request.json())
    except web.HTTPException:
        raise
    except Exception as exc:
        raise web.HTTPBadRequest(text="JSON body required") from exc
    key = _slug(payload.get("key"))
    incoming = _json_body(payload.get("product", payload))
    custom = {str(row["key"]).removeprefix("catalog_product_") for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'")}
    if key in BUILTIN_PRODUCTS or key in custom:
        raise web.HTTPConflict(text="That product key already exists")
    name = str(incoming.get("name", "")).strip()
    product_id = str(incoming.get("id", "")).strip()
    durations = _duration_list(incoming.get("durations"))
    price_map = _mapping(incoming.get("prices"), durations, prices=True)
    product = {
        "id": product_id,
        "name": name,
        "category": str(incoming.get("category", "")).strip(),
        "durations": durations,
        "prices": price_map,
        "usd_prices": {},
        "stock": {},
        "api_duration": {},
        "features": [],
        "icon": "",
        "duration_icon": "",
        "demo_url": "",
    }
    updated = _save_product(key, incoming, product, creating=True)
    return web.json_response({"ok": True, "product": updated}, status=201)


async def product_update(request: web.Request) -> web.Response:
    _auth(request)
    key = _slug(request.match_info["key"])
    from website_core import BUILTIN_PRODUCTS, db_rows, effective_product
    custom = {str(row["key"]).removeprefix("catalog_product_") for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'")}
    if key not in BUILTIN_PRODUCTS and key not in custom:
        raise web.HTTPNotFound(text="Product not found")
    try:
        payload = _json_body(await request.json())
    except web.HTTPException:
        raise
    except Exception as exc:
        raise web.HTTPBadRequest(text="JSON body required") from exc
    incoming = _json_body(payload.get("product", payload))
    updated = _save_product(key, incoming, effective_product(key))
    return web.json_response({"ok": True, "product": updated})


async def product_delete(request: web.Request) -> web.Response:
    _auth(request)
    key = _slug(request.match_info["key"])
    from website_core import BUILTIN_PRODUCTS, db_rows, delete_setting, effective_product, save_setting
    custom = {str(row["key"]).removeprefix("catalog_product_") for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'")}
    if key not in BUILTIN_PRODUCTS and key not in custom:
        raise web.HTTPNotFound(text="Product not found")
    if key in BUILTIN_PRODUCTS:
        _save_product(key, {"enabled": False, "maintenance": False}, effective_product(key))
        return web.json_response({"ok": True, "disabled": True})
    product = effective_product(key)
    keys = {
        f"catalog_product_{key}", f"enabled_{key}", f"maintenance_{key}", f"product_name_{key}",
        f"product_emoji_{key}", f"product_duration_emoji_{key}", f"product_demo_url_{key}", f"product_demo_enabled_{key}",
    }
    for duration in product.get("durations", []):
        keys.add(f"price_{key}_{duration}")
        keys.add(f"price_usd_{key}_{duration}")
    for setting_key in keys:
        delete_setting(setting_key)
    from storefront import connect
    with connect() as conn:
        conn.execute("DELETE FROM store_product_images WHERE product_key=?", (key,))
    from products import PRODUCTS, refresh_products_from_db
    PRODUCTS.pop(key, None)
    refresh_products_from_db()
    return web.json_response({"ok": True, "deleted": True})


async def product_image_upload(request: web.Request) -> web.Response:
    _auth(request)
    key = _slug(request.match_info["key"])
    from website_core import BUILTIN_PRODUCTS, db_rows
    custom = {str(row["key"]).removeprefix("catalog_product_") for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'")}
    if key not in BUILTIN_PRODUCTS and key not in custom:
        raise web.HTTPNotFound(text="Product not found")
    reader = await request.multipart()
    file_data = None
    filename = "product-image"
    while True:
        part = await reader.next()
        if part is None:
            break
        if part.name == "image" and part.filename:
            filename = Path(part.filename).name[:180]
            file_data = await part.read(decode=False)
            break
    if not file_data:
        raise web.HTTPBadRequest(text="Choose an image file")
    if len(file_data) > IMAGE_LIMIT:
        raise web.HTTPRequestEntityTooLarge(max_size=IMAGE_LIMIT, actual_size=len(file_data))
    try:
        from io import BytesIO
        import warnings
        from PIL import Image, UnidentifiedImageError
        content_types = {"PNG": "image/png", "JPEG": "image/jpeg", "WEBP": "image/webp"}
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(BytesIO(file_data)) as image:
                image_format = image.format
                width, height = image.size
                if image_format not in content_types or width < 1 or height < 1 or width * height > 16_000_000:
                    raise web.HTTPBadRequest(text="Use a valid PNG, JPEG, or WebP image up to 16 megapixels")
                image.verify()
            with Image.open(BytesIO(file_data)) as image:
                image.load()
        content_type = content_types[image_format]
    except web.HTTPException:
        raise
    except (UnidentifiedImageError, Image.DecompressionBombError, Image.DecompressionBombWarning, OSError, ValueError):
        raise web.HTTPBadRequest(text="The uploaded file is not a valid PNG, JPEG, or WebP image")
    from storefront import connect
    with connect() as conn:
        conn.execute(
            "INSERT INTO store_product_images(product_key,content_type,filename,data,updated_at) VALUES(?,?,?,?,?) "
            "ON CONFLICT(product_key) DO UPDATE SET content_type=excluded.content_type,filename=excluded.filename,data=excluded.data,updated_at=excluded.updated_at",
            (key, content_type, filename, sqlite3.Binary(file_data), int(time.time())),
        )
    return web.json_response({"ok": True, "key": key, "image_url": f"/api/store/images/{key}"})


async def product_image_delete(request: web.Request) -> web.Response:
    _auth(request)
    key = _slug(request.match_info["key"])
    from storefront import connect
    with connect() as conn:
        cur = conn.execute("DELETE FROM store_product_images WHERE product_key=?", (key,))
    if not cur.rowcount:
        raise web.HTTPNotFound(text="Product image not found")
    return web.json_response({"ok": True})
