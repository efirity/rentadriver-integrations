# RentADriver store-module protocol v1

One protocol for every self-hosted shop that runs a RentADriver module (OpenCart, PrestaShop, Adobe Commerce / Magento).
`Core.php` implements the module side; each platform supplies a `Bridge` (config storage, orders, order history).

## Connect

1. An administrator presses **Connect to RentADriver** in the module. The module stores a one-time install token
   (valid 15 minutes) and sends the browser to
   `https://api.rentadriver.ai/v1/<platform>/install?store=<https shop URL>&endpoint=<module endpoint>&install_token=<token>`.
2. RentADriver checks that the endpoint is HTTPS on the shop's own host, then calls it server-to-server with
   `{"action":"claim","install_token":…,"secret":…,"store_id":…,"store_key":…,"callback_token":…,"api_url":…,"webhooks_url":…,"rates_url":…}`.
   The module accepts it only with the matching, unexpired token, and forgets the token before answering.
3. The administrator lands in the RentADriver app to sign in (or create an account) and choose prices, delivery area
   and automatic booking. No API key is copied by hand and no admin URL or session token leaves the shop.

## Signed messages (both directions)

`X-RD-Timestamp: <unix seconds>` and `X-RD-Signature: hex(HMAC-SHA256(secret, "<timestamp>.<raw body>"))`.
Messages more than 300 seconds old or ahead are refused.

**RentADriver → module** (`POST <endpoint>`, JSON `{"protocol":1,"action":…}`):

| action | does | answers |
| --- | --- | --- |
| `ping` | health | `{ok, protocol, module, platform_version}` |
| `profile` | shop name, email, currency, timezone, address, weight unit | `{ok, profile}` |
| `configure` | saves `webhooks_url`, `rates_url`, `store_id`, `callback_token`, `app_url` (nothing else) | `{ok}` |
| `order` | one order (`ref` = order id or number) | `{ok, order}` (null when unknown) |
| `orders` | recent open orders (`limit` ≤ 100) | `{ok, orders}` |
| `history` | adds an order-history entry: `order_id`, `comment`, `notify`, `status` (`null` / `shipped` / `delivered`), `tracking {number,url}`, `event_key` | `{ok, id}`; a repeated `event_key` answers the first id without writing again |

**Module → RentADriver**: `POST <webhooks_url>` with `X-RD-Store` (the shop URL), `X-RD-Topic`
(`order.created`, `order.updated`, `app.uninstalled`), `X-RD-Event-Id` and the signature headers; body
`{"order_id":"…"}`. RentADriver reads the order back with `order` before acting.

**Checkout rates**: `POST <rates_url>` (the URL itself carries the store id and token) with
`{destination:{first_name,last_name,company,address1,address2,city,province_code,zip,country_code}, items:[{quantity, weight_kg, requires_shipping}], subtotal, currency}`;
answer `{rates:[{code,title,description,cost,cost_cents,currency,earliest,latest,same_day}]}`. An empty list means no
RentADriver option for that address. The module shows each rate with its code (`rentadriver.<code>`), so booking knows
same-day from next-morning.

## Orders ("rd order v1")

`{id, number, status, currency, subtotal, total, paid, cancelled, fulfilled, test, created_at, shipping_address{first_name,last_name,company,address1,address2,city,province_code,zip,country_code,phone}, customer{name,phone,email}, note, weight_kg, items[{id,title,quantity,weight_kg,price,sku,requires_shipping}], shipping_line{code,title,price,rate_code}}`.
Amounts are decimal strings in the shop's default currency. Without a shipping address there is nothing to deliver:
the billing address is never used instead. `test` marks orders placed while the module's "test orders" switch was on;
sandbox deliveries write back only to those.
