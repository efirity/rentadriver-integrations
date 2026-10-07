# RentADriver ecommerce app listings — source of truth

Reviewed: 15 September 2026. Language: English.

This is the canonical editorial source for titles, descriptions and features for Shopify, WooCommerce, Wix, Squarespace and BigCommerce. Copy updates should start here, then be applied to the relevant marketplace and packaged listing. The blocks below are proposed replacements based on the repository implementation and the current Wix text supplied by the owner; they are not a claim that marketplace listings have been updated or approved. Private marketplace dashboards were not inspected.

## Shared positioning

**Core benefit:** Local delivery without your own driver fleet. RentADriver provides the drivers and manages dispatch, tracking and delivery operations.

**Reusable headline:** Your store. Our drivers.

**Reusable summary:** Book a delivery from your store and let RentADriver handle the driver and journey to your customer. No driver recruitment or fleet management required.

**Booking message:** After setup, book eligible orders from the app or enable automatic booking for eligible paid orders.

Keep the convenience message concrete: merchants prepare the parcel; RentADriver arranges the driver and delivery. Avoid “one click and the parcel is delivered,” which promises a completed physical delivery from a booking action. Manual booking can involve a quote and confirmation, and driver acceptance and service coverage still apply.

### Shared service disclosure

Use this in a requirements or pricing field where one exists. Keep pricing statements out of Shopify's introduction, app details and features.

> A RentADriver account is required. Delivery services are charged separately from store purchases. Available services, prices and estimated delivery times vary by location, distance and service coverage. Same-day and scheduled delivery depend on availability.

Account creation or linking differs by platform; “account required” does not mean every merchant must complete a separate registration. Sandbox mode must be configured explicitly; a development store alone does not establish that delivery bookings are simulated.

## Shopify

### App title

RentADriver

### App card subtitle

Local delivery with our drivers. No fleet to manage.

### Introduction

Book local deliveries with drivers provided by RentADriver. No fleet to manage.

### App details

RentADriver provides local delivery drivers so you can deliver orders without hiring drivers or managing a fleet. Book eligible orders from Shopify or enable automatic booking for eligible paid orders. Configure pickup details and delivery areas, then track progress and view proof of delivery when available. Same-day and scheduled delivery depend on service coverage. Checkout rates require a supported Shopify plan and setup.

### Features

1. Book local deliveries with RentADriver drivers. No fleet of your own needed.
2. Book eligible paid orders automatically or choose orders to book manually.
3. Show local delivery rates at checkout with supported plans and setup.
4. Set pickup details, delivery areas, preparation time and order cut-offs.
5. Track deliveries and view proof of delivery when available.

### App Store search terms

- local delivery
- same day delivery
- courier booking
- delivery tracking
- shipping rates

### Web search title

RentADriver: Local Delivery Without Your Own Fleet

### Web search meta description

Deliver Shopify orders with drivers provided by RentADriver. Book eligible deliveries, track progress and view proof of delivery. No fleet to manage.

### Implementation and publishing notes

- Merchant interface: embedded in Shopify Admin; delivery services run on RentADriver.
- Live checkout rates depend on Shopify carrier-service eligibility and configuration. Do not imply every installation immediately adds live rates.
- Manual booking and automatic booking have eligibility checks. Sandbox testing is available but is not an additional promise of live service coverage.
- The supplied listing form limits are: title 30, subtitle 62, introduction 100, details 500, each feature 80, each search term 20, web title 60 and meta description 160 characters. Use 3–5 features and 1–5 search terms. The copy above fits these limits.

## WooCommerce

### Plugin title

RentADriver Same-day Delivery

### Short description

Local delivery with RentADriver drivers. Book orders, track deliveries and show eligible checkout rates without managing your own fleet.

### Description

RentADriver provides local delivery drivers for your WooCommerce orders, so you do not need to recruit drivers or manage a fleet. Prepare the parcel, book an eligible delivery and let RentADriver arrange the driver and journey to your customer.

Manage deliveries from WordPress. Display local delivery rates at checkout for eligible addresses, book eligible orders manually or enable automatic booking for eligible paid orders. Set your pickup location, delivery area, preparation time and order cut-off. Track delivery progress and view proof of delivery when available.

