#!/usr/bin/env node
import { McpServer, ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { createServer, type IncomingMessage } from "node:http";
import { createInterface } from "node:readline";
import { z } from "zod";
import { CONFIG_PATH, oauthLogin, readConfig, revokeOAuth, writeConfig } from "./auth.js";
import { loadConfig, RentADriverClient } from "./client.js";
import { PUBLIC_TOOLS, registerTools } from "./tools.js";
import { createSessionCheck } from "./session-check.js";
import { registerApps } from "./app.js";
import { containRequestErrors, readRequestBody } from "./http-request.js";

import { readFileSync } from "node:fs";

const VERSION: string = JSON.parse(readFileSync(new URL("../package.json", import.meta.url), "utf8")).version;

function buildServer(api: RentADriverClient, hide?: ReadonlySet<string>, only?: ReadonlySet<string>, signIn?: { challenge: string }): McpServer {
  const server = new McpServer({ name: "rentadriver", title: "RentADriver", version: VERSION, description: "From send it to delivered. Real package deliveries with upfront quotes, driver tracking and proof of handover.", websiteUrl: "https://rentadriver.ai", icons: [{ src: "https://rentadriver.ai/brand/mark-square-512.png", mimeType: "image/png", sizes: ["512x512"] }, { src: "https://rentadriver.ai/brand/mark-square.svg", mimeType: "image/svg+xml", sizes: ["any"] }] }, { instructions: `RentADriver books real-world package deliveries fulfilled by vetted RentADriver drivers. Typical flow: check_coverage → get_quote (free) → create_delivery (dryRun first if unsure) → track_delivery → get_proof_of_delivery → confirm_delivery. Prices are in cents. If a call fails with insufficient_funds, deposit to the wallet first. API: ${api.apiUrl}` });
  registerTools(server, api, { hide, only, signIn });
  // ChatGPT reads `securitySchemes` on the tool itself; the SDK passes only `_meta` through, so copy it up at list time.
  const handlers = (server.server as unknown as { _requestHandlers: Map<string, (req: unknown, extra: unknown) => Promise<{ tools: Array<{ _meta?: { securitySchemes?: unknown } }> }>> })._requestHandlers;
  const listTools = handlers.get("tools/list");
  if (listTools) handlers.set("tools/list", async (req, extra) => { const out = await listTools(req, extra); return { ...out, tools: out.tools.map((t) => ({ ...t, securitySchemes: t._meta?.securitySchemes })) }; });
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
    // ChatGPT's connector identifies itself as "openai-mcp/…"; it asks for sign-in from a tool result, not an HTTP 401.
    const TOOL_RESULT_SIGN_IN = new RegExp(process.env.MCP_TOOL_RESULT_SIGN_IN_UA || "openai|chatgpt", "i");
    const hiddenFor = (ua: string): Set<string> => {
      const hidden = new Set(parseTools(process.env.MCP_HIDE_TOOLS));
      if (CLAUDE_UA.test(ua) && !CLAUDE_DEV_UA.test(ua)) for (const t of parseTools(process.env.MCP_HIDE_TOOLS_CLAUDE ?? "create_x402_deposit")) hidden.add(t);
      return hidden;
    };
    // Anonymous callers (docs, quotes, coverage) are limited per client IP; signed-in calls are limited by the API.
    const ANON_PER_MIN = Number(process.env.MCP_ANON_RATE_PER_MIN || 120);
    const anonHits = new Map<string, { start: number; n: number }>();
    const anonLimited = (ip: string): number => {
      const now = Date.now(); const w = anonHits.get(ip);
      if (!w || now - w.start >= 60_000) { if (anonHits.size > 50_000) anonHits.clear(); anonHits.set(ip, { start: now, n: 1 }); return 0; }
      return ++w.n > ANON_PER_MIN ? Math.ceil((w.start + 60_000 - now) / 1000) : 0;
    };
    const clientIp = (req: IncomingMessage) => String(req.headers["x-forwarded-for"] ?? "").split(",")[0]!.trim() || req.socket.remoteAddress || "unknown";
    const cors = { "access-control-allow-origin": "*", "access-control-allow-headers": "Content-Type, Authorization, x-api-key, Mcp-Session-Id, Mcp-Protocol-Version", "access-control-allow-methods": "GET, POST, DELETE, OPTIONS", "access-control-expose-headers": "WWW-Authenticate, Mcp-Session-Id" };
    // A token the API no longer accepts (revoked key, expired connector session) is handled like no token.
    const sessionRejected = createSessionCheck(cfg.apiUrl);
    const prm = { resource: `${publicUrl}/mcp`, resource_name: "RentADriver MCP", authorization_servers: [cfg.apiUrl], bearer_methods_supported: ["header"], scopes_supported: ["read", "write"], resource_documentation: "https://rentadriver.ai/docs/mcp#oauth" };
    const http = createServer((req, res) => {
      void containRequestErrors(res, async () => {
      const path = (req.url ?? "/").split("?")[0]!;
      if (req.method === "OPTIONS") { res.writeHead(204, cors); res.end(); return; }
      if (path === "/health") { res.writeHead(200, { "content-type": "application/json", ...cors }); res.end(JSON.stringify({ ok: true, name: "rentadriver-mcp", version: VERSION, oauth: true })); return; }
      // Glama directory ownership proof (glama.ai/mcp/connectors/ai.rentadriver.mcp/...). The claim token is meant to be public.
      if (path === "/.well-known/glama.json") { res.writeHead(200, { "content-type": "application/json", "cache-control": "public, max-age=300", ...cors }); res.end(JSON.stringify({ $schema: "https://glama.ai/mcp/schemas/connector.json", claim: "glama_claim_Svuf5pASsmDG7vvin92mMa_SK5Sh5fHK" })); return; }
      if (path === "/.well-known/oauth-protected-resource" || path === "/.well-known/oauth-protected-resource/mcp") { res.writeHead(200, { "content-type": "application/json", "cache-control": "public, max-age=300", ...cors }); res.end(JSON.stringify(prm)); return; }
      // /public: no sign-in, only the account-free tools (coverage, cities, lanes, quotes, stats), so people can try
      // quotes before signing up. Any key sent here is ignored; /mcp keeps its OAuth challenge for the full tool set.
      const anonymous = !req.headers["x-api-key"] && !(typeof req.headers.authorization === "string" && /^bearer /i.test(req.headers.authorization));
      const retryAfter = anonymous && (path === "/public" || path === "/mcp") && req.method === "POST" ? anonLimited(clientIp(req)) : 0;
      if (retryAfter) { res.writeHead(429, { "content-type": "application/json", "retry-after": String(retryAfter), ...cors }); res.end(JSON.stringify({ jsonrpc: "2.0", error: { code: -32029, message: `Too many requests without an account. Retry in ${retryAfter}s, or sign in.` }, id: null })); return; }
      if (path === "/public") {
        const body = await readRequestBody(req);
        const server = buildServer(new RentADriverClient({ ...cfg, apiKey: undefined, oauth: undefined }), undefined, PUBLIC_TOOLS);
        const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
        res.on("close", () => { void Promise.allSettled([transport.close(), server.close()]); });
        await server.connect(transport);
        await transport.handleRequest(req, res, body);
        return;
      }
      if (path !== "/mcp") { res.writeHead(404, cors); res.end("Use /mcp"); return; }
      const key = (req.headers["x-api-key"] as string | undefined) || (typeof req.headers.authorization === "string" && req.headers.authorization.toLowerCase().startsWith("bearer ") ? req.headers.authorization.slice(7) : undefined);
      // Lazy sign-in (one URL for everyone): without a key, initialize, tools/list and the account-free tools work, and
      // calling a tool that needs an account asks the host to sign in. Hosts differ in how they want that asked:
      //  - Claude, VS Code, Zed and the MCP SDK clients start OAuth on an HTTP 401 + WWW-Authenticate for that request;
      //  - ChatGPT starts it from a tool result carrying _meta["mcp/www_authenticate"] (Apps SDK), so it gets that instead.
      // `?login` keeps sign-in-first for hosts that decide once, at setup, by whether the first request is refused
      // (grok.com, Le Chat, Perplexity, Gemini). MCP_LAZY_AUTH=false restores sign-in-first for everyone.
      const ua = String(req.headers["user-agent"] ?? "");
      const challenge = (error: string, description: string) => `Bearer realm="rentadriver", error="${error}", error_description="${description}", resource_metadata="${publicUrl}/.well-known/oauth-protected-resource", scope="read write"`;
      const refuse = (description: string) => {
        res.writeHead(401, { "content-type": "application/json", "www-authenticate": challenge("invalid_token", description), ...cors });
        res.end(JSON.stringify({ jsonrpc: "2.0", error: { code: -32001, message: `Unauthorized: ${description}. Connect with OAuth (see WWW-Authenticate) or send an API key in x-api-key / Authorization: Bearer` }, id: null }));
      };
      const signInFirst = new URLSearchParams((req.url ?? "").split("?")[1] ?? "").has("login") || process.env.MCP_LAZY_AUTH === "false";
      if (!key && signInFirst) { refuse("Sign in to use RentADriver"); return; }
      const body = await readRequestBody(req);
      const messages = (Array.isArray(body) ? body : [body]) as Array<{ method?: string; params?: { name?: unknown } }>;
      const needsAccount = messages.map((m) => m?.method === "tools/call" ? m.params?.name : undefined).find((n): n is string => typeof n === "string" && !PUBLIC_TOOLS.has(n));
      // A token the API rejects counts as no sign-in, so the host re-runs OAuth instead of resending it.
      let signedIn = key;
      if (key && needsAccount && await sessionRejected(key)) signedIn = undefined;
      if (!signedIn && needsAccount) {
        const expired = !!key;
        console.error(`[auth] ${expired ? "expired" : "anonymous"} ${needsAccount} → ${TOOL_RESULT_SIGN_IN.test(ua) ? "tool-result" : "401"} sign-in (ua ${ua.slice(0, 80)})`);
        if (!TOOL_RESULT_SIGN_IN.test(ua)) { refuse(expired ? "Your RentADriver sign-in has expired. Sign in again" : `Sign in to RentADriver to use ${needsAccount}`); return; }
      }
      // Remote callers must never inherit the operator’s local API key or OAuth session.
      const perReq = new RentADriverClient({ ...cfg, apiKey: signedIn, oauth: undefined });
      const server = buildServer(perReq, hiddenFor(ua), undefined, signedIn ? undefined : { challenge: challenge(key ? "invalid_token" : "insufficient_scope", key ? "Your RentADriver sign-in has expired. Sign in again" : "Sign in to RentADriver to continue") });
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
