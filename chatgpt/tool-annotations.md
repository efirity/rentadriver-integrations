# ChatGPT app — tool annotations and justifications

Paste-ready form values: `tool-justifications.json` (same shape as `tool_justifications` in the submission export).

Why this exists: v1.0.0 was rejected on 2026-09-25 because the tool annotations did not match the tools' behaviour.
Changes for the resubmission: `get_quote`/`get_intercity_quote` → readOnly (a 15-min pricing snapshot, no booking/charge);
`send_message_to_driver`, `open_dispute`, `rate_driver` → not destructive (additive records); openWorld limited to effects
that reach people/systems outside the account (`check_coverage`, quotes and `create_wallet_deposit` → false);
`revoke_api_key`, `delete_webhook` → idempotent.

Rules: readOnly = changes nothing the customer can see or must undo · destructive = moves/holds money, deletes, or overwrites ·
openWorld = reaches drivers, stores, blockchains or caller URLs outside the account · idempotent = same call twice, same end state.

| Tool | readOnly | destructive | idempotent | openWorld | Why |
|---|---|---|---|---|---|
| `check_coverage` | true | false | true | false | Only answers whether a location is inside a service area; stores nothing and changes nothing. |
| `list_service_areas` | true | false | true | false | Lists the configured service areas only. |
| `get_quote` | true | false | true | false | Prices a trip. The server stores a 15-minute pricing snapshot so a later create_delivery can reference the same price, but nothing is booked, held or charged and nothing is visible or needs undoing if unused. |
| `list_intercity_lanes` | true | false | true | false | Lists each city's neighbouring cities (≤ 160 km) with a same-day sample price. |
| `get_intercity_quote` | true | false | true | false | Prices a same-day trip to a neighbouring city; like get_quote it stores only an expiring pricing snapshot, with no booking, hold or charge. |
| `get_platform_stats` | true | false | true | false | Returns aggregate live counts. |
| `list_country_workspaces` | true | false | true | false | Lists the account's country workspaces and balances. |
| `enable_country_workspace` | false | false | true | false | Creates a new country workspace in the account, so it writes. |
| `signup` | false | false | false | false | Creates a new RentADriver account and API key. |
| `check_account_status` | true | false | true | false | Reads verification and funding status. |
| `get_wallet_balance` | true | false | true | false | Reads the wallet balance. |
| `create_wallet_deposit` | false | false | false | false | Creates a Stripe Checkout link for the account. |
| `create_x402_deposit` | false | false | false | true | The payment it prepares settles on a public blockchain (USDC on Base), outside RentADriver. |
| `set_spending_controls` | false | true | true | false | Overwrites the previous caps (null clears them), so earlier values are replaced. |
| `list_api_keys` | true | false | true | false | Lists API key metadata (no secrets). |
| `create_api_key` | false | false | false | false | Issues an additional API key. |
| `revoke_api_key` | false | true | true | false | Permanent: the revoked key can never be used again. |
| `create_delivery` | false | true | false | true | Holds real wallet funds (unless fund=false) and starts dispatch; cancelling after a driver is assigned costs a fee. dryRun=true validates without booking. |
| `create_store_pickup` | false | true | false | true | Holds real wallet funds and starts dispatch, like create_delivery. |
| `create_batch` | false | true | false | true | Holds real wallet funds for every delivery and starts dispatch. |
| `fund_delivery` | false | true | false | true | Holds real wallet funds and releases the delivery to dispatch. |
| `get_delivery` | true | false | true | false | Reads one delivery. |
| `list_deliveries` | true | false | true | false | Lists the caller's deliveries. |
| `create_recipient_code` | false | true | false | false | Replacing invalidates the previous code, overwriting it. |
| `track_delivery` | true | false | true | false | Reads live status, ETA and position. |
| `get_delivery_events` | true | false | true | false | Reads the delivery's event timeline. |
| `update_delivery` | false | true | true | false | Overwrites the instructions, contingency or drop-off contact previously stored. |
| `cancel_delivery` | false | true | false | false | Irreversible: a cancelled delivery cannot be resumed, and after assignment a cancellation fee is charged. |
| `create_return` | false | true | false | true | Creates and funds a new real delivery, holding wallet money. |
| `send_message_to_driver` | false | false | false | true | Delivered as a push and in-app message to the assigned driver, an independent person outside the account. |
| `get_delivery_messages` | true | false | true | false | Reads the delivery's message thread. |
| `get_proof_of_delivery` | true | false | true | false | Reads photos, signature and codes captured at handover. |
| `confirm_delivery` | false | true | true | true | Irreversibly releases the held payment to the driver. |
| `open_dispute` | false | false | false | false | Opens a dispute ticket. |
| `tip_driver` | false | true | false | true | Irreversibly moves wallet money to the driver. |
| `rate_driver` | false | false | true | false | Records a rating. |
| `list_shopify_orders` | true | false | true | false | Lists the connected Shopify store's orders. |
| `book_shopify_order` | false | true | false | true | Holds real wallet funds, starts dispatch and fulfils the order in Shopify. |
| `list_store_orders` | true | false | true | false | Lists orders from the caller's connected store. |
| `book_store_order` | false | true | false | true | Holds real wallet funds and starts dispatch. |
| `register_webhook` | false | false | false | true | RentADriver will POST delivery events to an arbitrary caller-supplied URL. |
| `list_webhooks` | true | false | true | false | Lists registered webhooks. |
| `delete_webhook` | false | true | true | false | Deactivates the webhook permanently; queued deliveries to it stop. |
