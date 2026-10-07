# RentADriver for WooCommerce

Same-day local delivery for WooCommerce stores: a live **"Same-day by RentADriver" shipping rate at checkout** (via the
WordPress plugin in `rentadriver-delivery/`), automatic **booking when the order is paid** (`processing`), an **order note
with the tracking link** at pickup, and the order **completed on delivery**. Merchants manage radius, cut-off, prep time,
size limit, price rule and proof from the hosted app at `https://rentadriver.ai/woocommerce`; agents list and book orders
through the API / MCP (`list_store_orders`, `book_store_order` with `platform: "woocommerce"`).

It runs on the RentADriver **shared commerce layer** (no separate app process): the hosted merchant UI at
`https://rentadriver.ai/woocommerce`, the API under `/v1/woocommerce/*`, and the WordPress plugin (shipping method,
settings and order meta box) in [`rentadriver-delivery/`](rentadriver-delivery/) in this folder.

## Nothing to register on a dev portal

The WooCommerce REST connection needs no centrally registered OAuth client: the **store issues the API keys** through its REST auth endpoint. Public distribution through WordPress.org or the Woo Marketplace has separate submission requirements.
The consumer key/secret are encrypted at rest. URLs the store talks to (on `https://api.rentadriver.ai`):

| Purpose | URL |
|---|---|
| Consent start | `GET /v1/woocommerce/install?store=https://shop.example.com[&return_to=<wp-admin url>]` |
| Keys callback (server → server, JSON) | `POST /v1/woocommerce/callback` |
| Browser return after consent | `GET /v1/woocommerce/connected?nonce=…` |
| Order webhooks (`order.created`, `order.updated`) | `POST /v1/woocommerce/webhooks` — signed with the store's `callback_token` (`X-WC-Webhook-Signature`) |
| Checkout rate (plugin → us) | `POST /v1/woocommerce/rates/<store_id>/<callback_token>` |

## Install flow

1. Merchant enters the store URL on `rentadriver.ai/integrations/woocommerce`, on `/woocommerce`, or presses **Connect** in the
   plugin's settings. We create a pending store record with an install nonce and redirect to
   `https://<store>/wc-auth/v1/authorize?app_name=RentADriver&scope=read_write&user_id=<nonce>&return_url=…&callback_url=…`.
2. WooCommerce POSTs `{ key_id, user_id, consumer_key, consumer_secret }` to `/callback`; `installStore()` encrypts the keys,
   creates a RentADriver organization for the store (email pre-verified) and provisions.
3. The browser lands on `/connected` → redirected to the hosted app with a merchant session (`?session=`), or back to
   wp-admin with `rd_store_id` + `rd_token` when the plugin started the flow (the plugin saves them).

