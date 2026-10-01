# Publish RentADriver for public BigCommerce use

Status: **development; not ready for public submission**. Readiness/code review: 2026-09-10. See [the prioritized production checklist](PRODUCTION-READINESS.md).
This runbook covers hosting the app, Marketplace approval and optional live checkout rates. Pushing this repository
only publishes source; it does not create a public BigCommerce listing. See [implementation/setup](README.md).

## 1. Finish the public-release blockers

Complete these items before submitting or onboarding customer stores:

| Item | Current implementation | Required outcome |
|---|---|---|
| Staff access | Done in 0.6.0: authorised users can install and load, each is provisioned per store, `/remove-user` ends that user's sessions | Release the multi-user schema change, enable Multiple Users in the portal, then test adding and removing a staff member in the sandbox store |
| Merchant session handoff | 0.1.4 implements one-use fragment codes; wallet return URLs have no credentials | Pass release regressions and retain actual BigCommerce install/load and funding-return acceptance evidence; see the readiness checklist |
| Shipment recovery | A saved `fulfillment_ref` avoids repeats; platform success followed by local persistence failure is unresolved | Add durable retry/reconciliation and regression coverage proving retries cannot create duplicate shipments |
| Supported order shapes | Multiple shipping addresses are rejected; partial shipments need work | Implement support or prevent unsupported orders from being booked, with clear merchant feedback and listing limitations |
| Sandbox and browser evidence | Fixtures cover contracts and adapter routes | Complete the isolated sandbox matrix below, including a real BigCommerce control-panel iframe |
| Public configuration | No live registration or production readiness is established by this branch | Verify credentials, encrypted storage, environment wiring, approved URLs, pricing, support and release evidence |

**Owner-only is a development restriction, not an acceptable public-release default.** BigCommerce currently requires
Marketplace apps to support multiple users. It also requires HTTPS callbacks, a working branded iframe, onboarding and
support, and V3 APIs where equivalent functionality exists. Follow its [approval requirements](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/approval-requirements).

The session-handoff implementation and acceptance criteria come from inspection of the API commerce service and routes
and the shared dashboard. BigCommerce's
[publishing guidance](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/publishing-apps)
also says sensitive data must not be passed in query parameters. Removing a token from browser history after load does
not prevent its earlier exposure to a web server or intermediary.

## 2. Register the app and choose distribution

