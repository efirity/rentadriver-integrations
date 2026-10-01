# TikTok Shop integration — specification

Status: **spec, not implemented** (2026-10-01). Nothing ships until the product owner confirms the build order and
the two platform gates below are answered by TikTok. Written with every existing integration in mind: the connector
runs on the shared commerce layer like WooCommerce, BigCommerce, Wix and Squarespace; only the adapter, its pure
parsers and the listing are TikTok-specific.

TikTok Shop facts were checked against the Partner Center docs on 2026-10-01 (slugs under
`https://partner.tiktokshop.com/docv2/page/<slug>`). Anything marked **unverified** must be confirmed with TikTok
before it is treated as a fact.

---

## 1. Summary and recommendation

TikTok Shop is a marketplace, not a storefront platform. The buyer never sees a "Same-day by RentADriver" option, the
seller cannot add a shipping method, and the order carries TikTok's own shipping fee. What the seller can do is ship a
`shipping_type = SELLER` order with a carrier of their choice and upload the tracking number. That is the only place
RentADriver fits: **the seller's carrier for local orders**, booked automatically for orders inside our coverage.

The flow is the standard connector pattern and maps onto the shared layer with no contract change:
OAuth → list authorized shops → register webhooks → on `AWAITING_SHIPMENT` re-read the order → book → mark the
package as shipped with our tracking code → push pickup / out-for-delivery / delivered events → TikTok completes the
order.

**One hard constraint decides whether this is viable.** Mark Package As Shipped requires a `shipping_provider_id`
from TikTok's approved carrier list for the delivery option; there is no "Other" carrier. A tracking number that no
approved carrier recognizes is accepted with a warning in the US, but the order then never reaches `IN_TRANSIT` or
`DELIVERED` on TikTok, the seller's Valid Tracking Rate drops, and the buyer may claim a refund after 7 business days
in `AWAITING_COLLECTION`. TikTok offers two ways out, both gated by TikTok, neither self-service:

| Gate | What it is | Who grants it | Market |
|---|---|---|---|
| **G1 Shipping provider** | RentADriver registered as a shipping provider so our `shipping_provider_id` exists for Seller Shipping delivery options | Account manager / business development ("unsupported carriers may be evaluated through Account Manager requests") | US, UK |
| **G2 Self-delivery tracking allowlist** | `POST /fulfillment/202609/order/sof/track/update`: a merchant on the allowlist pushes its own pickup / delivery / failure events with a `provider_id` "allowed under the merchant self-shipping and self-delivery method" | Per-merchant allowlist (error `11007016` otherwise) | US (and BR) only |

Recommendation: **do not start the adapter until G1 or G2 is confirmed in writing for at least one pilot seller.**
Phase 0 is a business conversation with TikTok, not code. If G2 is granted for US pilot sellers, the build is about
three weeks of engineering on the shared layer (the Squarespace adapter is the closest template: hosted page, OAuth,
no live rates). If neither gate opens, the integration cannot write back a valid shipment and should not be built.

What we give up compared with the other platforms, by design: no checkout rate, no customer-facing price, no
"rate chosen" eligibility signal, no order notes. What we gain: the full street address and phone for seller-shipped
orders, a buyer drop-off preference, a two-business-day dispatch SLA that same-day delivery beats comfortably, and a
US "3-Day Delivery" badge that is earned by performance.

---

## 2. TikTok Shop as a delivery market (facts)

| Topic | What TikTok offers | Source |
|---|---|---|
| Who may ship themselves | US: "Seller Shipping" exists but "may only be available to select sellers"; its discontinuation was announced for Feb–Mar 2026 and paused on ~2026-02-23 (a pause, not a reversal). UK: "Ship by Seller" is allowed for local and EU cross-border sellers with approved carriers. | Seller University US/UK; Adweek, eMarketer (Feb 2026) |
| Order shipping types | `shipping_type` = `SELLER` (seller picks the carrier: **our target**), `TIKTOK` (TikTok label: not addressable), `TIKTOK_DIGITAL`; `fulfillment_type` = `FULFILLMENT_BY_SELLER` / `FULFILLMENT_BY_TIKTOK` (FBT: not addressable) | `get-order-detail-202507` |
| Delivery speed at checkout | None for seller-shipped orders in US/UK. Buyers see performance badges (US `fast_delivery_program` = `3_DAY_DELIVERY`, UK "2-Day Shipping"). Same-day programs exist only in SEA (Singapore uParcel, PH/TH by invitation; `fulfillment_priority_level`). | `get-warehouse-delivery-options-202309`, Seller University SG/PH |
| Recipient address (US) | `shipping_type = SELLER` orders are **unmasked** in fulfillable statuses (`AWAITING_SHIPMENT` … `DELIVERED`); masked in `UNPAID`, `ON_HOLD`, `CANCELLED` and 30 days after `COMPLETED`. `TIKTOK` orders: street, phone and name masked in all statuses from mid-August 2026. | `us-market-changes-to-recipient-address-masking`, `cobygjha` |
| Buyer contact | `recipient_address.phone_number` (unmasked for SELLER orders), `buyer_email` is an anonymized relay address, `buyer_message`, `delivery_preferences.drop_off_location` (US) | `get-order-detail-202507` |
| Seller SLAs (US) | Dispatch to `IN_TRANSIT` within 2 business days of `AWAITING_SHIPMENT`, auto-cancel at 5 business days without tracking; delivery within 6 business days; Valid Tracking Rate ≥ 95 % (Seller Shipping); buyer may request a refund if not `IN_TRANSIT` within 7 business days of `AWAITING_COLLECTION`; TikTok may auto-refund on invalid tracking. Size cap L ≤ 60 cm, L+W+H ≤ 150 cm, ≤ 50 lb. | Seller University US |
| Seller SLAs (UK) | 7 working days to hand over; approved carriers only (Royal Mail, Evri, DPD, Yodel, Amazon Logistics, Parcelforce, UK Mail, DHL UK, Panther, DX); `delivery_sla_time` on 3PL orders; "2-Day Shipping" label at ≥ 80 % delivered within 2 calendar days | Seller University UK, `yeklql2a` |
| Buyer remorse | `ON_HOLD` = 1-hour window after payment; fulfillment is refused (`21011044`). Buyer cancel requests after that need a seller answer within 24 h (auto-approve). | `for-us-and-uk-markets-introducing-on-hold-order-status`, Seller University US |
| Returns | Return & Refund API exposes requests, statuses, approve/reject; `shipment_type` = `PLATFORM` | `BUYER_ARRANGE`. No API to register a seller-arranged return courier. | `search-returns-202602`, `approve-return-202309` |

