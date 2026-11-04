/**
 * Is the key or OAuth token a remote host sent still accepted by the API? A host that holds a revoked or expired
 * token (an old connector session) must be asked to sign in again, the same way as a host that sent none. If it got
 * a plain tool error instead, Claude and ChatGPT would keep resending the dead token and never show "Connect".
 *
 * Accepted tokens are cached for a few minutes so a session's calls cost one extra API round trip, not one per call.
 * A rejection is never cached (signing in again must work at once), and an API that can't be reached counts as
 * "not rejected": the tool call then runs and reports the outage itself rather than sending the user to sign in.
 */
import { createHash } from "node:crypto";

export function createSessionCheck(apiUrl: string, opts: { ttlMs?: number; fetchImpl?: typeof fetch; maxEntries?: number } = {}) {
  const ttl = opts.ttlMs ?? 5 * 60_000, max = opts.maxEntries ?? 10_000, doFetch = opts.fetchImpl ?? fetch;
  const accepted = new Map<string, number>();
  const id = (key: string) => createHash("sha256").update(key).digest("base64url");
  return async function rejected(key: string): Promise<boolean> {
    const k = id(key), until = accepted.get(k);
    if (until && until > Date.now()) return false;
    let status: number;
    try {
      const res = await doFetch(`${apiUrl.replace(/\/$/, "")}/v1/account`, { headers: { "x-api-key": key, "x-rd-client": "rentadriver-mcp/session-check" }, signal: AbortSignal.timeout(5_000) });
      status = res.status;
      await res.body?.cancel();
    } catch { return false; }
    if (status === 401) { accepted.delete(k); return true; }
    if (status < 500) { if (accepted.size >= max) accepted.clear(); accepted.set(k, Date.now() + ttl); }
    return false;
  };
}
