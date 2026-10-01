# Publish RentADriver for Squarespace

Last verified: 8 September 2026. This guide covers the hosted Squarespace extension in this repository. Merchants connect through OAuth and use the RentADriver merchant app; distribution consists of the hosted service, public install link and, when accepted, an Extensions directory listing.

**Current status (10 September):** public-use approval and a real-site test booking/fulfillment were observed on 9 September. Broad production readiness and an Extensions directory listing are not yet established. The maintained engineering and acceptance checklist is [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md); historical registration instructions below are not evidence of outstanding approval.

## 1. Create the Squarespace developer app

1. Open the [Squarespace Developer Platform](https://developers.squarespace.com/) and choose **Start building**.
2. Sign in with the company-controlled Squarespace account and accept the Developer Terms when prompted.
3. In the Account Dashboard's Developers / Developer Apps area, create the app and save its client ID and secret in the team's secret store.
4. Record the app owner, client ID and dashboard app reference in the release record. Keep the secret out of Git, screenshots and reviewer notes.

New apps start in **Demo Mode**. Development credentials are now self-service; request production review from the app details page when the integration is ready. Follow the dashboard's current Demo Mode restrictions when selecting test sites. This replaces the older manual credential-request instructions still present in the general OAuth guide. [Self-service announcement, effective 27 July 2026](https://developers.squarespace.com/changes/self-service-oauth-credential-management).

Use a separate development app and isolated environment for testing where supported. Register each environment's exact HTTPS callback; do not point a demo integration at customer data.

## 2. Fill in the app details

Use these production values when configuring the production app. Verify each public page before submitting it.

| Field | RentADriver value |
| --- | --- |
| Name | RentADriver |
| Description | Local delivery for Squarespace orders, with merchant booking controls and tracking |
| Website / setup guide | `https://rentadriver.ai/integrations/squarespace` |
| Merchant app | `https://rentadriver.ai/squarespace` |
| Initiate URL | `https://api.rentadriver.ai/v1/squarespace/install` |
| Redirect URI | `https://api.rentadriver.ai/v1/squarespace/callback` |
| Privacy policy | `https://rentadriver.ai/privacy` |
| Terms | `https://rentadriver.ai/terms` |
| Support contact | `support@rentadriver.ai` |
| OAuth scopes currently requested by the code | `website.orders,website.orders.read` |
| Per-site webhook endpoint, provisioned by the adapter | `https://api.rentadriver.ai/v1/squarespace/webhooks` |
| Webhook topics | `order.create`, `order.update`, `extension.uninstall` |

Prepare a square RentADriver icon. The OAuth guide specifies PNG or SVG, a 1:1 ratio and a maximum of 200 KB; confirm the current dashboard's upload constraints. Explain that order access is used to read delivery details and write fulfillments with tracking. The adapter requests offline access and forwards `website_id` when Squarespace supplies it on a partner-initiated install. [OAuth registration and permissions](https://developers.squarespace.com/commerce-apis/oauth).

## 3. Prepare the hosted service

Configure the deployment through the existing secret-management and release workflow. These values are configuration requirements, not instructions to change production before tests pass.

| Variable | Where | Value / purpose |
| --- | --- | --- |
| `SQUARESPACE_CLIENT_ID` | API runtime | Client ID for this environment's app |
| `SQUARESPACE_CLIENT_SECRET` | API runtime secret | Corresponding client secret |
| `API_PUBLIC_URL` | API runtime | `https://api.rentadriver.ai` |
| `COMMERCE_APP_URL` | API runtime | `https://rentadriver.ai` |
| `NEXT_PUBLIC_SITE_URL` | API runtime and web build | `https://rentadriver.ai` |
| `NEXT_PUBLIC_API_URL` | Web build | `https://api.rentadriver.ai` |
| `COMMERCE_TOKEN_KEY` | API runtime secret | Stable encryption key for saved commerce credentials |

The API returns 501 for Squarespace routes until both API credentials exist. The public web flag does not enable API access or confer Squarespace approval. Preserve the existing credential-encryption key; changing it requires a migration plan for connected stores.

The hosted Squarespace connect action calls the API install URL directly. `NEXT_PUBLIC_SQUARESPACE_CLIENT_ID` is no longer required; API runtime credentials remain required.

Verify the ordinary API prerequisites too: database/schema, commerce event processing, supported pickup areas, workspace wallets, CORS for the merchant origin, and operational error reporting. See the [implementation README](README.md) for the service and route overview.

## 4. Close the public-launch blockers

Use [the current readiness checklist](PRODUCTION-READINESS.md) for engineering blockers and acceptance evidence. OAuth renewal is implemented on the readiness branch; deployment and real-site lifecycle acceptance are still pending. Durable outbound fulfillment and fresh manual booking validation remain open.

## 5. Run tests and prepare a reviewer demonstration

Run the repository checks from its root:

```bash
pnpm test:unit
pnpm typecheck
```

The Squarespace fixture suite is discovered automatically. Keep regression tests with fixes. Select the affected-scope checks from the release checklist; local commands do not replace exact-commit release verification.

Use CI services or an explicitly isolated remote test database for database integration; no local Docker. Automated tests must not create real deliveries, mutate customer wallets or send real notifications. A Squarespace Demo Mode app or `testmode` order alone is not proof that all downstream services are isolated.

Prepare this acceptance demonstration with fixture data, an isolated RentADriver account/workspace, simulated dispatch and notification sinks:

| Demonstration | Expected result |
| --- | --- |
| Install from RentADriver and with a partner `website_id` | Correct site selected; consent returns to the merchant app |
| Deny consent; try missing/mismatched OAuth state | Denial returns cleanly; invalid state cannot connect a store |
| Enter pickup address and create the flat shipping option | Merchant understands coverage, cutoff, preparation time and the separately configured customer shipping charge |
| Import an eligible paid test order | One test delivery linked to the correct store/workspace |
| Replay a signed notification | No duplicate booking or wallet debit |
| Try unpaid, cancelled, fulfilled and non-shipping orders | No unintended automatic delivery |
| Book manually after remote state changes | Fresh state validated; test mode preserved |
| Simulate pickup and retry tracking synchronization | Correct tracking link; retry does not send a second notification |
| Rotate tokens; simulate expiry and provider errors | Connection recovers or clearly requests reconnect |
| Disconnect the extension and reconnect | Credentials/access handled correctly; no unauthorized continued booking |

Provide reviewer instructions, test-site access through the approved private review channel, a short recording and screenshots using synthetic customer data. This demonstration pack is our preparation checklist; the dashboard/reviewer determines the actual required fields and assets.

## 6. Request production approval

1. Finish the app details and close the launch blockers.
2. Make a validated review environment accessible at its registered HTTPS URLs. Apply the same exact-commit test gate before deploying that environment.
3. Open the app details page in the Squarespace dashboard and request production review.
4. Supply the information requested there, plus the setup/demonstration instructions where applicable.
5. Record the submission date and review reference. Address feedback with tested commits and resubmit as directed.
6. Confirm the app's production status and any conditions on its use before advertising public installs.

The review entry point is documented in the [self-service announcement](https://developers.squarespace.com/changes/self-service-oauth-credential-management). The authenticated review form was not inspected for this guide; required attachments, approval time and exact status labels must be checked there.

## 7. Arrange an Extensions directory listing

Treat production API access and visibility in the [Squarespace Extensions directory](https://www.squarespace.com/extensions/home) as separate release milestones. The public self-service announcement does not establish that production approval automatically creates a listing.

1. In the app's review flow, confirm whether an Extensions listing is included and what additional partner/listing review is needed. If no listing option is available, request the current route through the app's Squarespace review/support contact.
2. Prepare the name, icon, concise description, screenshots, setup URL, install URL, support contact, privacy/terms links, supported markets and accurate pricing information. Use the dimensions and fields supplied by the listing reviewer; they were not verified in the public documentation.
3. Explain the flat shipping setup, pickup-address requirement, service coverage, wallet funding, tracking and disconnect procedure. State who provides support and how delivery exceptions are handled.
4. Review the final listing and test its connect action with the isolated review site before announcing it.
5. Save the approved listing URL and approval evidence in the release record. Until it is actually listed, describe the product as connectable through RentADriver only after production approval permits that distribution.

Squarespace describes Extensions as third-party services and directs product support to their providers. [Extensions help guide](https://support.squarespace.com/hc/en-us/articles/360000975547-Squarespace-Extensions).

## 8. Release the validated commit and open public installs

1. Merge the reviewed implementation and publishing assets, record the resulting **main commit SHA**, and run the monorepo release checks for that exact commit; branch test results do not validate a different merge commit, and failed, missing, skipped or pending applicable checks block release.
2. Verify the required production image builds as well as the test checks. Never bypass a failed gate with direct cluster, database or upload commands.
3. After rollout, perform read-only readiness, setup-page and merchant-page checks. Confirm the built page presents the intended connection UI and the registered URLs match the deployed configuration.
4. Open public distribution only after production approval and launch acceptance are recorded. Update the integration page with the directory link if that listing is also approved.
5. Monitor OAuth failures, token-refresh failures, webhook rejection/retry counts, duplicate-booking prevention and fulfillment failures. If a regression appears, pause further rollout and follow the tested rollback process; existing deliveries still require operational handling.

Follow the release checklist for every subsequent release. Publishing a hosted update uses the same deployment gates as the initial launch.

## Release record

| Evidence | Fill in when completed |
| --- | --- |
| Release owner and support owner | Pending |
| Developer app reference / client ID (no secret) | Pending |
| Production approval and any conditions | Pending |
| Tested main SHA and CI pipeline URL | Pending |
| Isolated acceptance report and reviewer demonstration | Pending |
| Deployment reference and read-only smoke results | Pending |
| Extensions listing approval / public URL, if applicable | Pending |
| Public install URL and launch date | Pending |
