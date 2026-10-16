# HEX CHEATS SHOP — PHP website

Standalone PHP 8.3 website. Customer and admin pages are PHP-served views under `public/`; no standalone `.html` pages are used. This project runs as a website only: it does not start or require a Telegram bot or Telegram login.

## Included

- Responsive dark storefront with three editable homepage slides, announcement text, search, and exactly four product filters: **All, Non-root, Root, iOS**. The catalog shows three cards per row at tablet widths and two on narrow phones.
- Product detail views with platform, root/non-root type, description, duration-specific prices, demo-video URL, download URL, image, featured order, stock and displayed sales count.
- Email/password customer accounts, editable profile, payment history, key history and order status.
- INR wallet, UPI gateway top-ups, wallet purchases and a transaction ledger.
- **Binance Pay USD wallet top-ups** matching the legacy bot's manual-review process: customers pay the exact USD amount, upload a transaction ID and screenshot, and an admin independently verifies the transfer before approval credits the INR quote. No automatic Binance verification is claimed.
- Existing FamGateway payment order/status/QR request contract and reseller purchase contract, using the existing environment-variable names.
- Product catalog, product/banner/QR images, homepage copy, accounts, sessions, checkouts/orders, payment proofs, wallet ledger, fulfillment attempts and admin records stored in the same SQLite database. The shipped catalog seeds an empty database once; later admin edits live in SQLite.
- Admin login at `/admin`; catalog controls at `/admin/catalog`; homepage, banner and Binance QR controls at `/admin/homepage`; customer, wallet, order and Binance proof-review controls at `/admin/customers`.
- Admin can adjust a customer's INR wallet with a required ledger reason; disable/enable accounts; review supplier attempts; safely retry only after checking the supplier account; or save a key already issued by the supplier against the existing order.

## Binance Pay setup and review

The legacy archive contains the Binance Pay manual-proof code but did **not** include its `binance.png` QR file. Upload the current Binance Pay QR at **Admin → Homepage → Binance Pay** and save customer instructions before enabling top-ups. The QR and each submitted proof image are stored as BLOBs in the shared SQLite database.

The USD-to-INR quote is captured when a request is created. The rate uses the bot's INR-to-USD source (`api.frankfurter.app`), cached for five minutes, with the bot's `0.0119` INR-to-USD fallback if the service is unavailable. Review requests at **Admin → Customers & orders → Binance payments**. A screenshot alone does not prove funds arrived: check the transaction ID in the receiving Binance account before using **Verify & credit**. Approval and wallet-ledger insertion are atomic; a top-up cannot be credited twice.

## Deploy only to the existing Railway service

1. Keep the service connected only to `madangowdru17-star/ffpanelstore` and its `main` branch. Railway builds the PHP 8.3 + Apache container from the repository `Dockerfile` and binds Apache to Railway's runtime `PORT`.
2. The Docker build and container entrypoint remove competing Apache MPM module links and enable only `mpm_prefork`. Startup asserts exactly one MPM is loaded before Apache starts; this addresses the reported `AH00534: More than one MPM loaded` error.
3. Preserve the existing payment/reseller API variables. Do not paste API keys into GitHub or this README.
4. Set protected Railway variables `ADMIN_EMAIL` and `ADMIN_PASSWORD` to the administrator email/password you want. On the first request with those variables set, the app creates the admin account only if the admin-user table is empty; the password is stored as a hash in SQLite, not in source. The account can later change its password at `/admin/homepage`. `ADMIN_WEB_KEY` is optional transition-only compatibility.
5. Keep the existing `DATABASE_PATH` and persistent Railway volume exactly unchanged (for example, `/data/shop_database.db`). The app applies in-place schema additions and creates new product, banner, payment asset, proof, admin and attempt tables without replacing the database file.
6. After deployment, check `/healthz`, `/`, `/admin`, `/admin/catalog`, `/admin/homepage`, and `/admin/customers`.

## Fulfillment safety note

The website persists a fulfillment attempt before it contacts the supplier and reuses one stable order identifier in an `Idempotency-Key` header. The provider's support for that header is not verified, so it is not treated as guaranteed duplicate protection. If a supplier call times out or returns no confirmed key, the paid order becomes `delivery_unknown`; the website does not automatically issue a second supplier request. Check the supplier account/order history first. If the key already exists there, paste it into that same order in the admin panel. Only use the explicit retry action after checking the supplier record.

## Configuration and local run

See `.env.example` for safe placeholders. Add real values only in protected Railway variables or a local untracked `.env`. Never commit production credentials, database files, customer data or generated backups. If any live provider credentials were previously exposed in a public source, rotate them and update Railway's protected variables.

The INR/USD selector only changes how configured product prices are displayed. UPI product checkout and all wallet balances settle in INR. Binance Pay top-ups are requested in USD and, after manual verification, credited to the wallet in INR using the locked quote.

Requires PHP 8.3 with `pdo_sqlite`, `curl`, `fileinfo` and `mbstring` extensions.

```bash
cp .env.example .env
# Set local values in your shell or load .env with your preferred tool.
PORT=8080 php -S 0.0.0.0:8080 -t public public/index.php
```

The default local database is `shop_database.sqlite` in the project root. Use the included Dockerfile and a persistent Railway volume in production.
