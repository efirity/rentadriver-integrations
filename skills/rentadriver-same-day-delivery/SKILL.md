---
name: rentadriver-same-day-delivery
description: Book, track and confirm real same-day local package deliveries with RentADriver through its MCP server. Use when someone asks to send, courier, collect or deliver a physical item across a city today (for example "get this parcel from A to B this afternoon"), to price a local delivery, to check whether an address is covered, or to follow up on a RentADriver delivery (tracking, proof of delivery, confirming, cancelling). Covers connecting the MCP server, account sign-up, sandbox versus live API keys and wallet funding.
---

# RentADriver: same-day local delivery

RentADriver books real-world package deliveries fulfilled by vetted RentADriver drivers. This skill teaches you the
safe order of operations: **check coverage → free quote → dry run → book (fund) → track → proof of delivery → confirm**.

Everything happens through RentADriver's own MCP tools. This skill contains no code and makes no network calls of its
own; the tools call the RentADriver API (`https://api.rentadriver.ai`) on your behalf.

## Ground rules

1. **Use only RentADriver's tools.** If they are not connected, say so and help connect them (next section). Never
   quote a price from memory, the website or your own estimate as if it were a live quote.
2. **Never say something is booked unless a tool returned it.** Report the delivery id or short code (`RD-XXXXXXX`)
   the tool gave you.
3. **Money is in integer minor units** (cents for USD, pence for GBP), in the currency the tool returns.
   `1760` with currency `GBP` is £17.60. Convert for display; send integers.
4. **Get explicit approval before anything that spends money**: booking (`create_delivery` with funding),
   `fund_delivery`, `confirm_delivery`, `tip_driver`, and a cancellation that may carry a fee. Show the exact price the
   tool returned and wait for a clear yes.
5. **Follow `next_action`.** Every tool result includes a `next_action`; errors name the tool to call next
   (for example `insufficient_funds` → deposit, then `fund_delivery`).
6. **Never promise a pickup time** when a quote's ETA is null: that means driver availability is not confirmed yet.
7. **Keep keys secret.** An API key is shown once. Do not paste it into chat logs, files that get committed, or messages.

## 1. Connect the tools

Pick one:

- **Hosted server (no install).** Add a remote MCP server / custom connector with the URL
  `https://mcp.rentadriver.ai/mcp` and sign in when the app asks (OAuth in the browser). This is the only option in
  chat apps that cannot run local commands (Claude Desktop, claude.ai, ChatGPT).
- **npm package (terminal agents).** For example in Claude Code:
  `claude mcp add rentadriver -- npx -y rentadriver-mcp`, or in any MCP client config:

  ```json
  { "mcpServers": { "rentadriver": { "command": "npx", "args": ["-y", "rentadriver-mcp"] } } }
  ```

  Then run `npx -y rentadriver-mcp setup` once (browser sign-in, paste a key, or create an account).
  `npx -y rentadriver-mcp whoami` shows which account the server acts as. Node 18 or later is required.

Coverage, quotes and documentation work **before** signing in: `check_coverage`, `list_service_areas`, `get_quote`,
`list_intercity_lanes`, `get_intercity_quote`, `get_platform_stats`, `search_docs`, `read_docs_page`,
`list_docs_sections`, `get_pricing`, `get_faq`. Everything else needs an account.

## 2. Account, sandbox and live keys

- **No account yet?** Call `signup` with the person's or business's `name`, `email`, `kind` (`agent`, `app` or
  `business`) and `country_code` (`GB` for the UK, `US` for the United States). It returns an API key **once**. Store it
  as the `RENTADRIVER_API_KEY` environment variable (or run `npx -y rentadriver-mcp setup --key <key>`) and restart
  the MCP server. Ask the person to open the verification email: live bookings need a verified address.
- **Sandbox key** (`rd_test_…`): for rehearsing. Simulated drivers accept and complete the job with proof, and nothing
  is charged. Use it whenever someone is testing or building.
- **Live key** (`rd_live_…`): books real drivers and spends real money from the account's wallet.
- `check_account_status` confirms the key works and returns the wallet balance, spending caps and enabled
  capabilities. Call it when unsure which account or mode you are in, and tell the person whether it is sandbox or live.
- Optional guard rails: `set_spending_controls` caps the spend per delivery and per rolling 24 hours.

## 3. Check coverage

Call `check_coverage` with the pickup address (or `lat` + `lng`). RentADriver serves launch cities in the UK and the
US; availability varies by city. If the address is not covered, the result names the nearest service area and its
distance: say so plainly and stop, or offer `list_service_areas`. Drop-offs may be in the same city, or in a
neighbouring launch city in the same country (see "Nearby cities" below).

## 4. Get a free quote

Call `get_quote` with:

- `pickup` and `dropoffs` (1 to 8 stops), each with an `address` and, ideally, a contact name and phone;
- `items` with a `description`, `size_class` (`S`, `M`, `L`, `XL`) and `category` (`parcel`, `document`, `retail`,
  `food`, `grocery`, `flowers`, `pharmacy`, `keys`, `electronics`, `other`);
- `urgency`: `asap` (default), `today` or `scheduled` (with `scheduled_at`);
- optionally `vehicle_class`.

The result has a `quote_id` valid for **15 minutes**, the price in minor units with a breakdown, and ETAs. Tell the
person the total and, if present, the ETA. If 15 minutes pass, quote again.

