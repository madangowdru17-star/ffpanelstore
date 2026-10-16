# HEX CHEATS SHOP — PHP website

Standalone PHP 8.3 website hosted on Railway. Customer/admin views are PHP-served under `public/`; it is a standalone website.

## Included

- Mobile-first green-and-ivory shop theme with shared, remembered light/dark mode, carousel banners, search, exactly four product filters (**All, Non-root, Root, iOS**), and three product cards per row at phone widths, with compact imagery and spacing on narrow screens.
- PHP customer pages for login, registration, account/profile, wallet, add-funds, orders, key history, product detail, help and terms, plus shared layout partials. The storefront remains the home page; its product modal includes duration, price, demo, download/setup and buy actions. Standalone product detail links preserve the selected product through email sign-in.
- Email/password and Google OAuth customer sign-in. Google uses one-time state tied to a short-lived HTTP-only browser cookie, PKCE, verified identity checks and the existing SQLite account table; no Google secret is stored in source.
- INR wallet, UPI/FamGateway gateway top-ups, wallet purchases and transaction history. Binance Pay USD wallet top-ups use the existing manual proof review: customers provide a transaction ID and screenshot, and an admin verifies in Binance before the quoted INR amount is credited.
- Existing FamGateway payment order/status/QR request contract and reseller purchase contract; supplier uncertainty safeguards remain unchanged.
- Existing admin login and controls: catalog/product images at `/admin/catalog`, homepage/banners/Binance QR at `/admin/homepage`, customer/order/Binance proof review at `/admin/customers`.
- Account, catalog, product/banner/QR images, homepage copy, sessions, checkouts/orders, payment proofs, wallet ledger, fulfillment attempts and admin records remain in the same SQLite database.

## Google OAuth setup

In Railway, set protected variables `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`. The registered redirect URI must be `https://ffpanelstore.up.railway.app/auth/google/callback`; optionally set `GOOGLE_REDIRECT_URI` to exactly that same value. The callback route verifies state and PKCE and checks the ID token audience, issuer, expiry, verified email and subject before linking/signing in. If the variables are missing, sign-in returns to the login page with a configuration message. Rotate the client secret pasted into the earlier conversation, then store only the replacement in Railway's protected variables; never add it to Git or `.env.example`.

## Binance Pay setup and review

Upload the current Binance Pay QR at **Admin → Homepage → Binance Pay** and save customer instructions before enabling top-ups. The QR and each submitted proof image are stored as BLOBs in the shared SQLite database. USD-to-INR is quoted at request creation. Verify the transaction ID in the receiving Binance account before using **Verify & credit**; a screenshot alone is not proof. Approval and wallet-ledger insertion are atomic, so a request cannot be credited twice.

## Deploy only to the existing Railway service

1. Keep the service connected only to `madangowdru17-star/ffpanelstore` and its `main` branch. Railway builds the PHP 8.3 + Apache container from the repository `Dockerfile` and binds Apache to Railway's runtime `PORT`.
2. The Docker build and entrypoint enable only Apache `mpm_prefork`; startup asserts exactly one MPM is loaded, addressing the reported `AH00534: More than one MPM loaded` error.
3. Preserve the existing payment/reseller API variables. Do not paste API keys into GitHub, `.env.example`, or this README.
4. Set protected Railway variables `ADMIN_EMAIL` and `ADMIN_PASSWORD`. On first request, the app creates the admin account only if the user table is empty; the password is stored as a hash. `ADMIN_WEB_KEY` is optional transition-only compatibility.
5. Keep the existing `DATABASE_PATH` and persistent Railway volume unchanged (for example `/data/shop_database.db`). The app applies in-place schema additions without replacing the database file.
6. After deployment, check `/healthz`, `/`, `/login`, `/register`, `/wallet`, `/orders`, `/keys`, `/admin`, `/admin/catalog`, `/admin/homepage`, and `/admin/customers`.

## Fulfillment safety note

The website persists a fulfillment attempt before contacting the supplier and reuses one stable order identifier in an `Idempotency-Key` header. The provider's support for that header is not verified, so it is not treated as guaranteed duplicate protection. If a supplier call times out or returns no confirmed key, the paid order becomes `delivery_unknown`; the website does not automatically issue a second supplier request. Check the supplier account/order history first. If the key already exists there, paste it into the same order in the admin panel. Only use the explicit retry action after checking the supplier record.

## Configuration and local run

See `.env.example` for safe placeholders. Use protected Railway variables or a local untracked `.env`. Never commit production credentials, database files, customer data or generated backups. Requires PHP 8.3 with `pdo_sqlite`, `curl`, `fileinfo` and `mbstring`.

```bash
cp .env.example .env
PORT=8080 php -S 0.0.0.0:8080 -t public public/index.php
```

The default local database is `shop_database.sqlite` in the project root. In production, use the included Dockerfile and persistent Railway volume.