Same-day and scheduled delivery depend on service availability. A RentADriver account is required, and delivery services are charged separately from store purchases. Available services, prices and estimated delivery times vary by location, distance and service coverage.

### Features

1. Arrange local delivery with RentADriver drivers, without your own fleet.
2. Display live delivery rates at checkout for eligible addresses and orders.
3. Book eligible paid orders automatically or choose orders to book manually.
4. Manage pickup details, delivery areas and customer delivery pricing.
5. Track deliveries and view proof of delivery when available.

### Implementation and publishing notes

- Merchant interface: RentADriver's hosted app inside WordPress, with standalone dashboard access; a native WordPress plugin supplies the shipping method.
- Checkout requires the plugin to be connected and its shipping method configured in the relevant WooCommerce zone.
- Order completion and notification behavior depend on settings; do not promise unconditional automatic completion.
- When updating the packaged `readme.txt`, preserve its external-services/data disclosures, compatibility metadata, installation instructions and changelog. This document replaces editorial copy, not that required package information.

## Wix

### App title

RentADriver

### Short description / tagline

Local delivery with our drivers. No fleet to manage.

### Overview features

1. Deliver with RentADriver drivers, without hiring or managing your own fleet.
2. Book eligible orders manually or eligible paid orders automatically.
3. Track delivery progress and view proof of delivery when available.
4. Set delivery areas, pickup details and cut-offs; test setup in sandbox mode.

### Overview description

RentADriver provides local delivery drivers for your Wix store, so you do not need to hire drivers or manage a fleet. Prepare the parcel, book an eligible delivery and let RentADriver arrange the driver and journey to your customer.

Display delivery rates at checkout for eligible addresses and book orders manually or automatically for eligible paid orders. Set your pickup location, delivery area, pickup instructions and order cut-off times. Track delivery progress, view proof of delivery when available and test your setup in sandbox mode before accepting live orders. Same-day and scheduled delivery depend on service availability.

Using the integration requires a RentADriver account. Delivery services are charged separately from store purchases. Available services, prices and estimated delivery times vary by location, distance and service coverage.

### Implementation and publishing notes

- Merchant interface: embedded in the Wix dashboard, backed by RentADriver's service and Wix Shipping Rates extension.
- This replaces the owner-supplied current Wix overview. It retains the four-feature structure and account/service disclosures, and moves the supplied-driver benefit into both the first feature and opening paragraph.
- Account access depends on Wix ownership/permissions and any required RentADriver account authorization. Installation alone does not guarantee full delivery-management access for every dashboard user.
- Checkout rates need the Shipping Rates extension and store settings configured. Confirm the current field limits in the Wix submission form before publishing; no Wix limits are asserted here.

## Squarespace

### App / extension title

RentADriver

### Short description

Local delivery for Squarespace orders with RentADriver drivers. No fleet to manage.

### Description

RentADriver provides local delivery drivers for your Squarespace orders, without the need to hire drivers or manage your own fleet. Connect your store, prepare the parcel and book an eligible delivery from your RentADriver dashboard.

Use a flat shipping option in Squarespace to let customers choose local delivery. Book eligible orders manually or enable automatic booking for eligible paid orders. Set your pickup details and delivery area, then track delivery progress and view proof of delivery when available. Tracking links are sent back to supported Squarespace order fulfillments.

Delivery management takes place in the external RentADriver dashboard. This integration does not provide live delivery rates at Squarespace checkout. A RentADriver account is required, and delivery services are charged separately from store purchases. Same-day and scheduled delivery depend on availability; prices and estimated delivery times vary by location, distance and service coverage.

### Features

1. Deliver local orders with RentADriver drivers, without your own fleet.
2. Connect eligible orders using a Squarespace flat shipping option.
3. Book eligible paid orders automatically or choose orders to book manually.
4. Manage pickup details and delivery areas in your RentADriver dashboard.
5. Track deliveries and send tracking links back to Squarespace orders.

### Implementation and publishing notes

