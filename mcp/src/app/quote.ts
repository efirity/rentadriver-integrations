/** Quote card — rendered when `get_quote` runs. Shows price, ETA, stops, breakdown and cheaper time slots. Read-only. */
import { boot, esc, money, km, mins, when } from "./common";

type Quote = Record<string, unknown> & { price_cents?: number; client_price_cents?: number; id?: string; currency: string; eta_pickup_min?: number | null; eta_dropoff_min?: number | null; distance_m?: number; duration_s?: number; breakdown?: Record<string, unknown>; cheaper_slots?: Array<{ at: string; price_cents: number; saving_pct?: number }>; quote_id?: string; service_kind?: string; expires_at?: string; pickup?: { address?: string }; dropoffs?: Array<{ address?: string }>; stops?: Array<{ type: string; address: string }> };

function lines(b: Record<string, unknown> | undefined, cur: string): string {
  if (!b) return "";
  const rows: Array<[string, string]> = [];
  const c = (k: string) => Number(b[k] ?? 0);
  if (c("base_cents")) rows.push(["Base", money(c("base_cents"), cur)]);
  if (c("distance_cents")) rows.push([`Distance (${km(Number(b.distance_km ?? 0) * 1000)})`, money(c("distance_cents"), cur)]);
  if (c("time_cents")) rows.push([`Time (${esc(String(b.duration_min ?? "?"))} min incl. pickup wait)`, money(c("time_cents"), cur)]);
  if (c("stops_cents")) rows.push(["Extra stops", money(c("stops_cents"), cur)]);
  const mult: string[] = [];
  for (const [k, label] of [["size_multiplier", "size"], ["urgency_multiplier", "urgency"], ["vehicle_multiplier", "vehicle"], ["surge", "demand"], ["peak_multiplier", "time of day"]] as const) { const v = Number(b[k] ?? 1); if (v && v !== 1) mult.push(`${label} ×${v.toFixed(2)}`); }
  if (mult.length) rows.push(["Multipliers", mult.join(" · ")]);
  return rows.length ? `<ul class="lines">${rows.map(([k, v]) => `<li><span>${esc(k)}</span><b>${esc(v)}</b></li>`).join("")}</ul>` : "";
}

boot("RentADriver quote", ({ input, data, error }) => {
  const raw = (data?.quote ?? (data && (data.price_cents != null || data.client_price_cents != null) ? data : null)) as Quote | null;
  const q = raw ? { ...raw, price_cents: Number(raw.client_price_cents ?? raw.price_cents ?? 0), quote_id: String(raw.quote_id ?? raw.id ?? "") } as Quote & { price_cents: number } : null;
  if (!q) { document.body.innerHTML = `<div class="card"><div class="eyebrow">Quote</div><div class="sub muted" style="margin-top:8px">${error ? esc(error) : "Pricing…"}</div></div>`; return; }
  const cur = q.currency || "USD";
  const stops = q.stops?.length ? q.stops : [{ type: "pickup", address: String(q.pickup?.address ?? input?.pickup ?? "Pickup") }, ...((q.dropoffs ?? (input?.dropoffs as Array<{ address?: string }> | undefined) ?? []).map((d) => ({ type: "dropoff", address: String(d.address ?? d) })))];
  const eta = q.eta_pickup_min != null ? `pickup in ~${q.eta_pickup_min} min, drop-off in ~${q.eta_dropoff_min ?? "?"} min` : "driver availability unconfirmed — book to start the search";
  document.body.innerHTML = `<div class="card">
    <div class="row between"><div><div class="eyebrow">Quote${q.service_kind === "intercity" ? " · city to city, same day" : ""}</div><div class="title">${esc(money(q.price_cents, cur))}</div></div><span class="pill ${q.eta_pickup_min != null ? "live" : "warn"}">${q.eta_pickup_min != null ? "drivers nearby" : "eta pending"}</span></div>
    <div class="sub" style="margin-top:4px">${esc(eta)}</div>
    <div class="stats"><div class="stat"><b>${esc(km(q.distance_m))}</b><span>route</span></div><div class="stat"><b>${esc(mins(q.duration_s))}</b><span>drive time</span></div><div class="stat"><b>${stops.length - 1}</b><span>drop-off${stops.length - 1 === 1 ? "" : "s"}</span></div></div>
    <ul class="stops">${stops.map((s, i) => `<li><div class="dot">${i + 1}</div><div class="addr">${esc(s.type === "pickup" ? "Pickup" : "Drop-off")} · ${esc(s.address)}</div><div></div></li>`).join("")}</ul>
    ${lines(q.breakdown, cur)}
    ${q.cheaper_slots?.length ? `<div class="eyebrow" style="margin-top:12px">Cheaper if it can wait</div><div class="chips">${q.cheaper_slots.map((s) => `<span class="chip">${esc(when(s.at))} · ${esc(money(s.price_cents, cur))}${s.saving_pct ? ` (−${esc(s.saving_pct)}%)` : ""}</span>`).join("")}</div>` : ""}
    ${error ? `<div class="err">${esc(error)}</div>` : ""}
    <div class="foot">${q.quote_id ? `Quote <span class="mono">${esc(q.quote_id)}</span>${q.expires_at ? ` valid until ${esc(when(q.expires_at))}` : ""}. ` : ""}Say "book it" in this chat to create and fund the delivery from your wallet — nothing is charged from this card.</div>
  </div>`;
});
