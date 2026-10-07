<p align="center"><a href="https://rentadriver.ai"><img src="https://rentadriver.ai/opengraph-image" alt="RentADriver — physical delivery for AI agents" width="720"></a></p>

<h1 align="center">rentadriver-mcp</h1>

<p align="center"><b>From “send it” to delivered.</b><br>
The MCP server for <a href="https://rentadriver.ai">RentADriver.ai</a>: quote, book, fund, track, read proof and pay for a real-world package delivery, fulfilled by vetted RentADriver drivers.</p>

<p align="center">
<a href="https://www.npmjs.com/package/rentadriver-mcp"><img alt="npm" src="https://img.shields.io/npm/v/rentadriver-mcp?color=2855d9&label=npm"></a>
<a href="https://www.npmjs.com/package/rentadriver-mcp"><img alt="downloads" src="https://img.shields.io/npm/dm/rentadriver-mcp?color=2855d9"></a>
<img alt="MCP" src="https://img.shields.io/badge/MCP-stdio%20%2B%20remote%20(OAuth%202.1)-14223b">
<img alt="node" src="https://img.shields.io/badge/node-%3E%3D18-14223b">
<img alt="MIT" src="https://img.shields.io/badge/license-MIT-14223b">
</p>

<p align="center">
<a href="https://rentadriver.ai/docs/mcp">Tools reference</a> ·
<a href="https://rentadriver.ai/for-agents">For agents</a> ·
<a href="https://rentadriver.ai/coverage">Coverage</a> ·
<a href="https://rentadriver.ai/pricing">Pricing</a> ·
<a href="https://api.rentadriver.ai/llms.txt">llms.txt</a>
</p>

---

## Install — one line

