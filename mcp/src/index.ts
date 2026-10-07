#!/usr/bin/env node
import { McpServer, ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { createServer } from "node:http";
import { createInterface } from "node:readline";
import { z } from "zod";
import { CONFIG_PATH, oauthLogin, readConfig, revokeOAuth, writeConfig } from "./auth.js";
import { loadConfig, RentADriverClient } from "./client.js";
import { registerTools } from "./tools.js";
import { registerApps } from "./app.js";
import { containRequestErrors, readRequestBody } from "./http-request.js";

import { readFileSync } from "node:fs";

const VERSION: string = JSON.parse(readFileSync(new URL("../package.json", import.meta.url), "utf8")).version;

function buildServer(api: RentADriverClient, hide?: ReadonlySet<string>): McpServer {
  const server = new McpServer({ name: "rentadriver", title: "RentADriver", version: VERSION, description: "From send it to delivered. Real package deliveries with upfront quotes, driver tracking and proof of handover.", websiteUrl: "https://rentadriver.ai", icons: [{ src: "https://rentadriver.ai/brand/icon.svg", mimeType: "image/svg+xml" }] }, { instructions: `RentADriver books real-world package deliveries fulfilled by vetted RentADriver drivers. Typical flow: check_coverage → get_quote (free) → create_delivery (dryRun first if unsure) → track_delivery → get_proof_of_delivery → confirm_delivery. Prices are in cents. If a call fails with insufficient_funds, deposit to the wallet first. API: ${api.apiUrl}` });
  registerTools(server, api, { hide });
  registerApps(server); // MCP Apps: ui:// cards for get_delivery / get_quote
  server.registerResource("openapi", "openapi://spec", { title: "RentADriver OpenAPI", description: "Full REST spec", mimeType: "application/json" }, async (uri) => ({ contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(await api.get("/.well-known/openapi.json", {}, false)) }] }));
  server.registerResource("delivery", new ResourceTemplate("delivery://{id}", { list: undefined }), { title: "Delivery", description: "A delivery by id or short code" }, async (uri, { id }) => ({ contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(await api.get(`/v1/deliveries/${id}`), null, 2) }] }));
  server.registerResource("coverage", new ResourceTemplate("coverage://{area}", { list: async () => { try { return { resources: ((await api.get<{ service_areas: Array<{ slug: string; name: string }> }>("/v1/service-areas", {}, false)).service_areas).map((a) => ({ uri: `coverage://${a.slug}`, name: a.name })) }; } catch { return { resources: [] }; } } }), { title: "Service area", description: "Coverage details for a city" }, async (uri, { area }) => {
    const all = await api.get<{ service_areas: Array<Record<string, unknown>> }>("/v1/service-areas", {}, false);
    return { contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(all.service_areas.find((a) => a.slug === area) ?? { error: "unknown area", known: all.service_areas.map((a) => a.slug) }, null, 2) }] };
  });
  server.registerPrompt("plan_delivery", { title: "Plan a delivery", description: "Walks through coverage, quote, create, track and confirm for one delivery.", argsSchema: { pickup: z.string().describe("Pickup address"), dropoff: z.string().describe("Drop-off address"), item: z.string().describe("What is being moved").optional(), deadline: z.string().describe("When it must arrive").optional() } },
    ({ pickup, dropoff, item, deadline }) => ({ messages: [{ role: "user", content: { type: "text", text: `Book a delivery with RentADriver.\nPickup: ${pickup}\nDrop-off: ${dropoff}\nItem: ${item ?? "package"}\nDeadline: ${deadline ?? "as soon as possible"}\n\nSteps: 1) check_coverage for the pickup. 2) get_quote and tell me the price and ETA. 3) If the price is reasonable, create_delivery with dryRun=false and the quote_id. 4) track_delivery until delivered, reporting each status change. 5) get_proof_of_delivery and confirm_delivery, then summarise cost and timing.` } }] }));
  return server;
}

const MCP_SNIPPET = JSON.stringify({ mcpServers: { rentadriver: { command: "npx", args: ["-y", "rentadriver-mcp"] } } }, null, 2);
const flag = (args: string[], name: string) => { const i = args.indexOf(`--${name}`); return i > -1 ? (args[i + 1] && !args[i + 1]!.startsWith("--") ? args[i + 1]! : "true") : undefined; };
const apiUrlFrom = (args: string[]) => (flag(args, "api-url") || process.env.RENTADRIVER_API_URL || readConfig().api_url || "https://api.rentadriver.ai").replace(/\/$/, "");

async function whoami(apiUrl: string): Promise<string> {
  const api = new RentADriverClient(loadConfig());
  if (api.authMethod === "none") return "Not signed in.";
  try { const a = await api.get<{ account?: { name?: string; currency?: string; wallet_balance_cents?: number; api_key?: { role?: string; prefix?: string } } }>("/v1/account"); const acc = a.account ?? {}; return `Signed in via ${api.authMethod} as ${acc.name ?? "?"} (${acc.api_key?.role ?? "admin"}, balance ${((acc.wallet_balance_cents ?? 0) / 100).toFixed(2)} ${acc.currency ?? ""}) — ${apiUrl}`; }
  catch (e) { return `Credentials present (${api.authMethod}) but ${apiUrl} rejected them: ${(e as Error).message}`; }
}

