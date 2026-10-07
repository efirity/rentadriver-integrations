export const API_URL = 'https://api.rentadriver.ai/v1';
export class InputError extends Error {}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export type Operation = 'connection' | 'quote' | 'create' | 'delivery' | 'events';
export type Request = { method: 'GET' | 'POST'; url: string; headers: Record<string, string>; body?: Record<string, unknown>; qs?: Record<string, string | number>; json: true };
export function uuid(value: unknown, field: string): string {
  if (typeof value !== 'string' || !UUID.test(value)) throw new InputError(`${field} must be a UUID`);
  return value;
}
function text(value: unknown, field: string, min: number, max: number): string {
  if (typeof value !== 'string' || value.trim().length < min || value.trim().length > max) throw new InputError(`${field} must contain ${min}–${max} characters`);
  return value.trim();
}
function flag(value: unknown, field: string): boolean {
  if (value === undefined) return false;
  if (typeof value !== 'boolean') throw new InputError(`${field} must be a boolean`);
  return value;
}
export function buildRequest(operation: Operation, workspace: unknown, values: Record<string, unknown>): Request {
  const headers = { 'x-workspace-id': uuid(workspace, 'Country workspace ID') };
  const request: Request = { method: 'GET', url: API_URL + '/automation/connection', headers, json: true };
  switch (operation) {
    case 'connection': return request;
    case 'quote': {
      const size = values.size ?? 'M';
      if (!['S', 'M', 'L', 'XL'].includes(String(size))) throw new InputError('Invalid package size');
      return { ...request, method: 'POST', url: API_URL + '/quotes', body: {
        workspace_id: headers['x-workspace-id'],
        pickup: { address: text(values.pickup, 'Pickup address', 3, 300) },
        dropoffs: [{ address: text(values.dropoff, 'Drop-off address', 3, 300) }],
        items: [{ description: text(values.description, 'Package description', 1, 300), quantity: 1, size_class: size, category: 'parcel' }],
        urgency: 'asap', service: 'auto',
      } };
    }
    case 'create': {
      if (!Number.isSafeInteger(values.maximumPrice) || Number(values.maximumPrice) < 0) throw new InputError('Maximum price must be a nonnegative safe integer in minor units');
      if (typeof values.currency !== 'string' || !/^[A-Z]{3}$/.test(values.currency)) throw new InputError('Currency must match the quote’s uppercase three-letter currency');
      return { ...request, method: 'POST', url: API_URL + '/automation/deliveries', body: {
        quote_id: uuid(values.quoteId, 'Quote ID'), source_id: text(values.sourceId, 'Source ID', 1, 200),
        currency: values.currency, max_price_cents: values.maximumPrice,
        fund: flag(values.fund, 'Fund and dispatch'), dryRun: flag(values.dryRun, 'Validate only'),
      } };
    }
    case 'delivery': return { ...request, url: API_URL + '/deliveries/' + uuid(values.deliveryId, 'Delivery ID') };
    case 'events': {
      const after = values.after ?? '0';
      if (typeof after !== 'string' || !/^\d{1,19}$/.test(after) || BigInt(after) > 9223372036854775807n) throw new InputError('After cursor must be a decimal string');
      return { ...request, url: API_URL + '/automation/events', qs: { after, limit: 100 } };
    }
    default: throw new InputError('Unsupported operation');
  }
}
