# RentADriver for BigCommerce

Initial BigCommerce integration on the shared commerce layer used by WooCommerce: encrypted store credentials,
merchant settings, wallet/workspace selection, checkout quoting, queued order events, booking and the merchant dashboard.
Shopify retains its dedicated embedded app. BigCommerce's dashboard is at `/bigcommerce`.

This is development code. Marketplace publication, Shipping Provider registration and an end-to-end sandbox install
still need verification. No marketplace review or approval is implied by the implementation.
Track remaining work in [production readiness](PRODUCTION-READINESS.md).
For public distribution, follow [the publishing runbook](PUBLISHING.md), including the required multi-user work before submission.

## Marketplace media

See [listing assets](listing/README.md) for the app icon, primary and alternate logos, and local UI previews.

## Local demo

Install workspace dependencies with `pnpm install` (Node.js 22 recommended), then run from the repository root:

```sh
pnpm bigcommerce:dev
```

Open <http://127.0.0.1:3005/bigcommerce#handoff=local-demo-entry> after Next.js prints `Ready`.
The command starts the dashboard and a localhost fixture API on port 8790. Press **Ctrl+C** to stop both.
If either port is occupied, it exits without stopping the existing service. Choose other ports with:

```sh
pnpm bigcommerce:dev --port 3006 --api-port 8791
```

No `.env`, BigCommerce credentials, database or Docker is needed. The demo uses sample orders and a sample wallet;
settings and simulated booking/cancellation/return changes live in memory and reset on restart. Test rate returns a
fixed, labeled sample quote, without evaluating addresses. Payments and real account connections are disabled.
This previews the merchant UI; OAuth, real checkout rates and sandbox-store integration require the setup below.
The launcher uses `.next-bigcommerce-local` so its build output is separate from other local web servers.
Only one instance per checkout can use that build directory at a time.

## Implemented in this phase

- Single-click OAuth install, signed app load and uninstall callbacks. Validate the store context, JWT issuer,
  audience, signature and expiry before issuing a merchant session or changing an installation.
- Multiple users (0.6.0): every control-panel user who loads the app is provisioned in the store-users table and gets their own session (`sub` = BigCommerce user id). A user the owner removes loses access at once, including sessions already issued; re-authorising them does not revive old sessions. Everyone opens view-only until they sign in with a RentADriver account.
- Order webhook authentication using a per-store secret, deduplication through the shared event queue, and
  webhook secret refresh when reconnecting/syncing an existing store.
- Shipping Provider request parsing from `base_options`, discounted cart subtotal and weight conversion, and
  `carrier_quotes` responses. Invalid payloads and quote failures return no shipping quotes.
- One registered quote URL plus a connection-check URL, authenticated using merchant `connection_options`.
- Paid orders with the RentADriver shipping option use the shared booking flow. Required order fetch failures
  propagate to the event queue for retry. Missing shipping addresses never fall back to billing addresses;
  multi-address orders are rejected because one shared commerce order currently maps to one destination.
- Shipment creation at pickup (or assignment if configured), including a custom `tracking_link`, physical items
  and the stored shipment reference. Completion and failure notes follow the existing delivery event flow.

The existing shared dashboard supports merchant settings, rate previews, orders, manual booking, returns and wallet
funding. API/MCP clients use `GET /v1/bigcommerce/orders`, `POST /v1/bigcommerce/orders/:ref/book`,
`list_store_orders` and `book_store_order` with `platform: "bigcommerce"`.

## Developer Portal setup

Create a draft single-click app in the BigCommerce Developer Portal. Configure these URLs on your isolated development
API host, then install the draft app as the owner of a sandbox store.

