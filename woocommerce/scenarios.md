# WooCommerce: delivery exception scenarios

End-to-end walkthroughs for the two flows designed on 2026-09-30: an order that ends with **no driver found**, and a
**return**. Each step says who acts, what happens in WooCommerce, what happens in RentADriver, and what WooCommerce
allows. The shared behaviour (policies, settings, notices) is built once in the commerce layer;
this file is the WooCommerce-specific part. Status: **design, not
implemented**. Not yet scheduled. Items marked **VERIFY** come from knowledge of the
WooCommerce ecosystem and must be checked against a real store before building.

How WooCommerce is connected (relevant to every step): the connector holds a WooCommerce REST key with
`read_write` scope (granted by the merchant at Connect, `wc-auth/v1/authorize`), receives the `order.created` /
`order.updated` webhooks, and the PHP plugin on the merchant's WordPress adds the checkout rate and admin pages.

WooCommerce facts these scenarios rely on:

| Need | What WooCommerce offers | Permission |
|---|---|---|
| Mark an order "needs action" | Core statuses only (`pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`). `on-hold` emails the customer "your order is on hold" by default, and `on-hold` → `processing` sends the Processing email again. A custom status (e.g. `wc-rentadriver-wait`, max 20 chars incl. `wc-`) needs plugin PHP (`register_post_status` + `wc_order_statuses`), drops the order out of every flow that filters on `processing` (ShipStation, ERPs, fulfilment plugins), and disappears from the orders list if our plugin is deactivated. **Recommended: do not change the status.** | REST `read_write` (held) |
| Leave a visible message on the order | Order notes: `POST /wp-json/wc/v3/orders/{id}/notes` with `customer_note: false` (private). Deletable. | held |
| Show state on the order | Order meta `_rentadriver_delivery` (already written: code, status, tracking URL), shown in our order box; a RentADriver column on the WooCommerce orders list (`manage_edit-shop_order_columns` + HPOS `woocommerce_shop_order_list_table_columns`) needs plugin PHP. | held / plugin |
| Message the buyer | `POST /orders/{id}/notes` with `customer_note: true` → WooCommerce's own "Customer note" email, in the store's branding. | held |
| Refund the delivery charge | `POST /wp-json/wc/v3/orders/{id}/refunds` with `amount`, `line_items: [{ id: <shipping line id>, refund_total, refund_tax: [...] }]`, `api_refund: true` (gateway refunds automatically, only if it supports refunds) or `false` (recorded; money returned outside WooCommerce). | held |
| Hide the checkout rate | Our plugin's `calculate_shipping()` simply returns no rate (server decides). WooCommerce caches rates per cart for 2 min in our plugin. If ours is the only method in the zone, checkout shows "No shipping options" and cannot be placed. | (plugin) |
| Know a return was requested | Nothing in core: WooCommerce has no returns object. Returns plugins add request records and/or statuses (e.g. `wc-return-approved`): WooCommerce "Returns and Warranty Requests", YITH Advanced Refund System, WP Swings "Return Refund and Exchange". **VERIFY** each plugin's statuses and whether they appear in `order.updated`. | held |
| Know items came back / money returned | Refunds: `order.updated` fires when a refund is added; a full refund moves the order to `refunded`. A refund is not proof of a return (many refunds are for orders never shipped). | held |
| Return labels / return tracking | Not in core. Labels come from ShipStation, return SaaS or WooCommerce Shipping (**VERIFY** return-label support). Tracking recorded by WooCommerce Shipment Tracking / Advanced Shipment Tracking in order meta `_wc_shipment_tracking_items` (provider, number, date) with a REST route `wc-shipment-tracking/v3/orders/{id}/shipment-trackings`. **VERIFY** whether core REST returns that underscore meta; the PHP plugin can always read it. | held |

**Every step below uses the REST scope the connector already has** (`read_write`). No new WooCommerce permission and
no re-consent. The only additions are plugin PHP (orders-list column, settings UI), which ship as a normal plugin
update and are covered by the existing WordPress.org listing rules.

---

## Scenario A: no driver found

**Setup.** A florist sells on WooCommerce with the RentADriver plugin. Checkout showed "Same-day
Delivery". The order is paid (`processing`), automatic booking is on, policy `no_driver_policy = notify` (default).

1. **Customer pays.** `order.updated` → RentADriver books the delivery, holds the price in the wallet, writes
   `_rentadriver_delivery` meta and a private note with the tracking link. Order stays `processing`.
