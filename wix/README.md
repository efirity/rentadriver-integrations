# RentADriver for Wix Stores

Development implementation on the same commerce service used by WooCommerce, with the existing merchant dashboard, wallet, quote, booking and tracking flows. Shopify remains the reference for the delivery lifecycle. No Wix app has been published or verified on a dev site as part of this implementation.

- API adapter: the API's Wix commerce adapter
- Contract parsing and signature verification: the API's Wix contract helpers
- Dashboard: `/wix`, using the shared commerce dashboard component
- Shared storage: the shared commerce schema; no new migration is needed.

## Start the local sample dashboard

With Node.js 22+, Bun and the workspace dependencies installed (`pnpm install`), run from the repository root:

```bash
pnpm wix:dev
```

Open **http://localhost:8917/local/wix**. The launcher starts the sample API on port 8917 and the dashboard on port 3417, warms the page and prints the login link. Keep the terminal open; **Ctrl-C stops both processes**. Existing listeners are left alone. To use different ports:

```bash
WIX_API_PORT=8927 WIX_WEB_PORT=3427 pnpm wix:dev
```

This preview needs no `.env`, Wix app credentials, database or Docker. It uses the real Wix signature/session helpers with generated local credentials and sample commerce responses. Explore the overview, country wallets, orders, settings and sample quotes; try booking order 1002 or 1003. Settings and simulated bookings reset on restart. Payments and linking real accounts are disabled; outbound API requests are blocked. Sample quotes use a fixed base price, not live routing or coverage calculations.

The web preview uses `.next-wix-preview` so it can run beside another web build. Logs stay in the terminal. `pnpm wix:dev --api-only` starts only the sample API; `pnpm wix:dev --help` lists options. This preview does not verify a real Wix install or production booking flow; use the development-app setup below for that.

## Configure a development app

