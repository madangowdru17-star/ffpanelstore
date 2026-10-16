"""Product catalog and built-in pricing for the standalone shop website."""

import json
import os
import sqlite3
from pathlib import Path

from catalog_icons import (
    PE,
    ICON_BALA_DURATION,
    ICON_DRIPCLIENT,
    ICON_BALA_MODS_PRO,
    E_BALA_FACEBOOK,
    E_BALA_FEATURE,
    E_BALA_GOOGLE,
    E_BALA_ROOT_NONROOT,
    E_NON_ROOT,
    E_SELECT_DURATION,
    E_PRIME_HOOK_TITLE,
    E_TROLL_MODZ_TITLE,
    E_TROLL_PHONE,
)

PRODUCTS = {
    "bala_mods_pro": {
        "id": "136",
        "name": "BALA MODS V6 NONROOT",
        "category": "Android",
        "durations": ["1 Hour", "3 Hours", "6 Hours", "12 Hours", "1 Day"],
        "prices": {"1 Hour": "₹19", "3 Hours": "₹39", "6 Hours": "₹59", "12 Hours": "₹89", "1 Day": "₹149"},
        "stock": {"1 Hour": 999999, "3 Hours": 999999, "6 Hours": 999999, "12 Hours": 999999, "1 Day": 999999},
        "icon": ICON_BALA_MODS_PRO,
        "duration_icon": ICON_BALA_DURATION,
        "api_duration": {"1 Hour": "1 Hours", "3 Hours": "3 Hours", "6 Hours": "6 Hours", "12 Hours": "12 Hours", "1 Day": "1 Days"},
        "features": [
            ("ROOT + NON ROOT", E_BALA_ROOT_NONROOT),
            ("Headshot 100%", E_BALA_FEATURE),
            ("Headshot 50%", E_BALA_FEATURE),
            ("Speed & Teleport Map", E_BALA_FEATURE),
            ("Enemy Full Location", E_BALA_FEATURE),
            ("Hide Logo", E_BALA_FEATURE),
            ("Direct Install & Play", E_BALA_FEATURE),
            ("Facebook", E_BALA_FACEBOOK),
            ("Google Login", E_BALA_GOOGLE),
        ],
    },
    "drip_wire_externel": {
        "id": "150",
        "name": "DRIP WIRE EXTERNEL",
        "category": "Android",
        "durations": ["6 Hours", "12 Hours", "1 Days", "7 Days"],
        "prices": {"6 Hours": "₹29", "12 Hours": "₹49", "1 Days": "₹79", "7 Days": "₹149"},
        "stock": {"6 Hours": 999999, "12 Hours": 999999, "1 Days": 999999, "7 Days": 999999},
        "icon": 6197140742972909464,
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"6 Hours": "6 Hours", "12 Hours": "12 Hours", "1 Days": "1 Days", "7 Days": "7 Days"},
        "features": [
            ("Non Root Only", E_NON_ROOT),
            ("Enemy freeze", E_BALA_FEATURE),
            ("Moco Location", E_BALA_FEATURE),
            ("Inverted Wall", E_BALA_FEATURE),
            ("Auto Freez", E_BALA_FEATURE),
            ("Firing + Scooping", E_BALA_FEATURE),
            ("Headshot add 80%", E_BALA_FEATURE),
        ],
    },
    "troll_modz": {
        "id": "166",
        "name": "TROLL MODZ NONROOT",
        "category": "Android / iOS",
        "durations": ["1 Hours", "3 Hours", "6 Hours", "12 Hours", "1 Days", "3 Days", "7 Days"],
        "prices": {"1 Hours": "₹19", "3 Hours": "₹39", "6 Hours": "₹59", "12 Hours": "₹89", "1 Days": "₹149", "3 Days": "₹249", "7 Days": "₹399"},
        "stock": {"1 Hours": 999999, "3 Hours": 999999, "6 Hours": 999999, "12 Hours": 999999, "1 Days": 999999, "3 Days": 999999, "7 Days": 999999},
        "icon": 6197401713775747081,
        "text_product_emoji": E_TROLL_MODZ_TITLE,
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 Hours": "1 Hours", "3 Hours": "3 Hours", "6 Hours": "6 Hours", "12 Hours": "12 Hours", "1 Days": "1 Days", "3 Days": "3 Days", "7 Days": "7 Days"},
        "features": [
            ("Root + Non Root", E_BALA_ROOT_NONROOT),
            ("i PHONE", E_TROLL_PHONE),
            ("AIMBOT ADD", E_BALA_FEATURE),
            ("AUTO REVIVE", E_BALA_FEATURE),
            ("TP MARK", E_BALA_FEATURE),
            ("GLOW ROTATION", E_BALA_FEATURE),
            ("FIX MOCO ESP", E_BALA_FEATURE),
            ("FIX BLACKLIST", E_BALA_FEATURE),
            ("FIX LOGIN BUG", E_BALA_FEATURE),
        ],
    },
    "dripclient_ff_root_android": {
        "id": "63",
        "name": "DRIPCLIENT FF ROOT",
        "category": "Android",
        "durations": ["1 DaYS ROOT", "7 DAYS ROOT", "30 DAYS ROOT"],
        "prices": {"1 DaYS ROOT": "₹49", "7 DAYS ROOT": "₹199", "30 DAYS ROOT": "₹499"},
        "stock": {"1 DaYS ROOT": 999999, "7 DAYS ROOT": 999999, "30 DAYS ROOT": 999999},
        "icon": 6197140742972909464,
        "text_product_emoji": PE("6197140742972909464", "⚡"),
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 DaYS ROOT": "1 DaYS ROOT", "7 DAYS ROOT": "7 DAYS ROOT", "30 DAYS ROOT": "30 DAYS ROOT"},
        "features": [
            ("ONLY ROOT", E_BALA_ROOT_NONROOT),
            ("Aimbot Pro", E_BALA_FEATURE),
            ("Random HS Rate", E_BALA_FEATURE),
            ("HS Rate 0-120", E_BALA_FEATURE),
            ("All ESP + ESP Count", E_BALA_FEATURE),
        ],
    },
    "dripclient_ff_nonroot_apkmod": {
        "id": "62",
        "name": "DRIP CLIENT APK MOD",
        "category": "Android",
        "durations": ["1 DaYS NONROOT", "3 DaYS NONROOT", "7 DaYS NONROOT", "15 DaYS NONROOT", "30 DaYS NONROOT"],
        "prices": {"1 DaYS NONROOT": "₹49", "3 DaYS NONROOT": "₹99", "7 DaYS NONROOT": "₹199", "15 DaYS NONROOT": "₹299", "30 DaYS NONROOT": "₹499"},
        "usd_prices": {"1 DaYS NONROOT": "$0.59", "3 DaYS NONROOT": "$1.19", "7 DaYS NONROOT": "$2.39", "15 DaYS NONROOT": "$3.59", "30 DaYS NONROOT": "$5.99"},
        "stock": {"1 DaYS NONROOT": 999999, "3 DaYS NONROOT": 999999, "7 DaYS NONROOT": 999999, "15 DaYS NONROOT": 999999, "30 DaYS NONROOT": 999999},
        "icon": 6197140742972909464,
        "text_product_emoji": PE("6197140742972909464", "⚡"),
        "duration_icon": ICON_BALA_DURATION,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 DaYS NONROOT": "1 DaYS NONROOT", "3 DaYS NONROOT": "3 DaYS NONROOT", "7 DaYS NONROOT": "7 DaYS NONROOT", "15 DaYS NONROOT": "15 DaYS NONROOT", "30 DaYS NONROOT": "30 DaYS NONROOT"},
        "features": [
            ("NON ROOT", E_NON_ROOT),
            ("Aimbot Rage", E_BALA_FEATURE),
            ("Headshot Rate 0–120", E_BALA_FEATURE),
            ("ESP Line/Box/Health/Distance/Name", E_BALA_FEATURE),
            ("Normal Free Fire", E_BALA_FEATURE),
            ("Crash Fixed!", E_BALA_FEATURE),
        ],
    },
    "xrag_ff_all": {
        "id": "149",
        "name": "XRAG FF ALL",
        "category": "Android",
        "durations": ["1 Hours", "3 Hours", "6 Hours", "12 Hours", "24 Hours", "3 DaYs", "7 DaYs", "30 DaYs"],
        "prices": {"1 Hours": "₹19", "3 Hours": "₹29", "6 Hours": "₹49", "12 Hours": "₹79", "24 Hours": "₹129", "3 DaYs": "₹249", "7 DaYs": "₹399", "30 DaYs": "₹799"},
        "usd_prices": {"1 Hours": "$0.25", "3 Hours": "$0.39", "6 Hours": "$0.59", "12 Hours": "$0.95", "24 Hours": "$1.55", "3 DaYs": "$2.99", "7 DaYs": "$4.79", "30 DaYs": "$9.59"},
        "stock": {"1 Hours": 999999, "3 Hours": 999999, "6 Hours": 999999, "12 Hours": 999999, "24 Hours": 999999, "3 DaYs": 999999, "7 DaYs": 999999, "30 DaYs": 999999},
        "icon": 6230902179511674350,
        "text_product_emoji": PE("6230902179511674350", "⚡"),
        "duration_icon": ICON_BALA_DURATION,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 Hours": "1 Hours", "3 Hours": "3 Hours", "6 Hours": "6 Hours", "12 Hours": "12 Hours", "24 Hours": "24 Hours", "3 DaYs": "3 DaYs", "7 DaYs": "7 DaYs", "30 DaYs": "30 DaYs"},
        "features": [
            ("Non Root Only", E_NON_ROOT),
            ("Enemy freeze", E_BALA_FEATURE),
            ("Moco Location", E_BALA_FEATURE),
            ("Inverted Wall", E_BALA_FEATURE),
            ("Auto Freez", E_BALA_FEATURE),
            ("Firing + scooping", E_BALA_FEATURE),
            ("Headshot add 80%", E_BALA_FEATURE),
        ],
    },
    "prime_hook_ff_nonroot_android": {
        "id": "48",
        "name": "PRIME HOOK FF NONROOT",
        "category": "Android",
        "durations": ["1 Days Nonroot", "3 Days Nonroot", "7 Days NonRoot", "10 Days Nonroot"],
        "prices": {"1 Days Nonroot": "₹29", "3 Days Nonroot": "₹69", "7 Days NonRoot": "₹129", "10 Days Nonroot": "₹179"},
        "stock": {"1 Days Nonroot": 999999, "3 Days Nonroot": 999999, "7 Days NonRoot": 999999, "10 Days Nonroot": 999999},
        "icon": 6197034584266252339,
        "text_product_emoji": E_PRIME_HOOK_TITLE,
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 Days Nonroot": "1 Days Nonroot", "3 Days Nonroot": "3 Days Nonroot", "7 Days NonRoot": "7 Days NonRoot", "10 Days Nonroot": "10 Days Nonroot"},
        "features": [
            ("Root & Non Root", E_BALA_ROOT_NONROOT),
            ("Aim Megnet", E_BALA_FEATURE),
            ("Silent Aim", E_BALA_FEATURE),
            ("Aimbot", E_BALA_FEATURE),
            ("Speed H@ck", E_BALA_FEATURE),
            ("Headshot", E_BALA_FEATURE),
            ("Esp all Location", E_BALA_FEATURE),
            ("CS/BR Rank Working", E_BALA_FEATURE),
        ],
    },
    "haxx_cker_pro_ff_android": {
        "id": "64",
        "name": "HAXX-CKER PRO FF ANDROID",
        "category": "Android",
        "durations": ["3 DaYs", "5 DaYs", "10 DaYs", "20 DaYs", "30 DaYs", "60 DaYs"],
        "prices": {"3 DaYs": "₹49", "5 DaYs": "₹69", "10 DaYs": "₹99", "20 DaYs": "₹149", "30 DaYs": "₹199", "60 DaYs": "₹299"},
        "stock": {"3 DaYs": 999999, "5 DaYs": 999999, "10 DaYs": 999999, "20 DaYs": 999999, "30 DaYs": 999999, "60 DaYs": 999999},
        "icon": 6199206158385816625,
        "text_product_emoji": PE("6199206158385816625", "⚡"),
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"3 DaYs": "3 DaYs", "5 DaYs": "5 DaYs", "10 DaYs": "10 DaYs", "20 DaYs": "20 DaYs", "30 DaYs": "30 DaYs", "60 DaYs": "60 DaYs"},
        "features": [
            ("Only root", E_NON_ROOT),
            ("Aimsafe Main Id", E_BALA_FEATURE),
            ("Aimbot", E_BALA_FEATURE),
            ("Headshot", E_BALA_FEATURE),
            ("Esp all Location", E_BALA_FEATURE),
            ("CS/BR Rank Working", E_BALA_FEATURE),
        ],
    },
    "hg_cheats_ff_apkmod_android": {
        "id": "65",
        "name": "HG CHEATS FF APKMOD ANDROID",
        "category": "Android",
        "durations": ["1 DaYs Apkmod Safe", "7 DaYs Apkmod Safe", "10 DaYs Apkmod Safe", "30 DaYs Apkmod Safe"],
        "prices": {"1 DaYs Apkmod Safe": "₹29", "7 DaYs Apkmod Safe": "₹79", "10 DaYs Apkmod Safe": "₹109", "30 DaYs Apkmod Safe": "₹249"},
        "stock": {"1 DaYs Apkmod Safe": 999999, "7 DaYs Apkmod Safe": 999999, "10 DaYs Apkmod Safe": 999999, "30 DaYs Apkmod Safe": 999999},
        "icon": 6199583488442639881,
        "text_product_emoji": PE("6199583488442639881", "⚡"),
        "duration_icon": 6213033298789146084,
        "text_duration_emoji": E_SELECT_DURATION,
        "api_duration": {"1 DaYs Apkmod Safe": "1 DaYs Apkmod Safe", "7 DaYs Apkmod Safe": "7 DaYs Apkmod Safe", "10 DaYs Apkmod Safe": "10 DaYs Apkmod Safe", "30 DaYs Apkmod Safe": "30 DaYs Apkmod Safe"},
        "features": [
            ("Root & Non Root", E_BALA_ROOT_NONROOT),
            ("Ativar Aimbot", E_BALA_FEATURE),
            ("Aim Assist", E_BALA_FEATURE),
            ("70% Regular FOV", E_BALA_FEATURE),
            ("Puxada", E_BALA_FEATURE),
            ("Headshot", E_BALA_FEATURE),
            ("Esp all Location", E_BALA_FEATURE),
            ("CS/BR Rank Working", E_BALA_FEATURE),
        ],
    },
}