Implication for positioning: the pitch to a TikTok seller is **"ship local orders the same day with no label, and beat
the 3-Day badge"**, not "offer same-day at checkout". The seller pays our price from their wallet; the buyer's
shipping fee stays with the TikTok order. Listing copy must say this plainly (see §11).

---

## 3. Platform model: app, authorization, signing

| Item | Value | Notes |
|---|---|---|
| Partner Center | US: `partner.us.tiktokshop.com`; rest of world: `partner.tiktokshop.com`. **Separate registrations** and separate apps per region. Compliance and legal review is mandatory for US and UK partners ("three or more weeks"). | `developer-onboarding` |
| Business entity | Registration region must match the company's business certificate; cross-border partners are CN/HK only → a **local business entity may be required** for each regional app (**partially unverified**, confirm with partner.us@/partner.uk@tiktokshop.com) | `developer-onboarding` |
| App category | Irreversible at creation. Choose **Shipping & Fulfillment → Shipping** ("shipping, logistics and carrier integration tools that … update package status"). Avoid "eCommerce Management → Connectors": Connector apps targeting US/UK sellers go through a beta phase capped at 25 authorizations. | `hulvi36o`, `app-review-process` |
| Scopes (public, seller consents) | Shop Authorized Information, Order Information, Fulfillment Basic, Logistics Basic, **Update Delivery Status** ("push a package to delivered status for sellers with transport capacity (3PL shipping)"), Return & Refund Basic. Product Basic only if we read product weights (§5). | `access-scope` |
| Authorization URL | US `https://services.us.tiktokshop.com/open/authorize?service_id={service_id}&state=…`; others `https://services.tiktokshop.com/open/authorize?…`. Redirect `{redirect_url}?code=…&state=…`; denial `code=null&error=auth_denied`. Code valid 30 min, single use. | `authorization-overview-202407` |
| Token | `GET https://auth.tiktok-shops.com/api/v2/token/get?app_key&app_secret&auth_code&grant_type=authorized_code` (literally `authorized_code`); refresh `…/token/refresh?…&grant_type=refresh_token`. `access_token` 7 days; `refresh_token_expire_in` = the authorization duration the seller picked at consent. Response also has `open_id`, `seller_name`, `seller_base_region`, `user_type`, `granted_scopes[]`. Whether the refresh token rotates: **unverified**. | same |
| Shops | `GET /authorization/202309/shops` → `shops[]{id, name, region, seller_type (LOCAL|CROSS_BORDER), cipher, code}`. `shop_cipher` is a signed query param on every shop-level call. | `get-authorized-shops-202309` |
| Signing | Query `app_key`, `timestamp` (seconds), `sign`; header `x-tts-access-token`; `content-type: application/json`. `sign` = hex HMAC-SHA256 keyed with `app_secret` over `app_secret + path + concat(sorted(key+value) of all query params except sign/access_token) + raw body + app_secret`. | `sign-your-api-request`, `common-parameters` |
| Base URL | `https://open-api.tiktokglobalshop.com` for all markets (a US-specific API host is not documented) | `regions-and-languages` |
| Versions | In the path: `/order/202309/orders/search`, `/order/202507/orders`, `/fulfillment/202309/orders/{id}/packages`, `/fulfillment/202609/order/sof/track/update`, `/return_refund/202602/returns/search`. Old versions kept ≥ 2 months after a successor. | `api-versioning` |
| Rate limits | Dynamic per app × shop; HTTP 429 or business code `36009002`; honour `Retry-After`. Baseline writes 1–3 rps, reads 3–10 rps. | `rate-limits` |
| Lifecycle webhooks | `UPCOMING_AUTHORIZATION_EXPIRATION` 30 days before expiry then daily; `SELLER_DEAUTHORIZATION` on revoke | `tts-webhooks-overview` |

