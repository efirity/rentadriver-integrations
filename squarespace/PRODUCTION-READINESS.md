# Squarespace production readiness

Reviewed 10 September 2026. Owner: RentADriver engineering. This checklist separates completed evidence, remaining engineering and launch acceptance. It is not a claim that every production failure case has passed.

## Evidence already recorded

- The developer dashboard showed **Approved for public use** on 9 September.
- OAuth and the hosted merchant app were exercised on a test site.
- A test delivery completed the simulated delivery flow. After enabling a time-limited test-fulfillment switch for the store and retrying pickup, Squarespace returned **FULFILLED** with the RentADriver tracking link.
- Fulfillment reconciliation was verified against the existing provider record without another provider write, including Squarespace's removal of hyphens from tracking numbers.
- Verified Squarespace test orders can receive simulated fulfillment while that time-limited test-fulfillment switch is on.
- The hosted Squarespace connect button uses the API install route directly; it no longer needs `NEXT_PUBLIC_SQUARESPACE_CLIENT_ID`.

These observations establish the main test flow, not live payment/dispatch acceptance or an Extensions directory listing.

## Engineering blockers

| Item | Status | Required outcome |
| --- | --- | --- |
| 1. OAuth renewal | Implemented on the OAuth readiness branch; local validation passed, release pending | Coordinate rotating tokens across processes, reload current credentials, renew quiet stores, protect reconnect/uninstall from stale writers, and provide reconnect instructions for revoked/expired access. |
| 2. Durable outbound fulfillment | Open | Persist delivery-to-provider work, retry transient failures with backoff, reconcile ambiguous writes, expose exhausted jobs and allow an audited live-order retry in Ops. The current event listener records failures but does not durably retry them. |
| 3. Fresh manual booking validation | Open | Both merchant and API-key booking paths must fetch the current Squarespace order and validate cancellation, fulfillment, shipping details and test status. Define the intentional policy for manually booking unpaid orders. Cached rows must not bypass provider checks. |

### Item 1 design and operational behavior

- The OAuth renewal schema change adds a per-store 90-second database lease and a due timestamp. RPCs are service-role-only. Writes require the lease owner, an unexpired lease, an installed Squarespace store and the exact encrypted snapshot read under the lease.
- Each production API access reloads credentials under that lease before deciding whether renewal is necessary. A waiting request uses the winner's saved tokens. A crashed owner eventually loses its lease; a late owner cannot overwrite newer credentials.
- Metadata saves merge only changed webhook/measurement fields into current credentials, preserving rotated tokens.
- The leader worker checks up to 25 due installed sites every minute. Successful renewal schedules the next check within 24 hours and at least 48 hours before the known refresh-token expiry. Squarespace documents seven-day rotating refresh tokens; absent expiry metadata on a fresh token uses that documented lifetime. [OAuth lifecycle](https://developers.squarespace.com/commerce-apis/oauth).
- Transient failures defer the next scheduled attempt by 15 minutes. Expired tokens, `invalid_grant`, and current-token API 401 responses record a reconnect requirement. The merchant banner links directly to OAuth; Ops sees the connection error. Reconnect replaces credentials, generates a new connection identity and resets renewal scheduling. Requests from the earlier connection cannot save metadata into the new installation.
- A delayed 401 for a superseded access token cannot invalidate new credentials. Reconnect/uninstall changes invalidate pending writes.
- Renewal only calls the OAuth token endpoint; it never creates orders/deliveries, charges wallets or sends customer notifications.
- There remains an unavoidable external-service crash window if a process receives new tokens and dies before storing them. Database leases do not make a provider HTTP exchange transactional. Do not claim exactly-once token exchange; surface reauthorization if recovery is impossible.

Local adapter, credential-coordination, worker, SQL and browser checks passed on 10 September in isolated environments without production credentials or provider writes. Release, migration rollout, deployed worker verification and real-site reconnect/renewal/revocation acceptance are still pending.

## Acceptance before broad public installs

- [ ] Two-store/workspace isolation, install, denied consent, reconnect and uninstall.
- [ ] Expired/revoked credentials, simultaneous requests, provider timeout/rate-limit/outage and retry recovery.
- [ ] Duplicate webhooks and ambiguous fulfillment responses cannot create duplicate deliveries or provider fulfillments.
- [ ] Paid eligible auto booking; manual booking after cancellation/fulfillment/address changes; test status preserved.
- [ ] Insufficient funds, no available driver, cancellation before/after pickup, failed delivery and return handling.
- [ ] Separately authorized controlled live acceptance of funding, holds/settlement/release and actual dispatch. Automated tests must stay isolated and cannot mutate customer wallets, send real notifications or create real deliveries.
- [ ] Merchant setup explains flat shipping, coverage, wallet funding, cutoff/preparation and exceptions. Delivery progress/proof are viewed through the RentADriver tracking link; do not promise native Squarespace delivery states/proof write-back.
- [ ] Support owner and operational handling for failed renewals, exhausted webhooks and fulfillment retries are recorded.
- [ ] Approval evidence and tested/deployed commit are recorded in [PUBLISHING.md](PUBLISHING.md). Confirm any separate Extensions listing and its public URL before advertising directory availability.

Release each fix through the affected-scope release checks and bump the Squarespace plugin version before pushing plugin changes. Do not mark these items complete merely because implementation or fixture tests exist.
