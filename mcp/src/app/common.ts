/** Shared bits for the RentADriver MCP Apps (delivery tracker, quote card). Vanilla DOM, no framework. */
import { App, applyDocumentTheme, applyHostStyleVariables, applyHostFonts } from "@modelcontextprotocol/ext-apps";

export const CSS = `
:root { --bg: #ffffff; --panel: #f5f7fc; --ink: #14223b; --ink2: #4d5d76; --muted: #65738a; --line: #dce3ef; --accent: #2855d9; --accent-strong: #2045b4; --accent-soft: #e4ebff; --ember: #a85b21; --ember-soft: #faeadb; --ok: #2e7d4f; --ok-soft: #e2f1e8; --signal: #a8441a; --signal-soft: #f6e3d8; --on-accent: #ffffff; font: 14px/1.45 Inter, -apple-system, "Segoe UI", system-ui, sans-serif; color: var(--ink); }
:root[data-theme="dark"], .dark { --bg: #161f30; --panel: #1c2940; --ink: #e6ecf8; --ink2: #b5c1d8; --muted: #91a0bb; --line: #2a3853; --accent: #9db8ff; --accent-strong: #c2d2ff; --accent-soft: #20376b; --ember: #f0935a; --ember-soft: #3a2416; --ok: #5ac488; --ok-soft: #16301f; --signal: #f08a5d; --signal-soft: #3a2218; --on-accent: #101725; }
* { box-sizing: border-box; } body { margin: 0; background: transparent; color: var(--ink); }
.card { background: var(--bg); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; max-width: 720px; }
.row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; } .between { justify-content: space-between; }
.eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
.title { font-size: 20px; font-weight: 800; margin: 2px 0 0; letter-spacing: -.01em; } .mono { font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
.pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.pill.live { background: var(--accent-soft); color: var(--accent); } .pill.ok { background: var(--ok-soft); color: var(--ok); } .pill.next { background: var(--accent-soft); color: var(--accent); } .pill.warn { background: var(--ember-soft); color: var(--ember); } .pill.bad { background: var(--signal-soft); color: var(--signal); } .pill.dim { background: var(--panel); color: var(--muted); }
.stat { display: grid; gap: 2px; } .stat b { font-size: 22px; font-weight: 800; letter-spacing: -.02em; } .stat span { font-size: 12px; color: var(--muted); }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin: 14px 0; }
.stops { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 8px; }
.stops li { display: grid; grid-template-columns: 22px 1fr auto; gap: 10px; align-items: start; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; background: var(--panel); }
.dot { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--accent); display: grid; place-items: center; font-size: 10px; font-weight: 800; color: var(--accent); margin-top: 2px; }
.dot.done { background: var(--ok); border-color: var(--ok); color: var(--bg); } .dot.ember { border-color: var(--ember); color: var(--ember); }
.addr { font-weight: 600; } .sub { font-size: 12.5px; color: var(--ink2); }
.btn { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 8px; padding: 7px 12px; font-weight: 600; font-size: 13px; cursor: pointer; } .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); } .btn.primary:hover:not(:disabled) { background: var(--accent-strong); border-color: var(--accent-strong); } .btn:not(.primary):hover:not(:disabled) { border-color: var(--accent); color: var(--accent); } .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; } .btn:disabled { opacity: .6; cursor: default; }
.actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
.proof { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; } .proof img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); }
.lines { margin: 10px 0 0; padding: 0; list-style: none; font-size: 13px; } .lines li { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px dashed var(--line); } .lines li:last-child { border-bottom: 0; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; } .chip { border: 1px solid var(--line); border-radius: 999px; padding: 4px 10px; font-size: 12px; background: var(--panel); }
.muted { color: var(--muted); } .err { color: var(--signal); font-size: 13px; margin-top: 8px; } .foot { margin-top: 12px; font-size: 12px; color: var(--muted); }
`;

export function esc(s: unknown): string { return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c] as string)); }
export function money(cents: unknown, currency: string): string { const n = Number(cents ?? 0) / 100; try { return new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "USD" }).format(n); } catch { return `${n.toFixed(2)} ${currency}`; } }
export function km(m: unknown): string { const v = Number(m ?? 0); return v < 1000 ? `${Math.round(v)} m` : `${(v / 1000).toFixed(1)} km`; }
export function mins(s: unknown): string { const m = Math.round(Number(s ?? 0) / 60); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`; }
export function when(iso: unknown): string { if (!iso) return "—"; const d = new Date(String(iso)); return isNaN(d.getTime()) ? String(iso) : d.toLocaleString(undefined, { hour: "2-digit", minute: "2-digit", day: "numeric", month: "short" }); }

/** Tool results arrive as structuredContent (preferred) or as JSON text in content[0]. */
export function payload(result: { structuredContent?: unknown; content?: Array<{ type: string; text?: string }> } | undefined): Record<string, unknown> | null {
  if (!result) return null;
  if (result.structuredContent && typeof result.structuredContent === "object") return result.structuredContent as Record<string, unknown>;
  const t = result.content?.find((c) => c.type === "text")?.text; if (!t) return null;
  try { return JSON.parse(t) as Record<string, unknown>; } catch { return { message: t }; }
}

/** Boot: theme + host fonts, connect, and hand tool input/result to the renderer. */
export function boot(name: string, render: (ctx: { app: App; input?: Record<string, unknown>; data?: Record<string, unknown> | null; error?: string }) => void): App {
  const style = document.createElement("style"); style.textContent = CSS; document.head.appendChild(style);
  const app = new App({ name, version: "1" });
  let input: Record<string, unknown> | undefined;
  const sync = (ctx: { theme?: string; styles?: { variables?: Record<string, string>; css?: { fonts?: string } } } | undefined) => {
    if (!ctx) return;
    if (ctx.theme) { applyDocumentTheme(ctx.theme as "light" | "dark"); document.documentElement.dataset.theme = ctx.theme; }
    if (ctx.styles?.variables) applyHostStyleVariables(ctx.styles.variables as never);
    if (ctx.styles?.css?.fonts) applyHostFonts(ctx.styles.css.fonts);
  };
  app.ontoolinput = (p) => { input = (p.arguments ?? {}) as Record<string, unknown>; render({ app, input }); };
  app.ontoolresult = (r) => { const data = payload(r as never); render({ app, input, data, error: (r as { isError?: boolean }).isError ? String(data?.message ?? data?.error ?? "Tool call failed") : undefined }); };
  app.onhostcontextchanged = (ctx) => sync(ctx as never);
  app.connect().then(() => sync(app.getHostContext() as never)).catch((e) => render({ app, error: String(e) }));
  return app;
}
