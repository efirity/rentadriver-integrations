import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '../client.mjs';
const workspace = '00000000-0000-4000-8000-000000000001';
test('real client serializes Unicode, credentials and private Slack messages to fixed origins', async () => {
  const calls = [];
  const client = new Client({ clientId: 'fixture-client', botToken: 'fixture-bot', now: () => 1000,
    fetcher: async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => url.endsWith('/oauth/token') ? { access_token: 'fixture-access', refresh_token: 'fixture-refresh', expires_in: 3600 } : { ok: true } }; } });
  const c = { ...await client.token({ grant_type: 'authorization_code', code: 'fixture-code', code_verifier: 'fixture-verifier' }), workspace };
  assert.equal(c.expires, 3601000);
  assert.equal(new URLSearchParams(calls[0].options.body).get('code_verifier'), 'fixture-verifier');
  await client.api(c, '/quotes', { pickup: { address: 'Ștefan cel Mare' } });
  assert.equal(JSON.parse(calls[1].options.body).pickup.address, 'Ștefan cel Mare');
  assert.equal(calls[1].options.headers['x-workspace-id'], workspace);
  assert.equal(calls[1].options.headers.authorization, 'Bearer fixture-access');
  await client.slack('chat.postEphemeral', { channel: 'CEXAMPLE', user: 'UEXAMPLE', text: 'Private quote' });
  assert.equal(calls[2].url, 'https://slack.com/api/chat.postEphemeral');
  assert.equal(calls[2].options.headers.authorization, 'Bearer fixture-bot');
  assert.throws(() => client.api(c, '/automation/deliveries'), /Unsupported/);
  await assert.rejects(client.slack('chat.postMessage', {}), /Unsupported/);
  assert.equal(calls.every(c => c.options.redirect === 'error' && c.options.signal instanceof AbortSignal), true);
});
test('client refuses failed HTTP and Slack responses without exposing response details', async () => {
  const client = new Client({ fetcher: async () => ({ ok: false, status: 403, json: async () => ({ private: 'fixture-private' }) }) });
  await assert.rejects(client.api({ workspace }, '/automation/connection'), e => !e.message.includes('fixture-private'));
  client.fetcher = async () => ({ ok: true, json: async () => ({ ok: false, error: 'private-error' }) });
  await assert.rejects(client.slack('chat.postEphemeral', {}), /Slack could not/);
  await assert.rejects(client.token({}), /Invalid token/);
});
