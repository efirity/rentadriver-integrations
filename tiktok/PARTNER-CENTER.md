# TikTok Shop Partner Center: registration runbook

Status (2026-10-01): **account created** (services@efirity.com, partner ID 7494944350127686930, region Singapore, ISV; qualifications Shipping & Fulfillment / Shipping and / Returns, target UK, drafts). Data security questionnaire: draft saved, recorded locally in `compliance/` (git-ignored: answers, policy PDF). The gate emails to partner.us@ / partner.uk@tiktokshop.com are out
(`EMAIL-partner-*-gates.md`); question 5 in each asks where a Singapore entity must register for the US and UK markets.
Account creation is done by a person (sign-up, password, verification code); agents prepare and record only.

Source: `https://partner.tiktokshop.com/docv2/page/developer-onboarding` (read 2026-10-01).

## Which portal

| Target market | Portal | Note |
|---|---|---|
| US | `https://partner.us.tiktokshop.com/account/sign-up` | separate account from the non-US portal |
| UK and all other markets | `https://partner.tiktokshop.com/account/sign-up` | |

TikTok's rule: the **registration region must match the business certificate**; cross-border partners (China / Hong
Kong) may pick UK, MY, VN, TH, PH, SG; **local partners choose only their local market**. Our certificate is Singapore
(EFIRITY PTE. LTD., UEN 202402844G), so without an exception we would be a Singapore local partner on the non-US portal
with Singapore as the only target market. **Do not submit the developer application (step 2) until TikTok answers
question 5.** Creating the login itself (step 1) is market-agnostic and safe.

## Step 1: the login (safe now)

1. Open the sign-up page of the portal above. Choose **Sign Up**, not Log In.
2. Email: `services@efirity.com` (the mailbox every other marketplace registration uses; the toolkit reads it, so an
   agent can fetch the verification code with `mail_wait_for_code` while you type). **Not** any email that is tied to a
   TikTok Shop seller account: a seller email can only register as a seller developer.
3. Phone: optional; use the company number if asked.
4. Password: set it yourself and store it in the company password manager. It is never pasted in chat or in this repo.
5. After sign-in, set the account's notification email to `services@efirity.com` and keep it current (critical
   platform updates arrive there).

## Step 2: developer application (after TikTok's answer)

1. Business guide dialog → **For app developers (ISV)** → **Start Business**.
2. Registration region: **Singapore** (must match the certificate). Target market: as TikTok instructs (US / UK need
   their confirmation or an exception). Business category: **Shipping & Fulfillment** if offered for the region/market;
   otherwise the closest logistics category. The app category chosen later is irreversible (`SPEC.md` §3).
3. Company name and contact: `EFIRITY PTE. LTD.`, 68 Circular Road #02-01, Singapore 049422, `https://sg.efirity.com`,
   contact `services@efirity.com`; describe the business as "RentADriver, same-day local delivery for online stores,
   operated by Efirity", with `https://rentadriver.ai`.
4. Upload the business certificate and registration information (ACRA business profile / BizFile extract for the UEN).
   **Save for later** is available if the documents are not at hand; an app can be created meanwhile but not published.
5. Submit. Status: **My Account → My category & market** until "under review" disappears.

## Step 3: compliance and legal review (start immediately after step 2)

Mandatory for US and UK partners, three or more weeks. Email the market team for the due diligence questionnaire:
partner.us@tiktokshop.com (US), partner.uk@tiktokshop.com (UK). Prepare in advance: the ACRA extract, director ID,
proof that Efirity operates the RentADriver brand (DNS TXT on rentadriver.ai, the statement "RentADriver is operated by
Efirity"; see `apps/integrations/review-knowhow.md`), privacy policy and terms URLs (`https://rentadriver.ai/privacy`,
`https://rentadriver.ai/terms`), a data-handling description (order and recipient data used only to perform the
delivery, retention aligned with TikTok's 30-day post-completion masking).

## Record here

| Date | Portal | Step | Outcome |
|---|---|---|---|
| 2026-10-01 | partner.tiktokshop.com | 1 login | created with services@efirity.com |
| 2026-10-01 | partner.tiktokshop.com | 2 application | ISV, Singapore, UK, Shipping + Returns (drafts awaiting submission) |
| 2026-10-01 | partner.tiktokshop.com | security questionnaire | draft saved with policy PDF; owner to confirm 4 items and submit |
