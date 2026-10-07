# RentADriver for Zapier

Working CLI integration for a private pilot. It is not published in the Zapier App Directory. Platform OAuth consent and a complete sandbox Zap still need runtime verification before merchant invitations.

## Features

- **Check Connection** shows the authorized account, country workspace and sandbox/live mode.
- **Get Delivery Quote** quotes one pickup and one drop-off with a parcel size.
- **Create Draft Delivery** uses an exact quote, its currency and an integer price ceiling. It always leaves funding disabled. **Validate Only** creates nothing.
- **Get Delivery** reads an existing delivery in the connected workspace.
- **Delivery Event** receives created, assigned, picked-up, delivered or cancelled events. Each callback is verified by fetching the event through the authenticated API; callback content alone is never trusted.

Use a stable upstream order ID as **Stable Source ID**, and keep the quote and approval values unchanged on retries. Expired quotes require a new quote and approval. Trigger IDs remain decimal strings. Zapier deduplicates by event ID, but downstream actions still need their own idempotency.

## Set up a private pilot

Requires Node.js 22 or later and a Zapier developer account.

```sh
npm ci --ignore-scripts
npm test
npm run validate
npx zapier-platform login
npx zapier-platform register
```

Register a public RentADriver OAuth client with the exact callback shown for this Zapier integration. Use authorization-code and refresh-token grants, PKCE S256 and token endpoint authentication `none`. Set the returned client ID as the Zapier integration environment variable `CLIENT_ID`; no client secret is required. Do not commit your app registration file or credentials.

```sh
npx zapier-platform env:set 0.1.0 CLIENT_ID=<your-public-client-id>
npx zapier-platform push
```

If the platform requires the first version to exist before setting environment values, push first, set `CLIENT_ID`, then start a fresh connection attempt. Copy your country workspace ID from the RentADriver console. Authorize **sandbox** for the pilot, then run **Check Connection** and verify the account and mode before continuing. Reconnecting must use the existing mode; disconnect before changing account or workspace.

Test OAuth cancellation, PKCE, automatic refresh, revoked access, quote mapping, validate-only booking, unchanged source-ID retries, hook registration/unregistration and event deduplication in a sandbox Zap. The local suite uses mocked HTTP and does not create deliveries or notifications. Confirm Zapier's production PKCE handoff separately; local tests cannot prove its hosted authorization behavior.

## Privacy and publication

Tokens stay in Zapier authentication fields. The pinned SDK scrubs token and signing-secret fields from HTTP logs. Subscription outputs contain only an ID, and event outputs contain the API's minimal event envelope. Quote and delivery action outputs can contain addresses and delivery details: restrict Zap history access and retention.

Remaining dynamic-dropdown validation warnings are intentional: quote IDs come from a preceding quote step, source IDs from an upstream order, and delivery IDs from a preceding event or delivery step. Do not substitute an unrelated quote automatically.

A private integration push is separate from App Directory publication. Complete the sandbox acceptance checks, user-facing help and Zapier review requirements before requesting publication.

[Zapier CLI documentation](https://docs.zapier.com/platform/build-cli/overview) · [OAuth and PKCE](https://docs.zapier.com/integrations/build/oauth)
