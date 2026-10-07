'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const nock = require('nock');
const { createAppTester } = require('zapier-platform-core');
const app = require('../index');
const tester = createAppTester(app);
const id = '00000000-0000-4000-8000-000000000001';
const bundle = { authData: { workspace_id: id, access_token: 'fixture-access', refresh_token: 'fixture-refresh' }, inputData: {} };
nock.disableNetConnect();
test('Zapier runtime serializes scoped Unicode requests and maps real API response envelopes', async () => {
  const quote = { id, currency: 'GBP', client_price_cents: 1250 };
  const scope = nock('https://api.rentadriver.ai', { reqheaders: { authorization: 'Bearer fixture-access', 'x-workspace-id': id } })
    .post('/v1/quotes', b => b.workspace_id === id && b.items[0].description === 'Cheia sub piatră').reply(200, { success: true, quote })
    .get('/v1/deliveries/' + id).reply(200, { success: true, delivery: { id, status: 'draft' } })
    .post('/v1/automation/deliveries', b => b.fund === false && b.max_price_cents === 1250 && b.dryRun === true).reply(200, { success: true, dry_run: true, quote });
  assert.deepEqual(await tester(app.creates.quote.operation.perform, { ...bundle, inputData: { pickup: 'Northampton High Street', dropoff: 'Northampton Station', description: 'Cheia sub piatră' } }), quote);
  assert.deepEqual(await tester(app.creates.delivery.operation.perform, { ...bundle, inputData: { delivery_id: id } }), { id, status: 'draft' });
  const result = await tester(app.creates.draft.operation.perform, { ...bundle, inputData: { quote_id: id, source_id: 'example-order', currency: 'GBP', max_price_cents: 1250, validate_only: true } });
  assert.equal(result.dry_run, true); assert.equal(scope.isDone(), true);
});
test('Zapier runtime reads the nested webhook envelope and never emits the signing secret', async () => {
  const event = { id: '9007199254740993', cursor: '2', event: 'delivery.delivered', data: { delivery_id: id } };
  const scope = nock('https://api.rentadriver.ai')
    .post('/v1/automation/subscriptions').reply(201, { subscription: { id }, secret: 'fixture-secret' })
    .get('/v1/automation/events').query({ event_id: event.id, limit: 1 }).reply(200, { events: [event] });
  const b = { ...bundle, inputData: { event: event.event }, targetUrl: 'https://hooks.zapier.com/example', cleanedRequest: { data: { event: event.event, data: { event_id: event.id } } } };
  assert.deepEqual(await tester(app.triggers.delivery_event.operation.performSubscribe, b), { id });
  assert.deepEqual(await tester(app.triggers.delivery_event.operation.perform, b), [event]);
  // The pinned SDK discovers response-body secret fields before scrubbing HTTP logs.
  const scrubber = require('@zapier/secret-scrubber');
  assert.deepEqual(scrubber.findSensitiveValues({ secret: 'fixture-secret' }), ['fixture-secret']);
  assert.equal(scope.isDone(), true);
});

test('PKCE exchange uses form encoding and censors one-use code/verifier in SDK HTTP logs', async () => {
  const oldClient = process.env.CLIENT_ID, oldDetailed = process.env.DETAILED_LOG_TO_STDOUT;
  const originalLog = console.log, logs = [];
  process.env.CLIENT_ID = 'fixture-client'; process.env.DETAILED_LOG_TO_STDOUT = '1';
  console.log = (...args) => logs.push(args.map(String).join(' '));
  try {
    const scope = nock('https://api.rentadriver.ai')
      .post('/oauth/token', body => body.code_verifier === 'fixture-private-verifier' && body.code === 'fixture-private-code')
      .reply(200, { access_token: 'fixture-private-access', refresh_token: 'fixture-private-refresh' });
    const result = await tester(app.authentication.oauth2Config.getAccessToken, { authData: { workspace_id: id }, inputData: { code: 'fixture-private-code', code_verifier: 'fixture-private-verifier', redirect_uri: 'https://example.invalid/callback' } });
    assert.equal(scope.isDone(), true);
    assert.equal(result.pkce_secret, undefined);
    assert.equal(result.oauth_code_secret, undefined);
    const text = logs.join(' ');
    assert.equal(text.includes('fixture-private-verifier'), false);
    assert.equal(text.includes('fixture-private-code'), false);
    assert.equal(text.includes('fixture-private-access'), false);
    assert.equal(text.includes('fixture-private-refresh'), false);
  } finally {
    console.log = originalLog;
    for (const [key,value] of [['CLIENT_ID',oldClient],['DETAILED_LOG_TO_STDOUT',oldDetailed]]) {
      if (value === undefined) delete process.env[key]; else process.env[key] = value;
    }
  }
});