/** `setup` — interactive; `login` = browser OAuth; `logout`; `whoami`. Flags: --oauth | --key rd_… | --signup, --api-url, --no-open. */
async function setup(args: string[]) {
  const apiUrl = apiUrlFrom(args);
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  const ask = (q: string) => new Promise<string>((r) => rl.question(q, (a) => r(a.trim())));
  const done = (how: string) => { rl.close(); console.log(`\nSaved to ${CONFIG_PATH} (${how}).\n\nAdd to Claude Desktop / Cursor / VS Code:\n${MCP_SNIPPET}\n\nHosts that speak OAuth themselves can use the remote server instead: https://mcp.rentadriver.ai/mcp\nCommands: rentadriver-mcp whoami · login · logout\n`); };
  let mode = flag(args, "oauth") ? "1" : flag(args, "key") ? "2" : flag(args, "signup") ? "3" : "";
  if (!mode) {
    console.log(`RentADriver · MCP setup\nFrom “send it” to delivered.\n${apiUrl}\n\nHow do you want to sign in?\n  1) Browser sign-in with your RentADriver account (OAuth, recommended)\n  2) Paste an API key from https://rentadriver.ai/dashboard\n  3) Create a new account now (email signup, key issued immediately)\n`);
    mode = (await ask("Choose [1]: ")) || "1";
  }
  if (mode === "1") {
    try {
      const st = await oauthLogin(apiUrl, { open: flag(args, "no-open") !== "true", log: (m) => console.log(m) });
      console.log(`Signed in (scope ${st.scope ?? "read write"}, token refreshes automatically).`);
      console.log(await whoami(apiUrl));
      return done("OAuth");
    } catch (e) { rl.close(); console.error(`\nBrowser sign-in failed: ${(e as Error).message}\nRun \`rentadriver-mcp setup --key rd_…\` to use an API key instead.`); process.exitCode = 1; return; }
  }
  if (mode === "2") {
    const key = flag(args, "key") && flag(args, "key") !== "true" ? flag(args, "key")! : await ask("Paste your API key (rd_…): ");
    if (!key.startsWith("rd_")) { rl.close(); console.error("That does not look like a RentADriver key (rd_…)."); process.exitCode = 1; return; }
    writeConfig({ ...readConfig(), api_url: apiUrl, api_key: key, oauth: undefined });
    console.log(await whoami(apiUrl));
    return done("API key");
  }
  if (mode === "3") {
    const name = await ask("Company or agent name: "); const email = await ask("Email (for the verification link and receipts): ");
    const kind = (await ask("Account kind — agent / app / business [agent]: ")) || "agent";
    try {
      const r = await fetch(`${apiUrl}/v1/signup`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ name, email, kind }) });
      const j = (await r.json().catch(() => ({}))) as { api_key?: string; email_verification?: string; error?: { message?: string }; next_actions?: unknown };
      if (!r.ok || !j.api_key) throw new Error(j.error?.message ?? `signup failed (${r.status})`);
      writeConfig({ ...readConfig(), api_url: apiUrl, api_key: j.api_key, oauth: undefined });
      console.log(`\nAccount created. Key ${j.api_key.slice(0, 12)}… saved (shown only once by the API — it is in the config file).\nEmail verification: ${j.email_verification ?? "sent"} — verify to unlock the welcome credit and live bookings.`);
      return done("new account");
    } catch (e) { rl.close(); console.error(`\nSignup failed: ${(e as Error).message}`); process.exitCode = 1; return; }
  }
  rl.close(); console.error("Unknown choice."); process.exitCode = 1;
}

