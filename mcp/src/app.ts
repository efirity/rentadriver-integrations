/**
 * MCP Apps (io.modelcontextprotocol/ui): interactive cards hosts can render for two tools — `get_delivery` (tracker with
 * stops, driver, ETA, proof photos, refresh) and `get_quote` (price, ETA, breakdown, cheaper slots). The HTML lives in
 * dist/ui/*.html (built by scripts/build-app.ts, SDK bundled inline); hosts that do not support MCP Apps just see the
 * normal JSON result. Images in the cards come from our own domains, hence the CSP resource domains below.
 */
import { registerAppResource, RESOURCE_MIME_TYPE } from "@modelcontextprotocol/ext-apps/server";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

export const UI = { delivery: "ui://rentadriver/delivery.html", quote: "ui://rentadriver/quote.html" } as const;
export const uiMeta = (kind: keyof typeof UI) => ({ ui: { resourceUri: UI[kind] } });
// Extra https origins the cards may load images from (proof-of-delivery photos come from the API's file storage).
// The hosted server sets RENTADRIVER_MEDIA_ORIGINS; without it the card links each photo instead of showing it.
const MEDIA_ORIGINS = (process.env.RENTADRIVER_MEDIA_ORIGINS ?? "").split(",").map((o) => o.trim()).filter((o) => /^https:\/\/[^/\s]+$/.test(o));
const RESOURCE_DOMAINS = ["https://rentadriver.ai", "https://api.rentadriver.ai", ...MEDIA_ORIGINS];

function htmlFor(kind: keyof typeof UI): string {
  const here = dirname(fileURLToPath(import.meta.url));
  for (const p of [join(here, "ui", `${kind}.html`), join(here, "..", "dist", "ui", `${kind}.html`)]) if (existsSync(p)) return readFileSync(p, "utf8");
  return `<!doctype html><meta charset="utf-8"><p style="font-family:system-ui">RentADriver ${kind} view is not bundled in this build (run npm run build).</p>`;
}

export function registerApps(server: McpServer): void {
  for (const kind of Object.keys(UI) as Array<keyof typeof UI>) {
    registerAppResource(server, `rentadriver-${kind}-app`, UI[kind], { mimeType: RESOURCE_MIME_TYPE, description: `Interactive ${kind} card (MCP App)`, _meta: { ui: { csp: { resourceDomains: RESOURCE_DOMAINS }, prefersBorder: false } } },
      async () => ({ contents: [{ uri: UI[kind], mimeType: RESOURCE_MIME_TYPE, text: htmlFor(kind), _meta: { ui: { csp: { resourceDomains: RESOURCE_DOMAINS }, prefersBorder: false } } }] }));
  }
}
