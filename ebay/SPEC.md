# RentADriver for eBay: specification

Status: **draft, not implemented** (2026-10-01). Nothing here ships until the product owner confirms the P0 spike and
its go/no-go.

eBay is a marketplace, not a storefront. Nothing in it lets an outside service quote a price at checkout, show a
"same-day" delivery option, or give the buyer a tracking link. So this is **not** a checkout plugin like the Shopify,
WooCommerce, Wix, BigCommerce or Squarespace apps. It is a **fulfillment connector**: the seller offers a flat-priced
local courier option on their listings; RentADriver takes the orders that chose it, books a driver, writes the
tracking number back to eBay and sends the buyer the live tracking link by SMS.

Closest existing model: **Squarespace** (`live_rates: "none"`, flat shipping option matched by name, merchant-typed
pickup address). Two things no current platform needs: **order polling** and the **"Needs action" state** from
the shared delivery-exceptions design.

## 1. Who it is for

| Segment | In scope | Why |
|---|---|---|
| US business and private sellers | yes | Any seller may set their own shipping service and carrier |
| UK **business** sellers | yes | Business sellers keep their own couriers |
| UK **private** sellers | **no** | Since 15 Apr 2025 eBay "Simple Delivery" (eBay-assigned Royal Mail/Evri labels) is mandatory for private sellers on eligible items (≤ £750, ≤ 30 kg) [eBay help 5575] |
| Collection-in-person listings | no | eBay's own QR/6-digit code flow; a third-party courier voids the buyer protection [community, secondary] |

Best-fit sellers: stock held in one of our service areas, items where speed or size matters: furniture, appliances,
bikes, refurbished electronics, car parts, bulky goods private UK sellers can no longer ship through Simple Delivery.
eBay says 59 % of UK buyers expect delivery within 2 days and that 1–3 day delivery lifts sales up to 7 % [eBay UK
community announcement, 2024]. eBay's own same-day service (eBay Now/Shutl, US+UK) closed in 2015; there is no current
same-day programme on either site.

## 2. eBay facts the design relies on

Checked on 2026-10-01 against eBay's OpenAPI specs (Fulfillment, Account, Metadata, Notification, Message), Trading
API references and eBay help pages. Confidence: **D** = eBay documentation, **S** = secondary source, **I** = inference.
Anything marked **verify** is a P0 spike item.

