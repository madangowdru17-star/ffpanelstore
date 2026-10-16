# TODO — friend-reference PHP storefront

- [x] **Friend-reference storefront layout:** Adapt the supplied mobile-site direction into a modern green-and-ivory design with a shared persisted light/dark theme and 10+ PHP page/template files.
- [x] **Mobile catalog and product flow:** Preserve exactly four filters—All, Non-root, Root, iOS—and three product columns at every phone breakpoint, using tighter cards on narrow screens. Product details expose available durations, currency-safe INR/USD prices, demo and download/setup links, with sign-in returning to the selected product.
- [x] **Admin and one database:** Existing product/image, homepage/banner, order, customer and Binance-review admin controls remain. Existing SQLite storage remains the single store for customer, catalog, image, order, payment, proof, wallet and admin data; schema changes are in-place.
- [x] **Binance USD proof flow:** The customer flow requests a USD amount, then transaction ID and screenshot. It submits to the existing manual review queue; only admin approval credits the INR wallet, with existing duplicate-credit protection unchanged.
- [x] **Google sign-in:** `/auth/google` and `/auth/google/callback` use one-time state bound to a short-lived HTTP-only browser cookie, PKCE, server-side token exchange and verified issuer/audience/expiry/email/subject checks before creating/linking a customer row. Client secret is not present in source.
- [x] **Existing payment/fulfillment:** Keep existing email/password login, UPI/FamGateway contracts, reseller API contract, order/payment/key history and supplier-uncertainty protections. New wallet pages call the existing wallet endpoints.
- [x] **Verification:** PHP lint and Git whitespace checks pass. A temporary SQLite-backed HTTP smoke test returned success for 19 routes, verified design-token assets, confirmed the Google PKCE redirect, and rejected a callback from a browser missing the initiating nonce. Google credential configuration and live Railway callback remain deployment follow-ups.

## Railway deployment follow-up

Add protected Railway variables `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`; optionally set `GOOGLE_REDIRECT_URI` to the registered callback `https://ffpanelstore.up.railway.app/auth/google/callback`. Rotate the Google client secret pasted into the prior chat before storing its replacement in Railway. Keep the existing persistent volume and FamGateway/reseller variables unchanged.
