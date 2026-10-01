# RentADriver integrations

Store and AI-assistant integrations for [RentADriver](https://rentadriver.ai): same-day local delivery with vetted
drivers, booked from the tools merchants already use. This repository holds the platform-specific packages: the
WordPress plugin, app manifests and configuration, listing copy and assets, and the design notes for connectors in
progress. The API, the merchant apps and the deploy pipeline live in RentADriver's private monorepo; this repository
is a published mirror of its `apps/integrations` folder (see [CONTRIBUTING.md](CONTRIBUTING.md) for how changes
flow).

| Directory | Integration | Status |
|---|---|---|
| [shopify](shopify/README.md) | Shopify embedded app: configuration (`shopify.app.toml`), listing copy and assets | Shopify App Store review |
| [woocommerce](woocommerce/README.md) | **RentADriver Delivery** WordPress plugin ([WordPress.org](https://wordpress.org/plugins/rentadriver-delivery/)), coding-standards setup, directory artwork, local dev harness | Live |
| [wix](wix/README.md) | Wix Stores app: shipping-rates extension, listing copy and assets | Live on the Wix App Market |
| [squarespace](squarespace/README.md) | Squarespace connector: Developer Platform setup and publishing notes | Live (OAuth app approved) |
| [bigcommerce](bigcommerce/README.md) | BigCommerce app: setup, local demo API, listing assets | Marketplace review |
| [chatgpt](chatgpt/README.md) | ChatGPT app: MCP manifest, tool annotations and justifications, submission record | OpenAI review |
| [claude](claude/README.md) | Claude connector: remote MCP, OAuth consent, connectors-directory submission | Directory review |
| [ebay](ebay/README.md) | eBay fulfillment connector | Draft specification |
| [tiktok](tiktok/SPEC.md) | TikTok Shop connector | Draft specification |

Cross-cutting documents:

- [Ecommerce app listings](ecommerce-app-listings.md): canonical titles, descriptions and feature lists for the store
  integrations; marketplace copy starts here.
- [Architecture](architecture.md) and the [summary](architecture-summary.md): how each integration connects to its
  platform and which ones share the common commerce layer.

## How the integrations work

Every store integration does the same four things on its platform: offer a **same-day delivery option** at checkout
(live-quoted where the platform allows it, a flat shipping option where it does not), turn **paid orders** into
RentADriver deliveries (automatically by rule, or booked by the merchant), write **fulfilment and a tracking link**
back to the order when the driver collects it, and let the merchant manage pickup, booking rules and proof of
delivery from a hosted merchant page. The AI-assistant integrations expose the same delivery API as tools, so an
agent can quote, book and track deliveries on a merchant's behalf.

## Contributing

Pull requests and issues are welcome; read [CONTRIBUTING.md](CONTRIBUTING.md) first, because `main` is written by a
sync from the private monorepo and PRs are imported there rather than merged here. Never commit credentials; the
files that hold them locally are listed in `.gitignore`.

## Licence

MIT, except the WordPress plugin (GPL-2.0-or-later) and the brand assets and listing media (all rights reserved).
Details in [NOTICE.md](NOTICE.md).
