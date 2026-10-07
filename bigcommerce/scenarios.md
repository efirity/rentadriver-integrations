# BigCommerce: delivery exception scenarios

End-to-end walkthroughs for the two flows designed on 2026-09-30: an order that ends with **no driver found**,
and a **return**. Each step says who acts, what happens in BigCommerce, what happens in RentADriver, and what the
BigCommerce API allows. The shared behaviour (policies, settings, notices) is built once in the commerce layer;
this file is the BigCommerce-specific part. Status: **design, not implemented** — nothing below ships until the
product owner confirms.

BigCommerce facts these scenarios rely on (checked against docs.bigcommerce.com on 2026-09-30):

| Need | What BigCommerce offers apps | Permission |
|---|---|---|
| Mark an order "needs action" | A **fixed** list of 15 statuses (`GET /v2/order_statuses`); apps cannot create or relabel one. The only fit is `12 Manual Verification Required` ("on hold while some aspect needs to be manually confirmed"), set with `PUT /v2/orders/{id}` `status_id`. A status change can email the customer (store notification settings) and changes the merchant's order lists. | Orders: modify (held) |
| Leave a visible message on the order | `staff_notes` on `PUT /v2/orders/{id}`: one free-text field, staff-only, **replaced** on write (we append to the current text, as the delivery write-back already does). | Orders: modify (held) |
| Refund the delivery charge | `POST /v3/orders/{id}/payment_actions/refund_quotes` then `/refunds` with `items: [{ item_type: "SHIPPING", item_id: <order address id>, amount }]`. The order must be paid and settled, the gateway must support refunds; offline/manual payments cannot be refunded through the API. | **Order Transactions: modify** (not held) |
| Message the buyer directly | Nothing for apps (order messages are the customer's thread). | — |
| Hide the checkout rate | Today checkout uses our native flat-rate method "Same-day by RentADriver" (store-wide, per shipping zone). Hiding it means disabling the method through the shipping API. With Shipping Provider live rates (not yet approved) the rate callback simply returns no quote. | Information & settings: modify (held) |
| Know a return was requested | Nothing: no returns / RMA API and no return webhook. Merchants use return apps whose data BigCommerce does not expose. | — |
| Know money went back | Webhook `store/order/refund/created` ("fires if a refund has been submitted against an order"), data `{ id: order id, refund.refund_id }`; order status → `4 Refunded` / `14 Partially Refunded` via `store/order/statusUpdated` (already subscribed). A refund does **not** restock and does not mean the goods came back. | Webhook: none. Refund lines (`GET /v3/orders/{id}/payment_actions/refunds`): **Order Transactions: read** (not held) |
| Return labels / return tracking | Not exposed. Return apps (AfterShip Returns, Loop, ReturnGO…) and label apps (ShipStation, ShipperHQ, Shippo) keep their data. `GET /v2/orders/{id}/shipments` holds **outbound** tracking only; writing a return as a shipment would mark the order shipped again. | — |

**Permissions.** Scenario A (notes, optional status) and every manual return path use permissions the app already
has. Two optional features need new scopes, and every installed store re-approves on next open:
*Order Transactions: modify* (refund the delivery charge) and *Order Transactions: read* (refund lines for automatic
returns). The app is under Marketplace review, so add them **after approval**, in one batch with **Store Inventory:
read** (multi-location pickup, already needed). Money-moving scopes get extra reviewer scrutiny; say why in the
submission notes.

---

## Scenario A: no driver found

**Setup.** A florist in London sells on BigCommerce. Checkout showed "Same-day by RentADriver" (flat method). The
order is paid, automatic booking is on, `no_driver_policy = notify` (the default), early warning after 30 min.

1. **Customer pays.** `store/order/created` / `statusUpdated` arrives; RentADriver re-reads the live order, books the
   delivery and holds the price in the merchant's wallet. BigCommerce: status unchanged (`Awaiting Fulfillment`),
   staff note "RentADriver RD-…: <tracking link>".
2. **Search runs.** Offers go out in waves. Nothing changes in BigCommerce.
3. **Early warning (after N minutes, no acceptance).**
   - RentADriver: the Today tab shows the order under *Needs action*; the merchant gets an email. One-click actions:
     **Keep searching**, **Retry with a price boost** (wallet, capped), **Switch to tomorrow morning**, **Fulfil
     another way**.
   - BigCommerce: staff note line "RentADriver: no driver has accepted yet — open the RentADriver app to choose what
     to do". **Opt-in only:** status → `12 Manual Verification Required`, remembering the previous status. Off by
     default because the store may email the customer on that change.
4. **Merchant decides** (or the policy decides): same four outcomes as the shared design. *Fulfil another way*
   cancels our delivery and releases the hold; the order stays `Awaiting Fulfillment` for the merchant's own courier.
   Nothing is written that implies a shipment.
5. **A driver accepts later.** RentADriver: the order leaves *Needs action*. BigCommerce: staff note "Driver found —
   delivery back on track"; if the opt-in status was set, the previous status is restored **only if** the order is
   still `12` (a merchant change in between wins). Shipment + tracking are written at pickup as today.
6. **Customer notice (optional, merchant setting).** BigCommerce gives apps no way to message the buyer, so
   RentADriver sends its own SMS/email ("your delivery is delayed / moved to tomorrow morning"), honouring
   `notify_recipient`.
7. **Deadline passes with no driver** (ASAP: 24 h). RentADriver: `no_driver_found`, hold released, merchant email.
   BigCommerce: staff note "No driver found — deliver it another way or book again from the RentADriver app"; status
   left as is (or restored from `12`).
8. **Refund the delivery charge (merchant click, never automatic; needs Order Transactions: modify).** RentADriver's
   order page offers *Refund the delivery charge*: a refund quote for `SHIPPING` on the order's shipping address, then
   the refund. Refused with a clear message for unpaid, unsettled, offline/manual or non-refundable-gateway orders
   ("refund it in BigCommerce"). BigCommerce sends its own refund email per store settings.
9. **Rate hidden in a dead zone.** Not recommended until live rates: disabling the store-wide flat method would also
   hide it for covered areas and for orders mid-checkout, and re-enabling would flap. With live rates the callback
   returns no quote for that address.

**BigCommerce-specific limits:** no custom "needs action" status (only the opt-in `Manual Verification Required`),
a single replaceable staff-notes field, no buyer messaging, shipping refunds only for settled gateway payments.

---

## Scenario B: a return

**Setup.** The same florist; a customer wants to send a vase back. Settings: `auto_book_returns = off` (recommended
default for BigCommerce), return method default `rentadriver_scheduled`.

Return methods (shared design):

| Method | What RentADriver does | What happens in BigCommerce |
|---|---|---|
| `rentadriver_scheduled` (default) | Books a cheaper pickup window, customer → store, from the address we delivered to | Staff note with the tracking link |
| `rentadriver_today` / `rentadriver_asap` | Same, same-day or as soon as possible | Staff note with the tracking link |
| `other_carrier` | No booking. Records the carrier and tracking the merchant types in | Staff note "Return via <carrier>, tracking <no>" (never a shipment) |
| `customer_dropoff` | No booking. Records it | Staff note |

1. **Customer asks to return.** BigCommerce has no return request: the customer contacts the shop or uses a return
   app. RentADriver sees none of this.
2. **Merchant starts the return in RentADriver** (the main path on BigCommerce): order page → **Return to store** →
   method (default *scheduled*) and items.
   - RentADriver methods → a return delivery is booked back to the store's pickup location (with multi-location, the
     location that shipped, else the default), paid from the wallet. Staff note with the tracking link.
   - *Other carrier* → nothing booked; carrier + tracking recorded and mirrored into a staff note. No lookup is
     possible: labels bought in other apps are invisible to us.
   - *Customer drop-off* → recorded only.