def refresh_products_from_db(db_path=None):
    """Load admin-created/edited product definitions from SQLite."""
    path = Path(db_path or os.environ.get("DATABASE_PATH", str(Path(__file__).resolve().parent / "shop_database.db"))).expanduser()
    if not path.exists():
        return PRODUCTS
    try:
        conn = sqlite3.connect(path)
        rows = conn.execute(
            "SELECT key, value FROM settings WHERE key LIKE 'catalog_product_%'"
        ).fetchall()
        conn.close()
    except sqlite3.Error:
        return PRODUCTS
    for setting_key, raw in rows:
        key = setting_key.removeprefix("catalog_product_")
        try:
            product = json.loads(raw)
            if not isinstance(product, dict) or not key:
                continue
            builtin = PRODUCTS.get(key, {})
            durations = product.get("durations", [])
            prices = product.get("prices", {})
            if not isinstance(durations, list) or not isinstance(prices, dict):
                continue
            product["durations"] = [str(item) for item in durations]
            product["prices"] = {str(k): str(v) for k, v in prices.items()}
            product.setdefault("stock", {duration: 999999 for duration in product["durations"]})
            product.setdefault("api_duration", {duration: duration for duration in product["durations"]})
            for duration in builtin.get("durations", []):
                if duration in product["durations"]:
                    continue
                product["durations"].append(duration)
                for field in ("prices", "usd_prices", "stock", "api_duration"):
                    value = builtin.get(field, {}).get(duration)
                    if value is not None:
                        product.setdefault(field, {})[duration] = value
            product.setdefault("features", [])
            product["features"] = [tuple(item) for item in product["features"] if isinstance(item, (list, tuple)) and len(item) == 2]
            PRODUCTS[key] = product
        except (TypeError, ValueError, json.JSONDecodeError):
            continue
    return PRODUCTS