Create a self-managed app in the Wix Dev Center. New apps use OAuth client credentials; the legacy authorization-code installation flow is no longer available for new apps. See [Wix OAuth](https://dev.wix.com/docs/api-reference/app-management/oauth-2/introduction).

| Setting | Value |
| --- | --- |
| API environment | `WIX_APP_ID`, `WIX_APP_SECRET`, `WIX_PUBLIC_KEY` (PEM; escaped newlines accepted) |
| Web environment | `NEXT_PUBLIC_WIX_APP_ID` (enables the Wix connection copy) |
| Dashboard page iframe | `https://rentadriver.ai/wix` |
| Shipping Rates service plugin | Copy `shipping-rates.extension.json`; use isolated development URLs when testing |
| Webhook callback | `https://api.rentadriver.ai/v1/wix/webhooks` |
| Order subscriptions | eCommerce Order Approved, Payment Status Updated, Order Canceled, Order Updated |
| App subscription | App Removed |
| Permissions | Read Orders, Manage Orders for fulfillment writeback, Read Site Properties; configure the Shipping Rates extension |

The API refuses Wix requests with 501 until all three server settings are present. Confirm the exact scopes in the Wix permission selector for the configured endpoints. The shipping plugin's base URI ends in `/plugin`; Wix appends `/v1/getRates`. The checked-in JSON is a configuration template, not a deployed extension. See [extension configuration](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/extensions/shipping-rates/shipping-rates-integration-service-plugin/extension-config).

Store the complete `WIX_PUBLIC_KEY` in your secret manager, including both PEM markers, without surrounding quotes. For a single-line value, use literal `\n` separators; the API converts them to newlines. After changing it, restart the API so the process receives the updated value. An incomplete key prevents signature verification and leaves checkout without live rates.

## Installation and dashboard sessions

`GET /v1/wix/install` opens Wix's app installer with the configured app ID. Use the Dev Center's test installation for an unpublished app.

After installing, open the app from the site's Wix dashboard. Wix supplies `?instance=<signature>.<data>`, exchanged at `POST /v1/wix/session`. The API verifies the HMAC signature, rejects anonymous visitors, and requires a signature from the last hour (with one minute of future clock tolerance). These freshness limits are our session policy.

A signed `permissions: "OWNER"` grants dashboard access. Wix identity alone grants full access to wallets, settings and delivery actions only when `uid === siteOwnerId`. A dashboard manager can separately authorize access to their RentADriver account as described below. Legacy owner instances without a permissions field remain supported; explicit non-dashboard roles are rejected. When the IDs differ, the app shows a read-only store overview (name, URL and lifetime rate/delivery counts). Its signed session permits only `GET /app/me`; all other merchant routes return `403 site_owner_required`. The API excludes account details, wallets, callback tokens, settings and provider errors from that overview, rather than relying on hidden buttons. This follows [Wix's distinction between dashboard permission and payment ownership](https://dev.wix.com/docs/build-apps/launch-your-app/legal-and-security/security-and-privacy-best-practice).

If a restricted user opens a site that has not been connected, the app shows **Connect your RentADriver account** without issuing a merchant session or creating/restoring a store, organization or wallet. This covers Wix development sites where the creator has the Development Site Manager role and the signed user ID differs from the site owner ID.

From Wix dashboard → Apps → RentADriver, enter a **sandbox or live admin API key** from RentADriver dashboard → Developers. `POST /v1/wix/session/connect` verifies the fresh signed Wix dashboard instance, the account key, and a fresh Wix installation token before connecting the site. Sandbox admin keys grant merchant access while preserving sandbox mode. Viewer/operator keys and OAuth tokens cannot grant full merchant authority. The key is masked and sent only in the request body; it is not persisted in browser storage or URLs. A store already linked to a different account is rejected, including concurrent setup attempts. New stores start with rates disabled and automatic booking set to manual; no new RentADriver organization is created. Wix development-site permissions and free plans alone do not imply RentADriver sandbox billing. A store whose recorded plan is classified as test remains sandbox even with a live key; provider test orders also remain simulated.

The Wix app displays the linked wallet balance but has no top-up controls or payment checkout. Manage wallet funding in the RentADriver console, available through **Open console**. The Wix merchant endpoint `POST /v1/wix/app/wallet/deposit` rejects requests with `403 funding_in_console`; other platform funding flows remain unchanged.

For an existing store, **Unlock account** requires the linked account's key. These sessions are bound to that RentADriver organization and stop working if the store is moved to another account. Reopening from Wix starts with the scoped dashboard exchange again; enter the key to unlock management. Direct API-key sign-in can open an already connected store, but cannot create the initial Wix connection without a signed instance.

On the site owner’s first open, or after a local uninstall, the API obtains a fresh Wix instance token before provisioning the local store and organization. Invalid, revoked or unavailable token grants cannot create or restore a store. Tokens are short-lived, cached per instance, and never sent to the browser.

An optional external installation `postInstallationUrl` may use `/v1/wix/callback?signedInstance=...`; it enforces the same verification. Restricted users return to the dashboard for a read-only exchange; the callback never issues them a full session. Unsigned `instanceId` and legacy `code` query parameters are not accepted as proof of installation. Site copies are keyed by their own signed instance ID and never inherit access to the original store's wallet.

See [Wix app instance identity](https://dev.wix.com/docs/build-apps/develop-your-app/auth/app-instances/about-app-instances).

## Checkout rates

Wix posts an RS256-signed JWT body to `/v1/wix/plugin/v1/getRates`. Its decoded `data` contains:

```json
{
  "request": {
    "lineItems": [{ "quantity": 2, "price": "12.50", "physicalProperties": { "weight": 0.5, "shippable": true } }],
    "shippingDestination": { "addressLine1": "1 Test Street", "city": "Northampton", "postalCode": "NN1 1AA", "country": "GB" },
    "weightUnit": "KG"
  },
  "metadata": { "instanceId": "the-installed-instance-id", "currency": "GBP" }
}
```

The destination is a direct address in the current REST contract. The previous nested address format and `/plugin/v1/getShippingRates` and `/rates` aliases remain supported. Quantities, prices and weights are validated; a digital-only basket receives no delivery option. The shared quote service applies the store's radius, coverage, preparation time, cutoff and pricing settings. Failures return an empty rate list. No delivery is booked by a checkout rate request.

See [Get Shipping Rates](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/extensions/shipping-rates/shipping-rates-integration-service-plugin/get-shipping-rates).

## Orders and tracking

Verified webhooks enter the existing commerce event queue. The parser handles legacy wrappers and domain event bodies, including nested event IDs. Retries use the supplied event ID, or a stable hash of verified event data if absent. Unrelated order topics are ignored.

Order processing fetches the current order from Wix before taking action, because delayed events can contain stale payment or cancellation data. Only `PAID` and `APPROVED` orders reach shared booking. Cancellation events use the shared cancellation path. Shipping destinations are never inferred from billing addresses.

Shared booking applies the merchant's automatic-booking policy (`rate_only`, `all_paid` or `manual`), wallet/workspace rules and delivery idempotency. Existing fulfillment and order activity writeback remains in the adapter and still needs Wix dev-site verification. Agents can use `GET /v1/wix/orders`, `POST /v1/wix/orders/:ref/book`, and MCP `list_store_orders` / `book_store_order` with platform `wix`.

See [Wix webhook retries](https://dev.wix.com/docs/build-apps/develop-your-app/api-integrations/events-and-webhooks/about-webhooks).

## Verification and remaining work

Run `pnpm test:unit` and `pnpm --filter @rentadriver/api typecheck`. The unit runner discovers the Wix adapter and shared commerce test files, runs each file separately, disables dotenv loading and supplies isolated provider configuration. Tests use locally signed fixtures and mock all external Wix calls and commerce mutations.

Before release:

1. Install on an isolated Wix dev site; verify owner dashboard access, reconnect, copied-site separation and uninstall.
2. Verify shipping extension registration, regional enablement, live quote display, currencies, cutoff and unavailable coverage.
3. Exercise order/payment/cancellation retries, tracking and fulfillment writeback using the isolated test environment. Do not send real notifications, create real deliveries or mutate customer wallets in automated tests.
4. Verify the embedded dashboard at responsive sizes, wallet/workspace selection and manual booking.
5. Prepare the App Market listing and review submission. Collaborator permissions and an order-page action are follow-up work.

The checkout delivery-time labels are still the fixed “Today” / “Tomorrow morning” labels from the original adapter.

## Linked RentADriver account

Settings shows the linked account ID and contact email. Use **Open console** to sign into that account in a separate tab while preserving your other console tabs and the store’s sandbox/live mode. Console access and permissions are described in the RentADriver console documentation.
