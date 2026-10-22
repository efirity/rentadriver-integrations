/** Quote card — rendered when `get_quote` runs. Shows price, ETA, stops, breakdown and cheaper time slots. Read-only. */
import { boot, esc } from "./common";
import { quoteView } from "./quote-view";

boot("RentADriver quote", ({ input, data, error }) => {
  document.body.innerHTML = quoteView(data, input, error) ?? `<div class="card"><div class="eyebrow">Quote</div><div class="sub muted" style="margin-top:8px">${error ? esc(error) : "Pricing…"}</div></div>`;
});
