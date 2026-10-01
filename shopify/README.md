# RentADriver app for Shopify

Same-day local delivery inside Shopify: a live **carrier-service rate at checkout**, automatic **booking when the order is
paid**, **fulfillment + tracking link** when the driver picks up, and **fulfillment events** (out for delivery, delivered,
failed) on the order timeline. Merchants manage radius, cut-off, prep time, size limit, customer price rule and proof
requirements from an embedded admin page; AI agents can list and book Shopify orders through the API/MCP.

There is no separate app process. Everything runs in the monorepo:

| Piece | Where |
|---|---|
| Embedded admin UI (App Bridge) | `apps/web/src/app/shopify/` → `https://rentadriver.ai/shopify` |
| Install (OAuth), token exchange, webhooks, carrier rates, merchant + agent endpoints | `apps/api/src/routes/shopify.ts` (`/v1/shopify/*`) |
| Business logic (provisioning, rates, booking, fulfillment sync, webhook queue) | `apps/api/src/services/shopify.ts` |
| Pure helpers + unit tests (HMAC, session tokens, token crypto, pricing/size/window rules) | `apps/api/src/lib/shopify.ts`, `shopify.test.ts` |
| Schema | `supabase/migrations/0014_shopify.sql` (`shopify_shops`, `shopify_orders`, `shopify_events`, `shopify_rate_requests`) |
| MCP tools | `list_shopify_orders`, `book_shopify_order` (`apps/mcp/src/tools.ts`) |
| Shopify CLI config | `shopify.app.toml` (this folder) |

## The app

The app is **RentADriver** (client id `7e61891f27ac51c344b7805ea1bf2819`) in the EFIRITY PTE. LTD. organization.

It is created and configured with the Shopify CLI (`npm i -g @shopify/cli`). Log in with the organization account
(`shopify auth login`). If the CLI errors "Cannot find a valid organization", run `shopify auth logout && shopify auth login`.

Day-to-day with the CLI, from this folder:
- `shopify app config link --client-id 7e61891f27ac51c344b7805ea1bf2819 --file-name shopify.app.toml --force` links this
  folder to the app. Linking overwrites the TOML with the app's live config, so re-add anything the remote lacks (the second
  redirect URL and the compliance webhook subscriptions) before deploying.
- `shopify app deploy --no-color --allow-updates --message "…"` pushes `shopify.app.toml` (URLs, scopes, webhooks) as a new
  released app version — the only way config changes reach Shopify. In a non-interactive shell it requires one of
  `--allow-updates` / `--allow-deletes` / `--no-release`; `--force` is not a valid flag on CLI 4.x.
- Set `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` in the API environment; never commit them.
- `shopify app dev` (needs a development store) tunnels the app for an install test.

## One-time setup still done by hand (Dev Dashboard)

1. ~~Create the app~~ — done (above).
2. ~~App URL / redirect URLs / embedded~~ — pushed from the TOML.
3. ~~Access scopes~~ — pushed from the TOML: `read_orders, write_orders, read_shipping, write_shipping, read_fulfillments,
   write_fulfillments, read_merchant_managed_fulfillment_orders, write_merchant_managed_fulfillment_orders, read_locations`.
4. **Protected customer data**: request access (orders carry the shipping address, name and phone — needed to deliver).
5. ~~Compliance webhooks~~ — pushed from the TOML.
6. Apply the Shopify migration and configure the Shopify key/secret on the API.
7. Create a development store in the Dev Dashboard for install testing (`shopify app dev`).

## Install flows

- **Embedded / managed installation** (default): the merchant opens the app in Shopify admin → `/shopify` loads App Bridge
  → the page calls `POST /v1/shopify/session { id_token }` → the API verifies the session token, exchanges it for an offline
  access token (token exchange), stores it encrypted (`SHOPIFY_TOKEN_KEY`) and provisions.
- **Classic OAuth** (custom apps, non-embedded): `GET /v1/shopify/install?shop=store.myshopify.com` → Shopify consent →
  `GET /v1/shopify/callback` (HMAC + state cookie) → same `installShop()`. The `shop` value is only ever the one Shopify
  itself appends to the app URL: App Store review requirement 2.3.1 forbids asking a merchant to type their store
  domain, so the website links to the App Store listing (`apps/web/src/lib/shopify-install.ts`) and never renders an
  input for it.

Provisioning (`provisionShop`, re-runnable from the app's *Sync* button): shop profile + primary location (geocoded, matched
to a service area), carrier service `RentADriver` pointing at `/v1/shopify/rates/<shop_id>/<callback_token>`, and the
order/app webhook subscriptions. Errors land in `shopify_shops.last_error` and are shown in the app.

A RentADriver **organization** is created for the shop on first install (`metadata.shopify_managed`), email pre-verified;
the merchant funds it from the app (Stripe Checkout opens in the top window) or links an existing account by API key.

