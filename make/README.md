# RentADriver for Make

Custom App component definitions for delivery quotes, booking from an approved quote, delivery lookup, connection details and delivery events. This app has not been published to the Make catalogue.

## Set up a pilot

1. Create a Custom App in Make. Add an OAuth connection for RentADriver and paste its Communication, Parameters and Default Scope component files into the matching editor fields.
2. Register a public OAuth client with RentADriver, using the exact callback shown by Make. Set `clientId` in the connection's Common Data. No client secret is required for a public client using PKCE.
3. Paste `base.json` into the app's Base field. Add the five action modules and select the RentADriver OAuth connection in each module's Connection dropdown. Make manages the account selector; do not add a reserved `__IMTCONN__` parameter. Each folder supplies Communication, Mappable Parameters (`parameters.json`) and Interface files; leave Static Parameters empty and replace any default sample inputs, including User ID. Set each action Visible for private scenario testing. `metadata.json` lists the module name, label and type for manual editor setup. The connection name in metadata is a logical reference; the editor may assign its own identifier.
4. Select a country workspace when connecting. Verify the account, currency and sandbox/live mode with **Get Connection** before activating a scenario. Consent determines mode; reconnecting must not silently switch it.
5. Test with a sandbox connection. Confirm cancellation, refresh, revoked access, failed requests and duplicate booking retries before inviting merchants.

These components require editor validation. The PKCE verifier uses three UUID values to remain within the accepted verifier length. Verify independent randomness, challenge generation and verifier persistence in Make's actual OAuth runtime before pilot use. Do not weaken PKCE if this check fails.

## Booking and events

**Create a Delivery from a Quote** defaults to an unfunded draft. **Fund and dispatch** is an explicit opt-in that spends from the existing wallet in live mode. Use the quote's currency and an integer price ceiling in minor units. **Validate only** creates nothing.

Use the upstream order/event ID as the source ID. Preserve the source ID and all approval values on retries. Review a new quote when the old one expires; never automatically approve an increased price.

**Get Delivery Events** returns up to 100 events and a text `next_cursor`. Process the entire batch, deduplicate by event ID, then persist the cursor. Repeat until the batch is empty. The feed includes newly published events and does not backfill historical deliveries. This is a polling action; instant triggers are not included in this version.

Tokens stay in the connection. Communication logs redact authentication and delivery bodies. Workflow outputs can contain delivery details; configure scenario access and retention accordingly.

[Make Custom Apps documentation](https://developers.make.com/custom-apps-documentation/app-components/connections/oauth2)