## 5. Dry run, then book

1. Call `create_delivery` with `quote_id` and `dryRun: true`. Nothing is created; it validates the job, prices it and
   checks spending caps and the wallet balance. Show the exact price it returns.
2. After a clear yes, call `create_delivery` with the same `quote_id` (no `dryRun`). Pass an `idempotency_key` so a
   retry after a timeout cannot book twice. Useful extras: `instructions` for the driver, `contingency`
   (`if_no_answer`: `wait_then_leave_safe`, `wait_then_return`, `call_client`, `leave_with_neighbour`; `if_closed`:
   `return_to_pickup`, `hold_and_retry`, `call_client`; `max_wait_minutes`), `external_ref` for the person's own
   order number.
3. By default (`fund: true`) the price is held from the wallet and the driver search starts at once. A hold is not a
   charge; it is captured only when the delivery is confirmed.

Shortcut: to collect a prepaid order from a shop and deliver it, `create_store_pickup` (with `store_address`,
`order_reference`, `dropoff_address`, recipient details) sets sensible instructions and photo + recipient-name proof.
It also accepts `dryRun`. Several jobs at once: `create_batch` (up to 25 `create_delivery` payloads, per-item results).

## 6. Fund (when needed)

- If booking fails with `insufficient_funds`, call `create_wallet_deposit` with `amount_cents` (minimum 500). It
  returns a card checkout link that **the person** must open and pay; you cannot pay it. If the deposit tool is not
  available in your app, ask the person to top up the wallet from the RentADriver console at
  `https://rentadriver.ai/dashboard`. `get_wallet_balance` shows the balance and recent ledger entries.
- Then call `fund_delivery` with the `delivery_id`. Also use `fund_delivery` for a delivery created with `fund: false`.

## 7. Track

- `track_delivery` with the `delivery_id` (uuid or `RD-XXXXXXX`) gives status, `next_action`, ETAs, the driver's first
  name and position, and per-stop progress. Poll every 30 to 60 seconds while the delivery is active, and report
  only meaningful changes (driver assigned, picked up, delivered, problem).
- `get_delivery` returns the full record; `get_delivery_events` the complete timeline; `list_deliveries` with
  `status: "active"` lists jobs in progress.
- `send_message_to_driver` and `get_delivery_messages` talk to the assigned driver.
- `update_delivery` changes instructions, contingency or the drop-off contact while the job is in progress.
- For long-running agents, `register_webhook` (HMAC-signed `delivery.*` events) is better than polling.

## 8. Proof of delivery and confirmation

1. When the status is delivered, call `get_proof_of_delivery`: signed photo links (valid for one hour), signature,
   recipient name, location and time. Summarise it for the person.
2. If they are satisfied, call `confirm_delivery` to capture the held funds and settle the delivery. If nobody
   confirms, it auto-confirms 24 hours after delivery.
3. If something is wrong, call `open_dispute` instead (within 48 hours, with a reason and optional evidence URLs):
   the funds stay held while a person at RentADriver reviews the proof.
4. Optional, with approval: `rate_driver` (1 to 5 stars) and `tip_driver` (minor units, requires an `idempotency_key`;
   reuse the same key when retrying the same tip).

## Changes and cancellations

- `cancel_delivery` is free before a driver is assigned. After assignment and before pickup a cancellation fee
  applies: warn the person first. After pickup it cannot be cancelled; use `create_return` once delivered to bring
  the item back to the pickup address.
- Prohibited items are refused by the API. Do not try to work around a refusal.

## Nearby cities (same day)

`list_intercity_lanes` lists, for each launch city, neighbouring launch cities in the same country reachable the same
day (up to 160 km by road), with a sample price. `get_intercity_quote` prices such a trip, or `get_quote` picks the
intercity service automatically when the drop-off is in a neighbouring city. Longer trips return `too_far`.

## Answering questions

For "how does it work / what does it cost / what is covered" questions, use `get_pricing`, `get_faq`,
`search_docs` and `read_docs_page`, and cite the URLs they return. For the exact price of a route, always use
`get_quote`.

## Example

```text
person › Can you get this envelope from 1 Oxford St, London to King's Cross station this afternoon?
agent  › check_coverage {address: "1 Oxford St, London"}            → covered
agent  › get_quote {pickup, dropoffs, items: [{description: "Envelope", size_class: "S", category: "document"}], urgency: "today"}
                                                                     → quote_id, price in pence, ETA
agent  › create_delivery {quote_id, dryRun: true}                    → same price, funds sufficient
agent  › "It's £X.XX, collected this afternoon. Shall I book it?"
person › Yes.
agent  › create_delivery {quote_id, idempotency_key}                 → RD-XXXXXXX, dispatching
agent  › track_delivery (every 30–60 s)                              → assigned … picked up … delivered
agent  › get_proof_of_delivery                                       → photo, recipient name
agent  › "Delivered at 15:42, signed for by Sam. Shall I confirm it?" → confirm_delivery
```

## Links

- Tools reference: https://rentadriver.ai/docs/mcp
- Setup for agents: https://rentadriver.ai/for-agents
- Coverage: https://rentadriver.ai/coverage
- Pricing: https://rentadriver.ai/pricing
- npm package: https://www.npmjs.com/package/rentadriver-mcp
- Support: support@rentadriver.ai
