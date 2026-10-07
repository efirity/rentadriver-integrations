# Commerce integrations: architecture summary

Shopify is the one that's built differently. Wix, BigCommerce, Squarespace and WooCommerce all run on one shared "commerce" layer, while Shopify has its own dedicated code path. (`chatgpt/` and `claude/` are in the same folder but they're AI connectors, not shop apps, so they're left out.)

## The four similar apps: WooCommerce, Wix, BigCommerce, Squarespace

They share one backend and one merchant UI. The only per-platform part is a thin adapter.

- **Backend:** one shared commerce service holds the shared logic: install, rates, booking an order, cancel/return, the webhook queue and syncing delivery status back to the store. Each platform adds an adapter that handles its sign-in flow, webhook signature checks, order parsing, rate formats and writeback. The parsing code has unit tests.
- **Routes:** one route factory, mounted at `/v1/<platform>`. Each platform answers 501 until its platform credentials are configured; WooCommerce needs none.
- **Database:** one shared schema for stores, orders, incoming events and rate requests. Store credentials are encrypted at rest.
- **Merchant UI:** one React component, served at `rentadriver.ai/{woocommerce,wix,bigcommerce,squarespace}`. It signs in with a merchant session token, a Wix `?instance=`, or an API key.
- **AI agents:** use the same two MCP tools, `list_store_orders` and `book_store_order`, with a `platform` parameter.
- **What differs per platform:**
  - **Wix** and **BigCommerce** show our hosted page inside their own admin. Wix proves who the user is with a signed app instance. BigCommerce uses single-click OAuth plus a signed load callback.
  - **Squarespace** connects with OAuth but the merchant uses our website instead of an embedded page. It has no live checkout prices (it uses a flat shipping option the merchant creates) and no store address, so the merchant types in the pickup address.
  - **WooCommerce** also ships a PHP plugin that runs on the merchant's own WordPress server ([`woocommerce/rentadriver-delivery/`](./woocommerce/rentadriver-delivery/)), about 1,100 lines. It adds the checkout shipping method and admin screens, and calls our API. The store issues its own API keys, so nothing has to be registered on a developer portal.

## The different one: Shopify

Shopify was built first and has its own separate service and routes (`/v1/shopify`); its data now lives in the shared commerce tables.

- **Where it runs:** embedded in Shopify Admin at `/shopify`, using App Bridge session tokens and Polaris web components with its own sidebar.
- **Install:** token exchange from the embedded page, or the classic OAuth flow with HMAC and a state cookie.
- **Checkout prices:** comes from a registered carrier service, `RentADriver`.
- **Orders:** the `orders/paid` webhook books a delivery. Tracking goes back as Shopify fulfillments and fulfillment events.
- **AI agents:** has its own MCP tools, `list_shopify_orders` and `book_shopify_order`.
- **Config and tooling:** its setup is kept as code in [`shopify/shopify.app.toml`](./shopify/shopify.app.toml), deployed with the Shopify CLI.

It's a full parallel copy of the delivery logic; the shared commerce layer was written afterwards so the other four wouldn't need one each.