---

## 4. Fit with the shared commerce layer

Platform key `tiktok`; routes `/v1/tiktok/*`; web `/tiktok`; manifest key `tiktok`; package `apps/integrations/tiktok/`.

### `PLATFORM_INFO.tiktok`

| Field | Value | Why |
|---|---|---|
| `name` | `TikTok Shop` | |
| `embedded` | `false` | TikTok has no iframe app surface; the App Store entry opens our hosted page (like Squarespace) |
| `live_rates` | `none` | No checkout rates and, unlike Squarespace, **no merchant-created shipping method either**. `rdShippingChosen()` is always false |
| `remote_orders` | `true` | `POST /order/202309/orders/search` with `order_status = AWAITING_SHIPMENT` |
| `pickup_address_required` | `false` with a fallback | Warehouses come from `GET /logistics/202309/warehouses` (address, type SALES_WAREHOUSE / RETURN_WAREHOUSE). If the address cannot be geocoded the merchant types `pickup_address` as on Squarespace |
| `store_key_label` | `Shop id` | `shops[].id`; `cipher` lives in credentials |
| `frame_ancestors` | `[]` | |
| `book_on_fulfilled` | `false` | We are the fulfilment; there is no earlier "merchant marked it shipped" state |
| `order_id_example` | `576461413038785752` | |
| `geocode_dropoffs` | `true` | Orders carry no coordinates |
| `manual_requires_paid` | `true` | `UNPAID` / `ON_HOLD` orders have masked addresses and refuse fulfilment anyway |
| `sync_via_queue_only` | `true` | Mark-as-shipped and track pushes must carry the event time and be retried durably |
| `rate_location` | absent | No rates |
| `pickup_fallback` | absent in v1 | `warehouse_id` is fixed by TikTok per order; moving stock is not exposed |

### Eligibility and auto-booking (where TikTok differs)

`autoBookMiss()` keeps working, with these platform rules applied first in `recognizeOrder` / `handleEvent`:

1. `shipping_type` must be `SELLER` and `fulfillment_type` `FULFILLMENT_BY_SELLER`. Anything else → `skipped`
   with reason "TikTok ships this order (TikTok Shipping / FBT)". Shown in the Orders tab so the seller understands
   why most orders are not ours if they use TikTok Shipping.
2. `status` must be `AWAITING_SHIPMENT` (= `paid: true`). `UNPAID` and `ON_HOLD` → not paid; the 1-hour hold means
   a paid order appears to us roughly an hour after payment. `order_type` other than `NORMAL` (pre-order, made-to-order,
   virtual) → skipped.
