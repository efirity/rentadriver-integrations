# BigCommerce production readiness

Assessment: 2026-09-10. The app supports sandbox use but is not yet approved for public Marketplace distribution or ready for unrestricted customer onboarding. A successful deployment is not Marketplace or Shipping Provider approval.

## Work order

| Priority | Work | Status / acceptance criteria |
| --- | --- | --- |
| 1 | Secure app login and wallet-return URLs | Deployed in 0.1.5; service, HTTP, browser and database checks passed. Actual control-panel acceptance remains under item 6. |
| 2 | Reliable shipment synchronization | Open. Persist delivery-event work, retry failures and reconcile existing BigCommerce shipments before creating another. Test platform success followed by local persistence failure, concurrent events and lost completion updates. |
| 3 | Staff access and session revocation | Partly done. 0.4.0 binds every app session to the verified control-panel user (carried in the one-use handoff) and keeps or renews an account session only for that same user, for up to 30 days after the account sign-in; sign-out revokes it. 0.6.0 adds Multiple Users: authorised users can install and load, each is provisioned per store (the store-users table), remove-user ends that user's sessions and blocks renewal, the owner is never removed. Everyone is view-only until they sign in with a RentADriver account (no separate role mapping). Still open: release the multi-user schema change to production, enable Multiple Users in the Developer Portal, and test add/remove staff in the sandbox store. |
| 4 | Shipping Provider registration and real checkout rates | External status unverified. Obtain/confirm the assigned carrier ID, configure quote/connection endpoints and shipping zones, then test actual storefront rates and exclusions. A named flat rate does not check eligibility. |
| 5 | Supported order and payment policy | Open. Support or explicitly block partial fulfillment before booking; multi-address orders are currently rejected. Document pickup-location limits and offline/manual-payment behavior. Never treat an awaiting-fulfillment status alone as proof of payment. |
| 6 | Full sandbox acceptance | Open. Record install/load/reinstall/uninstall, account/workspace binding, staff removal, coverage/cutoffs/package limits, duplicate webhooks, funding/currency, pickup/tracking, cancellation, return and completion. Use an actual BigCommerce control-panel iframe and test restricted third-party storage. |
| 7 | Public submission and launch | Open / portal status unverified. Final sandbox screenshots, installation/user guides, pricing, support/legal links, reviewer accounts/instructions, review and approval. Replace the generic Marketplace link only after an approved listing exists. |

## Item 1: app session handoff (0.1.5)

This is separate from the already implemented **Open console** handoff, which selects the linked RentADriver account in an isolated tab.

- BigCommerce auth/load callbacks verify the platform owner as before, then redirect to `/bigcommerce#handoff=<opaque-code>`. No reusable merchant session is included in the redirect URL. Responses prohibit caching and referrers.
- The code expires after 60 seconds. Only its secret hash is stored in the existing `otp_codes` table under a separate `bigcommerce_app:` purpose. Redemption uses the existing atomic consumption function, so concurrent requests have only one winner.
- Codes bind to the store, account, installation, encrypted store credentials, connection key/mode and sandbox settings. Changed bindings, uninstall, expiry, replay and codes from another purpose are rejected.
- The browser strips the fragment before any app API request and clears the previous BigCommerce session before exchange. `POST /v1/bigcommerce/session/exchange` revalidates the current connection before returning the merchant session in a non-cacheable response body. It never issues a RentADriver account API key.
- Failed or legacy `?session=` entries cannot fall back to another store's saved session. Existing tab sessions still support ordinary reloads. Old bookmarked login URLs must be replaced by reopening the app from BigCommerce.
- Payment-provider success/cancel URLs contain only `deposit=success` or `deposit=cancelled`. They contain no session, handoff code, account ID or store credentials. A returning tab uses its existing app session; without one, it instructs the merchant to return to BigCommerce. Return parameters do not confirm payment or credit a wallet; confirmation remains server-side.
- Merchant alert emails already link to the plain app URL, without login credentials.
- This change is BigCommerce-only. Other connectors retain their existing login/return flows. BigCommerce's independent version is bumped from 0.1.3 to 0.1.5. The local fixture uses a clearly synthetic fragment entry and does not emulate production code expiry/security.

### Release evidence

Run the BigCommerce handoff service/HTTP tests, existing adapter tests, browser-session unit tests, the browser regression for the built app (new login, expired/legacy entries, wallet returns, account isolation, responsive layouts), the SQL regression for handoff-code purpose isolation, consumption, replay and expiry, and the API/web static checks. No new database migration is required.

Deploy API and web only after passing applicable tests and image builds, and record the resulting commit and live smoke results in the release report. Sandbox fixtures do not prove a complete external BigCommerce OAuth or payment flow; keep that acceptance work open under item 6. The 0.1.5 release was deployed on 10 September 2026 and its live smoke checks passed; actual BigCommerce OAuth/control-panel and payment-provider acceptance remains open under item 6.

## References

- [Full publishing runbook](PUBLISHING.md)
- [BigCommerce approval requirements](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/approval-requirements): Marketplace apps must be multi-user enabled.
- [Publishing guidance](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/publishing-apps)
- [Shipping Provider registration](https://docs.bigcommerce.com/developer/docs/integrations/shipping-providers): separate carrier registration and assigned carrier ID.
