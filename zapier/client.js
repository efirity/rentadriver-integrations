'use strict';
const API = 'https://api.rentadriver.ai/v1';
const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
function uuid(value, name) {
  if (typeof value !== 'string' || !UUID.test(value)) throw new Error(`${name} must be a UUID.`);
  return value;
}
function text(value, name, max) {
  if (typeof value !== 'string' || !value.trim() || value.trim().length > max) throw new Error(`Check ${name}.`);
  return value.trim();
}
async function request(z, bundle, path, method = 'GET', body, params) {
  const workspace = uuid(bundle.authData.workspace_id, 'Country workspace ID');
  const response = await z.request({ url: API + path, method, body, params,
    headers: { authorization: `Bearer ${bundle.authData.access_token}`, 'x-workspace-id': workspace, 'content-type': 'application/json' },
    skipThrowForStatus: true });
  if (response.status === 401) throw new z.errors.RefreshAuthError('Reconnect RentADriver.');
  if (response.status >= 400) throw new z.errors.Error(`RentADriver request failed (${response.status}). Check access and input values.`, 'RentADriverError', response.status);
  return response.data;
}
function quoteInput(input, workspace) {
  const size = input.size || 'M';
  if (!['S', 'M', 'L', 'XL'].includes(size)) throw new Error('Invalid package size.');
  const pickup = text(input.pickup, 'pickup address', 300), dropoff = text(input.dropoff, 'drop-off address', 300);
  if (pickup.length < 3 || dropoff.length < 3) throw new Error('Addresses need at least three characters.');
  return { workspace_id: uuid(workspace, 'Country workspace ID'), pickup: { address: pickup },
    dropoffs: [{ address: dropoff }], items: [{ description: text(input.description, 'description', 300), quantity: 1, size_class: size, category: 'parcel' }], urgency: 'asap', service: 'auto' };
}
function draftInput(input) {
  if (!Number.isSafeInteger(input.max_price_cents) || input.max_price_cents < 0) throw new Error('Maximum price must be integer minor units.');
  if (!/^[A-Z]{3}$/.test(input.currency || '')) throw new Error('Use the quote currency.');
  if (input.validate_only !== undefined && typeof input.validate_only !== 'boolean') throw new Error('Validate only must be a boolean.');
  return { quote_id: uuid(input.quote_id, 'Quote ID'), source_id: text(input.source_id, 'source ID', 200), currency: input.currency,
    max_price_cents: input.max_price_cents, fund: false, dryRun: input.validate_only ?? false };
}
module.exports = { API, uuid, request, quoteInput, draftInput };
