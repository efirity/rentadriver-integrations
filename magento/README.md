# RentADriver Same-Day Delivery for Adobe Commerce and Magento Open Source

Module `RentADriver_Delivery` for Magento 2.4.x: a delivery method with a live same-day price at checkout, automatic
booking of paid orders, and delivery progress back on the order (comments, a shipment with the tracking number).
Protocol: `../store-module-core/PROTOCOL.md`.

Download: `https://api.rentadriver.ai/v1/magento/plugin.zip` (`rentadriver-magento.zip`, unpacks to
`app/code/RentADriver/Delivery/`; the shared core is bundled as `Lib/Core.php` and `Lib/Bridge.php` under the `RentADriver\Delivery\Lib` namespace, autoloaded, no include). Requirements: HTTPS storefront, cURL.

## Install

```
unzip rentadriver-magento.zip -d <magento root>
bin/magento module:enable RentADriver_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode
bin/magento cache:flush
```

Then **Stores → Configuration → Sales → Delivery Methods → RentADriver Same-Day Delivery → Connect to RentADriver**.

Endpoint: `POST /rentadriver/api/index` (signed, CSRF-exempt). Order changes are sent after the order save commits
(`sales_order_save_commit_after`). The `rentadriver_order` table is declared in `etc/db_schema.xml`.
