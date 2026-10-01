# App Store review — what to paste where

The Dev Dashboard "App Store review" page (Distribution → App Store) cannot be driven from the CLI or the Admin API:
icon upload, listing content, capabilities, the automated checks and submission are dashboard-only. Everything below is
prepared so each step is a paste or an upload. App: **RentADriver** (id `418983280641`, client `7e61891f27ac51c344b7805ea1bf2819`)
in **EFIRITY PTE. LTD.**; contact email `support@rentadriver.ai`.

## 1. App icon (App settings → App icon)
Upload `listing/icon-1200.png` (1200×1200 PNG, Signal blue with the website’s Waypoint mark, square corners — Shopify applies its own
mask). `listing/icon.html` is the matching browser preview.

Use `./listing/app-store-listing.md` for the current listing copy; the dashboard notes below are historical preparation notes.

## 2. Listing content (English)

**App name** (30 max): `RentADriver Same-day Delivery`
**Tagline / subtitle** (62 max): `Same-day local delivery by vetted drivers, live at checkout`
**App introduction** (100 max): `A live same-day delivery rate at checkout, a vetted driver booked when the order is paid, tracking and proof on the order.`

**App details** (500 max):
```
RentADriver adds same-day local delivery to your store without a fleet or a contract. Customers inside your delivery radius see "Same-day by RentADriver" with a live price at checkout. When the order is paid, a vetted driver is booked automatically; the order is fulfilled with a tracking link at pickup, out-for-delivery and delivered events land on the order timeline, and a proof photo stays on file. Set your radius, cut-off time, prep time, size limit and what the customer pays (pass-through, flat or free over a basket size). No subscription — pay per delivery from a prepaid wallet.
```

**Key features / benefits** (3–5, title 40 max + text 80–100):
1. `Live rate at checkout` — Per-address price from your store to the customer's door, only inside your radius and before your cut-off.
2. `Booked the moment it is paid` — Orders paid with the RentADriver rate are dispatched to vetted local drivers automatically; any paid order can be booked by hand.
3. `Fulfillment and tracking done for you` — The order is fulfilled with a tracking link at pickup; delivered and proof-photo events post to the order.
4. `You control the customer price` — Pass the quote through, mark it up or down, charge a flat fee, or make it free over a basket size.
5. `Works for AI agents too` — The same account exposes an API and MCP tools, so an assistant can book Shopify orders for you.

**Categories**: Orders and shipping → Shipping rate calculators; Fulfilling orders → Local delivery / same-day delivery.
**Languages**: English (app UI); website in en, es, fr, de, pt, ru, ja.
**Works with**: Shopify checkout (carrier-calculated rates), Shopify Flow (via REST), AI agents (MCP).

**Pricing**: Free to install. Usage-based: pay per delivery, quoted before booking, charged to a prepaid wallet (card or USDC). Typical short urban delivery 12–18 in local currency. The Test tab rehearses quotes and bookings with simulated drivers at no cost before the wallet is topped up.

**Support**: support@rentadriver.ai · https://rentadriver.ai/faq · Privacy: https://rentadriver.ai/privacy · Terms: https://rentadriver.ai/terms · Security: https://rentadriver.ai/security
**Developer / API contact**: support@rentadriver.ai (already set; must not contain "shopify").
**Demo store URL**: `https://<demo store>.myshopify.com` (once the store address is inside a launch city and the flat "Same-day by RentADriver" rate exists).

**Screenshots** (1600×900, 3–6): take them from the embedded app on the dev store once it is provisioned — Overview
(coverage/wallet/week), Orders (Shopify list + booked deliveries), Settings (pricing rule), Test (rate preview), plus the
checkout showing the rate and an order page with the fulfillment/tracking events. Feature image 1600×900: the site's
`/integrations/shopify` hero block works as a source.

## 3. Capabilities (personalised review requirements)
Select: **Shipping rates / carrier service**, **Fulfillment (creates fulfillments and tracking)**, **Reads orders**,
**Embedded app**. Do not select: checkout UI extensions, theme app extensions, POS, payments, subscriptions, customer accounts.

## 4. Automated checks (become runnable after the icon is set)
The app already satisfies each check:
- *Authenticates immediately after install* — Shopify-managed installation + token exchange on first load (`POST /v1/shopify/session`); classic OAuth path for non-embedded installs (`/v1/shopify/install` → `/callback`).
- *Redirects to the app UI after authentication* — callback redirects to `https://<shop>/admin/apps/rentadriver`.
- *Compliance webhooks* — `customers/data_request`, `customers/redact`, `shop/redact` declared in the TOML at `/v1/shopify/webhooks`; verified HMAC → 200, invalid → 401.
- *HMAC verification* — every webhook is checked against the app secret (`lib/shopify.ts#verifyWebhookHmac`, unit-tested).

## 5. Reviewer instructions (paste into "Testing instructions")
```
1. Install the app on any development store from the listing (or open https://rentadriver.ai/shopify and enter the store domain).
2. The app opens embedded in admin. On first load it links the store, geocodes the store address and registers the carrier service and webhooks; a banner explains anything the store plan does not support (e.g. carrier-calculated rates on plans without third-party rates — booking from the Orders tab works on every plan).
3. Use the "Test" tab to rehearse with simulated drivers before depositing; live deliveries need a topped-up wallet. Deliveries are only dispatched inside our launch cities (Melbourne, Sydney, Brisbane, London, Birmingham, Toronto); for a store elsewhere use the "Test" tab to see the rate logic, or set the store address to one of those cities.
4. Place a paid test order (Bogus Gateway) with the "Same-day by RentADriver" rate, or open Orders → "Book delivery" on any paid order. The order is tagged rd:<code>, gets a rentadriver.delivery metafield and, once a driver picks it up, a fulfillment with tracking https://rentadriver.ai/track/<code>.
5. Settings lets you change radius, cut-off, prep time, size limit, customer price rule and proof requirements; Overview → Sync re-runs provisioning.
No merchant login is required beyond the Shopify session. Support: support@rentadriver.ai.
```

## 6. Submit
After the checks pass and the listing is saved, "Submit for review" becomes active. Review typically asks for a screen
recording of install → checkout rate → order booked.
