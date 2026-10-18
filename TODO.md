# TODO — Website re-audit and repairs

## Current payment UX and footer pass

- [x] Keep existing products, product data, database structure, payment endpoints, request payloads, CSRF checks, idempotency rules, provider callbacks/status handling and admin crypto review unchanged.
- [x] Make UPI, Binance Pay and USDT/BEP20 choices open their own amount-entry payment pages, with the selected method clearly shown and the Add Fund tab remaining active.
- [x] Show an animated payment-security sequence tied to a fresh signed-in session check and server-side amount/payment-request validation before displaying the QR.
- [x] Present the exact amount, enlarged local official payment mark, QR/deep link and existing status/proof workflow; preserve UPI provider confirmation and Binance/USDT administrator review, and retain submitted/reviewed proof state across page switching so duplicate proof uploads are not offered.
- [x] Keep QR content visible above the fixed mobile navigation; verify method choice, payment page, and footer layout at 320px.
- [x] Add consistent end-of-page text and Help/Terms links to the homepage and shared customer-page footer.
- [x] Validate changed PHP/JavaScript/SVG sources and run backend payment/session regressions; exercise browser payment flows with mocked provider responses only (no real payment or fulfillment).
- [ ] Commit and push the approved changes after final checks.

## Previous source-level fixes already present

- [x] Existing front controller, folders and green/ivory concept kept; duplicate homepage profile/wallet/history panel removed and homepage account control routed to existing account/sign-in flow.
- [x] Wallet remains accessible in shared mobile navigation; six-destination bottom nav layout added. Existing catalog, prices, images, durations and filters preserved; platform chips use the requested image-backed category indicators.
- [x] Login/register internal next-path validation corrected.
- [x] Method-scoped idempotency, exact-positive-status settlement, read-only status GETs, explicit CSRF-protected POST verification, global crypto TXID uniqueness, and authenticated admin proof handling implemented in prior source pass.
- [x] New admin sessions use HttpOnly same-origin cookies with CSRF checks; Railway `/readyz` and persistent DB startup checks added; public example admin defaults removed.
- [x] Previous local PHP/JS/shell syntax checks, `git diff --check`, and disposable SQLite HTTP regressions passed. This payment UX pass also reran focused checks on changed pages, scripts, styles and routes.

## Live-production prerequisites (not safely verifiable in this source-only UI pass)

- [ ] Rotate any administrator, payment, reseller and Google OAuth secrets historically committed in public Git history, using protected hosting/provider configuration.
- [ ] Confirm Railway persistent volume, payment QR destinations/receiving accounts and the exact payment provider webhook/signature contract. No unsupported callback is to be fabricated.
- [ ] Live provider credentials, callback/OAuth success, actual payments, supplier fulfillment and Railway browser rendering remain untested. The public Railway routes returned a 429 `railway-hikari` challenge during the earlier audit.
