# Wix: delivery exception scenarios

End-to-end walkthroughs for the two flows designed on 2026-09-30: an order that ends with **no driver found**,
and a **return**. Each step says who acts, what happens in Wix, what happens in RentADriver, and what the Wix API
allows. The shared behaviour (policies, settings, notices) is built once in the commerce layer; this file is the
Wix-specific part. Status: **design, not implemented** — nothing below ships until the product owner confirms.

Wix facts these scenarios rely on (checked against dev.wix.com on 2026-09-30):

| Need | What Wix offers apps | Permission |
|---|---|---|
| Mark an order "needs action" | No such order status. Fulfilment is `NOT_FULFILLED` / `FULFILLED` / `PARTIALLY_FULFILLED`; Update Order only changes contact, address, `archived` and extended fields. | — |
| Leave a visible message on the order | Order activity `MERCHANT_COMMENT` (`POST /ecom/v1/orders/{id}/activities`). Append-only. | Manage Orders (held) |
| Tag the order | `POST /ecom/v1/bulk/orders/update-tags` assigns/unassigns **existing** tags. The Tags API is read-only for apps (get / list per FQDN): apps cannot create tags. | Manage Orders (held) |
| Refund the delivery charge | `POST /ecom/v1/orders/{id}/refund-payments` with `refundItems.shipping.amount`; `paymentRefunds[{paymentId, amount}]` from the order's transactions; `sideEffects.sendOrderRefundedEmail` + `customMessage` emails the buyer. Offline payments need `externalRefund: true` (records only). | Manage Orders (held) |
| Message the buyer directly | Nothing for apps. | — |
| Hide the checkout rate | `getRates` returns no option. Wix caches a response ~10 min and fails a call after 10 s. | (already configured) |
| Know a return was requested | Nothing: Wix Stores has no online returns flow or return events. Exchanges exist only in Wix POS. | — |
| Know items came back | Order Transactions **Refund Completed** webhook: `orderId`, `refund.id`, `refund.details.lineItems[{lineItemId, quantity}]`, `details.reason`, `sideEffects.restockInfo {type: NO_ITEMS|ALL_ITEMS|SOME_ITEMS, items[]}`. Fires once, after the refund settles. | Read Orders (held) |
| Return labels / return tracking | Not exposed. Merchants use ReturnGo (return portal + prepaid Shippo labels), Shippo or ShipStation; their data stays in those systems. The fulfillments API holds outbound tracking only. | — |

**Every step below uses permissions the app already has.** No new Wix permission, no re-consent from installed sites,
no re-review expected. (Multi-location pickup is the only open permission item: "Read Locations", to be added after
the current review, see `README.md`.)

---

## Scenario A: no driver found

**Setup.** A florist in London sells on Wix. Checkout showed "Same-day by RentADriver". The order is paid, automatic
booking is on, the merchant's policy is `no_driver_policy = notify` (the default), early warning after 30 min.

1. **Customer pays.** Wix sends the order webhook; RentADriver re-reads the live order, books the delivery and holds
   the price in the merchant's wallet. Wix order: `NOT_FULFILLED`, activity note "RentADriver delivery RD-… booked,
   tracking: …".
2. **Search runs.** Dispatch sends offers in waves. Nothing changes in Wix.
3. **Early warning (after N minutes, no acceptance).**
   - RentADriver: the merchant app's Today tab shows the order under *Needs action*; the merchant gets an email.
     One-click actions: **Keep searching**, **Retry with a price boost** (wallet, capped), **Switch to tomorrow
     morning**, **Fulfil another way**.
   - Wix: activity note "RentADriver: no driver has accepted yet — open the RentADriver app to choose what to do".
     If the merchant created a tag named `RentADriver: needs action` in their Wix dashboard, it is assigned too
     (apps can assign but not create tags); otherwise notes only.
4. **Merchant decides** (or the policy decides for them):
   - *Keep searching* → nothing changes until the deadline.
   - *Retry with boost* → new search at a higher price; the extra is held from the wallet. Needs the policy
     `retry(K, boost%)` or an explicit click.
   - *Tomorrow morning* → re-booked as a scheduled delivery. Because checkout promised "Today", the customer is told
     (step 6).
   - *Fulfil another way* → the RentADriver delivery is cancelled, the hold released, the Wix order is left
     `NOT_FULFILLED` for the merchant's own courier.
5. **A driver accepts later.** RentADriver: the order leaves *Needs action*. Wix: a second activity note "Driver
   found — delivery back on track" (notes cannot be removed); the merchant tag is unassigned; fulfilment and tracking
   are written at pickup as today.
6. **Customer notice (optional, merchant setting).** Wix gives apps no way to message the buyer, so RentADriver sends
   its own SMS/email to the recipient ("your delivery is delayed / moved to tomorrow morning"), honouring the order's
   `notify_recipient` flag.
