# RentADriver Same-Day Delivery for OpenCart

Live same-day price at checkout, automatic booking of paid orders, and delivery progress back on the order
(history entries, the tracking link, your "shipped" and "delivered" statuses). Protocol: `../store-module-core/PROTOCOL.md`.

| Package | OpenCart | Download |
| --- | --- | --- |
| `oc4/` | 4.0.2.0 – 4.1.x | `https://api.rentadriver.ai/v1/opencart/plugin.zip?for=oc4` (`rentadriver.ocmod.zip`) |
| `oc3/` | 3.0.x | `https://api.rentadriver.ai/v1/opencart/plugin.zip?for=oc3` (`rentadriver-oc3.ocmod.zip`) |

Both packages share `common/OpenCartBridge.php` and `../store-module-core/Core.php`; the download bundles them under
`system/library/rentadriver/`. Requirements: HTTPS storefront, PHP 7.1+ with cURL.

## Install

1. **Extensions → Installer → Upload** the `.ocmod.zip`.
2. **Extensions → Extensions → Shipping → RentADriver Same-Day Delivery → Install**, then **Edit**.
3. **Connect to RentADriver**. You land in RentADriver to sign in (or create an account) and finish the guided setup.
4. Optional: choose the order statuses for "collected" and "delivered", a tax class, and "Mark new orders as test
   orders" while you try it in sandbox mode.

Order changes reach RentADriver through the `rentadriver` event on `catalog/model/checkout/order.addHistory/after`
(OpenCart 3: `…/addOrderHistory/after`). Uninstalling removes the event and the `rentadriver_order` table and
disconnects the store.
