# RentADriver for Slack

Working single-workspace bot for a private pilot. It provides private delivery quotes and status lookup. It is not a distributed Slack Marketplace app and has not completed a live Slack sandbox pilot.

## Commands

| Command | Result |
|---|---|
| `/rentadriver connect <country workspace ID>` | Sign in, approve RentADriver access, then confirm the named account privately in Slack |
| `/rentadriver status` | Verify your account, workspace, currency and sandbox/live mode |
| `/rentadriver quote` | Open a private modal for one pickup, one drop-off and a medium parcel |
| `/rentadriver delivery <delivery ID>` | Read the delivery's status privately |
| `/rentadriver disconnect` | Remove your connection, invalidate pending handovers and revoke its credential |

Each Slack user connects their own RentADriver account. Installing the bot does not share the installer's account. The bot does not book deliveries, spend wallet balances, read channel history or publish delivery information into channels. Complete booking in the RentADriver console.

## Run a private pilot

Requires Node.js 22.13 or later (Node.js 24 recommended), a durable storage directory and an HTTPS endpoint reachable by Slack and the browser.

1. Generate the app manifest with your service's HTTPS origin:

   ```sh
   PUBLIC_BASE_URL=https://your-bot.example npm run manifest > manifest.json
   ```

2. Create a Slack app from that manifest. Review the `commands` and `chat:write` bot scopes, then install it into the intended workspace. Keep the signing secret and bot token in your secret manager.
3. Register a public RentADriver OAuth client with the exact callback `https://your-bot.example/oauth/callback`, authorization-code and refresh-token grants, and token endpoint authentication `none`. The bot requests read-only access and uses PKCE S256. No RentADriver client secret is required.
4. Configure the server environment:

   | Variable | Value |
   |---|---|
   | `PUBLIC_BASE_URL` | Your HTTPS origin, with no path |
   | `SLACK_TEAM_ID` | The installed Slack workspace ID |
   | `SLACK_SIGNING_SECRET` | Slack request signing secret |
   | `SLACK_BOT_TOKEN` | Installed bot token |
   | `RENTADRIVER_CLIENT_ID` | Registered public OAuth client ID |
   | `STORAGE_KEY` | Stable 32-byte random key, encoded as 64 hexadecimal characters |
   | `STORAGE_PATH` | Optional database path; defaults to `./data/connections.sqlite` |
   | `HOST`, `PORT` | Optional bind address and port; defaults to `127.0.0.1:3000` |

   Generate the storage key directly into your secret manager. Retain it across restarts; changing it makes existing encrypted credentials unreadable. Never commit keys, database files or generated manifests containing private configuration.
5. Run `npm test`, then `npm start`. Forward HTTPS traffic to this process, preserve request bodies unchanged, and disable logging of callback query strings, cookies and POST bodies. `/health` returns a minimal readiness response.
6. In Slack, connect your sandbox account, confirm the displayed account and mode, then test status and a quote. Verify cancellation, callback expiry/replay, reconnect, refresh and revoked access before inviting colleagues.

The bot acknowledges signed Slack requests before network work. OAuth state, browser cookies and Slack confirmation bind the handover to the initiating user; authorization codes and connection tickets are single-use. Tokens and temporary handovers are encrypted at rest. Expired temporary rows are removed on subsequent writes. Unconfirmed OAuth grants may remain listed in RentADriver Connected apps until revoked there; review and remove abandoned grants.

Run one server process against its database. Request serialization prevents refresh/disconnect races within that process; multiple replicas require a shared transactional credential store and distributed locking first. Use durable storage and graceful shutdown. In-flight background responses may be lost after a process crash; retry the command.

A distributed app requires a separate Slack installation OAuth flow, per-workspace bot credentials, uninstall cleanup, operational monitoring and Marketplace review. Those are outside this single-workspace pilot. The local tests use fake Slack/API transports and do not send real notifications.

[Slack app manifests](https://docs.slack.dev/app-manifests/) · [Request verification](https://docs.slack.dev/authentication/verifying-requests-from-slack/) · [Ephemeral messages](https://docs.slack.dev/reference/methods/chat.postEphemeral/)