| Client | Command |
|---|---|
| **Claude Code** | `claude mcp add rentadriver -- npx -y rentadriver-mcp` |
| **Codex CLI** | `codex mcp add rentadriver -- npx -y rentadriver-mcp` |
| **Gemini CLI** | `gemini mcp add rentadriver npx -y rentadriver-mcp` |
| **VS Code** | `code --add-mcp '{"name":"rentadriver","command":"npx","args":["-y","rentadriver-mcp"]}'` |
| **Cursor** | [one-click install](cursor://anysphere.cursor-deeplink/mcp/install?name=rentadriver&config=eyJjb21tYW5kIjoibnB4IiwiYXJncyI6WyIteSIsInJlbnRhZHJpdmVyLW1jcCJdfQ==) · or the JSON below in `~/.cursor/mcp.json` |
| **Windsurf** | the JSON below in `~/.codeium/windsurf/mcp_config.json` |
| **Claude Desktop / claude.ai** | Settings → Connectors → Add custom connector → `https://mcp.rentadriver.ai/mcp` (OAuth, nothing to paste) |
| **Any MCP client** | `{ "mcpServers": { "rentadriver": { "command": "npx", "args": ["-y", "rentadriver-mcp"] } } }` |

The first run opens a browser sign-in (OAuth 2.1 + PKCE) or creates an account for you:

```bash
npx -y rentadriver-mcp setup     # browser sign-in · paste a key · or create an account
npx -y rentadriver-mcp whoami    # which account the server acts as
npx -y rentadriver-mcp logout    # revoke tokens / forget the key
```

> Quotes and coverage work before you sign in. Rehearse with a **sandbox key** (`rd_test_…`): simulated drivers accept, drive the real road route and deliver with proof — nothing is charged. Live bookings need a funded wallet.

## The one-paste rule (give this to any agent)

Copy this into your agent's system prompt or first message — it is the same block published on
[rentadriver.ai/for-agents](https://rentadriver.ai/for-agents), and it is all an agent needs to use RentADriver correctly:

```text
Set up RentADriver so this agent can book real-world package deliveries.

Rule: only use RentADriver's own tools (check_coverage, get_quote, create_delivery, track_delivery, get_proof_of_delivery, confirm_delivery). If those tools are NOT connected in this chat, tell me they are missing and stop. Never quote a price from the website, a memory, or your own estimate as if it were a live quote, and never say something is booked unless a tool returned it.

1. Connect the tools:
   - Terminal agents (Claude Code, Cursor, VS Code, Windsurf, Codex, Gemini): run  npx -y rentadriver-mcp setup  (or add the MCP config from rentadriver.ai/for-agents).
   - Claude Desktop / claude.ai / ChatGPT — you cannot run npx here: add the remote connector  https://mcp.rentadriver.ai/mcp  in the app's connector settings and sign in when asked. In ChatGPT this is Settings → Connectors (developer mode) → add a custom connector.
2. If there is no API key yet, call the signup tool with my name and email — it returns a key once. Rehearse with a sandbox (rd_test_) key; live bookings need a funded wallet.
3. Test with check_coverage and get_quote (both free). Before booking, always call create_delivery with dryRun=true and show me the exact price the tool returns.
4. Book with create_delivery, then track_delivery until delivered, then get_proof_of_delivery and confirm_delivery.
```

> **ChatGPT and Claude Desktop can't run `npx`.** They need the **hosted** server added as a connector: `https://mcp.rentadriver.ai/mcp` (OAuth sign-in). If the tools are not connected, a good agent will say so — it must never invent a price. See [rentadriver.ai/docs/mcp](https://rentadriver.ai/docs/mcp) for per-app steps.

## 60 seconds with an agent

```text
user   › Get this parcel from Queen Victoria Market to Flinders Street Station today.
agent  › check_coverage  → Melbourne, covered
agent  › get_quote       → A$14.20 · pickup ETA 11 min · quote_id q_…
agent  › create_delivery {quote_id, dryRun: true} → A$14.20, funds sufficient
agent  › "It's A$14.20 with a driver in about 11 minutes. Book it?"
user   › yes
agent  › create_delivery {quote_id} → RD-7K2P9QX · dispatching
agent  › track_delivery  → assigned · Zoe · 1.4 km away … delivered 09:41
agent  › get_proof_of_delivery → photo, recipient "Alex"
agent  › confirm_delivery → paid
```

Every tool returns JSON with a `next_action`, money is in integer cents, and errors come back as text with the error code and the tool to call next.

## MCP App (interactive cards)

The server ships an **MCP App** (the `io.modelcontextprotocol/ui` extension): hosts that support it — Claude, ChatGPT and
other compliant clients — render an interactive card instead of raw JSON for two tools:

- `get_delivery` → a **delivery tracker**: status, stops with progress, driver and ETA, proof-of-delivery photos and
  signature, a Refresh button (re-calls the tool) and a link to the public tracking page.
- `get_quote` → a **quote card**: price, ETA, route length, breakdown, and the cheaper time slots.

The cards are read-only by design: booking, funding, tipping and cancelling stay conversational, so nothing is charged
from a click. Hosts without MCP Apps support see the normal JSON result. The HTML is bundled into the package
(`dist/ui/*.html`, resources `ui://rentadriver/delivery.html` and `ui://rentadriver/quote.html`).

## Tools

<details>
<summary><b>Discovery</b> · 4 tools</summary>

Free and unauthenticated. Use these first to check feasibility and show the human a price.

| Tool | What it does | Inputs |
|---|---|---|
| `check_coverage` | Tells you whether an address or a lat/lng pair is inside a RentADriver service area, which city it maps to, and the nearest area with distance if it isn't covered. | address (string) or lat + lng (numbers) |
| `list_service_areas` | Launch cities with country, currency, timezone, centre, radius, launch phase and the minimum price. Use it to explain coverage or pick a city before quoting. | none |
| `get_quote` | Prices a delivery from one pickup to one or more drop-offs. Geocodes addresses, routes the trip, applies size, urgency, vehicle and live surge, and guarantees the driver floor. Returns a quote_id valid for 15 minutes plus the full breakdown and ETAs, so the agent can show the human the price before spending anything. | pickup (stop), dropoffs[] (1–8 stops), items[] (description, size_class S/M/L/XL, category), urgency asap|today|scheduled, scheduled_at, vehicle_class |
| `get_platform_stats` | Live counts: drivers signed up, on shift now, service areas, deliveries, agents connected, founding-driver signups. Useful for status pages or sanity checks. | none |

</details>

<details>
<summary><b>Account</b> · 9 tools</summary>

Self-serve signup, balance, deposits and keys. Everything here needs an API key except signup.

| Tool | What it does | Inputs |
|---|---|---|
| `signup` | Creates an organisation for an agent, app or business and returns an API key exactly once. Store the key as RENTADRIVER_API_KEY and restart the MCP server. | name, email, kind agent|app|business, agent_framework (optional) |
| `check_account_status` | Confirms the configured key works and returns the account name, plan, wallet balance, spending caps and which capabilities are enabled (card deposits, x402). Without a key it reports what still works (quotes). | none |
| `get_wallet_balance` | Current balance in cents with the last 50 ledger entries (deposits, holds, releases, captures, tips, refunds). | none |
| `create_wallet_deposit` | Creates a Stripe Checkout session for a card deposit and returns the URL a human must open to pay. The wallet is credited when payment completes. | amount_cents (≥ 500) |
| `create_x402_deposit` | Agent-native funding without a card. Returns x402 v2 payment requirements for USDC on Base; sign them with an x402 client and retry the REST endpoint with the payment header to credit the wallet. Returns not-enrolled while the account is outside early access. | amount_cents (≥ 100) |
| `set_spending_controls` | Caps what the account can spend per delivery and per rolling 24 hours. The API refuses any create_delivery above the cap. Pass null to clear a cap. | spending_cap_per_delivery_cents, daily_cap_cents |
| `list_api_keys` | Lists the account's keys with prefix, name, last use and revocation state. Full keys are never returned. | none |
| `create_api_key` | Issues an additional key (max 10 active) for another agent or environment. The key is shown once. | name (optional) |
| `revoke_api_key` | Permanently deactivates a key. You cannot revoke the key you are calling with. | key_id |

</details>

<details>
<summary><b>Deliveries</b> · 11 tools</summary>

Create, fund, read and manage deliveries. All mutating tools are idempotent when you pass idempotency_key.

| Tool | What it does | Inputs |
|---|---|---|
| `create_delivery` | Books a delivery from a quote_id or directly from pickup and drop-offs. With fund=true (default) the price is held from the wallet and dispatch starts immediately. dryRun=true validates, prices and checks caps and funds without creating anything. Prohibited items are refused. Returns the delivery with next_action. | quote_id or pickup + dropoffs[], items[], urgency, scheduled_at, vehicle_class, instructions, contingency {if_no_answer, if_closed, max_wait_minutes}, external_ref, idempotency_key, webhook_url, metadata, fund, dryRun |
| `create_store_pickup` | Shortcut for the most common agent job: a driver collects a prepaid order from a store using the order reference and delivers it with a photo and the recipient's name. Sets sensible proof and instructions for you. | store_address, order_reference, store_phone, dropoff_address, recipient_name, recipient_phone, item_description, size_class, instructions, dryRun |
| `create_batch` | Submits up to 25 create_delivery payloads in one call and returns a per-item result, so one bad address does not fail the batch. | deliveries[] (create_delivery payloads) |
| `fund_delivery` | Holds the price from the wallet for a delivery created with fund=false, or retries funding after a deposit. Moves the delivery to dispatching (or funded if scheduled). | delivery_id |
| `get_delivery` | Full delivery record by id or short code: status, next_action, stops with statuses, driver summary and live position while active, ETAs, proof summary, timestamps. | delivery_id (uuid or RD-XXXXXXX) |
| `list_deliveries` | Pages through the account's deliveries, newest first. Filter by status ('active' or a comma list) and creation time. | status, since (ISO), limit, offset |
| `track_delivery` | Compact live view for polling: status, next_action, ETAs, driver first name and position, per-stop progress, last event and the SSE stream URL. Poll every 30–60 seconds while active. | delivery_id |
| `get_delivery_events` | Every event on the delivery in order: created, funded, dispatching, offers_sent, assigned, each driver step, delivered, confirmed, paid, plus messages and location pings. | delivery_id |
| `update_delivery` | Changes instructions, contingency rules, the drop-off contact or the window while the job is in progress. The driver is notified. | delivery_id, instructions, contingency, dropoff_contact {contact_name, contact_phone}, window_end, metadata |
| `cancel_delivery` | Cancels and releases the hold. Free before a driver is assigned; after assignment and before pickup, a cancellation fee applies. Not possible after pickup: use create_return instead. | delivery_id, reason |
| `create_return` | Books the reverse trip of a completed delivery: the driver collects from the last drop-off and brings the item back to the original pickup, with proof at both ends. | delivery_id |

</details>

<details>
<summary><b>Driver communication</b> · 2 tools</summary>

Messages go to the assigned driver's app and are answered in the same thread.

| Tool | What it does | Inputs |
|---|---|---|
| `send_message_to_driver` | Sends a message to the assigned driver as a push notification and in-app thread. Only works once a driver is assigned. | delivery_id, body (≤ 2000 chars) |
| `get_delivery_messages` | The full message thread between your account and the driver for one delivery, oldest first. | delivery_id |

</details>

<details>
<summary><b>Proof and settlement</b> · 5 tools</summary>

Verify the outcome, then release or dispute the payment.

| Tool | What it does | Inputs |
|---|---|---|
| `get_proof_of_delivery` | Signed photo URLs (valid one hour), signature image, recipient name, OTP result, coordinates and timestamps for the delivery and each stop. | delivery_id |
| `confirm_delivery` | Confirms a delivered job, captures the held funds and releases the driver's pay. If you never call it, the delivery auto-confirms 24 hours after delivery. | delivery_id |
| `open_dispute` | Freezes the funds and asks a human to review the proof. Available on delivered, confirmed, failed and returned deliveries within 48 hours. Optional evidence URLs. | delivery_id, reason, evidence[] (urls) |
| `tip_driver` | Adds a tip from the wallet after delivery. 100% goes to the driver. | delivery_id, amount_cents (≥ 50) |
| `rate_driver` | 1–5 stars with an optional comment. Ratings feed dispatch priority and driver tiers. | delivery_id, stars, comment |

</details>

<details>
<summary><b>Webhooks</b> · 3 tools</summary>

Prefer webhooks or the SSE stream over polling for long-running agents.

| Tool | What it does | Inputs |
|---|---|---|
| `register_webhook` | Registers a URL for delivery.* events (or a subset). Returns the HMAC signing secret once; every POST carries X-RentADriver-Signature with a timestamp. | url, events[] (default delivery.*) |
| `list_webhooks` | Active webhooks on the account. | none |
| `delete_webhook` | Deactivates a webhook. Queued deliveries to it stop. | webhook_id |

</details>

### Resources and prompts

- `delivery://{id}` — A delivery by id or short code as JSON, for clients that prefer resources over tool calls.
- `coverage://{area}` — Coverage details for one service area; the list shows every launch city.
- `openapi://spec` — The full REST OpenAPI document, for agents that want to call endpoints directly.
- prompt `plan_delivery` — Walks the agent through coverage, quote, create, track and confirm for one delivery. Arguments: pickup, dropoff, item, deadline.

## Sign in

| Option | How | Best for |
|---|---|---|
| **Browser (OAuth)** | `npx -y rentadriver-mcp login` — PKCE + loopback redirect; 1 h tokens, refreshed for 30 days | people on a laptop |
| **API key** | `RENTADRIVER_API_KEY=rd_live_…` in the env (always wins) or `setup --key rd_…` | servers, CI, headless agents |
| **Create an account** | `setup --signup` or the `signup` tool from the agent | first contact |

Credentials live in `~/.rentadriver/config.json` (mode 600). Non-interactive flags: `setup --oauth --no-open` (prints the link), `--api-url <url>`.

## Remote server (no local process)

`https://mcp.rentadriver.ai/mcp` implements the MCP authorization spec — OAuth 2.1 with PKCE and dynamic client registration. Add it as a remote server and sign in when the host asks; an `x-api-key` header works on the same URL for server-side agents.

```json
{ "mcpServers": { "rentadriver": { "url": "https://mcp.rentadriver.ai/mcp" } } }
```

Scopes: `read` (quotes, deliveries, tracking, proof, balance) and `write` (book, fund from balance, cancel, confirm, webhooks). Tokens can never add funds, create keys or change team settings. Disconnect an app from the console (Developers → Connected apps) or `DELETE /v1/oauth/connections/:id`.

## Environment

| Variable | Meaning |
|---|---|
| `RENTADRIVER_API_KEY` | `rd_live_…` or `rd_test_…` (sandbox); overrides the stored sign-in |
| `RENTADRIVER_API_URL` | default `https://api.rentadriver.ai` |

## Links

- Tools reference with inputs, returns and auth per tool: https://rentadriver.ai/docs/mcp
- REST + OpenAPI: https://rentadriver.ai/docs · https://api.rentadriver.ai/.well-known/openapi.json
- Status: https://rentadriver.ai/status · Support: support@rentadriver.ai

MIT © RentADriver

`create_delivery` accepts `dispatch_timeout_hours` (0.25–72). For example, pass `72` to keep searching for up to three days without repeated bookings or wallet holds. Defaults are 24 hours for ASAP and 72 hours otherwise; today deliveries stop at the end of the pickup area's day, and explicit pickup-window ends always take precedence. `delivery.dispatch_summary.expires_at` reports the actual deadline. Individual driver offers still have short response windows.

### Country accounts and safe tip retries (0.3.4)

Pass `country_code: "GB"` (or `US`, `AU`, `CA`) to `signup` to select the operating country and its GBP/USD/AUD/CAD wallet. Older callers default to US/USD. Drivers stay in their local market and cross-currency payments are rejected. Enable additional country workspaces under the same account to operate internationally; each has its own local-currency wallet.

`tip_driver` now requires `idempotency_key` (8–100 characters). Generate a key for a new tip and reuse that same key after a timeout or connection failure. A different intended tip needs a new key. Upgrade older standalone MCP clients to this version, or use the hosted MCP server, before tipping.

### International accounts

Use `list_country_workspaces` and `enable_country_workspace` (admin role) to operate across countries. Each workspace starts with zero local-currency balance. Quotes identify the pickup workspace; bookings infer it. Pass `workspace_id` to wallet, deposit and spending-control tools. No conversion or transfers between wallets; USDC supports USD only. Per-call IDs keep concurrent agents independent.
