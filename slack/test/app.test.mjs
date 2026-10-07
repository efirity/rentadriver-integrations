import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { mkdtempSync, readFileSync, statSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { once } from 'node:events';
import { Store } from '../store.mjs';
import { createApp, quoteBody } from '../app.mjs';
import { hash, verifySlack, seal, unseal } from '../security.mjs';
import { manifest } from '../manifest.mjs';
const workspace = '00000000-0000-4000-8000-000000000001';
const facts = { account_id: workspace, account_name: 'Example Store', workspace_id: workspace, country_code: 'GB', currency: 'GBP', mode: 'sandbox' };
function fixture() {
  let clock = Date.now(); const store = new Store(':memory:', Buffer.alloc(32, 1), () => clock), calls = [];
  const client = {
    async slack(method, body) { calls.push({ method, body }); return { ok: true }; },
    async api(c, path, body) { calls.push({ path, body, c }); return path === '/quotes' ? { quote: { client_price_cents: 1250, currency: 'GBP' } } : { connection: facts }; },
    async token(body) { calls.push({ token: body }); return { access: 'fixture-access', refresh: 'fixture-refresh', expires: clock + 3600000 }; },
    async revoke(c) { calls.push({ revoke: c }); },
  };
  const app = createApp({ baseUrl: 'https://example.invalid', teamId: 'TEXAMPLE', signingSecret: 'fixture-signing', clientId: 'fixture-client', store, client, now: () => clock });
  return { ...app, store, calls, client, get clock() { return clock; }, advance(ms) { clock += ms; } };
}
const payload = { team_id: 'TEXAMPLE', user_id: 'UEXAMPLE', channel_id: 'CEXAMPLE', trigger_id: 'fixture-trigger' };
function signed(body, clock, secret = 'fixture-signing') {
  const ts = String(Math.floor(clock / 1000));
  return { 'content-type': 'application/x-www-form-urlencoded', 'x-slack-request-timestamp': ts,
    'x-slack-signature': 'v0=' + createHmac('sha256', secret).update(`v0:${ts}:${body}`).digest('hex') };
}
async function runServer(f, fn) {
  f.server.listen(0, '127.0.0.1'); await once(f.server, 'listening');
  try { await fn('http://127.0.0.1:' + f.server.address().port); }
  finally { await f.drain(); await new Promise(resolve => f.server.close(resolve)); f.store.close(); }
}
test('raw signatures reject altered bodies, stale requests and missing headers', () => {
  const body = 'text=status', now = Date.now(), headers = signed(body, now);
  assert.equal(verifySlack('fixture-signing', headers, Buffer.from(body), now), true);
  assert.equal(verifySlack('fixture-signing', headers, Buffer.from(body + 'x'), now), false);
  assert.equal(verifySlack('fixture-signing', headers, Buffer.from(body), now + 301000), false);
  assert.equal(verifySlack('fixture-signing', {}, Buffer.from(body), now), false);
});
test('persistent credentials are encrypted, authenticated and one-time states expire', () => {
  const key = Buffer.alloc(32, 1), encoded = seal(key, { access: 'private-access' });
  assert.equal(encoded.includes('private-access'), false); assert.deepEqual(unseal(key, encoded), { access: 'private-access' });
  assert.throws(() => unseal(Buffer.alloc(32, 2), encoded));
  const f = fixture(); f.store.put('state', 'one', true, 10);
  assert.equal(f.store.take('state', 'one'), true); assert.equal(f.store.take('state', 'one'), null);
  f.store.put('state', 'two', true, 10); f.advance(11); assert.equal(f.store.take('state', 'two'), null); f.store.close();
});
test('HTTP acknowledgements do not wait for network; wrong teams and request replays do not execute', async () => {
  const f = fixture(); await runServer(f, async base => {
    const body = new URLSearchParams({ ...payload, text: 'connect ' + workspace }).toString();
    assert.equal((await fetch(base + '/slack/commands', { method: 'POST', body, headers: signed(body, f.clock, 'wrong') })).status, 401);
    const wrong = new URLSearchParams({ ...payload, team_id: 'TOTHER', text: 'status' }).toString();
    assert.equal((await fetch(base + '/slack/commands', { method: 'POST', body: wrong, headers: signed(wrong, f.clock) })).status, 400);
    const response = await fetch(base + '/slack/commands', { method: 'POST', body, headers: signed(body, f.clock) });
    assert.equal((await response.json()).response_type, 'ephemeral'); await f.drain();
    await fetch(base + '/slack/commands', { method: 'POST', body, headers: signed(body, f.clock) }); await f.drain();
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].body.user, 'UEXAMPLE');
  });
});
test('OAuth cookie and state are single-use; only the same Slack user can confirm', async () => {
  const f = fixture(); await runServer(f, async base => {
    await f.command({ ...payload, text: 'connect ' + workspace });
    const link = new URL(f.calls[0].body.blocks[1].elements[0].url);
    const begin = await fetch(base + link.pathname + link.search, { redirect: 'manual' });
    const authorize = new URL(begin.headers.get('location')), cookie = begin.headers.get('set-cookie').split(';')[0];
    assert.equal(authorize.searchParams.get('scope'), 'read'); assert.equal(authorize.searchParams.get('code_challenge_method'), 'S256');
    const callback = '/oauth/callback?code=fixture-code&state=' + authorize.searchParams.get('state');
    assert.equal((await fetch(base + callback)).status, 400);
    assert.equal((await fetch(base + callback, { headers: { cookie } })).status, 200);
    assert.equal((await fetch(base + callback, { headers: { cookie } })).status, 400);
    assert.equal(f.store.get('connection', 'TEXAMPLE:UEXAMPLE'), null);
    const value = f.calls.find(c => c.body?.blocks?.[1]?.elements?.[0]?.action_id === 'confirm_connection').body.blocks[1].elements[0].value;
    const interaction = { type: 'block_actions', team: { id: 'TEXAMPLE' }, user: { id: 'UOTHER' }, channel: { id: 'CEXAMPLE' }, actions: [{ action_id: 'confirm_connection', value }] };
    await f.interaction(interaction); assert.equal(f.store.get('connection', 'TEXAMPLE:UEXAMPLE'), null);
    await f.interaction({ ...interaction, user: { id: 'UEXAMPLE' } });
    assert.equal(f.store.get('connection', 'TEXAMPLE:UEXAMPLE').facts.mode, 'sandbox');
    assert.equal(f.calls.find(c => c.token).token.code_verifier.length, 43);
  });
});
test('cancellation leaves no connection; expired tickets are rejected', async () => {
  const f = fixture(); await runServer(f, async base => {
    f.store.put('ticket', hash('expired'), { ...payload, workspace }, 1); f.advance(2);
    assert.equal((await fetch(base + '/connect?ticket=expired', { redirect: 'manual' })).status, 400);
    await f.command({ ...payload, text: 'connect ' + workspace });
    const url = new URL(f.calls.at(-1).body.blocks[1].elements[0].url);
    const begin = await fetch(base + url.pathname + url.search, { redirect: 'manual' });
    const state = new URL(begin.headers.get('location')).searchParams.get('state');
    const response = await fetch(base + '/oauth/callback?error=access_denied&state=' + state, { headers: { cookie: begin.headers.get('set-cookie').split(';')[0] } });
    assert.match(await response.text(), /cancelled/); assert.equal(f.calls.some(c => c.token), false);
  });
});
test('cross-user access and account/mode replacement fail closed; disconnect invalidates pending links', async () => {
  const f = fixture(), key = 'TEXAMPLE:UEXAMPLE';
  f.store.put('connection', key, { access: 'old-access', refresh: 'old-refresh', workspace, expires: f.clock + 100000, facts });
  await assert.rejects(f.connected({ key: 'TEXAMPLE:UOTHER' }, () => {}), /Connect first/);
  f.store.put('pending', hash('pending'), { key, workspace, facts: { ...facts, mode: 'live' }, generation: '' }, 1000);
  await f.interaction({ type: 'block_actions', team: { id: 'TEXAMPLE' }, user: { id: 'UEXAMPLE' }, channel: { id: 'CEXAMPLE' }, actions: [{ action_id: 'confirm_connection', value: 'pending' }] });
  assert.equal(f.store.get('connection', key).facts.mode, 'sandbox');
  await f.command({ ...payload, text: 'disconnect' }); assert.equal(f.store.get('connection', key), null);
  assert.notEqual(f.store.get('generation', key), null); f.store.close();
});
test('refreshes serialize and persist rotation; failed/revoked refresh requires reconnect', async () => {
  const f = fixture(), key = 'TEXAMPLE:UEXAMPLE';
  f.store.put('connection', key, { access: 'old', refresh: 'old-refresh', workspace, expires: 0, facts });
  await Promise.all([f.connected({ key }, () => true), f.connected({ key }, () => true)]);
  assert.equal(f.calls.filter(c => c.token).length, 1); assert.equal(f.store.get('connection', key).refresh, 'fixture-refresh');
  f.advance(3600001); f.client.token = async () => { throw new Error('revoked'); };
  await assert.rejects(f.connected({ key }, () => {}), /Connect again/); assert.equal(f.store.get('connection', key), null); f.store.close();
});
test('quotes preserve Unicode, private transport and never call a booking endpoint', async () => {
  assert.equal(quoteBody({ pickup: 'Ștefan cel Mare', dropoff: 'Bălți centru', description: 'Cheia' }, workspace).pickup.address, 'Ștefan cel Mare');
  const f = fixture(), key = 'TEXAMPLE:UEXAMPLE';
  f.store.put('connection', key, { access: 'fixture', refresh: 'fixture', workspace, expires: f.clock + 100000, facts });
  await f.command({ ...payload, text: 'quote' });
  const modal = f.calls.find(c => c.method === 'views.open').body.view;
  modal.state = { values: Object.fromEntries(Object.entries({ pickup: '123 Example Road', dropoff: '456 Example Road', description: 'Parcel' }).map(([key, value]) => [key, { value: { value } }])) };
  await f.interaction({ type: 'view_submission', team: { id: 'TEXAMPLE' }, user: { id: 'UEXAMPLE' }, view: modal });
  assert.equal(f.calls.some(c => c.path?.includes('/automation/deliveries')), false);
  assert.equal(f.calls.at(-1).body.user, 'UEXAMPLE'); f.store.close();
});
test('manifest requests only commands and private-reply capability', () => {
  assert.deepEqual(manifest('https://example.invalid').oauth_config.scopes.bot, ['commands', 'chat:write']);
  assert.throws(() => manifest('http://example.invalid'));
});

