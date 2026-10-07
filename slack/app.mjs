import { createServer } from 'node:http';
import { createHash } from 'node:crypto';
import { verifySlack, random, hash, uuid } from './security.mjs';
const TTL = 10 * 60 * 1000;
const plain = text => ({ type: 'plain_text', text });
const safe = value => String(value ?? '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]);
const label = facts => `${facts.account_name} · ${facts.country_code} · ${facts.currency} · ${facts.mode}`;
const userKey = (team, user) => `${team}:${user}`;
function identity(payload, team) {
  const actualTeam = payload.team_id ?? payload.team?.id, user = payload.user_id ?? payload.user?.id;
  if (actualTeam !== team || !/^[UW][A-Z0-9]+$/.test(user || '')) throw new Error('Wrong workspace or user.');
  return { key: userKey(actualTeam, user), user, team: actualTeam, channel: payload.channel_id ?? payload.channel?.id };
}
function fields(view) {
  const values = view?.state?.values || {};
  return Object.fromEntries(Object.entries(values).map(([key, input]) => [key, input.value?.value]));
}
export function quoteBody(values, workspace) {
  const checked = (key, min) => {
    const value = values[key]?.trim();
    if (!value || value.length < min || value.length > 300) throw new Error(`Check ${key}.`);
    return value;
  };
  return { workspace_id: workspace, pickup: { address: checked('pickup', 3) }, dropoffs: [{ address: checked('dropoff', 3) }],
    items: [{ description: checked('description', 1), quantity: 1, size_class: 'M', category: 'parcel' }], urgency: 'asap', service: 'auto' };
}
export function createApp({ baseUrl, teamId, signingSecret, clientId, store, client, now = Date.now, onError = () => {} }) {
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.pathname !== '/' || base.search || base.hash || base.username || base.password || !/^T[A-Z0-9]+$/.test(teamId)) throw new Error('Use an HTTPS origin and Slack team ID.');
  const jobs = new Set(), locks = new Map();
  const later = fn => {
    const job = Promise.resolve().then(fn).catch(() => onError('A background request failed. Retry the command.')).finally(() => jobs.delete(job));
    jobs.add(job);
  };
  const reply = (who, text, blocks) => client.slack('chat.postEphemeral', { channel: who.channel, user: who.user, text, ...(blocks ? { blocks } : {}) });
  async function serial(who, fn) {
    const previous = locks.get(who.key) ?? Promise.resolve();
    const current = previous.catch(() => {}).then(fn);
    locks.set(who.key, current);
    try { return await current; } finally { if (locks.get(who.key) === current) locks.delete(who.key); }
  }
  async function connected(who, fn) {
    return serial(who, async () => {
      let connection = store.get('connection', who.key);
      if (!connection) throw new Error('Connect first.');
      if (connection.expires <= now() + 30000) {
        try {
          connection = { ...connection, ...await client.token({ grant_type: 'refresh_token', refresh_token: connection.refresh }) };
          // Persist rotated tokens before any later read can fail.
          store.put('connection', who.key, connection);
        } catch {
          store.delete('connection', who.key);
          throw new Error('Connect again.');
        }
      }
      const facts = (await client.api(connection, '/automation/connection')).connection;
      if (facts.account_id !== connection.facts.account_id || facts.workspace_id !== connection.workspace || facts.mode !== connection.facts.mode) throw new Error('Connection binding changed.');
      return fn(connection, facts);
    });
  }
  async function command(payload) {
    const who = identity(payload, teamId), [action = 'help', value] = (payload.text || '').trim().split(/\s+/);
    try {
      if (action === 'connect') {
        if (!uuid(value)) return reply(who, 'Use /rentadriver connect <country workspace ID>. Find the ID in your RentADriver console.');
        const ticket = random();
        store.put('ticket', hash(ticket), { ...who, workspace: value }, TTL);
        return reply(who, 'Connect your own RentADriver account. Approve the connection here after signing in.', [
          { type: 'section', text: { type: 'mrkdwn', text: 'Sign in to RentADriver, select sandbox or live, then return here to confirm your account.' } },
          { type: 'actions', elements: [{ type: 'button', action_id: 'connect_link', text: plain('Connect RentADriver'), url: `${base.origin}/connect?ticket=${ticket}` }] },
        ]);
      }
      if (action === 'disconnect') {
        // Serialize with in-flight refreshes, so rotation cannot resurrect a disconnected credential.
        return await serial(who, async () => {
          const c = store.take('connection', who.key);
          store.put('generation', who.key, random());
          if (c) await client.revoke(c);
          return reply(who, 'Disconnected. You can also check Connected apps in RentADriver.');
        });
      }
      if (action === 'status') return await connected(who, (_, facts) => reply(who, label(facts)));
      if (action === 'delivery') {
        if (!uuid(value)) return reply(who, 'Use /rentadriver delivery <delivery ID>.');
        return await connected(who, async c => {
          const result = await client.api(c, '/deliveries/' + value), delivery = result.delivery ?? result;
          return reply(who, `Delivery ${safe(delivery.short_code ?? value)}: ${safe(delivery.status)}. Open the RentADriver console for details.`);
        });
      }
      if (action === 'quote') {
        if (!store.get('connection', who.key)) return reply(who, 'Connect your RentADriver account first.');
        const modal = random();
        store.put('modal', hash(modal), who, TTL);
        await client.slack('views.open', { trigger_id: payload.trigger_id, view: { type: 'modal', callback_id: 'quote', private_metadata: modal,
          title: plain('Delivery quote'), submit: plain('Get quote'), close: plain('Cancel'), blocks: [
            { type: 'section', text: { type: 'mrkdwn', text: 'One pickup, one drop-off, medium parcel. This quotes only; it does not book or charge.' } },
            ...['pickup', 'dropoff', 'description'].map(key => ({ type: 'input', block_id: key, label: plain({ pickup: 'Pickup address', dropoff: 'Drop-off address', description: 'Package description' }[key]), element: { type: 'plain_text_input', action_id: 'value', max_length: 300 } })),
          ] } });
        return;
      }
      return reply(who, 'Use /rentadriver connect <workspace ID>, status, quote, delivery <ID>, or disconnect. Booking is available in the RentADriver console.');
    } catch { return reply(who, 'Could not complete the request. Check your connection and inputs; reconnect if access expired or was revoked.'); }
  }
  async function interaction(payload) {
    const who = identity(payload, teamId);
    if (payload.type === 'block_actions' && payload.actions?.[0]?.action_id === 'confirm_connection') {
      const id = payload.actions[0].value, pending = store.get('pending', hash(id || ''));
      if (!pending || pending.key !== who.key) return;
      store.take('pending', hash(id));
      return serial(who, async () => {
        let adopted = false;
        try {
          if ((store.get('generation', who.key) ?? '') !== pending.generation) return reply(who, 'Connection attempt expired. Start again.');
          const previous = store.get('connection', who.key);
          if (previous && (previous.facts.account_id !== pending.facts.account_id || previous.facts.mode !== pending.facts.mode || previous.workspace !== pending.workspace)) return reply(who, 'Disconnect your current account before changing account, country workspace or mode.');
          const facts = (await client.api(pending, '/automation/connection')).connection;
          if (facts.account_id !== pending.facts.account_id || facts.mode !== pending.facts.mode || facts.workspace_id !== pending.workspace) throw new Error('Account changed.');
          store.put('connection', who.key, { access: pending.access, refresh: pending.refresh, expires: pending.expires, workspace: pending.workspace, facts });
          adopted = true;
          if (previous && previous.refresh !== pending.refresh) { try { await client.revoke(previous); } catch { onError('Review old connections in RentADriver Connected apps.'); } }
          return reply(who, `Connected: ${label(facts)}. Quotes and lookup only; booking stays in the console.`);
        } finally {
          if (!adopted) { try { await client.revoke(pending); } catch { onError('Review unfinished connections in RentADriver Connected apps.'); } }
        }
      });
    }
    if (payload.type === 'view_submission' && payload.view?.callback_id === 'quote') {
      const modal = store.get('modal', hash(payload.view.private_metadata || ''));
      if (!modal || modal.key !== who.key) return;
      store.take('modal', hash(payload.view.private_metadata));
      try { return await connected(modal, async c => {
        const result = await client.api(c, '/quotes', quoteBody(fields(payload.view), c.workspace)), quote = result.quote ?? result;
        if (!Number.isSafeInteger(quote.client_price_cents) || !/^[A-Z]{3}$/.test(quote.currency || '')) throw new Error('Invalid quote.');
        return reply(modal, `Quote: ${new Intl.NumberFormat('en', { style: 'currency', currency: quote.currency }).format(quote.client_price_cents / 100)}. No delivery was created. Book from the RentADriver console.`);
      }); } catch { return reply(modal, 'Could not get a quote. Check your connection and addresses; reconnect if access expired or was revoked.'); }
    }
  }
  async function route(req, res) {
    res.setHeader('Cache-Control', 'no-store'); res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    const url = new URL(req.url, base);
    const send = (status, body, type = 'text/plain') => { res.writeHead(status, { 'content-type': type }); res.end(body); };
    if (req.method === 'GET' && url.pathname === '/health') return send(200, 'ok');
    if (req.method === 'GET' && url.pathname === '/connect') {
      const ticket = store.take('ticket', hash(url.searchParams.get('ticket') || ''));
      if (!ticket) return send(400, 'Link expired. Run the connect command again.');
      const state = random(), nonce = random(), verifier = random();
      store.put('state', hash(state), { ...ticket, nonce: hash(nonce), verifier, generation: store.get('generation', ticket.key) ?? '' }, TTL);
      const authorize = new URL('https://rentadriver.ai/oauth/authorize');
      authorize.search = new URLSearchParams({ client_id: clientId, redirect_uri: `${base.origin}/oauth/callback`, response_type: 'code', scope: 'read', state,
        code_challenge_method: 'S256', code_challenge: createHash('sha256').update(verifier).digest('base64url') }).toString();
      res.setHeader('Set-Cookie', `rd_slack_link=${nonce}; HttpOnly; Secure; SameSite=Lax; Path=/oauth/callback; Max-Age=600`);
      res.writeHead(302, { location: authorize.href }); return res.end();
    }
    if (req.method === 'GET' && url.pathname === '/oauth/callback') {
      const stateId = hash(url.searchParams.get('state') || ''), state = store.get('state', stateId);
      const nonce = /(?:^|;\s*)rd_slack_link=([A-Za-z0-9_-]+)/.exec(req.headers.cookie || '')?.[1];
      if (!state || !nonce || hash(nonce) !== state.nonce) return send(400, 'Invalid or expired sign-in. Start again from Slack.');
      store.take('state', stateId);
      res.setHeader('Set-Cookie', 'rd_slack_link=; HttpOnly; Secure; SameSite=Lax; Path=/oauth/callback; Max-Age=0');
      if (url.searchParams.get('error') || !url.searchParams.get('code')) return send(200, 'Connection cancelled. Return to Slack.');
      let credential;
      try {
        credential = { ...await client.token({ grant_type: 'authorization_code', code: url.searchParams.get('code'), code_verifier: state.verifier, redirect_uri: `${base.origin}/oauth/callback` }), workspace: state.workspace };
        const facts = (await client.api(credential, '/automation/connection')).connection;
        if (facts.workspace_id !== state.workspace) throw new Error('Wrong workspace.');
        const id = random();
        store.put('pending', hash(id), { ...state, ...credential, facts }, TTL);
        await reply(state, `Confirm account: ${label(facts)}`, [{ type: 'section', text: { type: 'plain_text', text: `Connect ${label(facts)} to your Slack user?` } },
          { type: 'actions', elements: [{ type: 'button', action_id: 'confirm_connection', text: plain('Confirm my connection'), value: id }] }]);
        return send(200, 'Return to Slack and confirm the account. No connection is active until you confirm there.');
      } catch {
        if (credential) { try { await client.revoke(credential); } catch {} }
        return send(400, 'Could not connect. Check the selected workspace and start again from Slack.');
      }
    }
    if (req.method !== 'POST' || !['/slack/commands', '/slack/interactions'].includes(url.pathname)) return send(404, 'Not found.');
    let chunks = [], length = 0;
    for await (const chunk of req) { length += chunk.length; if (length > 65536) return send(413, 'Request too large.'); chunks.push(chunk); }
    const raw = Buffer.concat(chunks);
    if (!verifySlack(signingSecret, req.headers, raw, now())) return send(401, 'Invalid signature.');
    let payload, who;
    try {
      const params = new URLSearchParams(raw.toString('utf8'));
      payload = url.pathname === '/slack/interactions' ? JSON.parse(params.get('payload')) : Object.fromEntries(params);
      who = identity(payload, teamId);
    } catch { return send(400, 'Invalid request.'); }
    const replay = hash(raw);
    if (store.get('request', replay)) return send(200, '');
    store.put('request', replay, true, 300000);
    // Acknowledge before network calls; all replies remain ephemeral to the verified Slack user.
    if (url.pathname === '/slack/commands') {
      send(200, JSON.stringify({ response_type: 'ephemeral', text: 'Checking RentADriver…' }), 'application/json');
      later(() => command(payload));
    } else {
      if (payload.type === 'view_submission' && payload.view?.callback_id === 'quote') {
        try { quoteBody(fields(payload.view), 'validation'); }
        catch { return send(200, JSON.stringify({ response_action: 'errors', errors: { pickup: 'Check the addresses and package description.' } }), 'application/json'); }
      }
      send(200, '');
      later(async () => { try { await interaction(payload); } catch { if (who.channel) await reply(who, 'Could not complete the request. Check your connection and try again.'); } });
    }
  }
  const server = createServer((req, res) => route(req, res).catch(() => { if (!res.headersSent) res.writeHead(500); res.end('Could not complete the request.'); }));
  server.headersTimeout = 10000; server.requestTimeout = 15000;
  return { server, drain: async () => { while (jobs.size) await Promise.all([...jobs]); }, connected, command, interaction };
}
