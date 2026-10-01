=== RentADriver Delivery ===
Contributors: efirity
Tags: shipping, delivery, same-day, courier, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 0.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Same-day local delivery by vetted drivers, as a live shipping rate at WooCommerce checkout, booked automatically when the order is paid.

== Description ==

**From checkout to doorstep.** Live checkout rates, automatic booking and delivery tracking for your WooCommerce store.

Connect your store to RentADriver once, and customers inside your delivery radius see a live "Same-day by RentADriver" rate at checkout. Orders paid with that rate are booked with a local driver the moment WooCommerce marks them processing. The order gets an order note with a tracking link when the driver collects it, and is completed on delivery.

Everything day-to-day lives under WordPress → RentADriver: an overview of your account, mode, wallet balance and coverage, the orders RentADriver has booked with their tracking, and your delivery settings. Account sign-in, wallet top-ups and manual booking open the RentADriver app in a new tab. The plugin uses native WordPress screens and does not embed a remote dashboard.

* Live rate at checkout, priced per order and destination — not a flat table
* Your delivery radius, cut-off time, prep time and maximum item size
* Choose what the shopper pays: pass the quote through, add or subtract a percentage, charge a flat amount, or make it free over a basket size
* Optional cheaper "tomorrow morning" rate alongside same-day
* Automatic booking on paid orders, or book by hand from the RentADriver app
* Proof of delivery: photo, recipient name, signature or OTP
* Pickup address, contact and driver instructions per store

A RentADriver account is required. Pricing and coverage are at https://rentadriver.ai.

== External services ==