## Checkout rates (carrier service)

Shopify POSTs `{ rate: { origin, destination, items, currency, order_totals } }`. `ratesFor()` returns `[]` (no option
shown) when the app is disabled, the store location is not geocoded, the basket is under `min_subtotal_cents`, the items
exceed `max_size_class` (weight-based: S ≤ 1 kg, M ≤ 8 kg, L ≤ 25 kg, else XL), it is after `cutoff_time` and
`next_day_after_cutoff` is off, the destination is outside `radius_km` or outside a service area. Otherwise it quotes with
`buildQuote` (source `shopify`, so it also counts as demand) and returns one rate: `RD_SAMEDAY` (or `RD_NEXTDAY` after the
cut-off) priced by the merchant's rule — pass-through (± `adjust_pct`), flat, or free (optionally over `free_over_cents`).
The merchant always pays RentADriver the quote; the rate is what the shopper pays them. Every request is a
`shopify_rate_requests` row. Checkout rates need a plan with third-party calculated shipping (Advanced, Plus, or annual
billing on Grow). **Without it** (Basic/Grow, and a Basic-plan dev store) the carrier service
cannot be registered; the fallback is a manual flat shipping rate named "Same-day by RentADriver" — `rdShippingLine()` matches
our codes *or* a title containing "rentadriver", so orders paid with that rate still auto-book. Manual booking works on every plan.
Protected customer data access (Dev Dashboard → App settings) is required before `orders/*` webhooks can be subscribed; neither
setting is exposed to the CLI or the Admin API.

## Group-1 features (2026-09-04)
- **Flat-rate fallback** (`ensureFlatRate`, setting `flat_rate_fallback`): for stores without carrier-calculated shipping the app creates and
  re-prices (daily, `refreshFlatRates`) a manual method `Same-day by RentADriver` in the default delivery profile's zone for the store country
  (`deliveryProfileUpdate`). Price = the pricing rule (flat / free) or, for pass-through, a sample quote to the middle of the radius. State in
  `settings.flat_rate` (method/zone/profile gids, price, synced_at, error). Switching the setting off deletes the method.
- **Tomorrow-morning rate** (`offer_next_day`, `next_day_hour`, `next_day_label`): while same-day is on, a second carrier rate `RD_NEXTDAY` is
  quoted as a scheduled run at `next_day_hour` local (cheaper via the scheduled + off-peak factors); booking schedules the delivery at that time.
- **Multi-location pickup** (`location_mode = fulfillment`): pickup = the location assigned to the order's fulfillment order (`assignedLocation`),
  stored on `shopify_orders.pickup_location`; falls back to the primary location.
- **Return to store** (`POST /app/orders/:ref/return`, `POST /orders/:ref/return` for agents): reverse leg from the last drop-off to the pickup,
  `shopify_orders.return_delivery_id` (migration 0023), tags `rentadriver:return`, `rd-return:<code>`.
- **Merchant alerts** (`merchant_alerts`): email to the store contact on needs-funds, no driver found and failed hand-off (`merchantAlert`);
  low balance is already covered by the org-level workflow. Status tags `rentadriver:picked-up|delivered|returned` on the order.
- **Development stores run in sandbox mode** (`isDevelopmentPlan(shop.plan)` → `org.test_mode`): deliveries are `is_test`, ride the demo
  drivers, never touch the wallet — the same thing a test API key does.

## Orders → deliveries

`orders/paid` → `bookOrder()`: with `auto_book = rate_only` (default) only orders whose shipping line is one of our codes
are booked; `all_paid` books every paid order inside the radius/size limits; `fulfilled` waits for the merchant to pick and
pack (the driver is booked from `orders/fulfilled`, only when the order is both paid and fully fulfilled — `partial` is not
enough); `manual` never books automatically. In `fulfilled` mode there is no open fulfillment order left at pickup, so the
tracking number and link are written onto the merchant's existing fulfillment with `fulfillmentTrackingInfoUpdate` instead
of creating one; our own fulfillment fires `orders/fulfilled` too, which is harmless because `bookOrder` is idempotent.
Shops installed before the mode existed have no `orders/fulfilled` subscription — switching to it re-runs `ensureWebhooks`
(the PATCH reports a `webhook_error` if Shopify refuses), and the app's *Sync* button does the same. Pickup =
store location (+ `pickup_contact_*`, `driver_instructions`), drop-off = shipping address (lat/lng from Shopify when
present), items from the line items, `external_ref` = order name, `idempotency_key` = `shopify:<shop>:<order_id>`,
`created_via = 'shopify'`. Same-day orders dispatch immediately (scheduled `prep_minutes` ahead when > 45 min); next-day
orders are scheduled. The order gets tags `RentADriver`, `rd:<short_code>` and a `rentadriver.delivery` JSON metafield.
`insufficient_funds` / `spending_cap` → status `needs_funds` + tag `rentadriver:needs-funds` + ops alert; the app shows a
*Top up* button. Merchants (app) and agents (`POST /v1/shopify/orders/:ref/book`, MCP `book_shopify_order`) can book any
order by name, including ones placed before install (fetched from Shopify on demand). `orders/cancelled` / `orders/updated`
with `cancelled_at` cancel the delivery when it has not been picked up.