3. `auto_book` values: `rate_only` is **refused** on TikTok (`validateSettings`) and hidden in Settings; the default
   for new TikTok stores is `manual`, the merchant can switch to `all_paid` ("book every eligible order inside my
   coverage"). `fulfilled` is refused.
4. New shared, optional setting `max_quote_cents` (null = no cap): in `all_paid` mode the merchant eats the delivery
   price, so auto-booking must refuse quotes above the cap (`skipped: quote_above_cap`) and leave the order for manual
   booking with the price shown. This is a generic improvement for every platform's `all_paid` mode; TikTok makes it
   necessary. Hidden on platforms where the merchant passes the price through.
5. Coverage: pickup = the order's warehouse (`resolvePickup` by `warehouse_id`), drop-off geocoded from
   `recipient_address`, checked against the pickup's zone/radius and `max_size_class` as on every platform.
6. Deadline awareness: TikTok gives `shipping_due_time` / `delivery_sla_time`. Store them in `commerce_orders`
   metadata and show "TikTok expects dispatch by …" in the order details; a scheduled next-day booking that would
   miss `shipping_due_time` is refused in preview (`would_miss_platform_sla`).

### Normalized order (`lib/commerce/tiktok.ts#normalizeTtOrder`)

| `CommerceOrder` | TikTok source |
|---|---|
| `id`, `number` | `id` (TikTok has no separate order number; show the last 6 digits as the short label) |
| `currency`, `subtotal_cents`, `total_cents` | `payment.currency`, `centsOf(payment.sub_total)`, `centsOf(payment.total_amount)` |
| `paid` | `status ∈ {AWAITING_SHIPMENT, PARTIALLY_SHIPPING, AWAITING_COLLECTION, IN_TRANSIT, DELIVERED, COMPLETED}` |
| `cancelled` | `status = CANCELLED` or `is_buyer_request_cancel` with a pending cancellation |
| `shipping_address` | `recipient_address`: `address_line1..4` → address lines, `postal_code`, `region_code`, `post_town` (UK) / `district_info` L1–L3 (city/state), `name` / `first_name` / `last_name`, `phone_number`. **Masked values (`***`) → treat the field as missing and refuse booking** (`address_masked`), never geocode a masked line |
| `customer` | `recipient_address.name`, `phone_number`; `buyer_email` only as a relay reference, never for our notifications |
| `note` | `buyer_message` + `delivery_preferences.drop_off_location` ("Leave at: Front Door") |
| `items` | `line_items[]` grouped by `sku_id` (TikTok emits one line item per unit): `product_name` + `sku_name`, quantity, `centsOf(sale_price)`, `seller_sku`. Weights are not on the order: `grams = null`; see §5 |
| `shipping_line` | `{ code: null, title: delivery_option_name, price_cents: centsOf(payment.shipping_fee) }` (informational; never "chosen") |
| `test` | store is a Development Shop (§10) |
| `tags` | `undefined` (TikTok has no order tags; tag rules are hidden in Settings) |
| `fulfilled` | `status ∈ {AWAITING_COLLECTION, IN_TRANSIT, DELIVERED, COMPLETED}` — used only to refuse a manual booking of an order someone already shipped |
| platform extras (metadata) | `warehouse_id`, `shipping_type`, `fulfillment_type`, `delivery_option_id`, `shipping_due_time`, `delivery_sla_time`, `packages[].id`, `line_items[].id`, `fast_delivery_program`, `has_updated_recipient_address`, `is_sample_order` |

Line items `sku_type = PRE_ORDER`, `is_dangerous_good = true` or `needs_prescription = true` → the order is skipped
with a reason (we do not carry dangerous goods or prescriptions; pre-orders have no stock).

---

## 5. Cargo size and weight

TikTok orders carry no weights or dimensions. Options, in order of preference:

1. **Product read, cached** (needs Product Basic scope): `GET /product/202309/products/{product_id}` →
   `package_weight {value, unit}` and `package_dimensions`; cache per `sku_id` in `commerce_stores.settings.sku_cache`
   (bounded, 500 entries, 30-day TTL). `orderSizeClass()` then works as elsewhere.
2. **Merchant default** when the product is unknown or the scope is not granted: `settings.default_size_class`
   (new, optional, defaults to `M`), applied per parcel, with `max_size_class` still capping eligibility.

v1 ships option 2 and asks for Product Basic only if the pilot shows mis-sized bookings; adding the scope later means
re-consent by installed sellers (same trade-off as Shopify `read_returns`).

---

## 6. Install, sessions and account linking

Mirrors Squarespace (hosted page, OAuth, remembered sign-in), with two apps behind one adapter.

1. `GET /v1/tiktok/install?region=us|gb` sets the state cookie, picks the app for the region
   (`TIKTOK_US_APP_KEY/SECRET/SERVICE_ID` or `TIKTOK_GLOBAL_APP_KEY/SECRET/SERVICE_ID`; `enabled()` = at least one
   pair set) and redirects to the authorization URL. The TikTok App Store "open app" link lands here too; `state`
   carries the region.
2. `GET /v1/tiktok/callback?code&state` → token exchange → `GET /authorization/202309/shops`. v1 supports **one
   `LOCAL` shop per authorization**; a `CROSS_BORDER` seller with several shops gets a clear "not supported yet"
   page. `installStore("tiktok", shop.id, creds, { profile })` with `creds = { app: "us"|"global", access_token,
   access_expires_at, refresh_token, refresh_expires_at, open_id, seller_name, shop_cipher, shop_code, region }`.
   Profile: `name = shop.name`, `country_code = region`, currency from the first warehouse's region (US → USD,
   GB → GBP), `timezone` from the warehouse address region, `store_url` = `https://shop.tiktok.com/…` if TikTok
   exposes a public shop URL (**unverified**; otherwise null, `syncStoreProfile` leaves website empty).
3. `provision()` (idempotent): list warehouses → `pickup_locations` (geocoded, `area_slug`), default pickup = the
   `SALES_WAREHOUSE` marked default; register webhooks (§7); look up and cache the RentADriver
   `shipping_provider_id` per delivery option (§8); record `warnings` when the provider id is absent (G1/G2 not
   granted for this shop) so Settings shows the blocker before the first order.
4. Redirect to `https://rentadriver.ai/tiktok?session=…`. Later visits: saved session in localStorage, renewed through
   the shared `renewAccountSession` for 30 days (reuse `squarespace-session.ts` generalized to any hosted, non-launch
   platform: `signMerchantSession`'s `chainId` currently keys on `platform === "squarespace"` and must become a
   `PlatformInfo` flag, e.g. `hosted_sign_in: true`). Sign-out revokes the chain id.
5. Account linking: `commerceAccountAdapter("tiktok")` through the shared `account-oauth.ts` with its own reserved
   client, purpose and popup message type, `installationVersion` keyed on `open_id + shop_cipher + installed_at`
   API-key fallback under Advanced options as everywhere.
6. Token upkeep in `tick()`: refresh when `access_expires_at < now + 24 h`; a refresh failure or `SELLER_DEAUTHORIZATION`
   sets `oauth_reconnect_required` and the reconnect banner. `UPCOMING_AUTHORIZATION_EXPIRATION` → merchant email
   "your TikTok authorization ends on … — reconnect to keep same-day delivery running" (once per 7 days).
   Concurrent refresh uses the existing per-store credential lease, which must be made platform-neutral (only the
   Squarespace-only branches generalize).

Signing and token helpers are pure (`lib/commerce/tiktok.ts`: `ttSign(path, query, body, secret)`,
`ttAuthorizeUrl`, `ttTokenCredentials`, `verifyTtWebhook`, `normalizeTtOrder`, `ttTrackFor(deliveryEvent)`) with
unit tests, including a signature vector recorded from the sandbox.