| Setting | Path / value |
|---|---|
| Auth callback | `/v1/bigcommerce/auth` |
| Load callback | `/v1/bigcommerce/load` |
| Uninstall callback | `/v1/bigcommerce/uninstall` |
| Remove user callback | `/v1/bigcommerce/remove-user` (records the removal and ends that user's sessions; the owner is never removed) |
| OAuth scopes | Orders: modify; Information & settings: modify; Store Inventory: read (multi-location pickup: locations + per-location stock) |
| Multiple users | **Enable** (once the multi-user release, 0.6.0, is live) |

The API needs `BIGCOMMERCE_CLIENT_ID`, `BIGCOMMERCE_CLIENT_SECRET`, `API_PUBLIC_URL`, `COMMERCE_APP_URL` and a configured
`COMMERCE_TOKEN_KEY` for credential encryption. The web build uses `NEXT_PUBLIC_BIGCOMMERCE_CLIENT_ID` to show its
configured connect screen. If the API client ID or secret is missing, `/v1/bigcommerce/*` returns 501.

### Installer shows “api.rentadriver.ai refused to connect”

Check the callback response before retrying installation. `501 bigcommerce_not_configured` means the running API
does not have its app credentials. In your secret manager, set the Developer Portal's **Client ID** as
`BIGCOMMERCE_CLIENT_ID` and its **Client Secret** as `BIGCOMMERCE_CLIENT_SECRET` for the API and restart it. Keep the
secret server-only and out of Git; confirm the running API has picked up the values before retrying.
Keep `API_PUBLIC_URL=https://api.rentadriver.ai` and `COMMERCE_APP_URL=https://rentadriver.ai` for production.
Do not rotate `COMMERCE_TOKEN_KEY` when adding app credentials; it protects existing store tokens.

The GET `/auth` and `/load` responses allow framing only by our own origin and BigCommerce's `*.mybigcommerce.com`
control panels through CSP. They must not carry `X-Frame-Options: SAMEORIGIN`; it hides callback errors in the installer.
Other API routes keep their normal framing protection. A callback request without parameters should return a validation
error once configured; that check does not install a store or prove a complete OAuth exchange.

OAuth exchanges the callback's `code`, `scope` and `context=stores/<hash>` at
`https://login.bigcommerce.com/oauth2/token`. The token response must identify the same store. Credentials and owner
metadata are saved through `installStore`; the store profile and hooks are then provisioned. Subsequent signed `/load`
callbacks redirect the owner with a 60-second one-use fragment code. The dashboard exchanges it for a merchant session in a non-cacheable response body. Signed `/uninstall` callbacks disconnect
the store and remove its credentials.

Provisioning reads `/v2/store` and registers `/v3/hooks` for `store/order/created`, `store/order/statusUpdated`,
`store/order/updated` and `store/app/uninstalled`. Hook requests carry `x-rd-token`; the event `hash` supplies the dedupe ID.
The signed uninstall callback handles app removal; the store/app/uninstalled webhook also covers store cancellation.

## Checkout setup

Installation and **Sync store** automatically create a flat-rate method named **Same-day by RentADriver**
in each domestic country/state/postcode shipping zone matching the pickup country. Enable **Information and Settings →
Modify** (`store_v2_information`) in the Developer Portal and reauthorize existing installations. Global/mixed-country
zones are skipped. If no domestic zone exists, create one in BigCommerce **Settings → Shipping**, then **Sync store**.

New installations start disabled. Link your RentADriver account, configure a valid pickup address, select **Flat fee**
or **Free**, and enable delivery in RentADriver Settings. Saving these settings syncs the native method's price and
activation. Pass-through pricing or disabling delivery turns the managed flat method off. Sync preserves custom-named
RentADriver methods and carrier methods, and updates only the canonical `perorder` method named above. Repeated syncs
reuse it rather than creating duplicates. Partial failures appear in the app; retry **Sync store** after resolving them.

Review the domestic zones in BigCommerce before enabling delivery: they determine checkout availability, not
RentADriver's radius/cutoff. Flat-rate minimum-order/free-over thresholds and next-day options are not synchronized.
The merchant pays RentADriver's delivery quote separately from the shopper's flat/free charge.
A flat method does not provide a live eligibility check at checkout; booking still applies RentADriver coverage/settings.

For live rates, register the Shipping Provider with BigCommerce using these fixed endpoints:

| Purpose | Path |
|---|---|
| Quote URL | `POST /v1/bigcommerce/rates` |
| Check connection options URL | `POST /v1/bigcommerce/rates/check` |
| Connection fields | `store_id` (RentADriver store UUID), `token` (store callback secret) |

Supply the connection fields from the authenticated store overview. They are also the final two segments of its
`urls.rates_callback`; treat the token as a credential. `base_options.store_id`, if supplied by BigCommerce, is the
**BigCommerce store hash**, distinct from the RentADriver UUID in `connection_options.store_id`.

Requests contain `base_options.destination`, `base_options.items` and `connection_options`. Responses contain
`quote_id`, `messages` and `carrier_quotes[].carrier_info` / `quotes[]`. An unavailable quote returns `carrier_quotes: []`.
The existing per-store `/rates/<store_id>/<token>` and `/rates/<store_id>/<token>/check` routes remain available.

Live checkout requires acceptance into BigCommerce's shipping carrier registry and configuration of the carrier's
connection fields. The final carrier identifier/configuration must be checked against the registration; the code
currently uses `rentadriver`. Do not enable live rates on customer stores before sandbox verification.

## Verification

Run the repository's credential-isolated test runner from the repository root:

```sh
pnpm test:unit
pnpm --filter @rentadriver/api typecheck
```

It discovers the BigCommerce pure-contract suite and Hono adapter integration suite, along with the existing commerce,
Shopify and WooCommerce tests. Provider HTTP calls, quoting and persistence are fixtures in the new tests; they create
no real deliveries, send no real notifications and do not touch customer wallets. The full suite uses local fixture
servers and needs permission to bind localhost. No local Docker is required or permitted.

Before a sandbox install, use an isolated test environment/database and test provider credentials, disable real customer
notifications, and verify the store's sandbox classification before booking. Do not point development callbacks at a
production database.

## Remaining work before production rollout

- Sandbox install/load/reinstall/uninstall and checkout verification, including the embedded dashboard in a real control panel.
- Shipping Provider registration, final carrier configuration, app assets and marketplace submission.
- Staff-user access and revocation of individual merchant sessions (required for public Marketplace approval).
- Complete actual control-panel and funding-return acceptance for the one-use app handoff implemented in 0.1.4; see [production readiness](PRODUCTION-READINESS.md).
- Multiple shipping addresses and partial shipments. (Multiple pickup locations: supported from 0.6.x via Store Inventory locations; durable delivery-event retry: the shared durable sync outbox.)
  A stored shipment reference prevents repeat shipment creation after it is saved; a platform success followed by a
  persistence failure still needs reconciliation before production rollout.
- Offline/manual payment policy. Automatic booking currently requires an explicit captured/paid payment status;
  an awaiting-fulfillment status alone does not establish payment.

## Platform references

- [Single-click OAuth](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/auth)
- [Signed callbacks](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/handling-callbacks)
- [Shipping Provider contracts and registration](https://docs.bigcommerce.com/developer/docs/integrations/shipping-providers)
- [Webhook event reference](https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference/events)
- [Order shipments and custom tracking links](https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/order-shipments/create-order-shipments)

### Opening the linked RentADriver account

Opening the app without an active RentADriver session shows **View-only access** with the store, linked account name and counters. Choose **Connect RentADriver** to sign in to the linked account. **Sign out** replaces the tab's session with restricted access: refreshing the page or reopening it from BigCommerce does not unlock the account. Settings, orders, bookings and console access remain unavailable until account sign-in succeeds.

Settings shows the linked account's name, account ID and contact email. Use **Open console**
to sign in to that account without replacing another account already open in your browser.
The new tab preserves the store's sandbox/live mode and selects its pickup-country workspace.
Sandbox sign-in cannot switch to live using credentials from a different console account.

The app requests a single-use link through authenticated `POST /v1/bigcommerce/app/console-link`.
It expires after 60 seconds; the console exchanges the opaque fragment ticket through
`POST /v1/bigcommerce/console/exchange`. API keys and merchant sessions are never put in the URL.
The exchange rechecks the store installation, linked account, connection key and mode, consumes
its challenge atomically, and issues an audited console key kept in the new tab's session storage.
If the link expires or the connection changes, open a fresh link from the app. Console sign-out
clears only this tab's credentials. Issued keys can be revoked from the account's Developers tab.
