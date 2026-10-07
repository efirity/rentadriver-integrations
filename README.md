# RentADriver integrations

Store, automation and AI-assistant integrations for [RentADriver](https://rentadriver.ai): same-day local delivery with vetted
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
| [bigcommerce](bigcommerce/README.md) | BigCommerce app: setup, callbacks, listing assets | Marketplace review |
| [mcp](mcp/README.md) | **rentadriver-mcp** MCP server ([npm](https://www.npmjs.com/package/rentadriver-mcp)): stdio and remote Streamable HTTP, tools, OAuth and MCP App cards | Live (npm and https://mcp.rentadriver.ai/mcp) |
| [chatgpt](chatgpt/README.md) | ChatGPT app: MCP manifest, tool annotations and justifications, submission record | OpenAI review |
| [claude](claude/README.md) | Claude connector: remote MCP, OAuth consent, connectors-directory submission | Directory review |
| [make](make/README.md) | Make Custom App: OAuth, connection checks, quotes, exact-quote booking, delivery lookup and event polling | Private pilot; not in the catalogue |
| [n8n](n8n/README.md) | n8n community node: delivery actions and event polling | Self-hosted pilot; npm publication pending |
| [zapier](zapier/README.md) | Zapier CLI app: quotes, unfunded drafts, delivery lookup and authenticated event hooks | Functional implementation; hosted pilot pending |
| [slack](slack/README.md) | Slack bot: per-user account linking, private quotes and delivery status | Functional single-workspace implementation; live pilot pending |
| [ebay](ebay/README.md) | eBay fulfillment connector | Draft specification |
| tiktok | TikTok Shop connector | Planned; design maintained privately |

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