## Delivery → Shopify

`startShopifySync()` (worker process) subscribes to the in-process event bus. For deliveries mapped to a Shopify order:
`picked_up` (or `assigned` when `fulfill_on = assigned`) creates the fulfillment for all open fulfillment orders with tracking
`RentADriver / <short_code> / <tracking_url>` (`notify_customer` controls Shopify's shipping email); then fulfillment events
`IN_TRANSIT` (picked up), `OUT_FOR_DELIVERY` (en route / arrived), `DELIVERED`, `FAILURE` (failed / returned) with the driver
position; `cancelled` / `no_driver_found` tag the order and mark the row. The metafield is refreshed on every event.

## Webhooks

`POST /v1/shopify/webhooks` verifies `X-Shopify-Hmac-Sha256` (base64 HMAC-SHA256 of the raw body with the app secret),
dedupes on `X-Shopify-Webhook-Id` (`shopify_events`), answers 200 immediately and processes in the background; the
`shopify` worker (every 20 s) retries failures with backoff (30 s → 6 h, 6 attempts). `app/uninstalled` clears the token;
`shop/redact` deletes everything for the shop; `customers/redact` blanks the affected order rows. Retention (housekeeping worker):
recipient PII on `shopify_orders` is blanked 90 days after the order, processed webhook payloads are deleted after 30 days.

## Local development

The app opens on **Orders**. The menu follows daily shop work: **Orders → Live deliveries → Wallets → Reports → Settings**.
Orders holds booking issues, search, price reviews, and bookings. Live deliveries holds today's map and recent activity.
Reports owns the performance charts. Store setup, checkout configuration, customer pricing, notifications, account linking,
and support live under Settings. Old `/shopify/today` and `/shopify/analytics` bookmarks remain supported.

Wallets shows a top-up card for every enabled country workspace, each with its own currency, balance and amount
selection. Every checkout explicitly identifies the chosen workspace; it does not rely on the store's default wallet.
Sandbox stores show balances but disable deposits. Enable additional countries from the RentADriver console.

The embedded workspace includes **Live deliveries** at `/shopify/deliveries`: active deliveries due today (including overdue work),
completed deliveries on the store's local date, and orders needing attention. The list and map refresh every 15 seconds;
driver markers require a location update within two minutes. The list remains usable if the map cannot load.
Opening an order shows delivery stops, contacts, instructions, driver, timeline and signed proof-of-handover links.

Manual booking from the Orders tab now opens a price review for up to 25 orders. It separates the customer's shipping
payment from the merchant's delivery cost, checks the combined wallet requirement, and requires confirmation of an
expiring quote before holding funds. Each order remains a separate delivery. If a selection partially fails, successful
orders stay booked and refreshing prices retries only the remaining orders. An unfunded delivery retains its original
quote; if that quote expires, cancel the unfunded delivery from Orders before reviewing a new booking.

Automated checks use synthetic API fixtures. A real development-store check is still required for App Bridge navigation,
live driver locations, wallet funding, and fulfillment updates. Combined multi-order routes remain separate follow-up work. Outbound fulfillment synchronization uses a durable per-order queue.

**What local serving cannot show:** the sidebar navigation (`s-app-nav`), because App Bridge only registers those
elements inside the Shopify admin frame — locally the element stays undefined and is hidden by CSS. To see it, expose the
web app over an https tunnel and point a **development** app's App URL at `<tunnel>/shopify`, then set that dev app's key and secret in
the API environment so App Bridge and the API agree on which app is talking. Never repoint the production app's URLs at a tunnel:
those URLs serve real merchants and the carrier-rate callback would go with them.

### Country workspaces

Delivery pricing and merchant wallet funding follow the pickup country, independently of the shop’s retail currency. Enable each pickup country under the linked RentADriver account. Managed stores provision their primary pickup workspace without relabeling existing balances. Carrier rate responses label customer and quote currencies separately; fixed fees remain in shop currency.

## Sandbox API keys

Connect your own account’s `rd_test_…` administrator key to rehearse deliveries without adding wallet funds. The app labels sandbox mode; simulated jobs stay in RentADriver and never fulfill or complete real store orders. Changing mode pauses checkout rates and auto-booking.

## Linked RentADriver account

Settings shows the linked account ID and contact email. Use **Open console** to sign into that account in a separate tab while preserving your other console tabs and the store’s sandbox/live mode.
