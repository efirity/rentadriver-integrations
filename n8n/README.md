# RentADriver for n8n

Community node source for delivery quotes, booking from an approved quote, delivery lookup, connection details and delivery events. Version 0.1.0 has not been published to npm or submitted for n8n verification.

## Build and connect

Use Node.js 24 for development. Run `npm ci`, `npm test` and `npm pack` in this package. Install the package in a self-hosted n8n instance using its custom/community node installation process. n8n Cloud requires a verified, published package. `npm run dev` connects to an independently running development instance; it does not start a container.

OAuth2 is the default authentication method. Register a public RentADriver OAuth client with the exact redirect URL shown in n8n's credential editor, then enter its client ID and a country workspace ID. The credential uses PKCE; no client secret is required. Keep the workspace in the credential so later workflow expressions cannot silently select another workspace. API-key credentials are available as an advanced fallback and store the key as a password.

Run **Get Connection** and check the account, workspace, currency and sandbox/live mode before activating a workflow. Mode is determined by the authenticated credential. Test on a sandbox connection first. OAuth consent, connection verification, a quote, validation-only booking and empty event polling have been exercised in a self-hosted sandbox pilot. Cancellation, token refresh, revocation and full event pagination still need runtime validation.

Import [the connection example](examples/check-connection.json), select your own credential and run it manually. It makes a read-only request. There are no credentials or account identifiers in the example.

## Workflow behavior

- **Get a Delivery Quote** accepts one pickup, one drop-off and a package size.
- **Create a Delivery from a Quote** requires the exact quote ID, its currency, an integer price ceiling in minor units and a stable upstream source ID. Funding defaults to disabled, creating an unfunded draft. Enabling **Fund and Dispatch** spends from the existing wallet in live mode. **Validate Only** creates nothing.
- **Get a Delivery** reads one delivery belonging to the connection's account, workspace and mode.
- **Get Delivery Events** returns up to 100 events and a text `next_cursor`. Keep event IDs and cursors as strings. Process the full batch, deduplicate by event ID, then persist the cursor. Repeat until empty. The feed includes newly published events, without historical backfill.

Use Schedule Trigger and Get Delivery Events for polling. This version contains action nodes, without an instant webhook trigger. Never automatically requote or raise an approved price ceiling on retry. Preserve the source ID and all approval values; otherwise the API rejects the retry.

The node sends requests only to the RentADriver API. Credentials are handled by n8n's credential system, and transport errors are redacted. Node outputs may contain delivery details; configure workflow permissions and execution-data retention accordingly.

The node is not exposed as an AI tool: booking requires explicit workflow configuration and approved quote values. Live funding stays disabled unless deliberately enabled in the workflow.

## Compatibility and limitations

- Tested pilot: n8n 2.41.5. The node uses the n8n workflow API and has no runtime dependencies.
- Only one pickup and one drop-off are supported in the quote action.
- Delivery events require polling and explicit cursor persistence. There is no instant trigger.
- Each credential is bound to one country workspace. Create another credential to use another workspace.
- API-key credential tests and **Get Connection** use the same read-only connection endpoint.

See [publishing instructions](PUBLISHING.md) and [n8n community node installation](https://docs.n8n.io/integrations/community-nodes/installation-and-management/).
