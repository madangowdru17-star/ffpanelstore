# HEX CHEATS SHOP — PHP website

A standalone PHP/HTML shop. The runtime is PHP 8.3 with Apache and SQLite; it does not start or require a Telegram bot, Telegram login, or Telegram custom emojis. The storefront and admin UI use normal HTML/CSS and plain-text product features.

## Included features

- Customer storefront at `/`, email/password registration and sign-in, protected account sessions, order history, and delivery-key display.
- Product catalog with the existing built-in product IDs, INR/USD prices, durations, stock defaults, and reseller-duration mappings.
- FamGateway checkout creation, status verification, and QR retrieval using the existing request endpoints and payload/header format.
- Automatic digital-key delivery through the existing reseller API contract.
- Admin sign-in at `/admin`, product and image management at `/admin/catalog`, and customer/order controls at `/admin/customers`.
- SQLite persistence for catalog overrides, account records, sessions, checkouts/orders, admin sessions, and product images.

No actual payment or reseller keys are stored in this repository. The PHP application reads the existing environment-variable names at runtime, including `FAMGATEWAY_API_KEY` (or the supported aliases `FAM_API_KEY` / `FAMPAY_API_KEY`), `XYZ_API_KEY`, and `XYZ_MASTER_KEY`.

## Deploy to the existing Railway service

1. Keep the service connected to **only** `madangowdru17-star/ffpanelstore` and its `main` branch. Railway will use the repository `Dockerfile` to build PHP 8.3 + Apache and will bind Apache to Railway's runtime `PORT`.
2. Preserve the existing Railway variables. The same FamGateway and reseller key names are read by the PHP code, so their protected values do not need to be put in GitHub.
3. Set `ADMIN_WEB_KEY` to a unique random value of at least 24 characters if it is not already configured.
4. Keep the service's existing `DATABASE_PATH` **exactly unchanged** if it points at a live SQLite database. The PHP app creates/uses the same shop tables and reads the existing catalog settings. Do not point a live service at a new empty database unless you have backed up and migrated the old one.
5. Ensure the Railway persistent volume is mounted at the directory used by `DATABASE_PATH` (for example `/data`). Do not remove or replace the volume.
6. Check `/healthz`, `/`, `/admin`, and `/admin/catalog` after deployment. `GET /healthz` returns `{"ok":true}` without authentication.

## Environment variables

See `.env.example` for the names and safe placeholders. Add real credentials only in Railway's protected variable settings or a local untracked `.env` file. **Never commit payment/reseller credentials or customer databases.** If provider secrets were ever present in an earlier public source archive, rotate those credentials and update Railway's protected variables.

## Local run

Requires PHP 8.3 with `pdo_sqlite`, `curl`, `fileinfo`, and `mbstring` extensions.

```bash
cp .env.example .env
# Set local values in your shell or load .env with your preferred tool.
PORT=8080 php -S 0.0.0.0:8080 -t public public/index.php
```

The default database is `shop_database.sqlite` in the project root. Set `DATABASE_PATH` to a writable persistent location when needed. For production use the included Dockerfile and a persistent Railway volume.
