/** Delivery tracker card — rendered by hosts when `get_delivery` runs. Refresh re-calls the tool; nothing here spends money. */
import { boot, esc, money, km, mins, when, payload } from "./common";

const STATUS: Record<string, [string, string]> = {
  created: ["Created", "dim"], quoted: ["Quoted", "dim"], funded: ["Funded", "next"], dispatching: ["Finding a driver", "warn"], assigned: ["Driver assigned", "next"],
  en_route_pickup: ["Driver heading to pickup", "live"], at_pickup: ["Driver at pickup", "live"], picked_up: ["Picked up", "live"], en_route_dropoff: ["On the way", "live"],
  at_dropoff: ["Driver at drop-off", "live"], delivered: ["Delivered", "live"], confirmed: ["Confirmed", "live"], cancelled: ["Cancelled", "bad"], no_driver_found: ["No driver found", "bad"], failed: ["Failed", "bad"], disputed: ["Disputed", "bad"],
};
type Stop = { seq: number; type: string; address: string; contact_name?: string | null; arrived_at?: string | null; completed_at?: string | null; window_start?: string | null; window_end?: string | null };
type Delivery = Record<string, unknown> & { short_code: string; status: string; currency: string; price_cents: number; tip_cents?: number; stops?: Stop[]; driver?: { first_name?: string; rating?: number; jobs_completed?: number; vehicle_class?: string | null; location_updated_at?: string } | null; proof?: { photos?: string[]; signature?: string; recipient_name?: string } | null; eta_pickup_at?: string | null; eta_dropoff_at?: string | null; tracking_url?: string; distance_m?: number; duration_s?: number; dispatch_summary?: { reason?: string; waves?: number; radius_km?: number; minutes_searched?: number } | null; next_action?: string | null; delivered_at?: string | null; picked_up_at?: string | null };

const root = document.body;
let current: Delivery | null = null;