3. **Driver collects and delivers to the store.** The return leg completes with proof; staff note "Return delivered
   to the store". Stock is **not** changed in BigCommerce (the merchant restocks, as with any refund).
4. **Merchant refunds in BigCommerce.** `store/order/refund/created` arrives (and status 4/14).
5. **Automatic returns (only if the merchant switched them on).** A refund is not a return (lost parcel, damage,
   goodwill, price adjustment), so a refund never dispatches a driver on its own:
   - the order had a **delivered** RentADriver delivery, and
   - the refund covers **physical** line items (needs *Order Transactions: read* to read the lines; without it only a
     full `Refunded` status can be mapped, as all physical lines), and
   - no return is booked yet for that order,
   → RentADriver creates a **pending return** in the app (Today tab) with the default method pre-selected; the merchant
   approves with one click, or dismisses. Idempotency key `bigcommerce:<store>:<order>:refund:<refund_id>`: one
   pending return per refund. Because merchants often refund after the item is back, this trigger can be late — one
   reason it is off by default.

**BigCommerce-specific limits:** no return request or return event, no return labels or return tracking through the
API, refunds carry no restock information; the only automatic signal is a refund, so it becomes a suggestion the
merchant approves, never an automatic dispatch.

---

## Open questions for the product owner

- Default `no_driver_policy` for BigCommerce (notify / retry with boost / next day).
- Offer the opt-in `Manual Verification Required` status at all, given possible customer emails?
- Whether to enable automatic (pending, approve-to-book) returns for BigCommerce, and whether to request *Order
  Transactions: read* for partial refunds.
- Whether to request *Order Transactions: modify* for the one-click shipping refund, after Marketplace approval.

Sources: [Webhook event reference](https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference),
[Order statuses](https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/order-status/get-order-statuses),
[Order refunds](https://docs.bigcommerce.com/docs/store-operations/orders/refunds),
[Order consignments](https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/order-consignments/get-order-consignments),
[Request shipping rates](https://docs.bigcommerce.com/developer/api-reference/rest/integrations/shipping-provider/request-shipping-rates).
