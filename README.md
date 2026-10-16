# HEX CHEATS SHOP — standalone website

A Python/Aiohttp storefront and website admin panel. **This repository runs a website only: it does not start, include, or require a Telegram bot or Telegram login.**

## Website features

- Customer storefront at `/` with the built-in product catalog and INR prices.
- Email/password customer registration and sign-in, protected sessions, account order history, and delivery-key display.
- FamGateway checkout and payment verification, followed by automatic digital-key delivery through the existing reseller API.
- Website administrator sign-in at `/admin` and full product management at `/admin/catalog`.
- Admin product creation, editing, deletion, enable/maintenance switches, categories, reseller product IDs, durations, INR/USD prices, stock, delivery-duration mappings, feature text, demo links, and image upload/removal.
- SQLite storage for the site catalog overrides, customers, orders, sessions, and product images.

The built-in catalog, prices, payment request format, and reseller delivery request format are retained from the supplied project. No payment or reseller API keys are included in source code.

## Deploy to Railway

1. Connect only `madangowdru17-star/ffpanelstore` to the Railway service associated with `ffpanelstore.up.railway.app`. The `Procfile` starts `web_app.py`, the standalone website entry point.
2. Configure Railway variables:
   - `ADMIN_WEB_KEY`: required; use a unique random key of at least 24 characters.
   - `FAMGATEWAY_API_KEY` (or the already-supported `FAM_API_KEY` / `FAMPAY_API_KEY`).
   - `XYZ_API_KEY` and `XYZ_MASTER_KEY` for reseller delivery.
   - `FAMGATEWAY_BASE_URL` and `XYZ_API_URL` are optional if their defaults are correct for your providers.
   - `DATABASE_PATH` should point to a persistent Railway volume, for example `/data/shop_database.db`.
3. **Keep the same `DATABASE_PATH` if the current Railway service already has a live SQLite database.** Do not switch a live service to a new, empty database without first backing up and migrating the catalog settings, customer records, and orders. If the existing service used a different path, keep that exact path.
4. Deploy, then check `/healthz`, `/`, `/admin`, and `/admin/catalog`. The health endpoint returns a simple `ok` status. Sign in at `/admin` with `ADMIN_WEB_KEY`.

Railway may discard files on the container filesystem after a redeploy; use a volume for the SQLite database so customer accounts, orders, product settings, and images persist.

## Environment template

Copy `.env.example` for local setup. Add real credentials only to Railway's protected variables or a local, untracked `.env` file. Never commit credentials or customer databases.

The prior source archive contained payment/reseller credential fallbacks. Those fallbacks have been removed; rotate any provider credentials that were previously exposed and use new values in Railway.

## Run locally

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
# Set the values in your shell or load the local .env with your preferred tool.
python web_app.py
```

The website listens on `PORT` (default `8080`). By default it stores its SQLite database as `shop_database.db` beside the source. Set `DATABASE_PATH` to a persistent file path when needed.