function view(d: Delivery, busy = false, err?: string): string {
  const [label, tone] = STATUS[d.status] ?? [d.status, "dim"];
  const stops = (d.stops ?? []).slice().sort((a, b) => a.seq - b.seq);
  const live = ["assigned", "en_route_pickup", "at_pickup", "picked_up", "en_route_dropoff", "at_dropoff"].includes(d.status);
  const eta = d.status === "delivered" || d.status === "confirmed" ? `Delivered ${when(d.delivered_at)}` : d.eta_dropoff_at ? `ETA ${when(d.eta_dropoff_at)}` : d.eta_pickup_at ? `Pickup ETA ${when(d.eta_pickup_at)}` : "ETA pending";
  const proof = d.proof ?? {};
  const photos = Array.isArray(proof.photos) ? proof.photos.filter((p) => /^https:\/\//.test(String(p))) : [];
  const ds = d.dispatch_summary;
  return `<div class="card">
    <div class="row between"><div><div class="eyebrow">Delivery</div><div class="title mono">${esc(d.short_code)}</div></div><span class="pill ${tone}">${esc(label)}</span></div>
    <div class="stats">
      <div class="stat"><b>${esc(money(d.price_cents, d.currency))}</b><span>price${Number(d.tip_cents) > 0 ? ` + ${esc(money(d.tip_cents, d.currency))} tip` : ""}</span></div>
      <div class="stat"><b>${esc(km(d.distance_m))}</b><span>${esc(mins(d.duration_s))} drive</span></div>
      <div class="stat"><b>${esc(eta)}</b><span>${live && d.driver ? `${esc(d.driver.first_name ?? "Driver")}${d.driver.vehicle_class ? " · " + esc(d.driver.vehicle_class) : ""}${d.driver.rating ? " · ★ " + Number(d.driver.rating).toFixed(1) : ""}` : d.status === "dispatching" ? "searching for a driver" : "no driver yet"}</span></div>
    </div>
    ${ds && d.status === "dispatching" ? `<div class="sub">${esc(ds.reason === "no_drivers_on_shift" ? "No driver on shift nearby yet — the search keeps running." : ds.reason === "drivers_filtered" ? "Nearby drivers' filters skipped this job so far — widening the search." : "Searching for a driver…")}${ds.radius_km ? ` Radius ${esc(ds.radius_km)} km` : ""}${ds.minutes_searched ? `, ${esc(ds.minutes_searched)} min` : ""}</div>` : ""}
    <ul class="stops">${stops.map((s, i) => { const done = !!s.completed_at; const here = !done && !!s.arrived_at; return `<li><div class="dot ${done ? "done" : here ? "ember" : ""}">${done ? "✓" : i + 1}</div><div><div class="addr">${esc(s.type === "pickup" ? "Pickup" : `Drop-off ${stops.filter((x) => x.type === "dropoff").indexOf(s) + 1}`)} · ${esc(s.address)}</div><div class="sub">${esc(s.contact_name ?? "")}${s.window_start ? ` · window ${esc(when(s.window_start))}–${esc(when(s.window_end))}` : ""}</div></div><div class="sub">${done ? esc(when(s.completed_at)) : here ? "arrived" : ""}</div></li>`; }).join("")}</ul>
    ${photos.length || proof.signature ? `<div class="eyebrow" style="margin-top:12px">Proof of delivery${proof.recipient_name ? ` · received by ${esc(proof.recipient_name)}` : ""}</div><div class="proof">${photos.map((p) => `<img src="${esc(p)}" alt="proof photo" loading="lazy">`).join("")}${proof.signature && /^https:\/\//.test(String(proof.signature)) ? `<img src="${esc(proof.signature)}" alt="signature">` : ""}</div>` : ""}
    <div class="actions"><button class="btn primary" id="refresh" ${busy ? "disabled" : ""}>${busy ? "Refreshing…" : "Refresh"}</button>${d.tracking_url ? `<button class="btn" id="track">Open tracking page</button>` : ""}${d.next_action ? `<span class="sub" style="align-self:center">Next: ${esc(String(d.next_action).replace(/_/g, " "))}</span>` : ""}</div>
    ${err ? `<div class="err">${esc(err)}</div>` : ""}
    <div class="foot">To cancel, add a tip or confirm delivery, ask in this chat. This card is read-only. Live driver location is on the tracking page.</div>
  </div>`;
}

const app = boot("RentADriver delivery", ({ input, data, error }) => {
  const d = (data?.delivery ?? (data && data.short_code ? data : null)) as Delivery | null;
  if (d) current = d;
  if (!current) { root.innerHTML = `<div class="card"><div class="eyebrow">Delivery</div><div class="title mono">${esc(String(input?.delivery_id ?? "…"))}</div><div class="sub muted" style="margin-top:8px">${error ? esc(error) : "Loading…"}</div></div>`; return; }
  root.innerHTML = view(current, false, error);
  wire();
});

function wire() {
  document.getElementById("refresh")?.addEventListener("click", async () => {
    if (!current) return;
    root.innerHTML = view(current, true); wire();
    try {
      const r = await app.callServerTool({ name: "get_delivery", arguments: { delivery_id: current.short_code } });
      const p = payload(r as never); const d = (p?.delivery ?? p) as Delivery | null;
      if (d && d.short_code) current = d;
      root.innerHTML = view(current!, false, (r as { isError?: boolean }).isError ? String(p?.message ?? "Refresh failed") : undefined);
    } catch (e) { root.innerHTML = view(current!, false, String((e as Error).message ?? e)); }
    wire();
  });
  document.getElementById("track")?.addEventListener("click", () => { if (current?.tracking_url) void app.openLink({ url: current.tracking_url }); });
  // A photo the host's content policy blocks becomes a link that opens it outside the card.
  root.querySelectorAll<HTMLImageElement>(".proof img").forEach((img) => img.addEventListener("error", () => {
    const url = img.currentSrc || img.src; const link = document.createElement("button"); link.className = "btn"; link.textContent = img.alt === "proof photo" ? "View photo" : "View signature";
    link.addEventListener("click", () => void app.openLink({ url })); img.replaceWith(link);
  }, { once: true }));
}
