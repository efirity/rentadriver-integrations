# RentADriver Same-Day Delivery for PrestaShop

A carrier module for PrestaShop 1.7.8 – 9.x: live same-day price at checkout, automatic booking of paid orders, and
delivery progress back on the order (messages, the carrier tracking number, your Shipped / Delivered statuses).
Protocol: `../store-module-core/PROTOCOL.md`.

Download: `https://api.rentadriver.ai/v1/prestashop/plugin.zip` (`rentadriver.zip`, one `rentadriver/` folder; the shared
core is bundled as `rentadriver/lib/Core.php`). Requirements: HTTPS shop, cURL.

## Install

1. **Modules → Module Manager → Upload a module** → `rentadriver.zip`.
2. **Configure** → **Connect to RentADriver**. You land in RentADriver to sign in and finish the guided setup.
3. The module creates the **RentADriver** carrier (all zones, price from RentADriver). Choose which statuses mean
   "collected" and "delivered" on the module page.

Hooks: `actionValidateOrder`, `actionOrderStatusPostUpdate`, `actionCarrierUpdate`, `displayAdminOrderSide`. The module
endpoint is the front controller `module/rentadriver/api`. Uninstalling deletes the carrier and the `rentadriver_order`
table and disconnects the shop.