2. **Search runs.** Nothing changes in WooCommerce.
3. **Early warning (after N minutes, relative to the pickup window, not a fixed N).**
   - RentADriver: *Needs action* in the hosted app (Today tab) and on the plugin's **Orders** tab, merchant email.
     Actions: **Keep searching**, **Retry with a price boost** (capped per store), **Switch to tomorrow morning**,
     **Fulfil another way**.
   - WooCommerce: status unchanged; meta status `no_driver_yet`; private note "RentADriver: no driver has accepted
     yet — open RentADriver to choose what to do"; the RentADriver column on the orders list shows **Needs action**.
4. **Merchant decides** (or the policy does): as in the shared design. *Fulfil another way* cancels the RentADriver
   delivery, releases the hold, clears our meta and leaves the order `processing` for the merchant's own courier.
5. **A driver accepts later.** Meta back to the delivery status, column cleared, private note "Driver found —
   delivery back on track".
6. **Customer notice (optional, per store, off by default).** A customer note (`customer_note: true`) → WooCommerce
   emails "your delivery is delayed / moved to tomorrow morning" in the store's branding. Our own SMS stays available
   for stores that prefer it.
7. **Deadline passes with no driver.** `no_driver_found`, hold released, merchant email; private note "No driver
   found — deliver it another way or book again from RentADriver"; column **No driver**. Status unchanged.
8. **Refund the delivery charge (merchant click only).** Plugin Orders tab / hosted app: *Refund the delivery charge*.
   Only when the shopper paid for delivery (skip free / flat 0). Refund = the shipping line only, with its tax.
   `api_refund: true` when the gateway supports refunds, otherwise recorded with a message "refund it in your payment
   provider". WooCommerce sends its Refunded email.
9. **Rate hidden in a dead zone (optional).** Only when the zone has another shipping method; otherwise offer the
   next-day rate instead of none. Use hysteresis: our 2-minute rate cache would otherwise make the option flicker.

**WooCommerce-specific limits:** no neutral "on hold" status without customer emails; a custom status is possible but
intrusive (see the facts table); notes are deletable by the merchant.

---

## Scenario B: a return

**Setup.** The same florist; a customer wants to send a vase back. Settings: automatic returns off by default; return
method default `rentadriver_scheduled`.

Return methods (shared design), and how they look in WooCommerce:

| Method | RentADriver | WooCommerce |
|---|---|---|
| `rentadriver_scheduled` (default) / `rentadriver_today` / `rentadriver_asap` | Books customer → store at that urgency | Private note + `_rentadriver_return` meta with the tracking link; plugin Orders tab shows the return leg |
| `other_carrier` | No booking; records carrier + tracking (typed, or read from `_wc_shipment_tracking_items`) | Private note with carrier and tracking |
| `customer_dropoff` | No booking; recorded | Private note |

1. **Customer asks.** Core has no return request: the customer contacts the shop, or a returns plugin records the
   request and (with some plugins) moves the order to a status such as "Return approved".
2. **Trigger.**
   - *Manual (main path):* plugin Orders tab or hosted app → **Arrange return** → method choice.
   - *Automatic (opt-in):* per-store setting "Arrange a return automatically when an order changes to: [status]".
     The select lists the store's own statuses (`wc_get_order_statuses()`), so returns-plugin statuses appear;
     "Refunded" is selectable with a hint that refunds also happen for orders never delivered. Server side, the
     existing `order.updated` webhook carries the status; only orders with a delivered RentADriver delivery qualify.
3. **Idempotency.** `woocommerce:<store>:<order_id>:return:<status>`: a webhook retry or a status bounce books once.
4. **Mapping.** Pickup = the order's shipping address and phone; drop-off = the pickup address override, else the
   synced store address; items = the whole order (core has no line-level return data; a returns plugin may, **VERIFY**).
5. **Trap in today's code (fix before enabling).** `normalizeWcOrder` treats `refunded` / `cancelled` as cancelled
   and `handleEvent` returns `cancel` first. The return check must run before the cancel branch, or a delivered order
   moved to Refunded becomes a cancel.
6. **Tracking / completion.** Private notes at pickup and at arrival back at the store; the plugin's order box shows
   the return leg. The refund itself stays the merchant's (WooCommerce refund screen or their returns plugin).

**Permissions for B:** none new. Reading `_wc_shipment_tracking_items` through core REST is **VERIFY**; the PHP plugin
can read it and pass it to the API if core REST hides it.
