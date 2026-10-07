/**
 * Credentials for the local (stdio) server: ~/.rentadriver/config.json holds either an API key or an OAuth token set
 * obtained with `rentadriver-mcp login` (browser sign-in as a native public client: PKCE + loopback redirect, RFC 8252).
 * Access tokens last 1 h and are refreshed transparently by the client; refresh tokens rotate and last 30 d.
 */
import { createHash, randomBytes } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync, unlinkSync } from "node:fs";
import { createServer } from "node:http";
import { homedir } from "node:os";
import { join } from "node:path";
import { spawn } from "node:child_process";

/**
 * Branded loopback landing page (self-contained: served from 127.0.0.1, so no site assets besides Google Fonts).
 * Palette, logo, radii and fonts mirror the rentadriver.ai design system: blue accent (#2855d9), Sora/Inter/JetBrains Mono, 24px card radius, pill buttons, dark-aware.
 */
export function landingPage(ok: boolean, title: string, body: string): string {
  const icon = ok
    ? `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>`
    : `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>`;
  // Website mark (components/logo.tsx): rounded blue tile, white map-pin + R + road leg.
  const logo = `<svg width="30" height="30" viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="22" fill="var(--accent)"/><g fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"><path d="M32 51S16 37 16 25a16 16 0 0 1 32 0c0 12-16 26-16 26Z"/><path d="M26 20h8a5 5 0 0 1 0 10h-8v-10m8 10 6 7" stroke-width="3.5"/></g></svg>`;
  const next = ok ? `
      <div class="card"><div class="eyebrow">Your agent can now</div>
        <ol>
          <li><code>check_coverage</code> and <code>get_quote</code> — free, any address</li>
          <li><code>create_delivery</code> with <code>dryRun: true</code> — price and validate without booking</li>
          <li>Book, <code>track_delivery</code>, <code>get_proof_of_delivery</code>, <code>confirm_delivery</code></li>
        </ol>
        <p class="muted">Rehearse with a sandbox key (<code>rd_test_</code>): simulated drivers, nothing charged. Live bookings need a funded wallet.</p>
      </div>
      <div class="links"><a class="primary" href="https://rentadriver.ai/dashboard">Open the console</a><a href="https://rentadriver.ai/docs/mcp">MCP tools reference</a></div>` : `
      <div class="card"><div class="eyebrow">Try again</div><p>Back in the terminal run <code>npx -y rentadriver-mcp login</code>. If the browser keeps failing, use a key instead: <code>npx -y rentadriver-mcp setup --key rd_…</code>.</p></div>`;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title} · RentADriver</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ground:#f5f7fc;--panel:#ffffff;--ink:#14223b;--ink-2:#4d5d76;--muted:#65738a;--line:#dce3ef;
    --accent:${ok ? "#2855d9" : "#a8441a"};--accent-strong:${ok ? "#2045b4" : "#89471b"};--accent-soft:${ok ? "#e4ebff" : "#f6e3d8"};
    --f-body:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;--f-display:"Sora",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;--f-mono:"JetBrains Mono",ui-monospace,SFMono-Regular,Menlo,monospace;color-scheme:light dark;
  }
  @media (prefers-color-scheme: dark){:root{
    --ground:#101725;--panel:#161f30;--ink:#e6ecf8;--ink-2:#b5c1d8;--muted:#91a0bb;--line:#2a3853;
    --accent:${ok ? "#9db8ff" : "#f0935a"};--accent-strong:${ok ? "#c2d2ff" : "#f6ad7f"};--accent-soft:${ok ? "#20376b" : "#3a2416"};
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--ground);color:var(--ink);font:15px/1.55 var(--f-body),-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
  main{max-width:600px;margin:8vh auto 6vh;padding:0 22px}
  .brand{display:flex;align-items:center;gap:10px;font-family:var(--f-display);font-weight:700;font-size:17px;letter-spacing:-.03em}
  .brand .mark{width:30px;height:30px;display:grid;place-items:center}
  .hero{margin-top:40px;display:flex;gap:18px;align-items:flex-start}
  .badge{flex:0 0 46px;width:46px;height:46px;border-radius:50%;background:var(--accent);display:grid;place-items:center;box-shadow:0 12px 28px -14px var(--accent)}
  h1{font-family:var(--f-display);font-weight:600;font-size:30px;line-height:1.07;margin:4px 0 8px;letter-spacing:-.055em}
  .lede{color:var(--ink-2);margin:0;font-size:16px}
  .card{margin-top:28px;background:var(--panel);border:1px solid var(--line);border-radius:24px;padding:22px 24px}
  .eyebrow{font-family:var(--f-mono);font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px}
  ol{margin:0;padding-left:20px;display:grid;gap:8px}
  code{font-family:var(--f-mono);font-size:13px;background:var(--accent-soft);color:var(--accent-strong);padding:1px 6px;border-radius:5px}
  .muted{color:var(--muted);font-size:13.5px;margin:14px 0 0}
  .links{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
  .links a{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 22px;border-radius:24px;font-weight:600;font-size:14px;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:var(--panel);transition:background .15s,border-color .15s}
  .links a:hover{border-color:var(--accent)}
  .links a.primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .links a.primary:hover{background:var(--accent-strong);border-color:var(--accent-strong)}
  .foot{margin-top:34px;color:var(--muted);font-size:12.5px}
  .kbd{display:inline-block;border:1px solid var(--line);border-bottom-width:2px;border-radius:5px;padding:0 6px;font-size:12px;font-family:var(--f-mono);background:var(--panel)}
</style></head><body><main>
  <div class="brand"><span class="mark">${logo}</span>RentADriver</div>
  <div class="hero"><div class="badge">${icon}</div><div><h1>${title}</h1><p class="lede">${body}</p></div></div>
  ${next}
  <p class="foot">This page was served by the <code>rentadriver-mcp</code> process on your machine (127.0.0.1). Close the tab with <span class="kbd">⌘W</span> / <span class="kbd">Ctrl+W</span>.</p>
</main></body></html>`;
}


export interface OAuthState { client_id: string; access_token: string; refresh_token?: string; expires_at: number; scope?: string }
export interface StoredConfig { api_url?: string; api_key?: string; oauth?: OAuthState; oauth_client_id?: string }

export const CONFIG_DIR = join(homedir(), ".rentadriver");
export const CONFIG_PATH = join(CONFIG_DIR, "config.json");

export function readConfig(): StoredConfig {
  if (!existsSync(CONFIG_PATH)) return {};
  try { return JSON.parse(readFileSync(CONFIG_PATH, "utf8")) as StoredConfig; } catch { return {}; }
}
export function writeConfig(cfg: StoredConfig): void {
  mkdirSync(CONFIG_DIR, { recursive: true });
  writeFileSync(CONFIG_PATH, JSON.stringify(cfg, null, 2), { mode: 0o600 });
}
export function clearConfig(): void { if (existsSync(CONFIG_PATH)) unlinkSync(CONFIG_PATH); }

type Meta = { authorization_endpoint: string; token_endpoint: string; registration_endpoint?: string; revocation_endpoint?: string };
export async function fetchMetadata(apiUrl: string): Promise<Meta> {
  const r = await fetch(`${apiUrl}/.well-known/oauth-authorization-server`);
  if (!r.ok) throw new Error(`This API does not advertise OAuth (${r.status} from /.well-known/oauth-authorization-server). Use an API key instead.`);
  return (await r.json()) as Meta;
}

const form = (o: Record<string, string | undefined>) => new URLSearchParams(Object.entries(o).filter((e): e is [string, string] => typeof e[1] === "string"));

/** Exchange or refresh; returns the stored shape. */
export async function tokenRequest(tokenEndpoint: string, params: Record<string, string | undefined>, clientId: string): Promise<OAuthState> {
  const r = await fetch(tokenEndpoint, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: form({ ...params, client_id: clientId }) });
  const j = (await r.json().catch(() => ({}))) as { access_token?: string; refresh_token?: string; expires_in?: number; scope?: string; error?: string; error_description?: string };
  if (!r.ok || !j.access_token) throw new Error(`${j.error ?? r.status}: ${j.error_description ?? "token request failed"}`);
  return { client_id: clientId, access_token: j.access_token, refresh_token: j.refresh_token, expires_at: Date.now() + (j.expires_in ?? 3600) * 1000, scope: j.scope };
}

export async function refreshOAuth(apiUrl: string, st: OAuthState): Promise<OAuthState> {
  if (!st.refresh_token) throw new Error("No refresh token — run `rentadriver-mcp login` again.");
  const meta = await fetchMetadata(apiUrl);
  return tokenRequest(meta.token_endpoint, { grant_type: "refresh_token", refresh_token: st.refresh_token }, st.client_id);
}

function openBrowser(url: string): boolean {
  const cmd = process.platform === "darwin" ? ["open", url] : process.platform === "win32" ? ["cmd", "/c", "start", "", url.replace(/&/g, "^&")] : ["xdg-open", url];
  try { const p = spawn(cmd[0]!, cmd.slice(1), { stdio: "ignore", detached: true }); p.on("error", () => {}); p.unref(); return true; } catch { return false; }
}

/** Full browser sign-in. `log` receives progress lines (stderr in the CLI). Resolves with the token set already saved. */
export async function oauthLogin(apiUrl: string, opts: { scope?: string; open?: boolean; log?: (s: string) => void; timeoutMs?: number } = {}): Promise<OAuthState> {
  const log = opts.log ?? (() => {});
  const meta = await fetchMetadata(apiUrl);
  const cfg = readConfig();
  let clientId = cfg.oauth_client_id;
  if (!clientId) {
    if (!meta.registration_endpoint) throw new Error("Authorization server does not support dynamic client registration");
    const r = await fetch(meta.registration_endpoint, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ client_name: "rentadriver-mcp CLI", redirect_uris: ["http://127.0.0.1/callback"], token_endpoint_auth_method: "none", software_id: "rentadriver-mcp", client_uri: "https://rentadriver.ai/docs/mcp" }) });
    const j = (await r.json().catch(() => ({}))) as { client_id?: string; error_description?: string };
    if (!r.ok || !j.client_id) throw new Error(`Client registration failed: ${j.error_description ?? r.status}`);
    clientId = j.client_id; writeConfig({ ...cfg, oauth_client_id: clientId });
  }
  const verifier = randomBytes(32).toString("base64url"), challenge = createHash("sha256").update(verifier).digest("base64url"), state = randomBytes(16).toString("base64url");

  return new Promise<OAuthState>((resolve, reject) => {
    const server = createServer(async (req, res) => {
      const u = new URL(req.url ?? "/", "http://127.0.0.1");
      if (u.pathname !== "/callback") { res.writeHead(404); res.end(); return; }
      const fail = (msg: string) => { res.writeHead(400, { "content-type": "text/html" }); res.end(landingPage(false, "Sign-in did not complete", `${msg}. Nothing was changed on your account.`)); cleanup(); reject(new Error(msg)); };
      if (u.searchParams.get("state") !== state) return fail("State mismatch");
      const err = u.searchParams.get("error"); if (err) return fail(`Authorization ${err}`);
      const code = u.searchParams.get("code"); if (!code) return fail("No code in callback");
      try {
        const st = await tokenRequest(meta.token_endpoint, { grant_type: "authorization_code", code, code_verifier: verifier, redirect_uri: redirectUri }, clientId!);
        writeConfig({ ...readConfig(), api_url: apiUrl, api_key: undefined, oauth: st });
        res.writeHead(200, { "content-type": "text/html" }); res.end(landingPage(true, "Signed in to RentADriver", "This terminal now acts as your account. You can close this tab and go back to the terminal."));
        cleanup(); resolve(st);
      } catch (e) { fail((e as Error).message); }
    });
    let redirectUri = "";
    const timer = setTimeout(() => { cleanup(); reject(new Error("Timed out waiting for the browser (5 min)")); }, opts.timeoutMs ?? 5 * 60_000);
    const cleanup = () => { clearTimeout(timer); server.close(); };
    server.listen(0, "127.0.0.1", () => {
      const port = (server.address() as { port: number }).port;
      redirectUri = `http://127.0.0.1:${port}/callback`;
      const url = new URL(meta.authorization_endpoint);
      for (const [k, v] of Object.entries({ response_type: "code", client_id: clientId!, redirect_uri: redirectUri, scope: opts.scope ?? "read write", state, code_challenge: challenge, code_challenge_method: "S256" })) url.searchParams.set(k, v);
      log(`Open this link to approve access (it should open by itself):\n\n  ${url.toString()}\n`);
      if (opts.open !== false) openBrowser(url.toString());
      log("Waiting for the browser…");
    });
  });
}

export async function revokeOAuth(apiUrl: string, st: OAuthState): Promise<void> {
  try {
    const meta = await fetchMetadata(apiUrl);
    if (!meta.revocation_endpoint) return;
    for (const token of [st.refresh_token, st.access_token]) if (token) await fetch(meta.revocation_endpoint, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: form({ token, client_id: st.client_id }) }).catch(() => {});
  } catch { /* offline logout is fine */ }
}
