"""Shared website database, catalog, and admin authentication helpers."""
from __future__ import annotations

import copy
import json
import os
import secrets
import sqlite3
import time
from pathlib import Path
from typing import Any

from aiohttp import web
from products import PRODUCTS as BUILTIN_PRODUCTS

BASE_DIR = Path(__file__).resolve().parent
DB_PATH = Path(os.environ.get("DATABASE_PATH", str(BASE_DIR / "shop_database.db"))).expanduser()
ADMIN_KEY = os.environ.get("ADMIN_WEB_KEY", "").strip()
TOKENS: dict[str, float] = {}


def connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


def initialize_database() -> None:
    with connect() as conn:
        conn.execute("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)")
    from storefront import ensure_tables
    ensure_tables()


def db_rows(query: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    with connect() as conn:
        return [dict(row) for row in conn.execute(query, params).fetchall()]


def db_one(query: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
    rows = db_rows(query, params)
    return rows[0] if rows else None


def db_write(query: str, params: tuple[Any, ...] = ()) -> int:
    with connect() as conn:
        cur = conn.execute(query, params)
        return cur.rowcount


def setting(key: str, default: Any = None) -> Any:
    row = db_one("SELECT value FROM settings WHERE key=?", (key,))
    return row["value"] if row else default


def save_setting(key: str, value: Any) -> None:
    db_write(
        "INSERT INTO settings(key,value) VALUES(?,?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (key, str(value)),
    )


def delete_setting(key: str) -> None:
    db_write("DELETE FROM settings WHERE key=?", (key,))


def effective_product(key: str) -> dict[str, Any]:
    product = copy.deepcopy(BUILTIN_PRODUCTS.get(key, {
        "id": "", "name": key, "category": "Digital access", "durations": [],
        "prices": {}, "usd_prices": {}, "stock": {}, "api_duration": {}, "features": [],
    }))
    product["features"] = [list(item) if isinstance(item, (tuple, list)) else [str(item), ""] for item in product.get("features", [])]
    raw = setting(f"catalog_product_{key}")
    if raw:
        try:
            custom = json.loads(raw)
            if isinstance(custom, dict):
                product.update(custom)
        except (TypeError, json.JSONDecodeError):
            pass
    product.setdefault("durations", [])
    for field in ("prices", "usd_prices", "stock", "api_duration"):
        if not isinstance(product.get(field), dict):
            product[field] = {}
    builtin = BUILTIN_PRODUCTS.get(key, {})
    for duration in builtin.get("durations", []):
        if duration not in product["durations"]:
            product["durations"].append(duration)
            for field in ("prices", "usd_prices", "stock", "api_duration"):
                value = builtin.get(field, {}).get(duration)
                if value is not None:
                    product[field][duration] = value
    product["key"] = key
    product["enabled"] = str(setting(f"enabled_{key}", "true")).lower() == "true"
    product["maintenance"] = str(setting(f"maintenance_{key}", "false")).lower() == "true"
    product["name"] = setting(f"product_name_{key}", product.get("name", key))
    product["demo_url"] = setting(f"product_demo_url_{key}", product.get("demo_url", ""))
    product["demo_enabled"] = str(setting(f"product_demo_enabled_{key}", str(bool(product.get("demo_enabled", False))).lower())).lower() == "true"
    for duration in product["durations"]:
        inr = setting(f"price_{key}_{duration}")
        usd = setting(f"price_usd_{key}_{duration}")
        if inr is not None:
            product["prices"][duration] = inr
        if usd is not None:
            product["usd_prices"][duration] = usd
    return product


def require_auth(request: web.Request) -> None:
    token = request.headers.get("X-Admin-Token", "")
    expires = TOKENS.get(token, 0)
    if expires < time.time():
        TOKENS.pop(token, None)
        raise web.HTTPUnauthorized(text="Admin sign-in required")


async def login(request: web.Request) -> web.Response:
    try:
        payload = await request.json()
    except Exception as exc:
        raise web.HTTPBadRequest(text="JSON body required") from exc
    if not isinstance(payload, dict):
        raise web.HTTPBadRequest(text="JSON object required")
    if not ADMIN_KEY:
        raise web.HTTPServiceUnavailable(text="Admin access is not configured. Set ADMIN_WEB_KEY in Railway.")
    if len(ADMIN_KEY) < 24:
        raise web.HTTPServiceUnavailable(text="ADMIN_WEB_KEY must be at least 24 characters.")
    supplied = str(payload.get("key", ""))
    if not secrets.compare_digest(supplied, ADMIN_KEY):
        raise web.HTTPUnauthorized(text="Invalid admin key")
    now = time.time()
    TOKENS.update({token: expiry for token, expiry in TOKENS.items() if expiry > now})
    token = secrets.token_urlsafe(32)
    TOKENS[token] = now + 12 * 60 * 60
    return web.json_response({"token": token, "expires_in": 43200})