test('disk storage survives restart without plaintext credentials and removes expired rows', () => {
  const folder = mkdtempSync(join(tmpdir(), 'rentadriver-slack-')), path = join(folder, 'connections.sqlite'), key = Buffer.alloc(32, 1);
  let clock = 1000;
  try {
    let store = new Store(path, key, () => clock);
    store.put('connection', 'example', { access: 'fixture-private-persisted-token' });
    store.put('pending', 'expired', { access: 'fixture-private-abandoned-token' }, 10);
    store.close();
    assert.equal(readFileSync(path).includes(Buffer.from('fixture-private-persisted-token')), false);
    assert.equal(statSync(path).mode & 0o777, 0o600);
    store = new Store(path, key, () => clock);
    assert.equal(store.get('connection', 'example').access, 'fixture-private-persisted-token');
    clock += 11; store.put('request', 'new', true, 10);
    assert.equal(store.db.prepare("SELECT count(*) AS n FROM records WHERE kind='pending'").get().n, 0);
    store.close();
  } finally { rmSync(folder, { recursive: true, force: true }); }
});
test('failed asynchronous status and modal quotes send useful private errors', async () => {
  const f = fixture();
  await f.command({ ...payload, text: 'status' });
  assert.match(f.calls.at(-1).body.text, /Could not complete/);
  f.store.put('connection', 'TEXAMPLE:UEXAMPLE', { access: 'fixture', refresh: 'fixture', expires: f.clock + 3600000, workspace, facts });
  await f.command({ ...payload, text: 'quote' });
  const modal = f.calls.find(c => c.method === 'views.open').body.view.private_metadata;
  f.client.api = async () => { throw new Error('private-error'); };
  await f.interaction({ team: { id: 'TEXAMPLE' }, user: { id: 'UEXAMPLE' }, type: 'view_submission', view: { callback_id: 'quote', private_metadata: modal, state: { values: Object.fromEntries(['pickup','dropoff','description'].map(k => [k, { value: { value: 'Example address' } }])) } } });
  assert.match(f.calls.at(-1).body.text, /Could not get a quote/);
  assert.equal(f.calls.at(-1).body.channel, 'CEXAMPLE');
  assert.equal(f.calls.at(-1).body.text.includes('private-error'), false);
  f.store.close();
});
