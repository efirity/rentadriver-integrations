> Status: **API built, not yet released (2026-10-12).** The detailed design is maintained privately.

# RentADriver for Zapiet – Pickup + Delivery

RentADriver as a **Last Mile Delivery courier** inside [Zapiet – Pickup + Delivery](https://zapiet.com), the Shopify
app merchants use to offer local delivery with a date and time slot. This is a partner connector rather than a store
plugin: nothing is installed in Shopify. Zapiet calls a small set of RentADriver endpoints using request templates
configured in the [Zapiet Partners](https://partners.zapiet.com/) portal
([Last Mile API docs](https://docs.zapiet.com/last-mile-api/last-mile-api)).

Shopify stays the system of record for the order, and Zapiet owns the checkout widget, the delivery slots and the
Shopify fulfillment. RentADriver prices the delivery, books a driver for the customer's slot, and reports the delivery
status back to Zapiet.

## How it works

1. **Checkout** — the customer picks a delivery slot in the Zapiet widget. Zapiet asks RentADriver for one live rate
   for that address and slot.
2. **Order** — when the order is paid, Zapiet sends it to RentADriver. We book a driver for the slot and answer with a
   delivery id, an `RD-…` tracking number and a tracking link.
3. **Tracking** — Zapiet adds the tracking number and link to the Shopify order and, if the merchant enables it, emails
   the customer.
4. **Status** — RentADriver pushes each status change to Zapiet; *delivered* and *cancelled* close the order in Zapiet.
5. **Cancel** — cancelling in Zapiet before pickup cancels the RentADriver delivery.

## Endpoints

All under `https://api.rentadriver.ai/v1/zapiet`, authenticated with the merchant's RentADriver API key
(`Authorization: Bearer rd_…`).

| Zapiet request | Method and path | Response |
| --- | --- | --- |
| Rate estimate | `POST /rates` | `{rate_id, price_cents, currency}` (one rate) |
| Create delivery | `POST /deliveries` | `{delivery_id, tracking_number, tracking_url}` |
| Delivery status (polling fallback) | `GET /deliveries/{id}/status` | `{status}` |
| Cancel delivery | `POST /deliveries/{id}/cancel` | `{status: "cancelled"}`, or 409 after pickup |

Status values reported to Zapiet: `scheduled`, `driver_assigned`, `out_for_delivery`, `delivered`, `cancelled`,
`failed`. Zapiet treats `delivered` and `cancelled` as final.

## Merchant setup

1. Open the RentADriver listing in Zapiet › Settings › Last mile delivery and follow *Learn more* to
   `rentadriver.ai/integrations/zapiet`.
2. Sign in, choose the workspace that pays for deliveries, enter the store's `*.myshopify.com` domain and copy the API
   key shown once.
3. In Zapiet, paste the key, choose which Zapiet locations RentADriver serves, and turn on *Add tracking details to
   Shopify order* and *Send tracking details to customer*.
4. Top up the workspace wallet. Orders placed before that are kept and booked once funds arrive.

Merchant settings shown in Zapiet: leave at door and a pickup contact phone. There is no ID check: RentADriver drivers
cannot verify a recipient's identity, so an order sent with `id_check` set is refused with `422 unsupported_identity_proof`.

## Zapiet Partners configuration

The Liquid request bodies live in [`templates/`](templates/) and are pasted into App setup › Endpoints as they are. They
are the source of truth: change them here first, then in the portal.

| App setup field | Value |
| --- | --- |
| Production / staging endpoint | `api.rentadriver.ai/v1/zapiet` |
| Content type | `application/json` |
| Auth type | Bearer token (the merchant pastes a RentADriver API key) |
| Rate estimate | Automatic · `POST /rates` · body [`rate-estimate.liquid.json`](templates/rate-estimate.liquid.json) · line item schema [`line-item.liquid.json`](templates/line-item.liquid.json) · rate identifier `rate_id` · price `price_cents` in **cents** · currency field `currency` |
| Create delivery | `POST /deliveries` · body [`create-delivery.liquid.json`](templates/create-delivery.liquid.json) · same line item schema · Delivery ID `delivery_id` · Tracking number `tracking_number` · Tracking URL `tracking_url` |
| Delivery status | Webhook from carrier (`partners.zapiet.com/webhooks/delivery-status/rentadriver`), or polling `GET /deliveries/{{ courier.delivery.id }}/status` · status key `status` · values `scheduled`, `driver_assigned`, `out_for_delivery`, `delivered`, `cancelled`, `failed` · delivered = `delivered` · cancelled = `cancelled` |
| Cancel delivery | `POST /deliveries/{{ courier.delivery.id }}/cancel`, empty body |
| Settings | `leave_at_door` (checkbox), `pickup_phone` (text) |

The templates follow Zapiet's own convention: each value is a quoted `"{{ variable }}"` (an unset one renders as `""`),
the template is valid JSON before rendering, and the quoted `"{{ line_items }}"` becomes an array of the line-item
template. Line-item variables are `line_item.*`; the price is `line_item.price_in_dollars` (`line_item.price` is in
cents). The pickup phone is Zapiet's `pickup.contact.phone`, overridden by the `pickup_phone` setting.

## Behaviour notes

- **No install.** The first request with a key creates the connection for that key's RentADriver account; a sandbox key
  (`rd_test_…`) books sandbox deliveries. Revoking the key stops the connection.
- **One order, one delivery.** Repeating a create-delivery request returns the same delivery. If the RentADriver Shopify
  app on the same account already booked that Shopify order, Zapiet gets that delivery instead of a second one.
- **The customer's slot.** The pickup is scheduled for Zapiet's pickup time (or 45 minutes before the slot) and the
  drop-off carries the slot as its arrival window. A slot already under way is booked straight away.
- **Errors.** `401` bad or missing key, `403` key without admin rights, `402` empty RentADriver wallet (Zapiet retries
  for up to 10 minutes), `422` no rate or the order cannot be booked (the message says why), `409` cancel after pickup
  or of a delivery booked outside Zapiet. Errors are `{ "success": false, "error": { "code", "message" } }`.
