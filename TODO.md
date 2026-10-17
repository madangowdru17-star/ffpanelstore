# TODO — Website re-audit and repairs

## Completed in source and local tests

- [x] Audited the existing repository, page routes, major storefront/account/admin/payment flows, deployment files, and supplied screenshots before changing the site. The existing project already has more than 20 public assets/pages; no files were added just to increase the count.
- [x] Kept the PHP front controller, existing folders, green/ivory design concept, catalog and three-line menu. Removed the redundant homepage profile/wallet/history panel; the account control goes to the existing account page/sign-in flow.
- [x] Kept mobile navigation reachable, added Wallet to the shared bottom navigation, and adjusted the responsive six-destination layout. Preserved product records, API fields/contracts, images, prices, durations and filters while removing the visible Root/Non-root/iOS badges/specification chips from cards/details.
- [x] Corrected login/register internal next-path validation and its malformed-regex warnings.
- [x] Added method-scoped idempotency keys to homepage, product-detail and Add Funds requests. Replays return the same order only for the same account/method/product/duration/amount; mismatches are rejected. UPI provider requests create an `initiating` local intent first and ambiguous provider outcomes fail closed rather than asking the customer to pay again.
- [x] Tightened payment settlement to accept only exact documented positive status strings and check the received amount before wallet credit or product fulfillment.
- [x] Made GET payment-status routes read-only and added explicit CSRF-protected POST verification routes. Product-checkout verification is available in checkout, Orders and account views. Pending UPI wallet top-ups can be reopened and verified from Add Funds, Wallet and account payment history.
- [x] Prevented the same crypto transaction ID from being claimed in wallet and product-proof flows. Crypto proof stays private to the authenticated admin; only confirmed admin approval credits a wallet or begins product fulfillment.
- [x] Moved new administrator sessions from browser localStorage bearer tokens to HttpOnly same-origin cookies with CSRF checks on mutations, and added a server-side logout route. Existing legacy admin token headers are accepted for compatibility but are not issued by the updated UI.
- [x] Added `/readyz` for database readiness while keeping `/healthz` free from schema setup/seeding. Railway startup now fails clearly when production omits an absolute writable `DATABASE_PATH` for persistent storage.
- [x] Removed valid-looking administrator credentials from `.env.example`; admin bootstrap refuses placeholder/default-like credentials and short passwords.
- [x] PHP syntax lint passed on all 29 files; all standalone JavaScript and the PHP-rendered product checkout script parsed; shell syntax and `git diff --check` passed.
- [x] Disposable-SQLite HTTP tests passed for health/readiness, customer page routes, homepage menu/profile removal, payment replay/method binding, order status GET/POST, customer CSRF, global TXID uniqueness, cookie-based admin login/CSRF/logout, admin rejection, and fail-closed behavior without live UPI credentials. Tests used synthetic users/sessions and made no real provider or supplier calls.

## Must be completed in Railway/provider configuration before claiming live production verification

- [ ] Rotate any `ADMIN_PASSWORD`, `ADMIN_WEB_KEY`, FamGateway, reseller, and Google OAuth secrets ever committed in public Git history. Replace them only through protected hosting/provider configuration; do not reuse old values. This source update does not rewrite public history or change hosted secrets.
- [ ] Confirm Railway sets `DATABASE_PATH` to an absolute writable mounted persistent volume and uses a single migration/writer deployment path.
- [ ] Confirm the uploaded Binance Pay and USDT/BEP20 QR destinations, receiving accounts, supported chain and payment instructions before collecting real transfers.
- [ ] A provider callback/webhook was **not** fabricated. No authenticated provider callback contract/signature or endpoint was present in the repository. Live reconciliation currently uses explicit customer status-verification requests against the existing provider integration. Add a webhook only after obtaining the provider's exact signing and retry specification.
- [ ] Live provider credentials, successful payment, provider amount/status payload, OAuth callback and supplier fulfillment remain untested. Verify each against Railway/provider logs after deployment; do not use production customers or real charges for local regression testing.
- [ ] The public Railway homepage and `/healthz`, `/login`, `/` returned HTTP 429 `railway-hikari` edge challenges during this task; visual/live production behavior could not be confirmed while that edge check blocks access.
