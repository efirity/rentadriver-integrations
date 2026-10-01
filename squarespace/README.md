> Current launch status and remaining work: [Production readiness](PRODUCTION-READINESS.md).

# RentADriver for Squarespace

Squarespace connector on the **shared commerce layer** (the API's commerce service with a Squarespace adapter and pure
contract helpers, the hosted merchant page at `/squarespace` built on the shared commerce dashboard component, and the
shared commerce schema).
Squarespace has **no live checkout rates and no embedded admin**: the merchant creates a flat shipping option named
**"Same-day by RentADriver"**, orders paid with it are booked automatically, and a **fulfillment with the tracking link** is
created at pickup. Agents: `GET /v1/squarespace/orders`, `POST /v1/squarespace/orders/:ref/book`
(MCP `list_store_orders` / `book_store_order`, `platform: "squarespace"`).

For public distribution, follow [Publish RentADriver for Squarespace](PUBLISHING.md).

## Implementation status

The connector uses the existing merchant UI and shared booking, wallet, workspace and event-queue services.
The adapter has isolated OAuth route and provider contract tests. OAuth, a real Squarespace test order and provider fulfillment/tracking were exercised on 9 September. See [production readiness](PRODUCTION-READINESS.md) for the recorded evidence and remaining launch blockers.

## Developer Platform setup (developers.squarespace.com → Start building)

| Setting | Value |
|---|---|
| Initiate URL | `https://api.rentadriver.ai/v1/squarespace/install` |
| Redirect URI | `https://api.rentadriver.ai/v1/squarespace/callback` |
| Scopes | `website.orders`, `website.orders.read` |
| Webhook endpoint (created by us per site) | `https://api.rentadriver.ai/v1/squarespace/webhooks` |

Copy the **client id / secret** into the API env: `SQUARESPACE_CLIENT_ID`, `SQUARESPACE_CLIENT_SECRET` (501 on every
`/v1/squarespace/*` route until set). The web connect button calls the API install URL directly; no public client-ID build flag is needed. New OAuth credentials are self-service in the Squarespace account dashboard; apps start in Demo Mode and require production review before going live. See the [public publishing guide](PUBLISHING.md) for the current registration, review, listing and release steps.
Every Squarespace API call carries a `User-Agent` (required by their API).

## Install flow

1. `GET /v1/squarespace/install` sets a state cookie and redirects to
   `https://login.squarespace.com/api/1/login/oauth/provider/authorize?client_id&redirect_uri&scope&state&access_type=offline`.
   Partner-initiated connections forward Squarespace's `website_id` parameter.
2. `/callback?code&state` → `POST …/oauth/provider/tokens` (Basic `client_id:client_secret`,
   `{ grant_type: authorization_code, code, redirect_uri }`) → `access_token` + `refresh_token`, with Unix-second `access_token_expires_at` and `refresh_token_expires_at`; then
   `GET /1.0/authorization/website` for the site id/title/url/timezone/currency/measurement standard. `installStore("squarespace", <website id>, …)`
   stores the tokens encrypted (refreshed on use with `grant_type: refresh_token`) and
   provisions: `POST /1.0/webhook_subscriptions { endpointUrl, topics: [order.create, order.update, extension.uninstall] }` —
   the response's hex-encoded `secret` (returned once) and subscription id are kept in encrypted credentials for `Squarespace-Signature` checks.
3. Redirect to `https://rentadriver.ai/squarespace?session=<merchant session>`; later visits use the stored session or an
   API-key sign-in (`POST /v1/squarespace/session/by-key`).

The site profile carries **no store address**: the merchant types the pickup address in Settings (`settings.pickup_address`),
which is geocoded and matched to a service area on save (`updateStoreSettings` re-provisions). Until then `last_error`
says so and orders fail with "pickup location is not set".
Once the pickup is covered, the account and its default country workspace are created atomically using that service area's
country and currency. The store's retail currency stays unchanged; an unsupported retail currency does not block OAuth.
An existing account link is preserved, and concurrent setup requests reuse the same account.

## Orders → deliveries → back to the order

`order.create` / `order.update` notifications (`{ id, websiteId, subscriptionId, topic, data: { orderId } }`)
are verified against the connected site's subscription: HMAC-SHA256 over the raw UTF-8 body, using the **hex-decoded
secret as bytes**, compared to the **hex** `Squarespace-Signature`. They are deduped on the notification `id` in
`commerce_events` and processed by the commerce worker.

