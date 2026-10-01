# Squarespace: delivery exception scenarios

End-to-end walkthroughs for the two flows designed on 2026-09-30: an order that ends with **no driver found**,
and a **return**. Each step says who acts, what happens in Squarespace, what happens in RentADriver, and what the
Squarespace API allows. The shared behaviour (policies, settings, notices) is built once in the commerce layer; this
file is the Squarespace-specific part. Status: **design, not implemented**: nothing below ships until the product
owner confirms.

Squarespace facts these scenarios rely on (checked against developers.squarespace.com and support.squarespace.com on
2026-09-30):

| Need | What Squarespace offers apps | Permission |
|---|---|---|
| Mark an order "needs action" | Nothing. The Orders API has four endpoints: list, get, create (import) and fulfill. There is no update, note, tag, status, hold or cancel endpoint. | — |
| Leave a visible message on the order | Nothing. `fulfill` accepts only `shipments[]` + `shouldSendNotification`, and it marks the order FULFILLED, so it must never be used as a signal. | — |
| Refund the delivery charge | Nothing. No refund endpoint; refunds are issued by the merchant in the Squarespace admin. | — |
| Message the buyer directly | Nothing. (`fulfill` with `shouldSendNotification` only sends Squarespace's shipment email.) | — |
| Hide the checkout rate | Not possible. Squarespace has no live-rate API for us: the merchant created a flat shipping option ("Same-day by RentADriver") by hand, and no API changes shipping options. | — |
| Know a return was requested | Nothing. Squarespace Commerce has no return object, no RMA and no return events. | — |
| Know money was refunded | `order.update` webhook (already subscribed) fires with `data.update = "REFUNDED"` on **any** refund, partial or full; the order then carries `refundedTotal` vs `grandTotal`. No line-level refund detail. The Transactions API has `TransactionPayment.refunds[]` (money only, per payment, only for refunds issued in Squarespace) but needs a separate transactions permission, i.e. every merchant would have to re-consent. | `website.orders.read` (held) |
| Return labels / return tracking | Not exposed. Squarespace's own labels are outbound only (US, UPS/USPS, no return labels). Merchants use the AfterShip Returns Center extension, ShipStation, Shippo or Easyship; that data stays in those systems. | — |

**Every step below uses permissions the app already has** (`website.orders`, `website.orders.read`). No new scope,
no re-consent. The only thing Squarespace ever receives from us stays what it is today: a fulfillment with carrier
RentADriver and the tracking link, written at pickup.

---

## Scenario A: no driver found

**Setup.** A bakery in Northampton sells on Squarespace. Checkout showed the flat option "Same-day by RentADriver".
The order is paid, automatic booking is on (`auto_book = rate_only`: paid orders that chose the RentADriver option), the merchant's policy is
`no_driver_policy = notify` (the default), early warning after 30 min.

1. **Customer pays.** Squarespace sends `order.create`; RentADriver re-reads the live order, books the delivery and
   holds the price in the merchant's wallet. Squarespace order: `PENDING`. Nothing is written to Squarespace yet.
2. **Search runs.** Dispatch sends offers in waves. Nothing changes in Squarespace.
3. **Early warning (after N minutes, no acceptance).**
   - RentADriver: the merchant app's Today tab shows the order under *Needs action*; the merchant gets an email.
     One-click actions: **Keep searching**, **Retry with a price boost** (wallet, capped), **Switch to tomorrow
     morning**, **Fulfil another way**.
   - Squarespace: **nothing**. There is no note, tag or status an app can set. The in-app state and the email are the
     only signals, so the email must name the order number the merchant sees in Squarespace.
4. **Merchant decides** (or the policy decides for them):
   - *Keep searching* → nothing changes until the deadline.
   - *Retry with boost* → new search at a higher price; the extra is held from the wallet. Needs the policy
     `retry(K, boost%)` or an explicit click.
   - *Tomorrow morning* → re-booked as a scheduled delivery. The checkout option is literally called "Same-day", so
     this is only offered together with the customer notice (step 6), never as a silent policy.
   - *Fulfil another way* → the RentADriver delivery is cancelled and the hold released. The Squarespace order stays
     `PENDING` for the merchant's own courier; we never mark it fulfilled.
5. **A driver accepts later.** RentADriver: the order leaves *Needs action*. Squarespace: fulfillment and tracking are
   written at pickup, as today.
6. **Customer notice (optional, merchant setting, off by default).** Squarespace gives apps no way to message the
   buyer, so RentADriver sends its own SMS/email to the order's contact ("your delivery is delayed / moved to
   tomorrow morning"), honouring `sms_updates` / the delivery's `notify_recipient` flag. It is the merchant's
   customer and our name on the message, hence opt-in.
7. **Deadline passes with no driver** (ASAP: 24 h). RentADriver: status `no_driver_found`, hold released, merchant
   email. Squarespace: nothing; the order stays `PENDING`.
8. **Refund the delivery charge.** Not possible through the API. RentADriver's order page shows *Refund the delivery
   charge in Squarespace* with a deep link to the order in the Squarespace admin and the amount the customer paid
   for shipping.
9. **Dead zone.** The checkout rate cannot be hidden. When a zone has had no supply for a sustained period, the
   merchant app and email show a warning ("no drivers in your area right now; consider turning off the RentADriver
   shipping option") with the existing **Open shipping settings** button. Shared code marks this as
   platform-unsupported through a capability flag, not a per-platform `if`.

**Squarespace-specific limits:** nothing can be written to the order except the pickup fulfillment; no refund, no
buyer messaging, no rate control. Everything else lives in the RentADriver app and email.

---

## Scenario B: a return

**Setup.** The same bakery; a customer wants to send back a cake stand. Settings: `auto_book_returns = off`
(recommended default for Squarespace), return method default `rentadriver_scheduled`. A per-store *default return
method* lets stores that already use AfterShip or ShipStation returns choose `other_carrier`, so they are never offered
a RentADriver pickup.

Return methods (shared design):

| Method | What RentADriver does | What happens in Squarespace |
|---|---|---|
| `rentadriver_scheduled` (default) | Books a cheaper pickup window, customer → store, from the original delivery address to the store's `pickup_address` | Nothing (no field for return tracking) |
| `rentadriver_today` / `rentadriver_asap` | Same, same-day or as soon as possible | Nothing |
| `other_carrier` | No booking. Records the carrier and tracking the merchant types in | Nothing |
| `customer_dropoff` | No booking. Records it | Nothing |

1. **Customer asks to return.** Squarespace has no return-request step: the customer contacts the shop, or uses a
   return app such as the AfterShip Returns Center (its own portal and labels). RentADriver sees none of this.
2. **Merchant starts the return in RentADriver** (the main path): order page → **Return to store** → picks the
   method (default *scheduled*) and the items.
   - RentADriver methods → a return delivery is booked from the address we delivered to, back to the store's pickup
     address, and paid from the wallet. The merchant and customer follow it on the RentADriver tracking page.
   - *Other carrier* → nothing is booked; carrier and tracking are recorded in RentADriver only.
   - *Customer drop-off* → recorded only.
3. **Driver collects and delivers to the store.** RentADriver: the return leg completes with proof. Squarespace:
   nothing; the original order keeps its outbound fulfillment.
4. **Merchant refunds in Squarespace** (admin). This produces Squarespace's only related signal: `order.update` with
   `data.update = "REFUNDED"`.
5. **Suggested return (only if the merchant switched it on).** A refund is money, not items: merchants refund for
   goodwill or price adjustments, refund orders that never shipped, and often refund after the item is already back.
   So the signal only creates a **suggestion** the merchant confirms, never an automatic booking, and only when all of
   these hold:
   - `order.update` with `data.update = "REFUNDED"`;
   - the order had a **delivered** RentADriver delivery;
   - `refundedTotal >= grandTotal` (a full refund; partial refunds say nothing about items);
   - no return exists yet for the order.
   Items = all original lines (Squarespace gives no line-level refund data). Idempotency key
   `squarespace:<websiteId>:<orderId>:refund`: one suggestion per order. The card offers the merchant's default return
   method and the others.
6. **Squarespace side configuration:** none. `order.update` is already subscribed and read with the scopes we hold.

**Squarespace-specific limits:** no return request or return event, no return labels or return tracking through the
API, no line-level refund data; the only signal is a refund, which is too weak to book on its own.

---

## Open questions for the product owner

- Default `no_driver_policy` for Squarespace (notify / retry with boost / next day).
- Whether to offer the suggested return at all, or keep Squarespace returns manual-only.
- Whether the optional customer delay notice should be offered on Squarespace, where it is always sent under our name.

Sources: [Orders API overview](https://developers.squarespace.com/commerce-apis/orders-overview),
[Fulfill order](https://developers.squarespace.com/commerce-apis/fulfill-order),
[Transactions API overview](https://developers.squarespace.com/commerce-apis/transactions-overview),
[Webhooks overview](https://developers.squarespace.com/webhooks/overview),
[Buy and print shipping labels](https://support.squarespace.com/hc/en-us/articles/4412654332941-Buy-and-print-shipping-labels),
[Ship orders using ShipStation](https://support.squarespace.com/hc/en-us/articles/205826078-Connecting-Squarespace-to-ShipStation),
[Fulfilling orders](https://support.squarespace.com/hc/en-us/articles/206540697-Fulfilling-orders).