---

## 7. Webhooks and event handling

Registration is per shop and per event type: `PUT /event/202309/webhooks?shop_cipher=…`
`{ address: "https://api.rentadriver.ai/v1/tiktok/webhooks", event_type }` for `ORDER_STATUS_CHANGE`,
`CANCELLATION_STATUS_CHANGE`, `RECIPIENT_ADDRESS_UPDATE`, `PACKAGE_UPDATE`, `RETURN_STATUS_CHANGE`,
`SELLER_DEAUTHORIZATION`, `UPCOMING_AUTHORIZATION_EXPIRATION`. `GET /event/202309/webhooks` makes provisioning
idempotent. TikTok also lets a Partner Center setting register app-wide addresses; use the API so each shop is
explicit.

`verifyWebhook`: header `Authorization` = lowercase hex `HMAC-SHA256(app_key + raw_body, app_secret)`, no `Bearer`.
The payload names `shop_id`; load the store by `store_key` to know which app's secret applies, verify on raw bytes,
`event_id = tts_notification_id`, `store_key = shop_id`, `topic = type`. Unknown shop → `"ignore"` (TikTok retries
at +2 min, +30 min, +3 h, +12 h, then stops; a 200 within 3 s is required, which the shared route's enqueue-only
handler satisfies). Webhook egress IPs are published (`iuhsev4x`); we do not filter by IP.

Payloads carry **no address or package data** (`data{order_id, order_status, is_on_hold_order, update_time}`; note
`order_status` says `CANCEL`, not `CANCELLED`). `handleEvent` always re-reads the order (contract promise 2):

| Topic → action | Rule |
|---|---|
| `ORDER_STATUS_CHANGE`, order now `AWAITING_SHIPMENT` | `{ kind: "order" }` → shared auto-book rules |
| `ORDER_STATUS_CHANGE`, `CANCEL` | `{ kind: "cancel" }` → shared cancel (delivery cancelled while possible; after pickup → merchant alert and return-to-store flow as on other platforms) |
| `ORDER_STATUS_CHANGE`, other statuses | `ignore` (our own writes move the order to `AWAITING_COLLECTION` etc.) |
| `CANCELLATION_STATUS_CHANGE`, `CANCELLATION_REQUEST_PENDING` on a booked order | v1: `ignore` + merchant alert email "buyer asked to cancel order … — you have 24 h to answer in Seller Center; the driver is {status}". v2 (delivery-exceptions design): in-app *Needs action* with one-click **Approve cancel + cancel delivery** via `POST /return_refund/202309/cancellations/{id}/approve` when nothing was picked up yet |
| `RECIPIENT_ADDRESS_UPDATE` | booked and not picked up → cancel the delivery and re-run the order rules with the new address (new quote; `moved_address` in the timeline). Picked up → merchant alert; the driver's address stands |
| `PACKAGE_UPDATE` (split / combine) | re-read; if our package no longer covers every shippable line item, mark the row `needs_action` (merchant decides) |
| `RETURN_STATUS_CHANGE` | §9 |
| `SELLER_DEAUTHORIZATION` | `{ kind: "uninstall" }` |
| `UPCOMING_AUTHORIZATION_EXPIRATION` | `{ kind: "profile" }` + the reminder email in §6 |

**Polling fallback** (TikTok's own guidance: do not rely on webhooks alone; review rule G-API-005 still wants
webhooks): `tick()` runs `orders/search { order_status: AWAITING_SHIPMENT, update_time_ge: last_poll - 10 min }`
every 10 minutes per installed store with `auto_book = all_paid`, enqueuing synthetic events
(`event_id = poll:<order_id>:<update_time>`) so the same queue books them once.

---

## 8. Write-back: shipment and delivery events (the core of the adapter)

Nothing is written at booking: TikTok has no order-note, tag or metafield API. `onBooked` is a no-op; the merchant sees
the booking in our Orders tab and in the delivery email.

**At `fulfill_on` (`picked_up` default, `assigned` optional)** — `onDeliveryEvent`:

```
POST /fulfillment/202309/orders/{order_id}/packages?shop_cipher=…
{ "tracking_number": "<RD short code>", "shipping_provider_id": "<RentADriver provider id>",
  "order_line_item_ids": [ every shippable line item id of the order ] }
→ data.package_id  (fulfillment_ref), data.warning.message (US: tracking/provider mismatch → log + Ops alert)
```

- `shipping_provider_id` comes from `GET /logistics/202309/delivery_options/{delivery_option_id}/shipping_providers`
  (cached per store per delivery option in credentials) and must be **RentADriver's** id (G1) or the id TikTok allows
  "under the merchant self-shipping and self-delivery method" (G2). If neither is present the sync job fails with
  `provider_missing`, the order shows *Needs action: ask TikTok to enable self-delivery*, and the merchant can still
  mark the package shipped manually in Seller Center with our tracking code.
- Idempotent: before writing, `GET /fulfillment/202309/orders/{order_id}/tracking`; a package already carrying our
  tracking number is reconciled, not re-created (same pattern as the Squarespace reconciliation).