async function main() {
  const args = process.argv.slice(2);
  if (args[0] === "setup") return setup(args.slice(1));
  if (args[0] === "login") return setup(["--oauth", ...args.slice(1)]);
  if (args[0] === "logout") { const c = readConfig(); if (c.oauth) await revokeOAuth(apiUrlFrom(args), c.oauth); writeConfig({ ...c, api_key: undefined, oauth: undefined }); console.log("Signed out (tokens revoked, key removed from the config)."); return; }
  if (args[0] === "whoami") { console.log(await whoami(apiUrlFrom(args))); return; }
  if (args[0] === "--help" || args[0] === "-h") { console.log(`RentADriver · rentadriver-mcp ${VERSION}\nFrom “send it” to delivered.\nhttps://rentadriver.ai/docs/mcp\n\n  (no args)      stdio MCP server (Claude Desktop, Cursor, VS Code)\n  --http [port]  Streamable HTTP server with OAuth challenge\n  setup          interactive sign-in (--oauth | --key rd_… | --signup, --api-url, --no-open)\n  login          browser sign-in (OAuth)\n  logout         revoke tokens and clear the config\n  whoami         show the signed-in account`); return; }
  if (args[0] === "--version" || args[0] === "-v") { console.log(VERSION); return; }
  const cfg = loadConfig();
  const api = new RentADriverClient(cfg);
  if (args.includes("--http")) {
    // Remote Streamable HTTP mode (stateless): each request may carry its own x-api-key / Authorization header.
    const port = Number(process.env.PORT || args[args.indexOf("--http") + 1] || 8790);
    // OAuth (MCP authorization spec): this server is the protected RESOURCE; the RentADriver API is the authorization
    // server. Hosts discover it from the 401 challenge below → /.well-known/oauth-protected-resource → the API's
    // /.well-known/oauth-authorization-server, register dynamically, and send the rd_oat_ access token as a Bearer.
    const publicUrl = (process.env.MCP_PUBLIC_URL || "https://mcp.rentadriver.ai").replace(/\/$/, "");
    // Per-client tool hiding, decided from the User-Agent of each (stateless) request. Claude's connectors
    // directory rejects cryptocurrency transfers, so Claude-family clients (claude.ai connector, Claude
    // Desktop — NOT claude-cli/claude-code, whose users are agent developers) don't get the x402 deposit
    // tool by default. MCP_HIDE_TOOLS hides tools for every client; MCP_HIDE_TOOLS_CLAUDE overrides the
    // Claude-only default (set it to "" to serve Claude the full set).
    const parseTools = (v: string | undefined) => (v ?? "").split(",").map((s) => s.trim()).filter(Boolean);
    const CLAUDE_UA = /claude|anthropic/i, CLAUDE_DEV_UA = /claude-cli|claude-code/i;
    const hiddenFor = (ua: string): Set<string> => {
      const hidden = new Set(parseTools(process.env.MCP_HIDE_TOOLS));
      if (CLAUDE_UA.test(ua) && !CLAUDE_DEV_UA.test(ua)) for (const t of parseTools(process.env.MCP_HIDE_TOOLS_CLAUDE ?? "create_x402_deposit")) hidden.add(t);
      return hidden;
    };
    const cors = { "access-control-allow-origin": "*", "access-control-allow-headers": "Content-Type, Authorization, x-api-key, Mcp-Session-Id, Mcp-Protocol-Version", "access-control-allow-methods": "GET, POST, DELETE, OPTIONS", "access-control-expose-headers": "WWW-Authenticate, Mcp-Session-Id" };
    const prm = { resource: `${publicUrl}/mcp`, resource_name: "RentADriver MCP", authorization_servers: [cfg.apiUrl], bearer_methods_supported: ["header"], scopes_supported: ["read", "write"], resource_documentation: "https://rentadriver.ai/docs/mcp#oauth" };
    const http = createServer((req, res) => {
      void containRequestErrors(res, async () => {
      const path = (req.url ?? "/").split("?")[0]!;
      if (req.method === "OPTIONS") { res.writeHead(204, cors); res.end(); return; }
      if (path === "/health") { res.writeHead(200, { "content-type": "application/json", ...cors }); res.end(JSON.stringify({ ok: true, name: "rentadriver-mcp", version: VERSION, oauth: true })); return; }
      if (path === "/.well-known/oauth-protected-resource" || path === "/.well-known/oauth-protected-resource/mcp") { res.writeHead(200, { "content-type": "application/json", "cache-control": "public, max-age=300", ...cors }); res.end(JSON.stringify(prm)); return; }
      if (path !== "/mcp") { res.writeHead(404, cors); res.end("Use /mcp"); return; }
      const key = (req.headers["x-api-key"] as string | undefined) || (typeof req.headers.authorization === "string" && req.headers.authorization.toLowerCase().startsWith("bearer ") ? req.headers.authorization.slice(7) : undefined);
      if (!key && process.env.MCP_ALLOW_ANONYMOUS !== "true") {
        res.writeHead(401, { "content-type": "application/json", "www-authenticate": `Bearer realm="rentadriver", resource_metadata="${publicUrl}/.well-known/oauth-protected-resource", scope="read write"`, ...cors });
        res.end(JSON.stringify({ jsonrpc: "2.0", error: { code: -32001, message: "Unauthorized: connect with OAuth (see WWW-Authenticate) or send an API key in x-api-key / Authorization: Bearer" }, id: null }));
        return;
      }
      const body = await readRequestBody(req);
      // Remote callers must never inherit the operator’s local API key or OAuth session.
      const perReq = new RentADriverClient({ ...cfg, apiKey: key, oauth: undefined });
      const server = buildServer(perReq, hiddenFor(String(req.headers["user-agent"] ?? "")));
      const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
      res.on("close", () => {
        void Promise.allSettled([transport.close(), server.close()]);
      });
      await server.connect(transport);
      await transport.handleRequest(req, res, body);
      });
    });
    http.requestTimeout = 30_000;
    http.headersTimeout = 15_000;
    http.listen(port, () => console.error(`rentadriver-mcp HTTP on :${port}/mcp (API ${cfg.apiUrl}, OAuth resource ${publicUrl}/mcp)`));
    return;
  }
  const server = buildServer(api);
  await server.connect(new StdioServerTransport());
  console.error(`rentadriver-mcp ${VERSION} ready (API ${cfg.apiUrl}, key ${cfg.apiKey ? "set" : "missing — quotes still work"})`);
}
main().catch((e) => { console.error(e); process.exit(1); });
