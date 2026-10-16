"""Reseller API integration for product purchases.

Configure XYZ_API_KEY in Railway instead of committing the reseller token to source.
"""

import logging
import os
from typing import Any, Dict

import requests

XYZ_API_URL = os.environ.get(
    "XYZ_API_URL", "https://bantibhaiya.to/api/reseller_v1.php"
)
XYZ_MASTER_KEY = os.environ.get("XYZ_MASTER_KEY", "").strip()
XYZ_API_KEY = os.environ.get("XYZ_API_KEY", "").strip()


def buy_product_api(product_id: str, duration: str) -> Dict[str, Any]:
    """Buy a product using the existing reseller API contract."""
    try:
        data = {
            "api_key": XYZ_API_KEY,
            "action": "buy",
            "product_id": product_id,
            "duration": duration,
        }
        headers = {
            "Content-Type": "application/x-www-form-urlencoded",
            "x-master-key": XYZ_MASTER_KEY,
        }
        response = requests.post(
            XYZ_API_URL, data=data, headers=headers, timeout=30
        )
        result = response.json()
        logging.info("Buy API completed with status=%s", str(result.get("status", "unknown")) if isinstance(result, dict) else "unknown")
        return result
    except Exception as exc:
        logging.error("XYZ API Error: %s", exc)
        return {"status": "error", "msg": str(exc)}