- Corrections (`shipping_info/update`) are allowed within 72 h in the US and possibly once; we never need them because
  the tracking number is our stable short code.
- `ON_HOLD` orders cannot be fulfilled (`21011044`); the queue retries after the hold window.

**Delivery lifecycle → TikTok tracking events** (G2 path, `POST /fulfillment/202609/order/sof/track/update`,
US/BR; UK has no equivalent, so on UK the status after mark-as-shipped is whatever G1 gives us):

| RentADriver delivery event | TikTok `action_code` | Fields |
|---|---|---|
| `picked_up` | `30901` pickup_success | `occur_time` = event time (ms), `description`, `location` = pickup city/state/zip |
| `en_route_dropoff` | `40501` delivery_start (out for delivery) | |
| `delivered` | `50101` signed_personally (final) | `proof_url` = a **long-lived public proof link** (new: the 30-min signed links from `storeOrderDetails` are too short; add a tokenized, image-only `/proof/<delivery>/<token>` route scoped to this use) |
| `failed` (`if_no_answer` → `wait_then_return` etc.) | `60101` sign_failure | `failed_reason`: can't contact → `R104`, refused → `R106`, address error → `R103`, weather → `R110` |
| return leg delivered back to the store | `80101` unreachable_returned | |

Body: `{ country, order_id, provider_id, external_tracking_url: "https://rentadriver.ai/track/<code>", tracks[] }`, up to
50 tracks per call. Each delivery event is one durable sync job (the sync outbox) keyed by the event id, so a
retry resends the same `tracks[]` with the same `occur_time`; whether TikTok de-duplicates identical tracks is
**unverified**: the job records the sent action codes in `commerce_orders.fulfillment_meta` (new JSON column, or reuse
the existing sync-job payload) and skips codes already acknowledged with `code: 0`.

Error handling: `11007016` (merchant not allowlisted) → store flag `self_delivery_allowlist: "missing"`, Settings banner
with the TikTok contact, Ops alert, no further track pushes for that store until re-provisioned; `11007021` (provider id
not allowed) → same, with the provider id in the message; `11007018` (order not found by tracking + order) → the
mark-as-shipped step did not land, re-run it first.

Sandbox stores (`is_test` deliveries) **never** call mark-as-shipped or track update on a live shop; Development Shops
(§10) are the only place sandbox syncs, mirroring `sandbox_syncs_test_orders` semantics for TikTok's own test orders.

Order completion is TikTok's: `DELIVERED` → `COMPLETED` by their clock. `complete_order_on_delivered` is hidden on
TikTok.

---

## 9. Exceptions: no driver found, cancellations, returns

Aligned with the shared delivery-exceptions design (not implemented). TikTok column:

| Step | TikTok Shop |
|---|---|
| A4 needs-action on the order | nothing writable on the order (no notes, tags or holds) → in-app *Needs action* + merchant email, as Squarespace |
| A6 customer notice | our own SMS to `recipient_address.phone_number` (unmasked for SELLER orders); TikTok's buyer chat API (`NEW_MESSAGE`, Customer Service scope) is out of scope for v1 |
| A8 deadline | ASAP: 24 h, but TikTok's `shipping_due_time` is the real clock: an order we fail to dispatch is still the seller's to ship within 2 business days. Alert copy must say "ship it another way before {shipping_due_time}" |
| A9 refund delivery charge | not applicable: the buyer paid TikTok's shipping fee, not ours; refunds are TikTok's flow |
| A10 hide rate | not applicable (no rate) |
| B1/B2 return signal | `RETURN_STATUS_CHANGE` + `POST /return_refund/202602/returns/search`: `RETURN_OR_REFUND_REQUEST_PENDING` → `requested`; approved with `return_type ∈ {RETURN_AND_REFUND, REPLACEMENT, EXCHANGE}` and `shipment_type = BUYER_ARRANGE` → `approved`. `PLATFORM` shipment (TikTok label) → not ours |
| Auto options offered | `on_approval` only, and only when `shipment_type = BUYER_ARRANGE` and we delivered the original |
| B5 return tracking | no field for seller-arranged returns; our return leg is visible in our app only. `return_warehouse_address` from the return record is the drop-off |
| New permissions | Return & Refund Basic (requested at launch) |
| Cancellations | buyer cancel request pending → §7 row; seller-side cancel of a booked order is done in Seller Center and reaches us as `CANCEL` |

---

## 10. Development shops, sandbox and testing

- Partner Center → Development Kits → **Development Shops**: Core Function accounts (no KYC, generate orders, advance
  statuses manually) and Full Function accounts (KYC, real buyer checkout). **US and UK are missing from the
  supported-region table**; a legacy "Sandbox 2.0 (US and UK)" is referenced. Availability for US/UK must be confirmed
  (gate question 3). Sandbox shops are rate-limited (`p9x5je85`).
- A Development Shop is the TikTok `testPlan`: stores installed from one run `is_test` on the demo driver pool and are
  the only stores where sandbox deliveries sync to TikTok (mark-as-shipped + tracks), so reviewers see write-back
  (review rule ERP-004 asks for a fulfilment-status write-back demonstration).