| Need | What eBay offers apps | Scope / where | Conf. |
|---|---|---|---|
| Live rate at checkout | **Nothing.** `shippingOptions[].costType` is `FLAT_RATE` or `CALCULATED` (eBay's own carrier rates) only; ≤ 4 domestic services per policy | Account API `fulfillment_policy` | D |
| A "same-day" service code | **None on any site.** Closest: US `LocalDelivery` ("Local Delivery/Pickup"), `Other`, `ShippingMethodOvernight`; UK `UK_OtherCourier24` ("Other 24 Hour Courier"), `UK_OtherCourier`. Only HK/SG have `*_LocalCourier` | Trading `ShippingServiceCodeType`; Metadata `getShippingServices` (replaces `GeteBayDetails`, decommissioned 2027-03-15) | D; flat-rate on these codes **verify** |
| Same-day handling | `handlingTime {value:0, unit:DAY}`; eBay's same-day cut-off is 2 PM local | Account API | D |
| Limit the option to our area | **Region level only** (country, state, "special domestic region"). No postcode or radius | `shipToLocations` | D |
| Apply the policy to listings | Inventory API offers (`listingPolicies.fulfillmentPolicyId`), Trading `ReviseItem` (`SellerShippingProfile`), or the seller in Seller Hub (bulk edit). Seller must be opted in to Business Policies | `sell.inventory` / `sell.account` | D |
| Know an order was paid | `ORDER_CONFIRMATION` notification (payload: orderId + line-item ids only, no address) — HTTPS POST, **3 retries, no replay API**, endpoint marked down after consecutive failures. Plus `getOrders` with `lastmodifieddate` / `orderfulfillmentstatus` filters, ≤ 200 per page, 100 000 calls/day default | Notification API (`commerce.notification.subscription`), Fulfillment API (`sell.fulfillment`) | D |
| Buyer address + phone | `shipTo.contactAddress`, `fullName`, `primaryPhone` for 90 days; `email` is a 14-day `@members.ebay.com` alias | `getOrder` | D |
| Write tracking back | `createShippingFulfillment {lineItems, shippedDate, shippingCarrierCode, trackingNumber}`. Carrier must be a Trading enum value; **`Other`** = "any carrier not listed". Tracking number alphanumeric only. **No tracking-URL field.** eBay does not verify the number | Fulfillment API | D |
| What the buyer sees | Number shown, not clickable: "Other (No Carrier Match)". Expected-delivery date from handling + service transit, never refined by scans. eBay still sends its "item shipped" e-mail | eBay help 4027; community | D/S |
| Seller metrics | US Top Rated needs "tracking uploaded within handling time **and carrier validation** for 95 %" — `Other` never validates. Late-shipment / INR *seller protection* needs an **integrated** carrier. UK has no validation metric; late delivery judged by buyer feedback when tracking is not verifiable | eBay seller centre, help 4345/4347 | D |
| Money Back Guarantee proof | US: integrated-carrier scan + signature ≥ $750. UK: "tracking number that can be validated on the shipping carrier's website" (≥ £450 signature) | help 4210 | D; UK public tracking page as proof **verify** |
| Message the buyer | REST Message API (Nov 2025): `POST /sell/message/v1/send_message`, 2 000 chars, scope `commerce.message`; Trading `AddMemberMessageAAQToPartner` (90 days). **Links are blocked** by the member-to-member contact policy | Message API, help 4262 | D/S |
| Use the phone number for delivery | Allowed "in relation to a specific eBay transaction"; privacy notice names "delivery of purchased items by logistics/shipping service providers including … tracking information". API Licence: use "only as strictly necessary", delete when no longer needed, service providers bound by the same terms | User agreement 4259, privacy notice 4260, API Licence §4.1/§8.3 | D; transactional SMS by the seller's courier = I |
| Returns | Post-Order API: search/get returns, decide, refund, mark received, **Add Shipping Label Info**, messages. Label creation, drafts, estimates **decommissioned Jan–Mar 2026**. Notification topic `ORDER_RETURN_ACTIVITY` | Post-Order v2 | D; third-party label via Add Shipping Label Info **verify** |
| Cancellations | `ORDER_CANCELLATION_ACTIVITY` topic; order `cancelStatus` on re-read | Notification / Fulfillment API | D |
| Uninstall | `AUTHORIZATION_REVOCATION` topic when the seller revokes the app | Notification API | D |
| Production keys | **Marketplace Account Deletion** endpoint mandatory before the first production call: `GET ?challenge_code=` → `{"challengeResponse": sha256(challenge + verificationToken + endpointUrl)}`; then delete that user's data on each notification | developer.ebay.com/marketplace-account-deletion | D |
| OAuth | Authorization-code flow; access token 2 h; refresh token 18 months (`47304000 s`), then re-consent. Scopes: `sell.fulfillment`, `sell.account`, `commerce.notification.subscription`, `commerce.message`; `sell.inventory` only if we attach policies to listings | auth.ebay.com / sandbox | D |
| Call limits | Fulfillment 100 000/day, Account 25 000, Notification 10 000, Post-Order 5 000 per resource; more via the free Application Growth Check (3–7 business days, S) | developer.ebay.com | D/S |
| Distribution | **No app store or directory** (App Center closed 2018). Sellers connect from rentadriver.ai/ebay | — | D |

**Permissions a seller grants at connect:** `sell.fulfillment`, `sell.account`, `commerce.notification.subscription`,
`commerce.message`. `sell.inventory` is deferred to P2 (policy auto-attach), so P1 asks for the minimum.

## 3. What the seller gets

1. **Connect** on `rentadriver.ai/ebay`: eBay sign-in (OAuth), then the usual RentADriver account link (OAuth or API
   key), pickup address typed in (eBay exposes no business address to apps; same as Squarespace).
2. **Delivery option, created for them.** We create a fulfillment policy in their eBay account:
   - name `Same-day by RentADriver` (the `RD_METHOD_NAME` match, like Squarespace)
   - service `UK_OtherCourier24` (UK) / `LocalDelivery` (US), carrier `Other`, `handlingTime 0`
   - `shipToLocations` = the seller's country (the finest eBay allows)
   - flat price the seller picks; we suggest one from the service area's rate card and show the live quote range
   
   The seller attaches it to the listings they want (Seller Hub, or P2 auto-attach). We also give them one line of
   listing copy: "Same-day delivery in <city> by RentADriver for orders placed before <cut-off>".
3. **Orders.** Each paid order that chose the RentADriver service appears in the merchant app's Orders tab within a
   minute or two. The seller's booking rule applies: `manual` (default), `all_paid`, or `rate_only` (= paid with our
   service). `fulfilled` is not offered (`book_on_fulfilled: false`).
