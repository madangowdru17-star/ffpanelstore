"""Customer-facing website endpoints for the HEX CHEATS SHOP."""
from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import os
import re
import secrets
import sqlite3
import time
from pathlib import Path
from typing import Any

from aiohttp import web

BASE_DIR = Path(__file__).resolve().parent
DB_PATH = Path(os.environ.get("DATABASE_PATH", str(BASE_DIR / "shop_database.db"))).expanduser()
SESSION_COOKIE = "hex_shop_session"
SESSION_TTL = 60 * 60 * 24 * 30
PASSWORD_ITERATIONS = 310_000
MAX_IMAGE_BYTES = 5 * 1024 * 1024


def connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


def ensure_tables() -> None:
    with connect() as conn:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS store_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                password_salt TEXT NOT NULL,
                display_name TEXT NOT NULL DEFAULT '',
                disabled INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_login_at INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS store_sessions (
                token_hash TEXT PRIMARY KEY,
                account_id INTEGER NOT NULL,
                csrf_token TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY(account_id) REFERENCES store_accounts(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_store_sessions_account ON store_sessions(account_id);
            CREATE TABLE IF NOT EXISTS store_checkouts (
                order_id TEXT PRIMARY KEY,
                account_id INTEGER NOT NULL,
                product_key TEXT NOT NULL,
                product_id TEXT NOT NULL,
                product_name TEXT NOT NULL,
                duration TEXT NOT NULL,
                api_duration TEXT NOT NULL,
                amount_inr REAL NOT NULL,
                provider_order_id TEXT NOT NULL DEFAULT '',
                payment_url TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending',
                transaction_id TEXT NOT NULL DEFAULT '',
                delivery_key TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY(account_id) REFERENCES store_accounts(id)
            );
            CREATE INDEX IF NOT EXISTS idx_store_checkouts_account ON store_checkouts(account_id, created_at DESC);
            CREATE TABLE IF NOT EXISTS store_orders (
                order_id TEXT PRIMARY KEY,
                account_id INTEGER NOT NULL,
                product_key TEXT NOT NULL,
                product_name TEXT NOT NULL,
                duration TEXT NOT NULL,
                amount REAL NOT NULL,
                delivery_key TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                transaction_id TEXT NOT NULL DEFAULT '',
                FOREIGN KEY(account_id) REFERENCES store_accounts(id)
            );
            CREATE INDEX IF NOT EXISTS idx_store_orders_account ON store_orders(account_id, created_at DESC);
            CREATE TABLE IF NOT EXISTS store_product_images (
                product_key TEXT PRIMARY KEY,
                content_type TEXT NOT NULL,
                filename TEXT NOT NULL,
                data BLOB NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS store_auth_attempts (
                identity TEXT PRIMARY KEY,
                hits INTEGER NOT NULL,
                window_start INTEGER NOT NULL
            );
            """
        )


def _json_error(message: str, status: int = 400) -> web.Response:
    return web.json_response({"error": message}, status=status)


def _client_identity(request: web.Request) -> str:
    # Railway's proxy supplies X-Forwarded-For; only the first hop is used for rate limiting.
    value = request.headers.get("X-Forwarded-For", "").split(",", 1)[0].strip()
    if not value:
        value = request.remote or "unknown"
    return hashlib.sha256(value.encode("utf-8", "ignore")).hexdigest()[:24]


def _rate_limited(request: web.Request, action: str, limit: int = 12, window: int = 900) -> bool:
    now = int(time.time())
    identity = f"{action}:{_client_identity(request)}"
    with connect() as conn:
        row = conn.execute("SELECT hits,window_start FROM store_auth_attempts WHERE identity=?", (identity,)).fetchone()
        if not row or now - int(row["window_start"]) >= window:
            conn.execute(
                "INSERT INTO store_auth_attempts(identity,hits,window_start) VALUES(?,?,?) "
                "ON CONFLICT(identity) DO UPDATE SET hits=excluded.hits,window_start=excluded.window_start",
                (identity, 1, now),
            )
            return False
        if int(row["hits"]) >= limit:
            return True
        conn.execute("UPDATE store_auth_attempts SET hits=hits+1 WHERE identity=?", (identity,))
        return False


def _token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def _account_for(request: web.Request) -> tuple[dict[str, Any] | None, str | None]:
    token = request.cookies.get(SESSION_COOKIE, "")
    if not token or len(token) > 200:
        return None, None
    with connect() as conn:
        row = conn.execute(
            "SELECT s.csrf_token,s.expires_at,a.id,a.email,a.display_name,a.disabled "
            "FROM store_sessions s JOIN store_accounts a ON a.id=s.account_id WHERE s.token_hash=?",
            (_token_hash(token),),
        ).fetchone()
        if not row or int(row["expires_at"]) < int(time.time()) or row["disabled"]:
            conn.execute("DELETE FROM store_sessions WHERE token_hash=?", (_token_hash(token),))
            return None, None
        return dict(row), token


def _require_account(request: web.Request) -> tuple[dict[str, Any] | None, str | None]:
    account, token = _account_for(request)
    return account, token


def _csrf_ok(request: web.Request, account: dict[str, Any]) -> bool:
    supplied = request.headers.get("X-CSRF-Token", "")
    expected = str(account.get("csrf_token") or "")
    return bool(supplied and expected and hmac.compare_digest(supplied, expected))


def _secure_cookie(request: web.Request) -> bool:
    return request.scheme == "https" or request.headers.get("X-Forwarded-Proto", "").lower() == "https" or bool(os.environ.get("RAILWAY_PUBLIC_DOMAIN"))


def _new_session(response: web.Response, account_id: int, request: web.Request) -> dict[str, str]:
    now = int(time.time())
    raw_token = secrets.token_urlsafe(36)
    csrf = secrets.token_urlsafe(32)
    with connect() as conn:
        conn.execute("DELETE FROM store_sessions WHERE expires_at < ?", (now,))
        conn.execute(
            "INSERT INTO store_sessions(token_hash,account_id,csrf_token,expires_at,created_at) VALUES(?,?,?,?,?)",
            (_token_hash(raw_token), account_id, csrf, now + SESSION_TTL, now),
        )
        conn.execute("UPDATE store_accounts SET last_login_at=? WHERE id=?", (now, account_id))
    response.set_cookie(
        SESSION_COOKIE,
        raw_token,
        max_age=SESSION_TTL,
        httponly=True,
        secure=_secure_cookie(request),
        samesite="Lax",
        path="/",
    )
    return {"csrf_token": csrf}


def _account_public(account: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": account.get("id"),
        "email": account.get("email") or "",
        "display_name": account.get("display_name") or account.get("email") or "Shop customer",
    }


def _email_password_ok(password: str, salt_hex: str, expected_hex: str) -> bool:
    try:
        digest = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), bytes.fromhex(salt_hex), PASSWORD_ITERATIONS).hex()
        return hmac.compare_digest(digest, expected_hex)
    except (ValueError, TypeError):
        return False


async def store_page(request: web.Request) -> web.Response:
    return web.FileResponse(BASE_DIR / "storefront.html", headers={"Cache-Control": "no-cache"})


async def store_config(request: web.Request) -> web.Response:
    return web.json_response({"shop_name": "HEX CHEATS SHOP", "currency": "INR"})


async def store_products(request: web.Request) -> web.Response:
    from website_core import BUILTIN_PRODUCTS, db_rows, effective_product

    keys = set(BUILTIN_PRODUCTS)
    for row in db_rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'"):
        keys.add(str(row["key"]).removeprefix("catalog_product_"))
    result = []
    for key in sorted(keys):
        product = effective_product(key)
        if not product.get("enabled", True) or product.get("maintenance"):
            continue
        features = product.get("features") or []
        result.append(
            {
                "key": key,
                "name": str(product.get("name") or key),
                "category": str(product.get("category") or "Digital access"),
                "durations": list(product.get("durations") or []),
                "prices": product.get("prices") or {},
                "features": [str(f[0]) if isinstance(f, (list, tuple)) and f else str(f) for f in features],
                "demo_url": product.get("demo_url") if product.get("demo_enabled") else "",
                "image_url": f"/api/store/images/{key}",
            }
        )
    return web.json_response({"products": result})


async def product_image(request: web.Request) -> web.Response:
    key = request.match_info["key"]
    with connect() as conn:
        row = conn.execute("SELECT content_type,data,updated_at FROM store_product_images WHERE product_key=?", (key,)).fetchone()
    if not row:
        raise web.HTTPNotFound(text="Product image not found")
    return web.Response(
        body=bytes(row["data"]),
        content_type=row["content_type"],
        headers={"Cache-Control": "public, max-age=900", "ETag": f'"{row["updated_at"]}"', "X-Content-Type-Options": "nosniff"},
    )


async def store_session(request: web.Request) -> web.Response:
    account, _ = _account_for(request)
    if not account:
        return web.json_response({"authenticated": False})
    return web.json_response({"authenticated": True, "account": _account_public(account), "csrf_token": account["csrf_token"]})


async def store_register(request: web.Request) -> web.Response:
    if _rate_limited(request, "register", 8):
        return _json_error("Too many attempts. Please try again in 15 minutes.", 429)
    try:
        body = await request.json()
    except Exception:
        return _json_error("A JSON request is required.")
    email = str(body.get("email", "")).strip().lower()
    password = str(body.get("password", ""))
    display_name = str(body.get("display_name", "")).strip()[:100]
    if len(email) > 254 or not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email):
        return _json_error("Enter a valid email address.")
    if len(password) < 10 or len(password) > 256:
        return _json_error("Password must be at least 10 characters.")
    salt = secrets.token_bytes(16)
    password_hash = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, PASSWORD_ITERATIONS).hex()
    now = int(time.time())
    try:
        with connect() as conn:
            cur = conn.execute(
                "INSERT INTO store_accounts(email,password_hash,password_salt,display_name,created_at) VALUES(?,?,?,?,?)",
                (email, password_hash, salt.hex(), display_name, now),
            )
            account_id = int(cur.lastrowid)
    except sqlite3.IntegrityError:
        return _json_error("An account with that email already exists.", 409)
    response = web.json_response({"ok": True, "account": {"id": account_id, "email": email, "display_name": display_name or email}})
    _new_session(response, account_id, request)
    return response


async def store_login(request: web.Request) -> web.Response:
    if _rate_limited(request, "login", 12):
        return _json_error("Too many attempts. Please try again in 15 minutes.", 429)
    try:
        body = await request.json()
    except Exception:
        return _json_error("A JSON request is required.")
    email = str(body.get("email", "")).strip().lower()
    password = str(body.get("password", ""))
    with connect() as conn:
        row = conn.execute("SELECT * FROM store_accounts WHERE email=?", (email,)).fetchone()
    if not row or not row["password_hash"] or not _email_password_ok(password, row["password_salt"], row["password_hash"]):
        return _json_error("Email or password is incorrect.", 401)
    if row["disabled"]:
        return _json_error("This account is disabled. Contact the shop administrator.", 403)
    account = dict(row)
    response = web.json_response({"ok": True, "account": _account_public(account)})
    _new_session(response, int(row["id"]), request)
    return response


async def store_logout(request: web.Request) -> web.Response:
    token = request.cookies.get(SESSION_COOKIE, "")
    if token:
        with connect() as conn:
            conn.execute("DELETE FROM store_sessions WHERE token_hash=?", (_token_hash(token),))
    response = web.json_response({"ok": True})
    response.del_cookie(SESSION_COOKIE, path="/")
    return response


def _price_value(raw: Any) -> float | None:
    text = str(raw or "").replace("₹", "").replace(",", "").strip()
    try:
        amount = float(text)
    except (TypeError, ValueError):
        return None
    if amount <= 0 or amount > 1_000_000 or amount != amount:
        return None
    return amount


async def store_checkout(request: web.Request) -> web.Response:
    account, _ = _account_for(request)
    if not account:
        return _json_error("Sign in to place an order.", 401)
    if not _csrf_ok(request, account):
        return _json_error("Session check failed. Refresh and try again.", 403)
    if not os.environ.get("FAMGATEWAY_API_KEY", "").strip() and not os.environ.get("FAM_API_KEY", "").strip() and not os.environ.get("FAMPAY_API_KEY", "").strip():
        return _json_error("Online payments are not configured yet. Please contact the shop administrator.", 503)
    try:
        body = await request.json()
    except Exception:
        return _json_error("A JSON request is required.")
    product_key = str(body.get("product_key", "")).strip()
    duration = str(body.get("duration", "")).strip()
    from website_core import effective_product
    product = effective_product(product_key)
    if not product.get("enabled", True) or product.get("maintenance"):
        return _json_error("This product is currently unavailable.", 409)
    if duration not in (product.get("durations") or []):
        return _json_error("Choose a valid product duration.")
    amount = _price_value((product.get("prices") or {}).get(duration))
    if amount is None:
        return _json_error("The selected price is not configured.", 409)
    stock = (product.get("stock") or {}).get(duration)
    try:
        if stock is not None and float(str(stock).replace(",", "")) <= 0:
            return _json_error("This option is currently out of stock.", 409)
    except (ValueError, TypeError):
        pass
    order_id = "WEB" + secrets.token_hex(8).upper()
    try:
        from payment import create_payment_order, payment_url, provider_order_id
        gateway_response = await asyncio.to_thread(create_payment_order, amount, order_id, account.get("display_name") or account.get("email") or "Shop customer")
        if not gateway_response:
            return _json_error("The payment provider could not create an order. Please try again.", 502)
        checkout_url = payment_url(gateway_response)
        provider_id = provider_order_id(gateway_response, order_id)
    except Exception:
        return _json_error("The payment provider is temporarily unavailable.", 502)
    now = int(time.time())
    api_duration = str((product.get("api_duration") or {}).get(duration) or duration)
    with connect() as conn:
        conn.execute(
            "INSERT INTO store_checkouts(order_id,account_id,product_key,product_id,product_name,duration,api_duration,amount_inr,provider_order_id,payment_url,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (order_id, int(account["id"]), product_key, str(product.get("id") or ""), str(product.get("name") or product_key), duration, api_duration, amount, provider_id, checkout_url, "pending", now, now),
        )
    return web.json_response({"ok": True, "order_id": order_id, "product_name": str(product.get("name") or product_key), "payment_url": checkout_url, "qr_url": f"/api/store/checkouts/{order_id}/qr", "amount_inr": amount, "status": "pending"})


def _successful_payment(status: str) -> bool:
    normalized = str(status or "").lower().replace("-", "_").replace(" ", "_")
    return normalized in {"success", "successful", "paid", "completed", "verified", "captured"} or any(x in normalized for x in ("paid", "success", "completed", "verified", "captured"))


def _failed_payment(status: str) -> bool:
    normalized = str(status or "").lower().replace("-", "_").replace(" ", "_")
    return normalized in {"failed", "failure", "expired", "cancelled", "canceled"}


async def _deliver_checkout(order: dict[str, Any], transaction_id: str) -> dict[str, Any]:
    order_id = str(order["order_id"])
    with connect() as conn:
        conn.execute("BEGIN IMMEDIATE")
        fresh = conn.execute("SELECT * FROM store_checkouts WHERE order_id=?", (order_id,)).fetchone()
        if not fresh:
            conn.rollback()
            return {"status": "not_found"}
        if fresh["status"] == "complete":
            conn.commit()
            return {"status": "complete", "key": fresh["delivery_key"]}
        if fresh["status"] == "delivering":
            conn.commit()
            return {"status": "delivering"}
        if fresh["status"] not in {"pending", "paid_delivery_failed"}:
            conn.commit()
            return {"status": fresh["status"]}
        conn.execute("UPDATE store_checkouts SET status='delivering',transaction_id=?,updated_at=? WHERE order_id=?", (transaction_id, int(time.time()), order_id))
        order = dict(fresh)
        conn.commit()
    try:
        from reseller_api import buy_product_api
        result = await asyncio.to_thread(buy_product_api, order["product_id"], order["api_duration"])
        status = str(result.get("status", "")).lower() if isinstance(result, dict) else ""
        key = result.get("key") if isinstance(result, dict) else None
        if status != "success" or not key:
            raise RuntimeError("The reseller did not confirm key delivery")
        key = str(key).replace("Key: ", "", 1).strip()
    except Exception:
        with connect() as conn:
            conn.execute("UPDATE store_checkouts SET status='paid_delivery_failed',updated_at=? WHERE order_id=? AND status='delivering'", (int(time.time()), order_id))
        return {"status": "paid_delivery_failed"}
    now = int(time.time())
    with connect() as conn:
        conn.execute("BEGIN IMMEDIATE")
        conn.execute("UPDATE store_checkouts SET status='complete',delivery_key=?,transaction_id=?,updated_at=? WHERE order_id=?", (key, transaction_id, now, order_id))
        conn.execute(
            "INSERT OR IGNORE INTO store_orders(order_id,account_id,product_key,product_name,duration,amount,delivery_key,status,created_at,transaction_id) VALUES(?,?,?,?,?,?,?,?,?,?)",
            (order_id, int(order["account_id"]), order["product_key"], order["product_name"], order["duration"], float(order["amount_inr"]), key, "complete", now, transaction_id),
        )
        conn.commit()
    return {"status": "complete", "key": key}


async def store_checkout_status(request: web.Request) -> web.Response:
    account, _ = _account_for(request)
    if not account:
        return _json_error("Sign in to view this order.", 401)
    order_id = request.match_info["order_id"]
    with connect() as conn:
        row = conn.execute("SELECT * FROM store_checkouts WHERE order_id=? AND account_id=?", (order_id, int(account["id"]))).fetchone()
    if not row:
        raise web.HTTPNotFound(text="Order not found")
    item = dict(row)
    if item["status"] == "pending":
        try:
            from payment import check_payment_status, status_fields
            response = None
            candidates = list(dict.fromkeys([item["provider_order_id"], item["order_id"]]))
            for candidate in candidates:
                if candidate:
                    response = await asyncio.to_thread(check_payment_status, candidate)
                    if response:
                        break
            if response:
                fields = status_fields(response)
                status = fields.get("status", "")
                if _successful_payment(status):
                    try:
                        paid = float(str(fields.get("amount")).replace(",", "").replace("₹", "").strip())
                        if paid == float(item["amount_inr"]) * 100:
                            paid /= 100
                    except (ValueError, TypeError):
                        paid = None
                    if paid is not None and abs(paid - float(item["amount_inr"])) > 0.01:
                        with connect() as conn:
                            conn.execute("UPDATE store_checkouts SET status='amount_review',updated_at=? WHERE order_id=? AND status='pending'", (int(time.time()), order_id))
                        item["status"] = "amount_review"
                    elif paid is not None:
                        outcome = await _deliver_checkout(item, str(fields.get("transaction_id") or fields.get("utr") or ""))
                        item["status"] = outcome["status"]
                        item["delivery_key"] = outcome.get("key", "")
                elif _failed_payment(status):
                    with connect() as conn:
                        conn.execute("UPDATE store_checkouts SET status='failed',updated_at=? WHERE order_id=? AND status='pending'", (int(time.time()), order_id))
                    item["status"] = "failed"
        except Exception:
            pass
    return web.json_response({"order_id": order_id, "status": item["status"], "delivery_key": item.get("delivery_key") or "", "amount_inr": item["amount_inr"], "product_name": item["product_name"], "duration": item["duration"]})


async def store_checkout_qr(request: web.Request) -> web.Response:
    account, _ = _account_for(request)
    if not account:
        raise web.HTTPUnauthorized(text="Sign in required")
    with connect() as conn:
        row = conn.execute("SELECT provider_order_id,order_id FROM store_checkouts WHERE order_id=? AND account_id=?", (request.match_info["order_id"], int(account["id"]))).fetchone()
    if not row:
        raise web.HTTPNotFound(text="Order not found")
    try:
        from payment import payment_qr_image
        image = await asyncio.to_thread(payment_qr_image, {"data": {"order_id": row["provider_order_id"] or row["order_id"]}})
        if not image:
            raise web.HTTPNotFound(text="QR code is not available")
        stream, _ = image
        return web.Response(body=stream.getvalue(), content_type="image/png", headers={"Cache-Control": "no-store", "X-Content-Type-Options": "nosniff"})
    except web.HTTPException:
        raise
    except Exception:
        raise web.HTTPNotFound(text="QR code is not available")


async def store_orders(request: web.Request) -> web.Response:
    account, _ = _account_for(request)
    if not account:
        return _json_error("Sign in to view your orders.", 401)
    with connect() as conn:
        rows = conn.execute(
            "SELECT order_id,product_name,duration,amount,status,delivery_key,created_at,transaction_id FROM store_orders WHERE account_id=? "
            "UNION ALL SELECT order_id,product_name,duration,amount_inr AS amount,status,'' AS delivery_key,created_at,transaction_id FROM store_checkouts WHERE account_id=? AND status!='complete' "
            "ORDER BY created_at DESC LIMIT 100",
            (int(account["id"]), int(account["id"])),
        ).fetchall()
    return web.json_response({"orders": [dict(row) for row in rows]})


async def admin_store_accounts(request: web.Request) -> web.Response:
    from website_core import require_auth
    require_auth(request)
    query = str(request.query.get("q", "")).strip()[:120]
    with connect() as conn:
        if query:
            rows = conn.execute("SELECT id,email,display_name,disabled,created_at,last_login_at FROM store_accounts WHERE lower(COALESCE(email,'')) LIKE ? OR lower(COALESCE(display_name,'')) LIKE ? ORDER BY created_at DESC LIMIT 300", (f"%{query.lower()}%", f"%{query.lower()}%")).fetchall()
        else:
            rows = conn.execute("SELECT id,email,display_name,disabled,created_at,last_login_at FROM store_accounts ORDER BY created_at DESC LIMIT 300").fetchall()
    return web.json_response({"accounts": [dict(row) for row in rows]})


async def admin_store_account_status(request: web.Request) -> web.Response:
    from website_core import require_auth
    require_auth(request)
    account_id = int(request.match_info["account_id"])
    try:
        body = await request.json()
    except Exception:
        return _json_error("A JSON request is required.")
    disabled = 1 if bool(body.get("disabled")) else 0
    with connect() as conn:
        cur = conn.execute("UPDATE store_accounts SET disabled=? WHERE id=?", (disabled, account_id))
        if not cur.rowcount:
            raise web.HTTPNotFound(text="Account not found")
        if disabled:
            conn.execute("DELETE FROM store_sessions WHERE account_id=?", (account_id,))
    return web.json_response({"ok": True, "disabled": bool(disabled)})


def register_routes(app: web.Application) -> None:
    ensure_tables()
    app.router.add_get("/", store_page)
    app.router.add_get("/healthz", lambda request: web.json_response({"ok": True}))
    app.router.add_get("/api/store/config", store_config)
    app.router.add_get("/api/store/products", store_products)
    app.router.add_get("/api/store/images/{key}", product_image)
    app.router.add_get("/api/store/session", store_session)
    app.router.add_post("/api/store/register", store_register)
    app.router.add_post("/api/store/login", store_login)
    app.router.add_post("/api/store/logout", store_logout)
    app.router.add_post("/api/store/checkout", store_checkout)
    app.router.add_get("/api/store/checkouts/{order_id}", store_checkout_status)
    app.router.add_get("/api/store/checkouts/{order_id}/qr", store_checkout_qr)
    app.router.add_get("/api/store/orders", store_orders)
    app.router.add_get("/admin/api/store/accounts", admin_store_accounts)
    app.router.add_post("/admin/api/store/accounts/{account_id}/status", admin_store_account_status)
