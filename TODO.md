# TODO — Website re-audit and repairs

## Current UI-only pass (active request)

- [ ] Inspect every customer-facing page/shell, navigation state, tab row, payment option, form, and relevant stylesheet/script before editing. Use the supplied account screenshot to trace the top-control overlap and cross-page tab differences.
- [ ] Keep the existing project structure, green/ivory concept, products, APIs, pages and working behaviors. Limit this pass to presentation/markup/icon assets. Do not add a duplicate account/profile area or change payment logic, product data, routes, request payloads, status handling or QR configuration.
- [ ] Remove the redundant top profile icon/control from the shared header while keeping the existing hamburger menu and existing bottom Account destination. Align and space the remaining controls consistently on all pages.
- [ ] Make shared navigation/tab presentation consistent between home and every customer page; fix horizontal overflow, clipping, overlap, spacing and active states at mobile and desktop breakpoints without breaking the account dashboard’s in-place tabs.
- [ ] Replace emoji/Unicode glyphs used as UI icons with consistent inline SVG/HTML icons. Keep accessible labels and visible text for navigation and payment actions.
- [ ] Add the actual logo artwork requested for ALL, NON-ROOT (Android), ROOT (Kitsune Mask) and IOS (Apple) category/filter indicators, with textual labels and accessible descriptions. Keep existing filters and product data intact.
- [ ] Add locally stored first-party Google Pay, Binance Pay and USDt/Tether logo assets to the relevant existing UPI/Binance/USDT payment-method choices; do not imply a new provider/API integration or change the payment flow.
- [ ] Re-test all page shells and tab states at mobile widths, including account, wallet, Add Funds, orders, keys, login/register and admin layouts; verify keyboard focus/accessible names and that the rendered logos load locally.
- [ ] Commit and push only the approved UI/assets/docs changes to the selected repository after checks pass.

## Previous source-level fixes already present

- [x] Existing front controller, folders and green/ivory concept kept; duplicate homepage profile/wallet/history panel removed and homepage account control routed to existing account/sign-in flow.
- [x] Wallet remains accessible in shared mobile navigation; six-destination bottom nav layout added. Existing catalog, prices, images, durations and filters preserved; prior visible Root/Non-root/iOS chips were removed from product cards/details and will now be restored only as requested image-backed category badges in this UI-only pass.
- [x] Login/register internal next-path validation corrected.
- [x] Method-scoped idempotency, exact-positive-status settlement, read-only status GETs, explicit CSRF-protected POST verification, global crypto TXID uniqueness, and authenticated admin proof handling implemented in prior source pass.
- [x] New admin sessions use HttpOnly same-origin cookies with CSRF checks; Railway `/readyz` and persistent DB startup checks added; public example admin defaults removed.
- [x] Previous local PHP/JS/shell syntax checks, `git diff --check`, and disposable SQLite HTTP regressions passed. The current UI-only work will rerun focused checks on changed pages/styles/assets.

## Live-production prerequisites (not safely verifiable in this source-only UI pass)

- [ ] Rotate any administrator, payment, reseller and Google OAuth secrets historically committed in public Git history, using protected hosting/provider configuration.
- [ ] Confirm Railway persistent volume, payment QR destinations/receiving accounts and the exact payment provider webhook/signature contract. No unsupported callback is to be fabricated.
- [ ] Live provider credentials, callback/OAuth success, actual payments, supplier fulfillment and Railway browser rendering remain untested. The public Railway routes returned a 429 `railway-hikari` challenge during the earlier audit.