Use a company-controlled [BigCommerce Developer Portal](https://build.bigcommerce.com/) account. Obtain the Partner ID
required for submission, and record the app ID, owner and support contact in the release record. Keep the client secret
in the approved secret store.

| Distribution | Meaning |
|---|---|
| Draft | Development installation on stores owned by the Developer Portal account's email |
| Unlisted | Approved distribution without a searchable Marketplace listing; approved partners can request it |
| Public | Approved and listed in the App Marketplace |

A draft URL is not a public installation workaround. Follow BigCommerce's
[distribution rules](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/types-of-apps).

Create a draft **single-click app** first. Use an isolated HTTPS development host while testing. After the public-release
blockers are closed, configure the stable production endpoints below; these are intended URLs, not proof of deployment.

| Portal field | Production value |
|---|---|
| Auth callback | `https://api.rentadriver.ai/v1/bigcommerce/auth` |
| Load callback | `https://api.rentadriver.ai/v1/bigcommerce/load` |
| Uninstall callback | `https://api.rentadriver.ai/v1/bigcommerce/uninstall` |
| Remove user callback | `https://api.rentadriver.ai/v1/bigcommerce/remove-user` |
| Embedded dashboard | `https://rentadriver.ai/bigcommerce` |
| OAuth scopes | Orders: modify; Information & settings: read; add further scopes only when implemented features require them |
| Multiple users | Enabled once the multi-user release (0.6.0) is live; test adding and removing a staff member |

Select scopes individually. Do not submit with Modify All. Confirm the callback settings against the
[OAuth flow](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/auth) and
[signed callback contract](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/handling-callbacks).

## 3. Prepare hosting and pass the release gates

The app runs in the existing API and web services; there is no separate plugin ZIP to upload.

| Configuration | Where / purpose |
|---|---|
| `BIGCOMMERCE_CLIENT_ID`, `BIGCOMMERCE_CLIENT_SECRET` | API runtime; must belong to the same portal app |
| `COMMERCE_TOKEN_KEY` | API runtime secret for stored credentials; preserve it across releases |
| `API_PUBLIC_URL=https://api.rentadriver.ai` | API runtime; OAuth redirect and callback origin |
| `COMMERCE_APP_URL=https://rentadriver.ai` | API runtime; shared dashboard origin, without `/bigcommerce` suffix |
| `NEXT_PUBLIC_BIGCOMMERCE_CLIENT_ID` | Web configuration used by the connect screen; client ID only, never the secret |
| `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_SITE_URL` | Web public configuration for the intended environment |
| Commerce schema and workers | Verify required migrations, queue processing, encrypted credentials and delivery-event handling |

Complete and verify the chosen web build/runtime configuration before release; an API secret alone does not configure
the dashboard. Verify the built `/bigcommerce` page and its iframe headers in the target environment.

For every server or database deployment:

1. Commit the complete release candidate. Run `pnpm test:unit` and `pnpm --filter @rentadriver/api typecheck` during
   development. Add regression/integration coverage for the remaining fixes.
2. Push the feature branch, review it before merging, then run the monorepo release checks for the exact final `main`
   commit. Branch results or an earlier green commit cannot approve a later deployment; missing, skipped, failed or
   pending checks block it.
3. Use the gated deployment flow and retain its evidence. Do not bypass a hold or a failed gate with direct
   server/database commands. Smoke-check readiness and the app after rollout.

Use CI services or a disposable remote test database. **No local Docker.** Automated
checks must not send real notifications, create real deliveries or change customer wallets. Screenshots for this web
app should show the actual tested BigCommerce dashboard with fictitious merchant/customer data.

## 4. Verify the complete flow in an isolated sandbox

Keep a release record with the tested commit, environment, store hash, CI URLs, screenshots and results. Store reviewer
credentials securely outside Git. Use test payments, disabled real notifications and verified sandbox classification.

| Scenario | Evidence to retain |
|---|---|
| Install / load / reinstall | Correct store/account binding, branded iframe, usable onboarding, no duplicate webhook registrations |
| New and existing account | Correct account linking and workspace; no access to another merchant's orders or wallet |
| Staff access / removal | Authorized staff can load; a removed user's previous session stops working; owner remains authorized |
| Uninstall | Store credentials and sessions become unusable; fresh load requires installation |
| Checkout | In-area rate; outside coverage, cutoff, excessive size, invalid request and provider failure all handled clearly |
| Paid, unpaid, cancelled order | Paid order books once; unpaid order stays unbooked; eligible cancellation propagates |
| Duplicate webhook / retry | No duplicate delivery or financial hold; failed required fetches retry without billing-address substitution |
| Pickup / delivery / return | Correct physical items, usable tracking link, order completion and return/failure notes |
| Partial/multi-address order | Explicitly supported or blocked before booking, with actionable feedback |
| Wallet funding | Test-only payment, correct workspace/currency and safe return to the embedded app |
| Browser compatibility | BigCommerce-supported browsers, restricted third-party cookies, expired sessions and actual iframe behavior |

A successful mocked quote is not proof of a real carrier connection. Include the live-rate checks below only after the
sandbox carrier is registered. For flat-method testing, name the method **Same-day by RentADriver** and disclose that
checkout does not perform a live RentADriver coverage check.

## 5. Register live checkout rates separately

Marketplace visibility and Shipping Provider registry acceptance are separate milestones. Contact the BigCommerce
app/partner team using the process in its [Shipping Provider guide](https://docs.bigcommerce.com/developer/docs/integrations/shipping-providers).
Prepare the following registration packet:

| Field | RentADriver value / action |
|---|---|
| Provider model | Single carrier: RentADriver |
| Quote URL | `https://api.rentadriver.ai/v1/bigcommerce/rates` |
| Connection check URL | `https://api.rentadriver.ai/v1/bigcommerce/rates/check` |
| Connection fields | `store_id` = RentADriver store UUID; `token` = that store's callback secret |
| Carrier identity | Confirm the assigned identifier against the current `carrier_info.code` value `rentadriver` |
| Carrier assets | Prepare the guide's 70 × 70 logo and requested name/description |
| Availability | Declare supported countries/service areas accurately |

The provider request's `base_options.store_id` is the BigCommerce store hash. It differs from the UUID in
`connection_options.store_id`. Do not include real connection tokens in listing assets, tickets or Git.

After acceptance, configure the carrier connection and shipping zones in the sandbox. Verify quoting from storefront
checkout, both successful and empty `carrier_quotes` responses, and order shipping-method matching. Only advertise
live rates after this works. If proposing a flat-method-only public launch, describe that limitation and confirm the
review team accepts that scope.

## 6. Assemble the public listing and reviewer package

Prepare these materials before opening the submission form:

- Consistent app name and approved company branding; suggested name: **RentADriver**.
- Accurate summary, category, features, supported regions and limitations. Do not claim multi-address, partial-shipment,
  live-rate or offline-payment support until verified. Avoid competitor references in the listing and app dashboard.
- Logos and screenshots at the dimensions required by the current portal form. Use actual UI with fake customer data.
- Pricing that explains wallet funding, per-delivery charges and any other applicable fees; obtain business approval
  rather than assuming the integration is free.
- Working support email/site, public installation and user guides, privacy policy and terms. Candidate existing pages
  are `https://rentadriver.ai/privacy` and `https://rentadriver.ai/terms`; confirm they describe the integration's actual
  data use and that support links work before submission.
- Reviewer instructions: installation, sample addresses/orders, test wallet/payment setup, staff access, tracking,
  cancellation and uninstall. Provide any necessary test accounts through the review channel, never in this file.

Preview the listing and complete every applicable field. Videos and case studies are optional. Use the
[current listing requirements](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/approval-requirements)
for wording and assets.

## 7. Submit, resolve review feedback and announce

In the portal's Apps overview, open the app's action menu, choose **Publish app…**, review the listing, then select
**Submit app for Review**. Confirm the current review/listing fee in the portal; do not rely on historical prices.
The documented feedback response window is 30 days. Track the case and answer within that window; recheck current
terms when submitting. See [submission instructions](https://docs.bigcommerce.com/developer/docs/integrations/apps/guide/publishing-apps).

After approval, record the actual listing URL and verify that installation is available to the intended public audience.
Replace the generic BigCommerce apps-directory link on the integrations page with that URL, and update its launch
status and onboarding copy to match the approved capabilities. These source changes need their own tests and gated
deployment before announcement.

Monitor installation/provisioning errors, webhook queue retries, missing funds, checkout latency and shipment sync.
Keep support and incident ownership explicit. If launch validation fails, hold onboarding and use the documented,
tested rollback process. Do not mark a listing as public or live rates as enabled merely because this branch was pushed.

## Release record template

```text
App ID / Partner ID:
Portal owner / release owner:
Final source commit:
CI pipeline and required job results:
Deployment and read-only smoke evidence:
Sandbox store / test matrix / screenshots:
Multi-user and session-revocation evidence:
Shipment retry/reconciliation evidence:
Carrier registration / assigned code / live-rate evidence:
Listing preview / support / pricing approval:
Reviewer case / submitted date / response due:
Approval date / public listing URL:
Post-publication installation result:
```