Provisioning (`provisionStore`, re-runnable from the app's **Sync** button): `GET /wp-json/` (site name, timezone) +
`wc/v3/settings/general` (store address → geocoded → service area), `settings/email` (from address), `settings/products`
(weight unit), then `wc/v3/webhooks` for the two order topics with `secret = callback_token`. Errors are stored on
the store and shown in the app.

Three things about that round trip cost real debugging time (all found connecting a test store):

| Rule | Consequence |
|---|---|
| `WC_Auth` rebuilds the access_granted URL from the request and **splits `user_id` on `&`** | anything with a query string inside the install state comes back truncated, `/callback` cannot find the pending row, and WooCommerce shows "An error occurred in the request and at the time were unable to send the consumer data" — which reads as our callback being down. The state is base64url now (`wcInstallState`/`wcParseInstallState`); never put a raw URL in it |
| `WC_Shipping_Method::has_settings()` checks `supports('settings')` for `instance_id 0` | without `'settings'` in `$this->supports` the method's own page — the **Connect button, Store ID and Rate token** — is not listed as a section and its URL renders an empty page, so the merchant cannot connect from wp-admin at all |
| The store's **Country / Region** (`woocommerce_default_country`) is what we treat as the pickup country, not the geocoded street address | a store whose address says Melbourne but whose country says United Kingdom fails the cross-border check and every checkout rate comes back with reason `outside_radius`. The plugin then also drops any rate whose currency differs from `get_woocommerce_currency()`, so country **and** currency have to match the store's real address |

## Settings live on the store record, not in WordPress

The plugin's settings page (WooCommerce → Settings → Shipping → Same-day by RentADriver) renders the **same settings the
hosted app edits** — radius, cut-off, prep time, max size, the tomorrow-morning rate, the price rule, auto-booking, pickup
override/contact/instructions, proof, fulfilment trigger, customer email, complete-on-delivered and merchant alerts.

They are read and written over `GET|PATCH /v1/<platform>/plugin/<store_id>/<rate_token>/settings`, authenticated with the
same per-store token as the rate URL (the plugin has neither a merchant session nor an API key, and already holds both
values from Connect). `wp_options` keeps only `rentadriver_store_id` and `rentadriver_rate_token`.

Nothing is cached in WordPress on purpose: the quote engine obeys the server copy, so a second copy in wp-admin would let
the two screens disagree and make the settings page lie about what checkout will do. The page therefore GETs on render and
re-reads the server's response after saving (the API clamps and merges), and a save drops the plugin's own `rentadriver_delivery_rate_*`
quote cache version — those entries key on the cart, not on the settings, so a changed radius or price rule would otherwise take two
minutes to show.

## Checkout rates (plugin)

`calculate_shipping()` POSTs `{ destination, items[{ name, quantity, weight_kg, price }], subtotal, currency }` to the rates
URL and adds one `WC_Shipping_Rate` per returned entry (`rates[] { id, label, cost, currency, description, meta.rd_rate_code }`),
cached 2 min per destination. `ratesFor()` (shared) returns nothing when the app is disabled, the store is not geocoded, the
basket is under `min_subtotal_cents`, the items exceed `max_size_class`, it is after `cutoff_time` without next-day, or the
address is outside `radius_km` / coverage. Otherwise `RD_SAMEDAY` (+ optional cheaper `RD_NEXTDAY`), priced by the merchant's
rule (pass-through ± %, flat, free over a basket size). Without the plugin a flat method titled "Same-day by RentADriver"
is matched on the order (`rdShippingChosen`).

The plugin also writes `_rd_weight_kg` / `_rd_virtual` on line items (used for size class) and shows a "RentADriver delivery"
meta box with the tracking link (from the `_rentadriver_delivery` order meta the connector writes).

## Orders → deliveries → back to the order

`order.created` / `order.updated` webhooks are HMAC-checked, deduped on `X-WC-Webhook-ID:X-WC-Webhook-Delivery-ID` and
processed by the commerce worker (20 s, backoff 30 s → 6 h). A paid order (`processing`/`completed`)
→ `bookOrder()`: idempotency `woocommerce:<store>:<order_id>`, `created_via 'woocommerce'`, statuses
`pending / skipped / booked / needs_funds / failed / cancelled`; `insufficient_funds` → `needs_funds` + merchant email + ops alert.
Cancelled orders cancel the delivery while it is still cancellable.

Delivery events (in-process bus → `startCommerceSync`): `assigned` (if `fulfill_on = assigned`) / `picked_up` → customer
note with the tracking link; `en_route_dropoff` / `at_dropoff` / `delivered` / `failed` / `returned` → notes; `delivered` →
order status `completed` when `complete_order_on_delivered`; every step refreshes `_rentadriver_delivery` meta.

## The hosted test store

A hosted test store exists for the team; ask the maintainers for access.

## Testing without Docker

Run `composer install` and `composer check` in this folder. The plugin has PHP tests (see the repository CI).
The PHP fixtures call no live APIs. The isolated Bun suite includes API replay funding, plugin packaging and stable salts.
Database regression runs against CI's disposable PostGIS service.

## Docker harness (manual exploration)

The Docker harness is for manual exploration; CI runs the automated checks. Current connector checks refuse private
addresses, HTTP and nonstandard ports: for an end-to-end test, use a disposable remote WordPress store exposed through
public HTTPS with a valid certificate, and an isolated test database/API. Expose the API callback through HTTPS as well.
Never disable TLS verification or use production database credentials.

`dev/` is a throwaway WordPress + WooCommerce store on Docker with the plugin bind-mounted, so edits on your machine are
live in the container. One command sets it up (verified from a clean slate on WordPress 7.1 / WooCommerce 11.1):

```bash
cd apps/integrations/woocommerce/dev && ./setup.sh              # --reset wipes the volumes and starts over
```
It prints the store URL, a WooCommerce REST key pair and the three commands to finish: start the API, connect the
store with the maintainers' connect helper, and paste the printed `store_id` / `rate_token` into WordPress. Admin is `admin` / `admin`.

Then exercise the plugin:

```bash
cd apps/integrations/woocommerce/dev
# the checkout rate, straight through the plugin's calculate_shipping()
docker compose run --rm cli eval 'WC()->shipping(); $p = wc_get_products(["name"=>"Test Vase","limit"=>1])[0];
  $m = new RentADriver_Shipping_Method(1);
  $m->calculate_shipping(["destination"=>["country"=>"AU","state"=>"VIC","postcode"=>"3000","city"=>"Melbourne","address"=>"Flinders Street Station","address_2"=>""],
                          "contents"=>["a"=>["data"=>$p,"quantity"=>1,"line_total"=>45.0]]]);
  foreach ($m->rates as $r) echo $r->get_label(), " = ", $r->get_cost(), "\n";'
# a paid order → webhook → delivery → note + _rentadriver_delivery meta back on the order
docker compose run --rm cli wc shop_order create --user=1 --status=processing --set_paid=true --payment_method=cod \
  --billing='{"first_name":"Alex","last_name":"Tester","phone":"+61400000000","email":"alex@example.com","country":"AU"}' \
  --shipping='{"first_name":"Alex","last_name":"Tester","address_1":"Flinders Street Station","city":"Melbourne","state":"VIC","postcode":"3000","country":"AU"}' \
  --line_items='[{"product_id":10,"quantity":1}]' \
  --shipping_lines='[{"method_id":"rentadriver_sameday","method_title":"Same-day by RentADriver","total":"16.20"}]' --porcelain
docker compose run --rm cli action-scheduler run          # wp-cli queues webhook delivery; a browser checkout fires WP-Cron itself
docker compose run --rm cli eval '$o = wc_get_order(11); echo json_encode($o->get_meta("_rentadriver_delivery")), "\n";'
```

The store is linked to the **sandbox organization** with a sandbox plan, so every delivery it books is `is_test`: demo
drivers, no wallet movement. The connect helper's `--cleanup` flag removes the store row when you are done.

Four WordPress/WooCommerce rules shape this harness — all of them cost real debugging time, so they are worth knowing:

| Rule | Consequence |
|---|---|
| `wc-auth` refuses a `callback_url` that is not https (`class-wc-auth.php`) | the **Connect button cannot reach a plain-http dev API**. The connect helper stores the key pair directly instead; to exercise the redirect itself, put an https tunnel (`ngrok http 8787`) in front and set `API_PUBLIC_URL` to it |
| WooCommerce reads REST key pairs only when `is_ssl()`, and demands OAuth 1.0a over plain http | Use public HTTPS with a valid certificate for current tests; the historical self-signed/private-IP proxy is refused by the connector |
| `wp_safe_remote_*` blocks private hosts and ports outside 80/443/8080 | webhook delivery to the API on :8787 fails with "A valid URL was not provided". `dev/mu-plugins/rd-dev.php` lifts both, and is dev-only. The plugin's own rate call uses `wp_remote_post`, which is not affected |
| `WORDPRESS_CONFIG_EXTRA` is only written when `wp-config.php` does not exist | changing the site URL in compose after the first run does nothing; `setup.sh` sets `home`/`siteurl` with wp-cli |

Everything is addressed by the machine's LAN IP so the browser, WordPress-in-Docker and the API all reach each other.

Without Docker: any WordPress with WooCommerce and the REST API enabled works, as long as it is public over https so the
callback and webhooks reach the RentADriver API. Copy `rentadriver-delivery/` into `wp-content/plugins/`, activate, add the
method to a shipping zone, press Connect.

Unit tests for the pure pieces (signatures, the wc-auth URL, order normalization, the rate contract) live in the API's
`lib/commerce.test.ts`.

## Getting the plugin to merchants

`GET /v1/woocommerce/plugin.zip` serves the plugin, built from the copy inside the api image, and the merchant app
links to it ("Download the plugin (.zip)"). WordPress.org and the WooCommerce Marketplace are submissions that still
have to be made by a human — the plugin and readme are written to pass both. **`DISTRIBUTION.md`** is
the runbook: what is live, what each submission needs, and how to bump a version.

## Not done yet
- WordPress.org listing and WooCommerce Marketplace listing — see `DISTRIBUTION.md` (submissions, not code).
- Block-checkout description under the rate (classic checkout only); pickup from multiple store locations.

## Sandbox API keys

Connect your own account’s `rd_test_…` administrator key to rehearse deliveries without adding wallet funds. The app labels sandbox mode; simulated jobs stay in RentADriver and never fulfill or complete real store orders. Changing mode pauses checkout rates and auto-booking.

## Linked RentADriver account

Settings shows the linked account ID and contact email. Use **Open console** to sign into that account in a separate tab while preserving your other console tabs and the store’s sandbox/live mode.

## Account access inside WordPress

The embedded app uses your WordPress administrator session directly. Version 0.2.5 removes the app-only Sign out button and Continue with WordPress screen. To change the connected RentADriver account, use **Settings → Link an existing account instead**. To end access to WordPress, sign out of WordPress. Standalone RentADriver app and console sessions retain their own sign-out controls.
