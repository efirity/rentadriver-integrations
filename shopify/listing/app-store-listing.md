# RentADriver — Shopify App Store listing

Every field the App Store listing form asks for, written from the product's own copy rather than
invented. Character counts are Shopify's limits; the counts in brackets are what is written here.

- **Organisation** EFIRITY PTE. LTD.
- **App** RentADriver — id `418983280641`, active version `rentadriver-2`
- **Distribution** Public (App Store). Custom distribution needs none of this.

---

## Identity

| Field | Value |
|---|---|
| App name (30) | `RentADriver` [11] |
| App icon | 1200×1200 PNG, no transparency — `listing/app-icon-1200.png`, generated from the brand SVG |
| Primary category | Shipping and delivery |
| Secondary category | Orders and shipping → Fulfillment |
| Languages | English |

## App card subtitle (62)

```
From checkout to doorstep. Same-day local delivery.
```
[49]

## App introduction (100)

```
Show a live same-day delivery rate at checkout and dispatch a driver when the order is paid.
```
[92]

## App details (500)

```
RentADriver adds same-day, driver-delivered shipping to your store. The app registers a carrier
service, so customers see "Same-day by RentADriver" with a live, distance-based price at
checkout. Orders paid with that rate are booked automatically, or you can book any paid order by
hand. Track the driver, capture proof of delivery with a photo, signature or one-time code, and
pay per delivery from a prepaid wallet. No subscription, and quotes are free.
```
[458]

## Features (3)

1. **A real price at checkout** — Your store location, radius, cut-off and prep time set the
   quote. Customers see a live same-day rate next to your other shipping options.
2. **Booked the moment it is paid** — Orders that chose the same-day rate are dispatched
   automatically. Anything else can be booked by hand from the app.
3. **Tracked and proven** — Live driver position, and proof of delivery captured as a photo,
   signature or one-time code against the order.

## Pricing

```
Free to install. Pay per delivery from a prepaid wallet — distance-based, priced per city, with
the quote shown before you book. No subscription and no monthly fee. Quotes are free and valid
for 15 minutes.
```
Model: **Free to install, usage-based charges.**

## Setup instructions

```
1. Install from the Shopify App Store, or open rentadriver.ai/shopify and enter your store domain.
   Installing creates your RentADriver account and registers the carrier service.
2. Confirm your store location is inside a covered city, then set your delivery radius, cut-off
   time, prep time and the price rule your customers see.
3. Top up the wallet. Same-day rates appear at checkout straight away.
```

## URLs

| Field | Value |
|---|---|
| App URL | `https://rentadriver.ai/shopify` |
| Privacy policy | `https://rentadriver.ai/privacy` |
| Terms of service | `https://rentadriver.ai/terms` |
| Support / FAQ | `https://rentadriver.ai/faq` |
| Support email | `support@rentadriver.ai` |
| Emergency developer email | `hi@rentadriver.ai` |

## Screenshots — **assets needed**

Minimum 3, 1600×900 PNG or JPG. Suggested, all capturable from the live product:

1. Checkout showing "Same-day by RentADriver" beside standard shipping rates.
2. The app's order view with the live driver map and ETA.
3. Settings: coverage radius, cut-off time, prep time and the customer price rule.
4. Proof of delivery — photo and signature against the order.

## Data and permissions

Requested scopes, matching the released version exactly:

```
read_orders, write_orders, read_shipping, write_shipping, read_fulfillments,
write_fulfillments, read_merchant_managed_fulfillment_orders,
write_merchant_managed_fulfillment_orders, read_locations
```

Why each is needed, for the review questionnaire:
- **orders** — read the paid order to quote and book it; write tracking and status back.
- **shipping** — register the carrier service that returns the checkout rate.
- **fulfillments / merchant managed fulfillment orders** — create and complete the fulfilment
  when the driver delivers, and attach proof.
- **locations** — the pickup point a delivery starts from.

Customer data handled: recipient name, address and phone, for the purpose of the delivery only.
Retention and controller are stated in the privacy policy; EFIRITY PTE. LTD. (UEN 202402844G) is
the controller for merchant data and the processor for a merchant's customer data.

## Still required before submitting

- [ ] App icon, 1200×1200
- [ ] At least three 1600×900 screenshots
- [ ] A demo store, or review instructions with test credentials
- [ ] Decide `Use legacy install flow` — the API builds its own OAuth authorize URL and exchanges
      the code, which is the classic flow; the app is currently on Shopify's managed install
      default. Test one real install before submitting.

## Brand assets

Use the website’s Waypoint mark in Signal blue (`#2855d9`), with Sora headings, Inter body text, navy (`#14223b`) and pale blue surfaces (`#f5f7fc`). The embedded app shares the website’s CSS tokens and fonts. Both 1200×1200 opaque listing icons are generated from the brand SVG. Upload the PNG when updating the listing; generating it locally does not change the published app.