- Automated tests: pure parser tests (`lib/commerce/tiktok.test.ts`: signing
  vector, webhook signature, order normalization incl. masked addresses, grouping of unit line items, status→paid,
  track mapping), adapter tests with a fixture service and mocked TikTok responses (`services/commerce/tiktok.test.ts`:
  install/callback state binding, token refresh, shop listing, provision idempotency, mark-as-shipped reconciliation,
  `11007016` handling, cancellation and address-update actions), plus the shared commerce suites. No real shops,
  wallets or notifications in CI.
- Local demo: `pnpm dev:tiktok` (fixture API + real merchant UI).

---

## 11. Merchant UI, listing and copy

- Hosted page `apps/web/src/app/(commerce)/tiktok/page.tsx` → `commerce-app.tsx` with `platform = "tiktok"`.
  Settings hides: pricing, rate label/description, next-day rate, `rate_only` and `fulfilled` auto-book modes, tag rules,
  `complete_order_on_delivered`, checkout-rate test. Settings adds: **Warehouses** coverage card (reuse the Shopify
  locations card), **Self-delivery status** (provider id found / allowlist missing, with TikTok's contact),
  `max_quote_cents`, `default_size_class`, and a one-line explanation: "You pay the RentADriver price from your wallet.
  The shipping fee your buyer paid stays with the TikTok order."
- Orders tab adds the TikTok dispatch deadline and the skip reason for TikTok-shipped orders.
- Integrations page on the website: status `coming_soon` until the pilot; copy from the shared positioning in
  `ecommerce-app-listings.md`, with these TikTok-specific lines:
  headline "Ship TikTok Shop orders the same day. No label, no fleet."; the shared service disclosure; "Works with
  orders you ship yourself (Seller Shipping / Ship by Seller). Orders fulfilled by TikTok are not eligible."
- Listing: App & Service Store, category Shipping & Fulfillment → Shipping, English for US/UK, ARD/design review
  (3–5 business days), app review with product URL, test account, screenshots and scope justification (5–10 business
  days), compliance/DSPR (3+ weeks).

---

## 12. Schema and wiring checklist

No new tables. One migration:

- `commerce_stores.platform` check constraint: add `'tiktok'`.
- `deliveries.created_via` check constraint: add `'tiktok'` (currently `…,'shopify','woocommerce','bigcommerce','wix','squarespace'`).
- Optional: `commerce_orders.fulfillment_meta jsonb` for acknowledged TikTok action codes, if the sync-job payload
  cannot hold them.
- Generalize the Squarespace-only credential-lease handling to any OAuth platform (code change, no schema change).

Code wiring (every file that names the four routed platforms today):

| Area | File(s) |
|---|---|
| Platform catalogue | the API's commerce platform catalogue (`ROUTED_PLATFORMS`, `PLATFORM_INFO`, `chainId` → `hosted_sign_in` flag) |
| Pure helpers | `lib/commerce/tiktok.ts` + `tiktok.test.ts` in the API |
| Adapter | `services/commerce/tiktok.ts` in the API (+ `tiktok-credentials.ts` if the lease stays per-platform), `ADAPTERS` in `services/commerce.ts` |
| Env | the API environment: `TIKTOK_US_APP_KEY`, `TIKTOK_US_APP_SECRET`, `TIKTOK_US_SERVICE_ID`, `TIKTOK_GLOBAL_APP_KEY`, `TIKTOK_GLOBAL_APP_SECRET`, `TIKTOK_GLOBAL_SERVICE_ID` |
| Routes | `routes/commerce.ts` mounts `/v1/tiktok` via `commerceRoutes("tiktok")` in `index.ts`; account OAuth via `commerceAccountAdapter("tiktok")` |
| Account linking | `services/commerce/account-link-adapters.ts` (`installationVersion` branch), `account-oauth.ts` reserved client |
| Web | `app/(commerce)/tiktok/page.tsx`, `(commerce)/layout.tsx` comment, `proxy.ts` PASSTHROUGH (two regexes), `components/commerce-app.tsx` (hidden fields), `components/brand-tile.tsx`, `lib/commerce-mode-guidance.ts`, `app/oauth/commerce/[platform]/page.tsx`, `app/[locale]/integrations/*` content + tour, `lib/mcp-tools.ts` description, `lib/tiktok-session.ts` (or the generalized hosted-session helper) |
| Shared package | `packages/shared/src/site-fingerprint.ts` `CONNECTOR_PLATFORMS` (+ a `shop.tiktok.com` fingerprint) |
| MCP | the MCP server's `Platform` enum for `list_store_orders` / `book_store_order`; follow the MCP tool checklist (annotations, catalogue check) |
| Versions | `apps/web/src/lib/commerce-plugin-versions.json` → `"tiktok": "0.1.0"` and `.md` table row; shared-UI changes bump every platform |
| Docs | `apps/integrations/tiktok/README.md` (runbook), `apps/integrations/README.md` row, `apps/integrations/architecture*.md`, the commerce adapter contract if a hook is added, the delivery-exceptions design's TikTok column |
| Brand | TikTok Shop mark usage per their brand guidelines; the RentADriver mark per ours |

Contract additions proposed (cross-platform): `PlatformInfo.hosted_sign_in?: boolean`,
`CommerceSettings.max_quote_cents?: number | null`, `CommerceSettings.default_size_class?: SizeClass`. No adapter hook
changes are needed; everything else fits the existing `CommerceAdapter` surface.

---

## 13. Phased plan and gates

| Phase | Work | Exit criterion |
|---|---|---|
| **0. Discovery with TikTok** (no code) | Register Partner Center accounts (US, non-US) with the right entities; open the two gate questions with partner.us@ and partner.uk@tiktokshop.com; confirm Development Shop availability for US/UK; confirm US Seller Shipping status for new sellers; pick the app category | Written answer on G1 or G2 for at least one pilot seller; a US or UK dev shop we can order from |
| **1. Adapter on the shared layer** | §4–§8 and §12; sandbox-only end to end on a Development Shop; `tiktok` 0.1.0 | Dev-shop order booked from the webhook, marked shipped with our tracking, tracks acknowledged (or cleanly reported as `11007016`); all commerce suites green |
| **2. Pilot** | One allowlisted US seller (or a UK seller once G1 exists), live wallet, real orders, manual booking first then `all_paid` with `max_quote_cents` | 20 deliveries written back with `DELIVERED` landing on TikTok; VTR unaffected; no duplicate packages |
| **3. Listing** | ARD, app review, compliance; listing copy | Listed in the App & Service Store, US first |

Effort after Phase 0: about three engineering weeks for Phase 1 (adapter + parsers ≈ 450 lines with tests, consistent
with Squarespace's 225 + 80 lines plus its session/credential helpers), one week of web/Ops wiring and copy, pilot
support in parallel. Phase 3 is dominated by TikTok's review clock (six to eight weeks).

---

## 14. Risks and open questions

1. **G1/G2 unanswered** → no valid write-back → do not build. Owner: the product owner (business contact with TikTok).
2. **US Seller Shipping may be withdrawn again** (paused mandate, Feb 2026). A withdrawal removes the whole US
   addressable base except allowlisted self-delivery sellers. Watch Seller University announcements before Phase 2.
3. **Entity requirement** for the US Partner Center (unverified): TikTok's guide says the registration region must
   match the business certificate and that cross-border developers are CN/HK only, so a local business entity may be
   required for each regional app; if a US entity is required the US app waits. The UK app can proceed on G1 only (no
   SOF track API in the UK, so UK status depends entirely on TikTok reading our tracking as a registered provider).
   Asked in the partner-gate e-mail to TikTok US (question 5).
4. **Refresh-token rotation** semantics unverified: design for rotation (lease) and verify on the dev shop.
5. **TikTok de-duplication of track pushes** unverified: our side records acknowledged action codes regardless.
6. **Public proof URL** is a new, longer-lived exposure of proof photos; scope the token to the delivery, image only,
   expire with TikTok's 30-day post-completion window.
7. **Review classification**: if TikTok classes us as a Connector despite the Shipping category, the 25-authorization
   beta cap applies during review; plan the pilot inside that cap.
8. **Rate limits** are per app × shop and dynamic; the 10-minute poll plus webhooks is well inside the 3–10 rps read
   baseline, but `tick()` must back off on `429` / `36009002`.
9. Decisions for the product owner: build order relative to the delivery-exceptions work; whether to request Product Basic at
   launch (weights) or after the pilot; US-first or UK-first given the entity question.

---

## 15. Sources

Partner Center docs (`https://partner.tiktokshop.com/docv2/page/<slug>`): `developer-onboarding`,
`app-development-overview`, `app-review-process`, `app-review-requirements`, `publish-and-list-public-app`, `hulvi36o`
(app categories), `authorization-overview-202407`, `get-authorized-shops-202309`, `common-parameters`,
`sign-your-api-request`, `api-versioning`, `regions-and-languages`, `access-scope`, `rate-limits`,
`get-order-list-202309`, `get-order-detail-202507`, `us-market-changes-to-recipient-address-masking`, `cobygjha`,
`for-us-and-uk-markets-introducing-on-hold-order-status`, `fulfillment-api-overview`, `mark-package-as-shipped-202309`,
`for-local-sellers-in-us-market-warning-information-added-to-mark-package-as-shipped-api`,
`get-shipping-providers-202309`, `get-warehouse-list-202309`, `get-warehouse-delivery-options-202309`,
`update-shipping-info-202309`, `tracking-status-update-202609`, `1cjht3qx` (action/reason codes),
`update-package-delivery-status-202309` (SEA only), `confirm-package-shipment-202309`, `tts-webhooks-overview`,
`update-shop-webhook-202309`, `iuhsev4x` (webhook egress IPs), `return-refund-and-cancel-api-overview`,
`search-returns-202602`, `approve-return-202309`, `approve-cancellation-202309`, `seller-center-development-shops`,
`p9x5je85`, `generate-test-access-token`.

Seller University: US Seller Shipping overview (knowledge_id 8308896260065025), US order management and SLAs
(3253210454181634, 7740101263378189, 6734735776089869); UK Ship by Seller (7753847099426562, 7796971545003809).
Press on the Feb 2026 Seller Shipping pause: Adweek, eMarketer. SEA same-day: TikTok Shop × uParcel (Singapore, June
2026), Seller University PH (138284181309185).
