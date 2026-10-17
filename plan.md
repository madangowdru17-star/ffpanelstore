# Implementation plan — friend-reference PHP storefront

## Goal and source review

Use the supplied `ALLFILESIN2FILEADDED.zip` as a visual/interaction reference only. It contains two large PHP-named files (`index.php` and `productselect.php`) which are static HTML/JS with no PHP tags and reference CSS/JS/image assets not included in the ZIP. Their useful direction is a green, mobile-first shop: pill-shaped top navigation, carousel banner, four compact filters, three product tiles across on phones, explicit product actions, and a fixed bottom navigation. Do not copy the files wholesale, their remote checkout routes, embedded CSRF values, or missing assets.

Continue only in the existing `madangowdru17-star/ffpanelstore` repository. Keep the existing SQLite database path, FamGateway order/status/QR request contract and reseller contract unchanged. Keep product management, images, homepage content, wallet, orders, keys, top-ups and account records in that one SQLite database. Do not embed the Google OAuth client secret or any other secret in Git; Google credentials are environment variables and OAuth is disabled with a clear message until configured.

## Architecture / project structure

- `public/index.php` remains the PHP front controller.
- `src/App.php` adds explicit page/OAuth routes and additive Binance/USDT checkout/proof-review endpoints while preserving existing UPI, wallet, Binance and supplier API contracts.
- `src/GoogleOAuth.php` handles one-time state bound to a short-lived HTTP-only browser cookie, PKCE, token exchange and verified Google identity checks; `src/Auth.php` links verified Google accounts into existing customer records.
- `src/Database.php` adds in-place Google subject/browser-bound short-lived OAuth-state migrations and an additive direct-crypto-checkout proof table to the existing database.
- `public/pages/` contains 10+ focused PHP-rendered customer pages and reusable layout partials. Each uses one shared header/footer/bottom-nav and design-token stylesheet.
- `public/assets/css/` and `public/assets/js/` hold shared color themes, light/dark preference handling, the responsive account drawer/tab behavior, and page-specific payment/proof behaviour.
- Existing `public/storefront.php`, `public/storefront.js`, and admin PHP screens remain the operational home/admin surfaces; add common theme controls and links without changing FamGateway or reseller integration semantics.

## Design

- **Design movement:** modern mobile storefront inspired by the supplied Panelsell screenshot, refined as a clean green-and-ivory digital shop rather than copied literally.
- **Core principles:** one shared design system across all routes; immediately scannable image-first products; clear price and availability; mobile-first bottom navigation.
- **Color philosophy:** leaf green signals the shop/actions and availability; ivory surfaces improve readability; ink/navy dark mode reduces glare; one shared green accent persists in both themes.
- **Layout paradigm:** compact fixed pill header and bottom navigation frame a content-first page; hero carousel precedes the filter row; catalog uses three columns on mobile, with tighter card imagery and spacing on extra-narrow screens.
- **Signature elements:** glassy green header capsules; compact product cards with image, clear price, file/setup and buy states; floating bottom navigation with active indicator.
- **Interaction philosophy:** touch-sized buttons, persistent four filters (`All`, `Non-root`, `Root`, `iOS`), immediate price/status feedback, a single account drawer with accessible in-place tabs, explicit amount/network details, and no modal-only destination for profile/history pages.
- **Animation:** subtle state transitions, smooth account/payment-tab changes and carousel fade/slide only; honor reduced-motion preferences.
- **Typography:** system UI for dependable mobile rendering; heavier condensed-feeling product headings and tabular/monospaced transaction/key values.
- **Brand essence:** digital access products for mobile gamers, with clear pricing and account-based fulfillment; practical, confident, approachable.
- **Brand voice:** concise, direct, helpful. Example: “Choose your access type.” / “Your key appears in My Key after delivery.”
- **Wordmark & logo:** retain the current HX mark and shop wordmark in a compact pill; no unrelated service/logo assets are introduced.
- **Signature brand color:** leaf green, adapted from the screenshot; light and dark themes share the same accent.

## Authentication and payments

Google Sign-In must use the supplied OAuth client ID through environment configuration, but the client secret pasted into the chat must not be committed or hardcoded and should be rotated in Google Cloud. Use redirect URI `https://ffpanelstore.up.railway.app/auth/google/callback`. Verify `state`, PKCE, token audience, issuer, expiry, verified email and subject before linking/signing in. Keep existing email/password accounts, FamGateway checkout and wallet contracts, and reseller delivery safeguards. Add Binance USD and USDT/BEP20 QR/proof review using the existing database, and after admin verification only, route direct crypto product purchases through the existing supplier delivery helper.