This plugin connects to the RentADriver API (https://api.rentadriver.ai), operated by efirity. It is required: without it the plugin cannot quote a delivery or book a driver, and no rate is shown at checkout.

When a shopper reaches checkout and your store is connected, the plugin sends the **delivery address** (street, city, state, postcode, country), the **cart contents** (item names, quantities, weights and prices), the **basket subtotal** and the **store currency**, so RentADriver can price the trip. It sends this only while calculating shipping, and caches the answer for two minutes.

When an administrator opens the Overview, Orders or Delivery settings pages, the plugin reads the store's status, recent orders or settings from the API using your store ID and rate token; saving settings or testing a rate sends those values (and the address you typed for the test). Syncing store details (the button, or saving WooCommerce General settings) asks the API to re-read your store address, currency and timezone through the WooCommerce REST API. Nothing is requested for a store that is not connected. Connecting sends your store URL and, after you approve WooCommerce access, exchanges a one-use handoff for checkout credentials.

Choosing Open RentADriver contacts the API with your store ID and rate token to read the current account binding. An administrator-approved, one-use proof is verified through your authenticated WooCommerce REST API; the resulting one-use sign-in link opens https://rentadriver.ai/woocommerce in a new tab. No long-lived key is placed in that URL.

When orders are retrieved or processed for delivery, the connector reads order details and relevant customer contact and address data through the WooCommerce API. These are used for delivery management, booking, tracking and fulfillment updates. Booking is performed by the RentADriver connector.

* Terms: https://rentadriver.ai/terms
* Privacy policy: https://rentadriver.ai/privacy

== Installation ==

1. Plugins → Add New → Upload Plugin, choose the `rentadriver-delivery.zip` you downloaded from the RentADriver app, and activate it.
2. WooCommerce → Settings → Shipping → your local zone → Add shipping method → "Same-day by RentADriver".
3. Open WordPress → RentADriver and press "Connect store". You are sent to WooCommerce's own authorisation screen, then back with the store connected.
4. On the Overview page, choose "Link account" and sign in to your RentADriver account in the new tab (OAuth, or an administrator API key under Advanced).
5. Set your radius, cut-off, pricing and booking preferences under RentADriver → Delivery settings, and try an address with "Test rate".

== Frequently Asked Questions ==

= Do I need a RentADriver account? =
Yes. Connect or create your RentADriver account from the hosted app after connecting your store.

= What happens after my cut-off time? =
By default the rate is still offered, scheduled for the next morning. You can turn that off, in which case the rate is hidden after the cut-off.

= Why is no rate shown at checkout? =
The address is outside your radius, the basket is under your minimum, the items exceed your maximum size, it is after the cut-off with next-day disabled, or the destination is outside RentADriver's coverage. The RentADriver app shows the reason for every rate request.

= Does the customer pay what RentADriver charges me? =
Only if you choose to pass the quote through. You can add or subtract a percentage, charge a flat amount, or make delivery free over a basket size — you still pay the live quote.

= Can I book an order that did not use the RentADriver rate? =
Yes, from the Orders tab of the RentADriver app, or by setting "Book automatically" to every paid order.

== Screenshots ==

1. Same-day and tomorrow-morning delivery rates at WooCommerce checkout.
2. Install RentADriver Delivery from Plugins → Add New.
3. Connection: your linked RentADriver account, WooCommerce access and store details.
4. Overview: account, mode, wallet, delivery area, the last 7 days and quick settings.
5. Delivery settings in WordPress, grouped in short sections.
6. Booking: when a driver is booked and how the WooCommerce order is updated.
7. Orders: every order RentADriver has seen, with its booking status and tracking link.
8. Test rate: preview what a customer at any address is offered. Nothing is booked.
9. The customer's live tracking page after the delivery.

== Changelog ==

= 0.5.6 =
* Harden store connections: public HTTPS destinations, bounded API requests and safe return links.
* Update the hosted app framework security patch.

= 0.5.5 =
* Same-day delivery windows now use the delivery area's timezone when WordPress is set to a UTC offset (it sends no timezone name), instead of a timezone kept from an earlier store address. Click Sync store details once after updating.

= 0.5.4 =
* Hosted app only: the booking preview explains when a driver collects from another of your locations. No change to the WordPress plugin itself.

= 0.5.3 =
* Booking an order by hand (or by an agent) now always reads the order's current state from WooCommerce, so an order cancelled since the last update is never booked. No change to the WordPress plugin itself.

= 0.5.2 =
* Automatic booking can wait until the order is marked Completed ("When the order is marked Completed"), on the Overview and in Settings → Booking.

= 0.5.1 =
* New "Sync store details" button (Overview and Settings → Connection) re-reads the store address, currency and timezone from WooCommerce, and shows the address RentADriver has on file.
* Saving WooCommerce → Settings → General now syncs the store details in the background.

= 0.5.0 =
* The hosted RentADriver app adds a Today tab, a Reports tab, order details with proof photos and a delivery timeline, and a price review before every manual booking. No change to the WordPress plugin itself.

= 0.4.6 =
* The hosted RentADriver app gains its server side for Today, order details with proof photos, reports and priced booking previews (the screens follow in the next release). No change to the WordPress plugin itself.

= 0.4.5 =
* The RentADriver menu has a submenu (Overview, Orders, Settings, Test rate) on hover, like WooCommerce.
* The pickup address field shows your WooCommerce store address as the default it falls back to.
* The wallet note names the country to enable (for example "Enable Moldova") and the currency to fund.

= 0.4.4 =
* Uses standard WordPress admin styles only; the custom stylesheet is gone.
* Settings are split into sections (Checkout rate, Next day, Customer price, Booking, Pickup & proof, Connection), each saved on its own.
* Overview shows status as text with links, and a Quick settings form for the checkout rate and automatic booking.
* Proof required at drop-off is a row of checkboxes (Photo, Recipient name, Signature, One-time code, ID check).

= 0.4.3 =
* Tabs now match the RentADriver app: Overview, Orders, Settings and Test rate. Connection details moved into Settings.
* Overview values and their buttons line up with their labels; explanations sit underneath.
* The plugin version is shown once, at the bottom of Settings.

= 0.4.2 =
* Show the installed plugin version at the bottom of every RentADriver page.

= 0.4.1 =
* Overview is actionable: turn the checkout rate on or off and change automatic booking in place, add funds next to the wallet balance, and jump to the pickup address or radius from the delivery area.

= 0.4.0 =
* Native WordPress pages: Overview (account, mode, wallet balance, coverage, this week), Orders (booking and delivery status with tracking), Delivery settings with a checkout rate test, and Connection.
* Account sign-in, wallet top-ups and manual booking still open the RentADriver app in a new tab.

= 0.3.6 =
* Clearer connection page: one "Open RentADriver" action, with WooCommerce access renewal moved under Troubleshooting.
* Confirm when the store is connected or its WooCommerce access is renewed.
* The app now asks you to reconnect your RentADriver account when its link stopped working, instead of showing an error.

= 0.3.5 =
* Use plugin-specific prefixes for shipping-rate caches and one-use console approvals to prevent naming conflicts.

= 0.3.4 =
* Use native WordPress connection controls with an explicit new-tab link to the hosted delivery app.
* Restrict connection errors to the RentADriver page and remove the retired dashboard scripts and styling.
* Align contributor, plugin name, license and external-service documentation with the directory submission.

= 0.3.3 =
* Sign out of the embedded app and reconnect the current store through OAuth or an administrator API key.

= 0.3.2 =
* Connect your RentADriver account with sign-in and explicit consent. API keys remain under Advanced options.
* Keep your existing sandbox/live mode and begin new connections with rates and automatic booking off.
* Exchange single-use installation handoffs without exposing credentials in return links.

= 0.2.6 =
* Dismiss the saved settings bar after four seconds; keep unsaved and pending changes visible.

= 0.2.5 =
* Use WordPress sign-in directly; remove embedded app sign-out and the Continue with WordPress screen. Change the linked RentADriver account in Settings.

= 0.2.4 =
* Keep the embedded app signed out after refreshing WordPress; use Continue with WordPress to sign back in.

= 0.2.3 =
* Organize the integration package and build references under apps/integrations.

= 0.2.2 =
* Show the store and linked RentADriver account together in the compact dashboard header.

= 0.2.1 =
* Use one RentADriver settings page. Old WooCommerce shipping settings links and connection callbacks open the RentADriver menu.

= 0.2.0 =
* Use the shared RentADriver merchant interface inside WordPress, with administrator-approved sign-in and store-specific framing permissions.

= 0.1.7 =
* Fix test-rate feedback, tab headings, unsaved settings warnings and mobile orders.
* Render order values safely and distinguish loading failures from empty lists.

= 0.1.6 =
* Move the plugin version to the bottom of Settings.

= 0.1.5 =
* Open the linked console account through the current WordPress administrator session.

= 0.1.4 =
* Show the installed plugin version in RentADriver settings.

= 0.1.3 =
* Refresh the wp-admin settings screen with RentADriver console styling.

= 0.1.2 =
* Show the linked account ID and email; open its console with an administrator-approved single-use sign-in link.

= 0.1.1 =
* WordPress Coding Standards, output escaping, and checkout cache invalidation for persistent object caches.

= 0.1.0 =
* First release: live checkout rate, automatic booking on paid orders, tracking note at pickup, order completed on delivery, and the full delivery settings in wp-admin.

== Upgrade Notice ==

= 0.1.0 =
First release.
