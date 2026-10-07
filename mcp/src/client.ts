import { readConfig, refreshOAuth, writeConfig, type OAuthState } from "./auth.js";

export interface ClientConfig { apiUrl: string; apiKey?: string; mock: boolean; oauth?: OAuthState }

/** Env wins over ~/.rentadriver/config.json; an OAuth token set (from `login`) is used like a key and refreshed on expiry. */
export function loadConfig(): ClientConfig {
  const f = readConfig();
  const oauth = !process.env.RENTADRIVER_API_KEY && !f.api_key ? f.oauth : undefined;
  return {
    apiUrl: (process.env.RENTADRIVER_API_URL || f.api_url || "https://api.rentadriver.ai").replace(/\/$/, ""),
    apiKey: process.env.RENTADRIVER_API_KEY || f.api_key || oauth?.access_token,
    mock: process.env.RENTADRIVER_MOCK_MODE === "true",
    oauth,
  };
}

export class RentADriverClient {
  private refreshing?: Promise<void>;
  constructor(private cfg: ClientConfig, private extraHeaders: Record<string, string> = {}) {}
  get hasKey() { return !!this.cfg.apiKey; }
  get apiUrl() { return this.cfg.apiUrl; }
  get authMethod(): "oauth" | "api_key" | "none" { return this.cfg.oauth ? "oauth" : this.cfg.apiKey ? "api_key" : "none"; }

  /** Refresh the OAuth access token (once per expiry, shared between concurrent calls) and persist it. */
  private async ensureFreshToken(force = false): Promise<void> {
    const st = this.cfg.oauth; if (!st) return;
    if (!force && st.expires_at - Date.now() > 60_000) return;
    if (!this.refreshing) this.refreshing = (async () => {
      try { const next = await refreshOAuth(this.cfg.apiUrl, st); this.cfg.oauth = next; this.cfg.apiKey = next.access_token; try { writeConfig({ ...readConfig(), oauth: next }); } catch { /* read-only home */ } }
      finally { this.refreshing = undefined; }
    })();
    await this.refreshing;
  }

  async request<T = unknown>(method: string, path: string, body?: unknown, opts: { auth?: boolean; query?: Record<string, string | number | boolean | undefined>; _retried?: boolean } = {}): Promise<T> {
    // Public endpoints (auth:false) must keep working even when the stored OAuth session is dead: refresh only when the
    // call needs credentials, and send no stale token on public calls.
    let expiredSession = false;
    if (this.cfg.oauth) {
      try { await this.ensureFreshToken(); }
      catch (e) { if (opts.auth) throw new Error(`OAuth session expired (${(e as Error).message}). Run: npx -y rentadriver-mcp login`); expiredSession = true; }
    }
    const url = new URL(this.cfg.apiUrl + path);
    for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined && v !== "") url.searchParams.set(k, String(v));
    const headers: Record<string, string> = { "Content-Type": "application/json", "x-rd-client": "rentadriver-mcp/0.3.0", ...this.extraHeaders };
    if (this.cfg.apiKey && !expiredSession) headers["x-api-key"] = this.cfg.apiKey;
    if (opts.auth && !this.cfg.apiKey) throw new Error("Not signed in. Run `npx -y rentadriver-mcp login` (browser sign-in), set RENTADRIVER_API_KEY, or call the signup tool.");
    const res = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(30_000) });
    const text = await res.text();
    let json: unknown; try { json = JSON.parse(text); } catch { json = { raw: text }; }
    if (res.status === 401 && this.cfg.oauth && !opts._retried) { await this.ensureFreshToken(true); return this.request<T>(method, path, body, { ...opts, _retried: true }); }
    if (!res.ok) {
      const err = (json as { error?: { code?: string; message?: string; details?: unknown } }).error;
      const e = new Error(`${err?.code ?? res.status}: ${err?.message ?? text.slice(0, 200)}`) as Error & { status: number; code?: string; details?: unknown; body?: unknown };
      e.status = res.status; e.code = err?.code; e.details = err?.details; e.body = json;
      throw e;
    }
    return json as T;
  }
  get<T = unknown>(path: string, query?: Record<string, string | number | boolean | undefined>, auth = true) { return this.request<T>("GET", path, undefined, { auth, query }); }
  post<T = unknown>(path: string, body?: unknown, auth = true) { return this.request<T>("POST", path, body ?? {}, { auth }); }
  patch<T = unknown>(path: string, body?: unknown) { return this.request<T>("PATCH", path, body ?? {}, { auth: true }); }
  del<T = unknown>(path: string) { return this.request<T>("DELETE", path, undefined, { auth: true }); }
}
