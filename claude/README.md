# RentADriver connector for Claude

Claude books deliveries through our **remote MCP server** at `https://mcp.rentadriver.ai/mcp`. There is no separate
app process and no Claude-specific code path: the same server serves Cursor, VS Code, ChatGPT and `npx rentadriver-mcp`.

Two ways a customer gets it, and they are independent:

| Route | Who can use it | State |
|---|---|---|
| **Custom connector** — Claude → Settings → Connectors → Add custom connector → the URL above | Anyone, any plan (Free is capped at one custom connector) | **Live.** Verified end to end 2026-09-16 |
| **Connectors directory** — RentADriver listed at claude.ai/directory for one-click install | Anyone, once approved | **In review** (see below) |

The directory adds discoverability, not capability. Nothing about the product is blocked on it.

The submitted listing values are recorded verbatim in [`directory-submission.json`](directory-submission.json);
customer-facing documentation is at <https://rentadriver.ai/docs/mcp>.

## Server vs plugin — they are different submissions

The directory has two sections, and RentADriver belongs in exactly one:

- **MCP server** (ours): a remote HTTPS service Claude connects to over the network. Works in claude.ai web, Desktop and
  mobile. OAuth per user. Closed source is fine.
- **Plugin**: a bundle installed into Claude Code / Cowork — skills, slash commands, subagents, hooks, local MCP configs.
  **Must link a public GitHub repo**; closed source is rejected. Only worth doing if we ship dev-side tooling.

## Auth (what a customer actually experiences)

OAuth 2.1, no API key to paste:

1. Claude hits `/mcp`, gets 401 + `WWW-Authenticate` naming `/.well-known/oauth-protected-resource`.
2. That points at the authorization server `https://api.rentadriver.ai`
   (`/.well-known/oauth-authorization-server`). Dynamic client registration (RFC 7591) + PKCE S256.
3. Browser opens `https://rentadriver.ai/oauth/authorize` — Continue with Google, email or SMS code, Create account,
   or **"Allow with a sandbox account"** (24 h key on the demo org, simulated drivers, nothing charged).
4. Access token `rd_oat_` valid 1 h, refresh `rd_ort_` 30 d rotating. Customers revoke under Developers → Connected
   apps, or `DELETE /v1/oauth/connections/:id`.

Verify the whole chain end to end after every deployment.

## create_x402_deposit is hidden from Claude clients

The directory rejects connectors that "transfer money, cryptocurrency, or other financial assets". Our x402 USDC
top-up tool reads as exactly that, so the server hides `create_x402_deposit` from Claude clients. Claude sees 42
tools, everyone else 43. `/v1/x402/wallet/deposit` on the REST API is untouched.

**The submission portal's own probe does not send a Claude user agent**, so the synced tool list on the listing shows
43 including x402. That is disclosed in the submission's Additional notes, with an offer to drop it globally if review
prefers.

## Directory submission (EFIRITY PTE. LTD.)

**Submitted ~2026-09-09; recorded state after the 2026-09-16 edit. Not a live status feed.**

| Field | Submitted value |
| --- | --- |
| Name / slug | RentADriver / `rentadriver` (permanent) |
| Organization | EFIRITY PTE. LTD. (Claude Team org, the company's developer contact address) |
| MCP endpoint | `https://mcp.rentadriver.ai/mcp`, streamable HTTP, Universal URL |
| Authentication | OAuth 2.0 + Dynamic Client Registration |
| Tools | 43 + prompt `plan_delivery` (re-synced 2026-09-16; previously 38) |
| Categories | Commerce & Shopping, Productivity, Development tools |
| Has an MCP App | Yes — `get_delivery` tracker card, `get_quote` quote card |
| Read / write | Read and write |
| API ownership | First-party |
