const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buildRequest, API_URL } = require('../dist/nodes/RentADriver/request');
const { RentADriver } = require('../dist/nodes/RentADriver/RentADriver.node');
const { RentADriverOAuth2Api } = require('../dist/credentials/RentADriverOAuth2Api.credentials');
const { RentADriverApi } = require('../dist/credentials/RentADriverApi.credentials');
const id = '00000000-0000-4000-8000-000000000001';
const booking = { quoteId: id, sourceId: 'order-1', currency: 'GBP', maximumPrice: 1250 };

test('draft default, explicit funding, stable retry values and validation-only', () => {
  const draft = buildRequest('create', id, booking);
  assert.equal(draft.body.fund, false);
  assert.equal(draft.body.dryRun, false);
  assert.deepEqual(buildRequest('create', id, booking), draft);
  assert.equal(buildRequest('create', id, { ...booking, fund: true }).body.fund, true);
  assert.equal(buildRequest('create', id, { ...booking, dryRun: true }).body.dryRun, true);
  for (const price of [-1, 1.5, '1250', NaN, Number.MAX_SAFE_INTEGER + 1]) {
    assert.throws(() => buildRequest('create', id, { ...booking, maximumPrice: price }));
  }
  for (const values of [{ sourceId: ' ' }, { quoteId: 'https://example.com' }, { currency: 'gbp' }, { fund: 'false' }]) {
    assert.throws(() => buildRequest('create', id, { ...booking, ...values }));
  }
});

test('workspace required; fixed hosts and strict delivery path; Unicode stays intact', () => {
  assert.throws(() => buildRequest('connection', undefined, {}));
  assert.throws(() => buildRequest('delivery', id, { deliveryId: '../account' }));
  const quote = buildRequest('quote', id, { pickup: 'Ștefan cel Mare', dropoff: 'Bălți centru', description: 'Cheia sub piatră' });
  assert.equal(quote.body.pickup.address, 'Ștefan cel Mare');
  assert.equal(quote.body.items[0].description, 'Cheia sub piatră');
  assert.equal(quote.body.workspace_id, quote.headers['x-workspace-id']);
  assert.equal(quote.url, API_URL + '/quotes');
  assert.throws(() => buildRequest('quote', id, { pickup: 'Valid address', dropoff: 'Other address', description: 'Package', size: 'XS' }));
});

test('polling preserves bigint cursor strings and rejects unsafe coercion', () => {
  assert.equal(buildRequest('events', id, { after: '9007199254740993' }).qs.after, '9007199254740993');
  assert.equal(buildRequest('events', id, {}).qs.after, '0');
  for (const after of [9007199254740993, '-1', '1.2', '9223372036854775808']) assert.throws(() => buildRequest('events', id, { after }));
});

test('OAuth is PKCE with fixed endpoints; API-key fallback stores a password field', () => {
  const credential = new RentADriverOAuth2Api();
  assert.deepEqual(credential.extends, ['oAuth2Api']);
  const props = Object.fromEntries(credential.properties.map(p => [p.name, p]));
  assert.equal(props.grantType.default, 'pkce');
  assert.equal(props.scope.type, 'hidden');
  assert.equal(props.authUrl.default, 'https://rentadriver.ai/oauth/authorize');
  assert.equal(props.accessTokenUrl.default, 'https://api.rentadriver.ai/oauth/token');
  assert.equal(props.authentication.default, 'body');
  assert.equal(new RentADriverApi().properties[0].typeOptions.password, true);
});

test('credential verification includes its country workspace and never books a delivery', () => {
  const api = new RentADriverApi();
  const oauth = new RentADriverOAuth2Api();
  for (const credential of [api, oauth]) {
    assert.equal(credential.test.request.baseURL, API_URL);
    assert.equal(credential.test.request.url, '/automation/connection');
    assert.notEqual(credential.test.request.method, 'POST');
  }
  assert.equal(api.authenticate.properties.headers['x-workspace-id'], '={{$credentials.workspaceId}}');
  assert.equal(oauth.test.request.headers['x-workspace-id'], '={{$credentials.workspaceId}}');
});

function context(authentication, inputs, fail = false) {
  const calls = [];
  return { calls, getInputData: () => inputs, getCredentials: async () => ({ workspaceId: id }),
    getNodeParameter: (key, index) => key === 'authentication' ? authentication : key === 'operation' ? 'create' : inputs[index][key] ?? false,
    getNode: () => ({ name: 'RentADriver', type: 'rentADriver', typeVersion: 1, position: [0, 0], parameters: {} }),
    continueOnFail: () => true,
    helpers: { httpRequestWithAuthentication: async function (credential, request) {
      calls.push({ credential, request });
      if (fail) throw Object.assign(new Error('Authorization: secret must never appear'), { response: { status: 409 } });
      return { success: true, delivery: { id } };
    } },
  };
}

test('execution pairs items, selects credentials, never retries or exposes transport secrets', async () => {
  for (const auth of ['oauth2', 'apiKey']) {
    const ctx = context(auth, [booking, { ...booking, sourceId: 'order-2' }]);
    const [out] = await RentADriver.prototype.execute.call(ctx);
    assert.equal(ctx.calls.length, 2);
    assert.equal(ctx.calls[0].credential, auth === 'apiKey' ? 'rentADriverApi' : 'rentADriverOAuth2Api');
    assert.deepEqual(out.map(i => i.pairedItem), [{ item: 0 }, { item: 1 }]);
    assert.equal(ctx.calls[1].request.body.source_id, 'order-2');
  }
  const ctx = context('oauth2', [booking], true);
  const [out] = await RentADriver.prototype.execute.call(ctx);
  assert.equal(ctx.calls.length, 1);
  assert.equal(JSON.stringify(out).includes('secret must never appear'), false);
  const invalid = context('apiKey', [{ ...booking, maximumPrice: -1 }]);
  const [errors] = await RentADriver.prototype.execute.call(invalid);
  assert.equal(invalid.calls.length, 0);
  assert.match(errors[0].json.error, /Maximum price/);
});

test('stop-on-error halts the batch without leaking credential-bearing transport details', async () => {
  const ctx = context('oauth2', [booking, { ...booking, sourceId: 'order-2' }], true);
  ctx.continueOnFail = () => false;
  await assert.rejects(() => RentADriver.prototype.execute.call(ctx), error => {
    assert.equal(JSON.stringify(error).includes('secret must never appear'), false);
    assert.match(error.message, /Check the connection/);
    return true;
  });
  assert.equal(ctx.calls.length, 1);
  assert.notEqual(new RentADriver().description.usableAsTool, true);
});
