// Pure delivery-area rules, shared by the checkout function (run.js) and its tests. They mirror the API's
// pickup-zone rules: a radius zone is a distance from the pickup location,
// a postal-code zone matches the normalised postcode exactly, or by prefix when the code ends with "*".

/** @typedef {{ type: "radius", lat: number, lng: number, km: number, country?: string } | { type: "postal", codes: string[], country?: string }} Zone */
/** @typedef {{ v: number, hide: string[], zones: Zone[] }} Config */

const normalizedPostal = (value) => String(value ?? "").toUpperCase().replace(/[\s-]/g, "");
const sameTitle = (a, b) => String(a ?? "").trim().toLowerCase() === String(b ?? "").trim().toLowerCase();

/** Great-circle distance in metres. */
export function haversineM(a, b) {
  const R = 6371e3, rad = (d) => (d * Math.PI) / 180;
  const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
}

/**
 * Is this delivery address inside one of the zones? null = can't tell (no postcode and no coordinates), in which case
 * nothing is hidden and booking still checks the address.
 */
export function insideAreas(address, zones) {
  const country = String(address?.countryCode ?? "").toUpperCase();
  const postal = normalizedPostal(address?.zip);
  const point = typeof address?.latitude === "number" && typeof address?.longitude === "number" ? { lat: address.latitude, lng: address.longitude } : null;
  let decidable = false;
  for (const zone of zones ?? []) {
    if (zone.country && country && zone.country.toUpperCase() !== country) { decidable = true; continue; }
    if (zone.type === "postal") {
      if (!postal) continue;
      decidable = true;
      if (zone.codes.some((code) => { const c = normalizedPostal(code); return c.endsWith("*") ? postal.startsWith(c.slice(0, -1)) : postal === c; })) return true;
    } else if (zone.type === "radius") {
      if (!point) continue;
      decidable = true;
      if (haversineM({ lat: zone.lat, lng: zone.lng }, point) <= zone.km * 1000) return true;
    }
  }
  if (!(zones ?? []).length) return false;
  return decidable ? false : null;
}

/** The function's operations: hide the RentADriver rates for every delivery group whose address is outside all zones. */
export function deliveryOperations(input) {
  /** @type {Config | undefined} */
  const config = input?.deliveryCustomization?.metafield?.jsonValue;
  if (!config || config.v !== 1 || !Array.isArray(config.hide) || !config.hide.length || !Array.isArray(config.zones)) return [];
  const operations = [];
  for (const group of input?.cart?.deliveryGroups ?? []) {
    if (!group?.deliveryAddress || insideAreas(group.deliveryAddress, config.zones) !== false) continue;
    for (const option of group.deliveryOptions ?? []) {
      if (config.hide.some((title) => sameTitle(title, option.title))) operations.push({ deliveryOptionHide: { deliveryOptionHandle: option.handle } });
    }
  }
  return operations;
}
