# HEX CHEATS SHOP — PHP website

Standalone PHP 8.3 website. Customer and admin pages are PHP-served views under `public/`; there are no standalone `.html` pages. This project runs as a website only: it does not start or require a Telegram bot or Telegram login.

## Included

- Responsive dark storefront with three editable homepage slides, announcement text, search and category/platform/access filters.
- Product details with platform, root/non-root type, description, duration-specific INR and USD display prices, demo-video URL, download URL, image, featured order, stock and displayed sales count.
- Email/password customer accounts, editable profile, order history and delivery-key display.
- INR wallet, gateway top-ups, wallet purchases and a transaction ledger. The INR/USD selector changes display only; payment settlement and wallet balances remain INR.
- Existing FamGateway payment order/status/QR request contract and reseller purchase contract, using the existing environment-variable names.
- Product catalog, product images, banner images, homepage copy, accounts, sessions, checkout/order data, wallet ledger, fulfillment attempts and admin account stored in the same SQLite database. The shipped catalog is used only to seed initial products into SQLite on an empty database; later admin edits are stored in SQLite.
- Admin login at `/admin`; catalog controls at `/admin/catalog`; homepage/banner controls at `/admin/homepage`; customer, wallet and order controls at `/admin/customers`.
- Admin can adjust a customer's INR wallet with a required reason recorded in the ledger; disable/enable accounts; review provider attempts; safely retry after checking the supplier account; or save a key already issued by the supplier against the existing order.

## Deploy only to the existing Railway service

1. Keep the service connected only to `madangowdru17-star/ffpanelstore` and its `main` branch. Railway builds the PHP 8.3 + Apache container from the repository `Dockerfile` and binds Apache to Railway's runtime `PORT`.
2. The Docker build and container entrypoint remove competing Apache MPM module links and enable only `mpm_prefork`. Startup asserts exactly one MPM is loaded before Apache starts; this addresses the reported `AH00534: More than one MPM loaded` error.
3. Preserve all existing payment/reseller API variables. Do not paste API keys into GitHub or this README.
4. Set protected Railway variables `ADMIN_EMAIL` and `ADMIN_PASSWORD` to the administrator email/password you want. On the first request with those variables set, the app creates the admin account only if the admin-user table is empty; the password is stored as a password hash in SQLite, not in source. The account can later change its password at `/admin/homepage`. `ADMIN_WEB_KEY` is optional transition-only compatibility.
5. Keep the existing `DATABASE_PATH` and persistent Railway volume exactly unchanged (for example, `/data/shop_database.db`). The app runs in-place schema additions with zero-default balances, and creates new product, banner, admin and attempt tables without replacing the database file.
6. After deployment, check `/healthz`, `/`, `/admin`, `/admin/catalog`, `/admin/homepage`, and `/admin/customers`.

## Fulfillment safety note

The website persists a fulfillment attempt before it contacts the supplier and reuses one stable order identifier in an `Idempotency-Key` header. The provider's support for that header is not verified, so it is not treated as guaranteed duplicate protection. If a supplier call times out or returns no confirmed key, the paid order becomes `delivery_unknown`; the website does not automatically issue a second supplier request. Check the supplier account/order history first. If the key already exists there, paste it into that same order in the admin panel. Only use the explicit retry action after checking the supplier record.

## Configuration and local run

See `.env.example` for safe placeholders. Add real values only in protected Railway variables or a local untracked `.env`. Never commit production credentials, database files, customer data or generated backups. If any live provider credentials were previously exposed in a public source, rotate them and update Railway's protected variables.

The existing FamGateway integration settles in INR. The USD selector only displays USD values that are configured for products in `/admin/catalog`; it is not USD payment processing.

Requires PHP 8.3 with `pdo_sqlite`, `curl`, `fileinfo` and `mbstring` extensions.

```bash
cp .env.example .env
# Set local values in your shell or load .env with your preferred tool.
PORT=8080 php -S 0.0.0.0:8080 -t public public/index.php
```

The default local database is `shop_database.sqlite` in the project root. Use the included Dockerfile and a persistent Railway volume in production.