4. **Out-of-area guard (the eBay-specific part).** eBay cannot restrict the option to a city, so any buyer in the
   country may pick it. Every eligible order is **priced before booking**. Outside the area, or no supply for the slot
   → not booked; the order goes to **Needs action** in Today, the seller gets the alert e-mail, and one click
   "Ship it another way" clears it. Orders inside the area book normally (or auto-book).
5. **Tracking.** At pickup (or at driver assignment, `fulfill_on`), we call `createShippingFulfillment` with carrier
   `Other`, tracking number = the delivery short code without the hyphen, `shippedDate` = pickup time. eBay e-mails
   the buyer "your item has shipped". **We** text the buyer the live tracking link from the order's phone number
   (`sms_updates`, on by default for eBay because the link cannot travel through eBay). We also post a plain-text
   eBay message without a link ("Your order is on its way with a local courier today; you'll get a text with live
   tracking").
6. **Proof of delivery** (photo / recipient name / signature / OTP) is stored on the delivery and downloadable from
   the Orders tab, because eBay never records "delivered" for an `Other` carrier. Default `proof_required` for eBay:
   `photo` + `recipient_name`; `signature` recommended above $750 / £450 (eBay's Money Back Guarantee thresholds).
7. **Upgrade any order.** From Orders, the seller can book same-day for *any* open order inside the area, including
   ones where the buyer chose standard post (seller pays, no change to what the buyer paid). This is the use case
   sellers ask for most on eBay's forums ("can I hand-deliver / use my own courier").
8. **Cancellation.** eBay cancellation → cancel the delivery if not yet picked up (existing `onCancelled` path);
   after pickup → return to store.
9. **Returns.** Manual "Return to store" as on every platform. When eBay's `ORDER_RETURN_ACTIVITY` arrives we show the
   return in Orders; whether we can attach our tracking via "Add Shipping Label Info" is a spike item.
10. **Disconnect.** Seller revokes in eBay (`AUTHORIZATION_REVOCATION`) or from the app; tokens dropped, polling stops.

What the seller must be told at connect time (plain words, in the app and on the website page):
- The option shows to buyers as "Other 24 Hour Courier" / "Local Delivery" with your flat price; eBay has no
  "same-day" label. Say it in the listing text.
- eBay will show the tracking number but cannot link to it; your buyer gets the link by text message.
- eBay cannot verify our tracking. US sellers: this counts against the Top Rated "validated tracking" rate; UK
  sellers: no metric is affected, but keep proof of delivery for any "item not received" case.
- UK private sellers cannot use this (Simple Delivery).

## 4. Shared-layer design

### 4.1 Platform entry

```ts
ebay: { name: "eBay", embedded: false, live_rates: "none", remote_orders: true, pickup_address_required: true,
  store_key_label: "eBay user id", frame_ancestors: [], order_id_example: "12-34567-89012",
  book_on_fulfilled: false, geocode_dropoffs: true, manual_requires_paid: true,
  order_intake: "poll" /* new field, see 4.3 */ }
```

`ROUTED_PLATFORMS` gains `"ebay"`. Store key = eBay `userId` (immutable; usernames are being replaced by ids for
developers since 2025-09-26).

### 4.2 Adapter `services/commerce/ebay.ts` (+ pure helpers `lib/commerce/ebay.ts`)

| Member | eBay implementation |
|---|---|
| `enabled()` | `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET`, `EBAY_RU_NAME`, `EBAY_ENV` (sandbox/production), `EBAY_DELETION_TOKEN` |
| `mountRoutes` | `/install` (consent URL, state cookie), `/callback` (code → tokens, `getUser`, `installStore("ebay", userId)`), `/session/*` as Squarespace, `/account-deletion` (GET challenge + POST notification) |
| `verifyWebhook` | `X-EBAY-SIGNATURE` ECC check against the cached `getPublicKey` result; topic from `metadata.topic`; `"ignore"` for topics we do not use |
| `fetchProfile` | `getUser` (Commerce Identity) for name/e-mail; currency/country from the marketplace; `test` = sandbox env; no pickup locations (merchant-typed) |
| `provision` | Idempotent: ensure the notification destination + subscriptions (`ORDER_CONFIRMATION`, `ORDER_CANCELLATION_ACTIVITY`, `ORDER_RETURN_ACTIVITY`, `AUTHORIZATION_REVOCATION`); ensure the `Same-day by RentADriver` fulfillment policy (create or update, P1 creates it, seller attaches). Returns `live_rates_unavailable: true` and a warning if the seller is not opted in to Business Policies |
| `fetchOrder` | `getOrder` → `CommerceOrder`: `rd_shipping_chosen` = `shippingServiceCode` ∈ our policy's codes **or** `fulfillmentStartInstructions[].shippingStep.shippingServiceCode` matches and the policy name is ours; buyer phone from `shipTo.primaryPhone`; `paid` = `orderPaymentStatus = PAID`; line items with weights when the listing exposes them |
| `handleEvent` | `ORDER_CONFIRMATION` → `{kind:"order"}` after a live re-read; cancellation → `cancel`; return activity → `return_signal` (design B); revocation → `uninstall`; `MARKETPLACE_ACCOUNT_DELETION` → `redact_customer` |
| `listOpenOrders` | `getOrders?filter=orderfulfillmentstatus:{NOT_STARTED|IN_PROGRESS}` (manual Orders tab, also the poll source) |
| `onDeliveryEvent` | `picked_up` (or `assigned` per `fulfill_on`) → `createShippingFulfillment` once (check existing fulfillments for our tracking number first); `no_driver_found`/`failed` → nothing on eBay (no note API), e-mail + Needs action; `delivered` → optional plain-text eBay message |
| `onBooked` | Plain-text eBay message (no link) if `notify_customer`; SMS with link via the shared notifier |
| `markNeedsAction` / `clearNeedsAction` | In-app only (eBay has no order note/tag API); same as Squarespace |
| `tick` (hourly) | Refresh the access token when < 15 min left (2 h tokens; refresh token 18 months → `reconnect_required` on failure); re-verify the notification destination is not `MARKED_DOWN`; re-create it if so |
| `testPlan` | Sandbox seller + sandbox buyer order; tracking write-back; account-deletion challenge |

### 4.3 New shared pieces (first consumers: eBay)

1. **Order polling worker** `workers/commerce-poll.ts`: every 2 minutes for stores whose platform has
   `order_intake: "poll"`, call `listOpenOrders` with `lastmodifieddate > last_poll_at - 5 min`, and
   `enqueueEvent(platform, event_id = "poll:<orderId>:<lastModifiedDate>")`. Dedup on the existing
   `commerce_events (platform, event_id)` key makes the push + poll overlap harmless. Lease-based like the other workers.
   Budget: 15 stores × 720 polls/day ≈ 11 000 calls/day, inside the 100 000 default.
2. **Needs action** state and merchant actions from the shared delivery-exceptions design (A3/A4/A7): eBay needs it
   for the out-of-area guard before any no-driver policy work, so build the state + the "Ship it another way" action
   first; the early-warning timer and `no_driver_policy` follow on their own schedule.
3. **Pre-booking area check** in `bookOrder` for platforms with `live_rates: "none"`: quote first; `out_of_area` and
   `no_supply` become distinct `last_error` reasons surfaced in Today and the alert e-mail (today Squarespace just
   fails the booking).
4. **Buyer SMS with tracking link** on booking/pickup for platforms that cannot carry a link (`info.tracking_link:
   "sms_only"`): reuse the delivery notifications; the seller's `sms_updates` setting governs it; the phone is kept only
   on the delivery row and purged by the existing retention job (eBay licence: purpose-bound deletion).
5. **Account-deletion notification** handler: shared `redact_customer` already exists for Shopify GDPR topics; eBay
   reuses it with its own challenge handshake.

### 4.4 Data changes (one migration, `01xx_ebay.sql`)

- `commerce_stores_platform_check`, `deliveries_created_via_check`, the sandbox triggers and the test-order RPC
  gain `'ebay'`.
- `commerce_stores.last_poll_at timestamptz` (null for push-only platforms).
- No new tables. Returns table only if design B lands first.

### 4.5 Everything else that lists platforms

Every file that lists platforms (API, merchant app, website, Ops, MCP) gains `ebay`, and the plugin version for
`ebay` starts at `0.1.0`.

Settings defaults for eBay (`validateSettings`): `auto_book: manual`, `sms_updates: true`, `notify_customer: true`
(plain-text eBay message), `proof_required: [photo, recipient_name]`, `fulfill_on: picked_up`, `offer_next_day: true`
(UK code is "24 hour courier", so next-morning is honest), pricing section hidden (flat price lives in eBay, as
Squarespace).

## 5. Phases

| Phase | Scope | Exit |
|---|---|---|
| **P0 spike** (2–3 days) | Developer account + sandbox keyset; account-deletion endpoint on `api.rentadriver.ai/v1/ebay/account-deletion`; sandbox seller + buyer; verify the **verify** rows in §2: `getShippingServices` output for `Other`/`LocalDelivery`/`UK_OtherCourier24` (flat-rate allowed, category), `createShippingFulfillment` with `Other` + what the sandbox buyer sees, Message API without links, `ITEM_MARKED_SHIPPED` scope, Add Shipping Label Info for a third-party label, Business Policies opt-in via API | Written go/no-go recorded in this folder, with the spike results |
| **P1 pilot** | §3 items 1–6, 8, 10; §4.3 items 1–3, 5; migration; merchant app page; Ops names/icon; website page as `beta`; 1–2 friendly sellers (one UK business seller in Northampton, one US) | 20 real deliveries, no unbooked out-of-area order reaching a driver, tracking visible on eBay for every delivered order |
| **P2** | Policy auto-attach to selected listings (`sell.inventory`), "upgrade any order", proof-of-delivery download, Reports tab, Application Growth Check if needed | Website `live` |
| **P3** | Returns via `ORDER_RETURN_ACTIVITY` + Add Shipping Label Info (if P0 confirms it), customer delay notice, `no_driver_policy` once design A ships | — |

Sizes: P0 S, P1 M–L (polling worker + Needs action are the bulk, and both are shared), P2 M, P3 S–M.

## 6. Risks

1. **Unvalidated tracking.** `Other` never validates, so US sellers lose Top Rated eligibility on those orders and
   lose eBay's late-shipment/INR seller protection; UK sellers lose nothing measurable. Mitigation: say it plainly,
   keep proof of delivery, recommend signature above the MBG thresholds, target UK business sellers first. There is
   no public "become an integrated carrier" programme; eBay adds carriers itself.
2. **Flat price, national reach.** Buyers anywhere in the country can pick the option. Mitigation: the pre-booking
   area check + Needs action + the listing-copy line; the seller sets the price, so a wrong pick costs them a
   relist of the shipping method, not a delivery.
3. **Phone-number use.** Permitted for transaction delivery by eBay's own privacy notice, but the SMS is sent by
   us on the seller's behalf. Mitigation: SMS copy names the seller ("<Seller> has sent your eBay order with a local
   courier"), no marketing, purge after delivery; `commerce.message` plain-text fallback for buyers without a number.
4. **Moving APIs.** `GeteBayDetails` goes in 2027 (use Metadata), Post-Order lost most return calls in 2026,
   notifications have 3 retries and no replay (hence polling).
5. **Demand.** eBay's own same-day failed in 2015 and no same-day programme exists today; the forum demand is real
   but modest. Mitigation: pilot before building P2; keep P1 to the shared pieces that also serve future marketplaces
   (Etsy, Amazon Seller Central have the same poll + flat-option shape).
6. **No directory.** Growth is outreach-led (sellers in our cities, existing merchants with eBay shops).

## 7. Open decisions (product owner)

1. Go ahead with the P0 spike? (needs an eBay developer account and the account-deletion endpoint deployed; no cost)
2. Markets for the pilot: UK business sellers only (Northampton supply) or UK + US?
3. Build the shared **Needs action** state as part of P1, or wait for the delivery-exceptions design to land first?
4. Pilot sellers: existing merchants with an eBay shop, or recruit two?
