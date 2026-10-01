# RentADriver for ChatGPT

OpenAI portal export for the submitted version: [rentadriver-1-0-0.json](rentadriver-1-0-0.json).

Machine-readable submission record: [chatgpt-app-submission.json](chatgpt-app-submission.json).

**Submitted for OpenAI review on 2026-09-16.** This is the recorded status at
submission, not a live status feed. Public availability is pending approval.
The portal confirmed “RentADriver submitted for review” and displayed
“Viewing the review version. Only draft versions can be edited.”

## Submission

| Field | Submitted value |
| --- | --- |
| Name | RentADriver |
| Version | 1.0.0 |
| Subtitle | Quote, book and track parcels |
| Developer / organization | EFIRITY PTE. LTD. |
| Category | Business & Operations |
| MCP endpoint | https://mcp.rentadriver.ai/mcp |
| Authentication | OAuth with dynamic client registration and PKCE |
| Availability | GB, US, MD; en-US |
| Tools | 43, with all 129 annotation justifications completed |
| Domain | `rentadriver.ai` verified |

- [Plugin status dashboard](https://platform.openai.com/plugins)
- A 20-minute review demo video was submitted with the listing; playback was verified before submission.
- [Support](https://rentadriver.ai/support), [privacy policy](https://rentadriver.ai/privacy), [terms](https://rentadriver.ai/terms)

Release notes: “Initial release: check parcel delivery coverage, request quotes,
book deliveries and track delivery progress through a connected RentADriver account.”

Directory and composer icons were uploaded using the website icon.

## Purchasing declarations

The submission discloses external purchases because the card-deposit tool links
to Stripe Checkout for prepaid wallet funding. The description explains that
RentADriver arranges physical parcel pickup and delivery, with payment completed
outside ChatGPT.

The product owner explicitly confirmed the three purchasing declarations and authorized
submission: no digital goods, services or subscriptions; no prohibited purchases;
and compliance of payment activity with applicable laws, including AML, consumer
protection and sanctions. These are the submitter's attestations, not an
independent legal assessment. The main policy declarations were also checked.

## Reviewer access and validation

On the OAuth consent page, select **Allow with a sandbox account**. This supplies
a 24-hour demo key, test wallet funds and simulated drivers; no password, MFA,
SMS or email code is required. Reconnect through the sandbox option when access
expires. No credentials or tokens are stored in this record.

The sandbox account comes with completed demo deliveries that provide tracking
and simulated proof. List sandbox deliveries and select an existing one.

ChatGPT checks covered coverage, quotes, account/workspace balances, delivery
tracking/proof and a booking preview with `dryRun=true`, `fund=false`. The preview
left delivery count and wallet balance unchanged. Two transient read failures
passed on sequential retry. Passenger-taxi and PDF-email requests were declined
without delivery tools; birthday-card writing used no delivery tools.

The submitted build was deployed after its applicable CI checks and builds passed:

- Post-deployment sandbox probes passed for service areas, coverage, account status,
  country workspaces, wallet balance, delivery listing and quotes.
- OpenAI rescanned the deployed tools successfully before submission; all 129
  justifications were retained.

## Review history

- 2026-09-25: 1.0.0 was rejected because tool annotations did not match behaviour. Quotes were marked
  as writes, additive tools as destructive, and lookups as open-world.
- 2026-09-26: fixed and resubmitted with the per-tool justifications in
  [tool-justifications.json](tool-justifications.json); table in [tool-annotations.md](tool-annotations.md).
  Keep the justification files in step with every tool change.

## Maintaining this record

OpenAI said it will notify the organization when a decision is made. Record the
decision and any requested changes here, and record the public listing URL once
publication is confirmed. Do not treat submission as approval or publication.
