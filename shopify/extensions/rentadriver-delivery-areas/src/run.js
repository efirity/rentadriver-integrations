// @ts-check
import { deliveryOperations } from "./areas.js";

/**
 * Shopify checkout: hide the RentADriver shipping rates when the delivery address is outside every delivery area the
 * store configured in the RentADriver app (the app writes them to this customization's metafield).
 * @param {any} input
 */
export function run(input) {
  return { operations: deliveryOperations(input) };
}