7. **Deadline passes with no driver** (ASAP: 24 h). RentADriver: status `no_driver_found`, hold released, merchant
   email. Wix: activity note "No driver found — deliver it another way or book again from the RentADriver app"; the
   order stays `NOT_FULFILLED`.
8. **Refund the delivery charge (merchant click, never automatic).** RentADriver's order page offers *Refund the
   delivery charge*: it checks refundability, finds the payment in the order's transactions and calls Refund Payments
   for the shipping amount only, with `sendOrderRefundedEmail` and a short `customMessage`. Wix emails the buyer and
   records the refund. Offline/manual payments are only recorded (`externalRefund`); the money goes back outside Wix.
9. **Rate hidden in a dead zone (optional).** When a zone has had no supply for a sustained period (not one empty
   wave), `getRates` stops offering the RentADriver option there. The check must answer from cache: Wix waits at most
   10 s and caches the answer ~10 min, so a flapping check would make the option flicker at checkout.

**Wix-specific limits:** no on-hold status, append-only notes, tags only if the merchant created one, no buyer
messaging from Wix.

---

## Scenario B: a return

**Setup.** The same florist; a customer wants to send a vase back. Settings: `auto_book_returns = off` (recommended
default for Wix), return method default `rentadriver_scheduled`.

Return methods (shared design):

| Method | What RentADriver does | What happens in Wix |
|---|---|---|
| `rentadriver_scheduled` (default) | Books a cheaper pickup window, customer → store, from the original delivery address | Activity note with the tracking link |
| `rentadriver_today` / `rentadriver_asap` | Same, same-day or as soon as possible | Activity note with the tracking link |
| `other_carrier` | No booking. Records the carrier and tracking the merchant types in | Optional activity note with that carrier and tracking |
| `customer_dropoff` | No booking. Records it | Optional activity note |

1. **Customer asks to return.** Wix has no return-request step: the customer contacts the shop, or uses a return app
   such as ReturnGo (its own portal, with a prepaid Shippo label). RentADriver sees none of this.
2. **Merchant starts the return in RentADriver** (the main path on Wix): order page → **Return to store** → picks the
   method (default *scheduled*) and the items.
   - RentADriver methods → a return delivery is booked from the address we delivered to, back to the store's pickup
     location, and paid from the wallet. Wix: activity note "Return pickup RD-… booked, tracking: …".
   - *Other carrier* → nothing is booked; the merchant can enter carrier and tracking, mirrored into a Wix note. Wix has
     no field for return tracking.
   - *Customer drop-off* → recorded only.
3. **Driver collects and delivers to the store.** RentADriver: the return leg completes with proof. Wix: activity note
   "Return delivered to the store".
4. **Merchant refunds in Wix** (dashboard, with or without *restock*). This is Wix's only return signal: the **Refund
   Completed** webhook arrives after the refund settles.
5. **Automatic returns (only if the merchant switched them on).** When Refund Completed arrives with
   `restockInfo.type` = `ALL_ITEMS` or `SOME_ITEMS` **and** the order had a delivered RentADriver delivery **and** no
   return was booked yet, RentADriver books a return with the merchant's chosen method (items = the restocked lines,
   or the whole order for `ALL_ITEMS`). Idempotency key `wix:refund:<refund.id>`: one return per refund. A refund with
   `NO_ITEMS` (goodwill, damaged, never delivered) books nothing. Because merchants usually refund after the item is
   back, this trigger is late; that is why it is off by default for Wix.
6. **Wix side configuration for automatic returns:** add the "Refund Completed" webhook (Order Transactions) in the Wix
   Dev Center, same callback URL. It uses Read Orders, which the app already holds. Whether adding a webhook to the
   published app needs a new version or review is not stated in the docs; the Dev Center will show it.

**Wix-specific limits:** no return request or return event, no return labels or return tracking through the API; the
only automatic signal is a completed refund with restock.

---

## Open questions for the product owner

- Default `no_driver_policy` for Wix (notify / retry with boost / next day).
- Whether to create the `RentADriver: needs action` tag suggestion in merchant onboarding.
- Whether to enable automatic returns at all for Wix, given the late signal.

Sources: [Refund Completed webhook](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/order-transactions/order-transactions-refund-completed),
[Refund Payments](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/order-billing/refund-payments),
[Bulk Update Order Tags](https://dev.wix.com/docs/sdk/backend-modules/ecom/orders/bulk-update-order-tags),
[Tags API](https://dev.wix.com/docs/api-reference/business-management/tags/introduction),
[Update Order](https://dev.wix.com/docs/rest/business-solutions/e-commerce/orders/update-order),
[Get Shipping Rates](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/extensions/shipping-rates/shipping-rates-integration-service-plugin/get-shipping-rates),
[ReturnGo on Wix](https://support.wix.com/en/article/wix-stores-managing-product-returns-with-the-returngo-app),
[Wix refunds](https://support.wix.com/en/article/refunding-customers-in-wix-stores).
