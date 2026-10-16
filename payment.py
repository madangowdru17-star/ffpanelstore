"""Direct FamGateway integration based on the user's verified Working.py API."""

import io
import logging
import os
from typing import Any, Dict, Optional, Tuple

import requests

logger = logging.getLogger(__name__)

FAMGATEWAY_BASE_URL = os.environ.get("FAMGATEWAY_BASE_URL", "https://famgateway.in").rstrip("/")
FAMGATEWAY_API_KEY = (
    os.environ.get("FAMGATEWAY_API_KEY", "").strip()
    or os.environ.get("FAM_API_KEY", "").strip()
    or os.environ.get("FAMPAY_API_KEY", "").strip()
)
FAMGATEWAY_EXPIRY_MINUTES = 5


def _headers() -> Dict[str, str]:
    if not FAMGATEWAY_API_KEY:
        raise RuntimeError("Set FAMGATEWAY_API_KEY, FAM_API_KEY, or FAMPAY_API_KEY in Railway")
    return {
        "Authorization": f"Bearer {FAMGATEWAY_API_KEY}",
        "X-Api-Key": FAMGATEWAY_API_KEY,
        "Content-Type": "application/json",
    }


def _data(response: Dict[str, Any]) -> Dict[str, Any]:
    value = response.get("data")
    return value if isinstance(value, dict) else response


def create_payment_order(amount_rupees: int, order_id: str, customer_name: str = "Shop customer") -> Optional[Dict[str, Any]]:
    """Use the verified Working.py create-order request format."""
    try:
        payload = {
            "amount": float(amount_rupees),
            "order_id": order_id,
            "customer_name": customer_name[:100],
        }
        response = requests.post(
            f"{FAMGATEWAY_BASE_URL}/api/create-order",
            headers=_headers(),
            json=payload,
            timeout=20,
        )
        response.raise_for_status()
        body = response.json()
        logger.info("FamGateway order created: order_id=%s status=%s", order_id, body.get("status"))
        return body
    except Exception as exc:
        logger.error("FamGateway create order failed (%s)", type(exc).__name__)
        return None


def payment_url(response: Dict[str, Any]) -> str:
    """Return the hosted payment URL from a provider response."""
    data = _data(response)
    return str(data.get("checkout_url") or data.get("payment_url") or "")


def direct_payment_payload(response: Dict[str, Any]) -> str:
    """The provider QR image is fetched directly from its QR endpoint."""
    return ""


def payment_qr_image(response: Dict[str, Any]) -> Optional[Tuple[io.BytesIO, str]]:
    """Download the direct QR PNG from FamGateway's verified qr-image.php endpoint."""
    data = _data(response)
    order_id = str(
        data.get("order_id") or data.get("orderId") or data.get("id")
        or data.get("payment_id") or response.get("order_id")
        or response.get("orderId") or response.get("id") or ""
    ).strip()
    if not order_id:
        return None
    try:
        qr_response = requests.get(
            f"{FAMGATEWAY_BASE_URL}/api/qr-image.php",
            params={"order_id": order_id},
            timeout=20,
        )
        qr_response.raise_for_status()
        image = io.BytesIO(qr_response.content)
        image.name = f"QR_{order_id}.png"
        return image, f"{FAMGATEWAY_BASE_URL}/api/qr-image.php?order_id={order_id}"
    except Exception as exc:
        logger.error("FamGateway direct QR download failed for order %s: %s", order_id, exc)
        return None


def extract_hosted_qr(payment_page_url: str):
    """Hosted page scraping is intentionally disabled; the direct QR endpoint is used."""
    return "", None


def provider_order_id(response: Dict[str, Any], fallback: str) -> str:
    data = _data(response)
    return str(
        data.get("order_id") or data.get("orderId") or data.get("id")
        or data.get("payment_id") or response.get("order_id")
        or response.get("orderId") or response.get("id") or fallback
    )


def check_payment_status(order_id: str) -> Optional[Dict[str, Any]]:
    """Use the original working FamGateway verify-order query-auth format."""
    try:
        response = requests.get(
            f"{FAMGATEWAY_BASE_URL}/api/verify-order.php",
            params={"api_key": FAMGATEWAY_API_KEY, "order_id": order_id},
            timeout=15,
        )
        response.raise_for_status()
        return response.json()
    except Exception as exc:
        logger.warning("FamGateway status check failed for %s (%s)", order_id, type(exc).__name__)
        return None


def status_fields(response: Dict[str, Any]) -> Dict[str, Any]:
    data = _data(response)
    status = str(response.get("status") or data.get("status") or data.get("payment_status") or data.get("paymentStatus") or "").strip().lower().replace("-", "_").replace(" ", "_")
    amount = data.get("amount") or data.get("paid_amount") or data.get("amount_paid") or data.get("paidAmount")
    transaction_id = data.get("transaction_id") or data.get("txn_id") or data.get("transactionId") or data.get("transaction") or ""
    utr = data.get("utr") or data.get("utr_number") or data.get("rrn") or data.get("utrNumber") or ""
    return {"status": status, "amount": amount, "transaction_id": str(transaction_id), "utr": str(utr)}