Orders are fetched from `GET /1.0/commerce/orders/<id>`. Only explicit `paymentState: PAID` enables automatic booking;
missing, authorized, pending, failed, partially paid and refund states do not. `testmode` is preserved for shared booking.
A shipping address is required; the billing address is not used as a delivery destination. Weights use the site's
`measurementStandard` (`IMPERIAL` → lb, `METRIC` → kg), independently of pickup country.

The shipping line's `method` must contain "rentadriver" unless the merchant selected `auto_book: all_paid`.
Booking uses `created_via: squarespace` and idempotency `squarespace:<websiteId>:<orderId>`.
Order numbers (with an optional `#`) are searched across pending-order pages, up to 20 pages; larger stores can use the
24-character order id. Cursor requests use only `cursor`, never a provider-supplied URL. Fulfilled orders are ignored by
the event handler and rejected by remote manual lookup. `fulfillmentStatus: CANCELED` cancels the delivery while possible;
`extension.uninstall` marks the store uninstalled.

Delivery events: `picked_up` (or `assigned` if configured) → `POST /1.0/commerce/orders/<id>/fulfillments`
`{ shouldSendNotification, shipments: [{ shipDate, carrierName: "RentADriver", service: "Same-day", trackingNumber, trackingUrl }] }`.
Before writing, the adapter checks for a matching RentADriver tracking fulfillment so a retry after remote success can reconcile without another write. Already cancelled or externally fulfilled orders reject the write.
Squarespace has no order-note API, so failures / no-driver outcomes reach the merchant by email (`merchant_alerts`).

## Start the local demo

With Node.js 22+ and workspace dependencies installed (`pnpm install`), run from the repository root:

```bash
pnpm dev:squarespace
```

Open the printed URL (by default `http://localhost:3004/squarespace?session=local-squarespace-demo`).
The script starts the real merchant UI and an in-memory fixture API on port 8789. Sample order 1003 can be booked;
1004 is unpaid, 1001 can be cancelled, and delivered order 1002 supports a return. Settings and sample booking changes
reset when stopped. Payments, account linking, notifications, real dispatch and Squarespace OAuth are not connected.
The **Connect Squarespace** button enters the local demo; it does not test provider authorization.

Stop with Ctrl+C in the starting terminal, or from another terminal in the same checkout:

```bash
pnpm dev:squarespace --stop
```

For different ports:

```bash
SQUARESPACE_WEB_PORT=3104 SQUARESPACE_API_PORT=8889 pnpm dev:squarespace
```

The script refuses occupied ports and leaves other servers alone. Starting it again reports the active demo URL.
Stop and restart to change ports. Logs are in `.dev-logs/squarespace/web.log`; the web build uses
a separate `.next-squarespace-demo` directory so it does not overwrite another Next.js build. The demo API needs no credentials
or database and never loads `.env`; the web API URL is explicitly set to the local fixture server. Next.js may download
its development fonts on the first run. This is a UI demo, not the provider/database acceptance test required for release.

## Local testing

Run from the repository root; the test runner strips provider credentials and does not load dotenv:

```bash
pnpm test:unit
pnpm --filter @rentadriver/api typecheck
```

The focused Squarespace adapter and shared commerce test files both mock or avoid all external services.

The discovered service suite includes actual Hono install/callback requests with a fixture commerce service and mocked
Squarespace responses. It covers state binding, expiry, rotating tokens, signature encoding and subscription binding,
payment states, pagination, weight units, cancellation, uninstall and fulfillment reconciliation. It does not create
stores, deliveries, wallet transactions or notifications on any real service.

## Before a pilot / remaining work

The maintained checklist is [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md). OAuth renewal is implemented on the readiness branch, pending release and real-site lifecycle acceptance. Durable outbound retries, fresh manual booking validation and the remaining merchant acceptance scenarios are still open. Follow the affected-scope release checks before deployment.

## Provider contracts

Checked against the official docs while implementing this increment:
[OAuth](https://developers.squarespace.com/commerce-apis/oauth),
[site profile](https://developers.squarespace.com/commerce-apis/retrieve-basic-site-info),
[webhook verification](https://developers.squarespace.com/webhooks/verifying-notifications),
[webhook subscriptions](https://developers.squarespace.com/commerce-apis/webhooksubscriptions),
[orders and fulfillments](https://developers.squarespace.com/commerce-apis/orders).

## Linked RentADriver account

Settings shows the linked account ID and contact email. Use **Open console** to sign into that account in a separate tab while preserving your other console tabs and the store’s sandbox/live mode. Console access and permissions are described in the RentADriver console documentation.
