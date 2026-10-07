'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const app = require('../index');
const { draftInput, quoteInput } = require('../client');
const id = '00000000-0000-4000-8000-000000000001';
const bundle = { authData: { workspace_id: id, access_token: 'fixture-access', refresh_token: 'fixture-refresh' }, inputData: {} };
function transport(data, status = 200) {
  const calls = [];
  class ErrorWithCode extends Error { constructor(message, code) { super(message); this.code = code; } }
  return { calls, errors: { Error: ErrorWithCode, RefreshAuthError: ErrorWithCode }, request: async options => { calls.push(options); return { status, data }; } };
}
test('quotes preserve Unicode and scope; draft booking cannot dispatch or change mode', async () => {
  const input = { pickup: 'Ștefan cel Mare', dropoff: 'Bălți centru', description: 'Cheia sub piatră' };
  assert.equal(quoteInput(input, id).items[0].description, input.description);
  const draft = { quote_id: id, source_id: 'example-order', currency: 'GBP', max_price_cents: 1250, fund: true, mode: 'live' };
  assert.deepEqual(draftInput(draft), { quote_id: id, source_id: 'example-order', currency: 'GBP', max_price_cents: 1250, fund: false, dryRun: false });
  assert.throws(() => draftInput({ ...draft, max_price_cents: 12.5 }));
  assert.throws(() => draftInput({ ...draft, validate_only: 'false' }));
  const z = transport({ quote: { id } });
  await app.creates.quote.operation.perform(z, { ...bundle, inputData: input });
  assert.equal(z.calls[0].headers['x-workspace-id'], id);
  assert.equal(z.calls[0].headers.authorization, 'Bearer fixture-access');
  assert.throws(() => quoteInput(input, 'wrong-workspace'));
});
test('OAuth exchanges use PKCE and rotating refresh without changing workspace', async () => {
  const previous = process.env.CLIENT_ID; process.env.CLIENT_ID = 'fixture-client';
  try {
    const z = transport({ access_token: 'new-access', refresh_token: 'new-refresh' });
    const auth = app.authentication.oauth2Config;
    assert.equal(auth.enablePkce, true);
    const result = await auth.getAccessToken(z, { ...bundle, inputData: { code: 'fixture-code', code_verifier: 'fixture-verifier', redirect_uri: 'https://example.invalid/callback' } });
    assert.equal(z.calls[0].body.code_verifier, 'fixture-verifier');
    assert.equal(result.workspace_id, id);
    assert.equal(result.refresh_token, 'new-refresh');
    await auth.refreshAccessToken(z, bundle);
    assert.equal(z.calls[1].body.refresh_token, 'fixture-refresh');
    await assert.rejects(auth.refreshAccessToken(transport({ error: 'private-error' }, 400), bundle), /Connect again/);
  } finally { if (previous === undefined) delete process.env.CLIENT_ID; else process.env.CLIENT_ID = previous; }
});
test('event callbacks use only scoped authoritative events, retain bigint IDs and filter event type', async () => {
  const event = { id: '9007199254740993', cursor: '9007199254740994', event: 'delivery.delivered', data: { delivery_id: id, status: 'delivered' } };
  const z = transport({ events: [event] }), operation = app.triggers.delivery_event.operation;
  const b = { ...bundle, inputData: { event: 'delivery.delivered' }, cleanedRequest: { data: { data: { event_id: event.id, status: 'forged', secret: 'ignored' } } } };
  assert.deepEqual(await operation.perform(z, b), [event]);
  assert.deepEqual(z.calls[0].params, { event_id: event.id, limit: 1 });
  assert.deepEqual(await operation.perform(transport({ events: [] }), b), []);
  assert.deepEqual(await operation.perform(z, { ...b, inputData: { event: 'delivery.created' } }), []);
  assert.deepEqual(await operation.perform(z, { ...b, cleanedRequest: { data: { data: { event_id: Number(event.id) } } } }), []);
});
test('subscription lifecycle drops signing secrets and unsubscribe is scoped', async () => {
  const z = transport({ subscription: { id }, secret: 'fixture-signing-secret' }), op = app.triggers.delivery_event.operation;
  assert.deepEqual(await op.performSubscribe(z, { ...bundle, targetUrl: 'https://hooks.zapier.com/example', inputData: { event: 'delivery.delivered' } }), { id });
  await op.performUnsubscribe(z, { ...bundle, subscribeData: { id } });
  assert.equal(z.calls[1].method, 'DELETE');
  assert.equal(z.calls[1].headers['x-workspace-id'], id);
});
test('revoked credentials request refresh and private API errors stay out of action errors', async () => {
  await assert.rejects(app.creates.connection.operation.perform(transport({ message: 'secret-value' }, 403), bundle), error => !error.message.includes('secret-value'));
  await assert.rejects(app.creates.connection.operation.perform(transport({}, 401), bundle), /Reconnect/);
});
