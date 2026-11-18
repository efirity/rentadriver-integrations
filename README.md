# RentADriver integrations

Store, automation and AI-assistant integrations for [RentADriver](https://rentadriver.ai): same-day local delivery with vetted
drivers, booked from the tools merchants already use.

## MCP server (`mcp/`)

**This repository contains the full source of the RentADriver MCP server**, `rentadriver-mcp`, in [`mcp/`](mcp/README.md):
TypeScript sources (`mcp/src/`), tool definitions, OAuth sign-in and MCP App cards. It is the same code published to npm
as [`rentadriver-mcp`](https://www.npmjs.com/package/rentadriver-mcp) and served at `https://mcp.rentadriver.ai/mcp`.

```bash
# run from source (stdio)
cd mcp && npm install && npm run build && node dist/index.js

# or as a container, from the repository root
docker build -t rentadriver-mcp . && docker run -i --rm rentadriver-mcp
```

Coverage checks and quotes work without an account; set `RENTADRIVER_API_KEY` (or run `node dist/index.js setup`) to
book. Tools and setup for every client: [mcp/README.md](mcp/README.md).

## Everything else

The rest of the repository holds the platform-specific packages: the WordPress plugin, app manifests and configuration,
listing copy and assets, and the design notes for connectors in progress. The RentADriver API, the merchant apps and the
deploy pipeline are private; this repository is published from RentADriver's monorepo (see
[CONTRIBUTING.md](CONTRIBUTING.md) for how changes flow).

| Directory | Integration | Status |
|---|---|---|
| [shopify](shopify/README.md) | Shopify embedded app: configuration (`shopify.app.toml`), listing copy and assets | Live on the Shopify App Store |
| [woocommerce](woocommerce/README.md) | **RentADriver Delivery** WordPress plugin ([WordPress.org](https://wordpress.org/plugins/rentadriver-delivery/)), coding-standards setup, directory artwork, local dev harness | Live |
| [wix](wix/README.md) | Wix Stores app: shipping-rates extension, listing copy and assets | Live on the Wix App Market |
| [squarespace](squarespace/README.md) | Squarespace connector: Developer Platform setup and publishing notes | Live (OAuth app approved) |
| [bigcommerce](bigcommerce/README.md) | BigCommerce app: setup, callbacks, listing assets | Live on the [BigCommerce App Marketplace](https://www.bigcommerce.com/apps/rentadriver/) |
| [mcp](mcp/README.md) | **rentadriver-mcp** MCP server ([npm](https://www.npmjs.com/package/rentadriver-mcp)): stdio and remote Streamable HTTP, tools, OAuth and MCP App cards | Live (npm and https://mcp.rentadriver.ai/mcp); listed in the [official MCP Registry](https://registry.modelcontextprotocol.io/v0/servers?search=ai.rentadriver) as `ai.rentadriver/rentadriver`, on [Glama](https://glama.ai/mcp/connectors/ai.rentadriver.mcp/rent-adriver-sameday-delivery) and [mcp.so](https://mcp.so/servers/rentadriver-sameday-delivery) |
| [chatgpt](chatgpt/README.md) | ChatGPT app: MCP manifest, tool annotations and justifications, submission record | Live in ChatGPT |
| [claude](claude/README.md) | Claude connector: remote MCP, OAuth consent, connectors-directory submission | Directory review |
| [make](make/README.md) | Make Custom App: OAuth, connection checks, quotes, exact-quote booking, delivery lookup and event polling | Private pilot; not in the catalogue |
| [n8n](n8n/README.md) | n8n community node: delivery actions and event polling | Self-hosted pilot; npm publication pending |
| [zapier](zapier/README.md) | Zapier CLI app: quotes, unfunded drafts, delivery lookup and authenticated event hooks | Functional implementation; hosted pilot pending |
| [slack](slack/README.md) | Slack bot: per-user account linking, private quotes and delivery status | Functional single-workspace implementation; live pilot pending |
| [ebay](ebay/README.md) | eBay fulfillment connector | Live (UK business sellers) |
| [zapiet](zapiet/README.md) | Zapiet – Pickup + Delivery: RentADriver as a Last Mile Delivery courier | Draft specification |
| [skills](skills/rentadriver-same-day-delivery/SKILL.md) | **Agent skill** `rentadriver-same-day-delivery` (SKILL.md): teaches any MCP-capable agent to quote, book, track and confirm deliveries | Free on [Agensi](https://agensi.io/skills/rentadriver-same-day-local-delivery) |
| tiktok | TikTok Shop connector | Live in the TikTok Shop app store (UK); design maintained privately |

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
