# TODO — Website re-audit and repairs

## Current purchase and proof-handling pass

- [x] Keep existing products and product records, database tables, payment routes/payloads/success fields, CSRF rules, provider verification, and admin review contract unchanged; no new tables or migrations.
- [x] For Binance and USDT/BEP20 crypto proofs, accept the existing transaction ID and screenshot flow, show an animated green success check only after the server accepts the proof, then open Order History with the submitted status.
- [x] Apply an atomic account-wide cap of 10 submitted/reviewed crypto proof uploads per rolling 24 hours across wallet top-ups and product checkouts, without changing successful API response shapes.
- [x] Show existing wallet top-ups and product purchases together in Order History; only show the SETUP + APK action after a product order is fulfilled.
- [x] Keep the product catalog to the Buy action; make product image/title controls buttons rather than raw-link anchors and suppress native image/link callouts on long press.
- [x] Keep the © / trust / Help / Terms footer text on Home only; preserve the existing six-tab navigation.
- [x] Preserve UPI/Paytm provider confirmation as authoritative and include existing UPI payments in Order History. Do not treat a manually uploaded screenshot as payment confirmation or add an unsupported Paytm proof API.
- [x] PHP/JavaScript syntax checks, existing disposable-database payment/session regressions, proof-limit boundary checks, and 320px Chromium flows for proof redirection, receipt status, gated setup access, Home-only footer, and long-press protection passed with disposable data and mocked provider confirmation.

## Previous source-level fixes already present

- [x] Existing front controller, folders and green/ivory concept kept; duplicate homepage profile/wallet/history panel removed and homepage account control routed to existing account/sign-in flow.
- [x] Wallet remains accessible in shared mobile navigation; six-destination bottom nav layout added. Existing catalog, prices, images, durations and filters preserved; platform chips use requested image-backed category indicators.
- [x] Login/register internal next-path validation corrected.
- [x] Method-scoped idempotency, exact-positive-status settlement, read-only status GETs, explicit CSRF-protected POST verification, global crypto TXID uniqueness, and authenticated admin proof handling implemented in prior source pass.
- [x] New admin sessions use HttpOnly same-origin cookies with CSRF checks; Railway `/readyz` and persistent DB startup checks added; public example admin defaults removed.

## Live-production prerequisites (not safely verifiable in this source-only pass)

- [ ] Rotate any administrator, payment, reseller and Google OAuth secrets historically committed in public Git history, using protected hosting/provider configuration.
- [ ] Confirm Railway persistent volume, payment QR destinations/receiving accounts and the exact payment provider webhook/signature contract. No unsupported callback is to be fabricated.
- [ ] Live provider credentials, callback/OAuth success, actual payments, supplier fulfillment and Railway browser rendering remain untested. The public Railway routes returned a 429 `railway-hikari` challenge during the earlier audit.
