# HEX CHEATS SHOP — PHP website

Standalone PHP 8.3 website. All customer and admin views are PHP files under `public/` (there are no standalone `.html` view files); JavaScript is kept in separate assets. The service does not start or require a Telegram bot, Telegram login, or Telegram custom emojis.

## Included features

- Customer storefront, email/password accounts, editable display name, account profile, order history, and delivery-key display.
- INR wallet balance, payment-gateway wallet top-ups (₹50–₹50,000), purchase using wallet funds, and a transaction ledger. Existing accounts are retained during the balance-column migration and start at ₹0.
- INR/USD storefront display switch. USD prices are displayed only where a USD price is configured in the catalog; **the existing FamGateway payment API and wallet settle in INR**. Top-ups, wallet charges, and online checkout show the final INR amount before payment.
- Product catalog with the existing product IDs, INR/USD price fields, durations, stock defaults, and reseller-duration mappings.
- FamGateway checkout, payment-status verification, and QR retrieval using the existing request endpoints, payloads, and protected environment-variable names.
- Automatic digital-key delivery through the existing reseller API contract.
- Admin sign-in at `/admin`, product/image management at `/admin/catalog`, and customer/order/balance view at `/admin/customers`.
- SQLite persistence for catalog overrides, customer records, sessions, wallet top-ups/ledger, checkouts/orders, admin sessions, and product images.

No actual payment or reseller keys are stored in this repository. The PHP application reads the existing environment-variable names at runtime, including `FAMGATEWAY_API_KEY` (or `FAM_API_KEY` / `FAMPAY_API_KEY`), `XYZ_API_KEY`, and `XYZ_MASTER_KEY`.

## Deploy to the existing Railway service

1. Keep the service connected to **only** `madangowdru17-star/ffpanelstore` and its `main` branch. Railway builds the PHP 8.3 + Apache container from the repository `Dockerfile` and binds Apache to Railway's runtime `PORT`.
2. The Docker build explicitly disables the competing Apache MPM modules and enables only `mpm_prefork`, then fails the build if more than one MPM remains enabled. This addresses the reported `More than one MPM loaded` startup loop.
3. Preserve the existing Railway variables. Payment and reseller values stay in protected Railway settings and are never committed to GitHub.
4. Set `ADMIN_WEB_KEY` to a unique random value of at least 24 characters if it is not already configured.
5. Keep the service's existing `DATABASE_PATH` **exactly unchanged** if it points at a live SQLite database. The application adds the wallet balance column with a zero default and creates new wallet tables; it does not replace the existing customer/order database.
6. Keep the Railway persistent volume mounted at the directory used by `DATABASE_PATH` (for example `/data`). Do not remove or replace the volume.
7. After deployment, check `/healthz`, `/`, `/admin`, and `/admin/catalog`.

## Configuration and security

See `.env.example` for safe placeholders. Add actual credentials only in Railway's protected variable settings or a local untracked `.env` file. Never commit payment/reseller credentials, customer databases, or uploaded images. If provider secrets were ever present in an earlier public source archive, rotate those credentials and update Railway's protected variables.

The public FamGateway integration contract used here accepts INR amounts; the USD selector is a catalog display preference, not USD payment processing. To show USD prices for more products, set their USD prices in `/admin/catalog`.

## Local run

Requires PHP 8.3 with `pdo_sqlite`, `curl`, `fileinfo`, and `mbstring` extensions.

```bash
cp .env.example .env
# Set local values in your shell or load .env with your preferred tool.
PORT=8080 php -S 0.0.0.0:8080 -t public public/index.php
```

The default database is `shop_database.sqlite` in the project root. Set `DATABASE_PATH` to a writable persistent location when needed. Use the included Dockerfile and a persistent Railway volume in production.
