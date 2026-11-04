import { render } from "preact";
import { useEffect, useState } from "preact/hooks";

// The order's Print menu → "RentADriver label". The preview loads a short-lived signed PDF URL from the RentADriver
// API; Shopify adds the ID token to this fetch because api.rentadriver.ai is a subdomain of the app URL.
const API = "https://api.rentadriver.ai";

export default async () => {
  render(<Extension />, document.body);
};

function Extension() {
  const [size, setSize] = useState("4x6");
  const [src, setSrc] = useState(null);
  const [error, setError] = useState("");
  const orderId = shopify.data.selected?.[0]?.id;

  useEffect(() => {
    let alive = true;
    setSrc(null);
    setError("");
    if (!orderId) { setError("Open an order to print its RentADriver label."); return; }
    fetch(`${API}/v1/shopify/print/orders/${encodeURIComponent(orderId)}/label-link?size=${size}`)
      .then((r) => r.json())
      .then((j) => { if (!alive) return; if (j.success && j.url) setSrc(j.url); else setError(j.error?.message ?? "This order has no RentADriver label."); })
      .catch(() => { if (alive) setError("Couldn't load the RentADriver label. Try again."); });
    return () => { alive = false; };
  }, [orderId, size]);

  return (
    <s-admin-print-action src={src ?? undefined}>
      <s-stack direction="block" gap="base">
        {error ? <s-banner tone="warning">{error}</s-banner> : null}
        <s-choice-list label="Label size" name="size" onChange={(e) => setSize(e.currentTarget.values?.[0] ?? "4x6")}>
          <s-choice value="4x6" selected={size === "4x6"}>4 × 6 in (thermal)</s-choice>
          <s-choice value="a4" selected={size === "a4"}>A4</s-choice>
        </s-choice-list>
      </s-stack>
    </s-admin-print-action>
  );
}