- Merchant interface: external RentADriver dashboard, connected through Squarespace authorization; not an embedded Squarespace admin app.
- The implemented checkout setup uses a merchant-created flat shipping option named `Same-day by RentADriver`. The shopper's flat shipping charge is separate from RentADriver's delivery quote.
- A flat shipping option does not perform RentADriver's live eligibility checks at checkout. Eligibility is still checked when booking.
- Publishing/readiness documentation contains outstanding rollout work. These descriptions do not imply public directory approval or completed acceptance testing.

## BigCommerce

### App title

RentADriver

### Short description

Local delivery with RentADriver drivers. Book and track BigCommerce orders without managing your own fleet.

### Description

RentADriver provides local delivery drivers for your BigCommerce store, so you do not need to hire drivers or manage a fleet. Prepare the parcel, book an eligible delivery and let RentADriver arrange the driver and journey to your customer.

Manage delivery bookings from the BigCommerce control panel. Book eligible orders manually or enable automatic booking for eligible paid orders. Configure pickup details, delivery areas and order cut-offs. Track delivery progress and view proof of delivery when available, with shipment tracking sent back to BigCommerce.

Checkout can use a configured flat shipping option. Live checkout rates require a separately enabled BigCommerce Shipping Provider connection. A RentADriver account is required, and delivery services are charged separately from store purchases. Same-day and scheduled delivery depend on availability; prices and estimated delivery times vary by location, distance and service coverage.

### Features

1. Arrange local delivery with RentADriver drivers, without your own fleet.
2. Manage delivery bookings from the BigCommerce control panel.
3. Book eligible paid orders automatically or choose orders to book manually.
4. Set pickup details, delivery areas, preparation time and order cut-offs.
5. Track deliveries and sync shipment tracking with BigCommerce.

### Implementation and publishing notes

- Merchant interface: hosted RentADriver app embedded in BigCommerce's control panel.
- Installing the app and registering/enabling a Shipping Provider are separate steps. The feature list deliberately does not promise live rates on installation.
- Current adapter limitations include multi-address orders and partial shipments. Public rollout also depends on the maintained production-readiness checklist; do not imply universal staff-user access or completed marketplace approval.

## Audit and maintenance

| App | Existing editorial source reviewed | Result |
| --- | --- | --- |
| Shopify | [App Store draft](./shopify/listing/app-store-listing.md), [older listing draft](./shopify/LISTING.md), owner-supplied form screenshots | Consolidated titles, descriptions and features. Removed unconditional dispatch/live-rate claims and pricing statements from descriptive fields; added the no-fleet benefit. |
| WooCommerce | [Packaged plugin readme](./woocommerce/rentadriver-delivery/readme.txt) | Retained plugin title and core capabilities; replaced immediate-booking/unconditional-completion wording with eligibility-aware copy. |
| Wix | Current overview supplied by the owner; [implementation](./wix/README.md), [listing media notes](./wix/listing/README.md) | Preserved checkout, booking, tracking, configuration, sandbox and service disclosures; led with RentADriver-provided drivers. |
| Squarespace | [Implementation](./squarespace/README.md), publishing guide | No complete listing-copy source identified in the reviewed files; drafted platform-specific copy with external dashboard and flat-shipping limitations. |
| BigCommerce | [Implementation](./bigcommerce/README.md), publishing guide, [media notes](./bigcommerce/listing/README.md) | No complete listing-copy source identified in the reviewed files; drafted embedded-app copy with separate Shipping Provider requirement. |

1. Maintain future title, description and feature changes in this file first. Older listing drafts are historical reference where their copy differs from this document.
2. Check changed claims against the relevant implementation and readiness documentation. Do not add unverified countries, delivery guarantees, carrier approvals, pricing or publication status.
3. Copy the relevant fields into each marketplace and applicable package. Record the date and exact fields actually published; until then, these remain proposed replacements.
4. Recheck the destination form's current limits before submission. Only the Shopify limits supplied by the owner were used for character validation here.
5. Keep plugin release versions in the central plugin version file, following its version policy. Do not maintain a second version table here. This documentation-only compilation does not change plugin versions or publish any app.
