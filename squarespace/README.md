# RentADriver for Squarespace

Squarespace connector on the **shared commerce layer** (the API's commerce service with a Squarespace adapter and pure
contract helpers, the hosted merchant page at `/squarespace` built on the shared commerce dashboard component, and the
shared commerce schema).
Squarespace has **no live checkout rates and no embedded admin**: the merchant creates a flat shipping option named
**"Same-day by RentADriver"**, orders paid with it are booked automatically, and a **fulfillment with the tracking link** is
created at pickup. Agents: `GET /v1/squarespace/orders`, `POST /v1/squarespace/orders/:ref/book`
(MCP `list_store_orders` / `book_store_order`, `platform: "squarespace"`).

## Implementation status

The connector uses the existing merchant UI and shared booking, wallet, workspace and event-queue services.
The adapter has isolated OAuth route and provider contract tests. OAuth, a real Squarespace test order and provider fulfillment/tracking were exercised on 9 September.

## Developer Platform setup (developers.squarespace.com → Start building)

| Setting | Value |
|---|---|
| Initiate URL | `https://api.rentadriver.ai/v1/squarespace/install` |
| Redirect URI | `https://api.rentadriver.ai/v1/squarespace/callback` |
| Scopes | `website.orders`, `website.orders.read` |
| Webhook endpoint (created by us per site) | `https://api.rentadriver.ai/v1/squarespace/webhooks` |

The API needs the app's **client id / secret** (every `/v1/squarespace/*` route answers 501 until they are configured). The web connect button calls the API install URL directly. New OAuth credentials are self-service in the Squarespace account dashboard; apps start in Demo Mode and require production review before going live.
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
secret as bytes**, compared to the **hex** `Squarespace-Signature`. They are deduped on the notification `id` and
processed by the commerce worker.

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

## Before a pilot / remaining work

OAuth renewal is implemented and pending real-site lifecycle acceptance. Durable outbound retries, fresh manual booking validation and the remaining merchant acceptance scenarios are still open.

## Provider contracts

Checked against the official docs while implementing this increment:
[OAuth](https://developers.squarespace.com/commerce-apis/oauth),
[site profile](https://developers.squarespace.com/commerce-apis/retrieve-basic-site-info),
[webhook verification](https://developers.squarespace.com/webhooks/verifying-notifications),
[webhook subscriptions](https://developers.squarespace.com/commerce-apis/webhooksubscriptions),
[orders and fulfillments](https://developers.squarespace.com/commerce-apis/orders).

## Linked RentADriver account

Settings shows the linked account ID and contact email. Use **Open console** to sign into that account in a separate tab while preserving your other console tabs and the store’s sandbox/live mode. Console access and permissions are described in the RentADriver console documentation.
