export class Client {
  constructor({ clientId, botToken, fetcher = fetch, now = Date.now }) {
    this.clientId = clientId; this.botToken = botToken; this.fetcher = fetcher; this.now = now;
  }
  async json(url, options) {
    const response = await this.fetcher(url, { ...options, redirect: 'error', signal: AbortSignal.timeout(15000) });
    if (!response.ok) throw new Error(`Request failed (${response.status}).`);
    return response.json();
  }
  async token(values) {
    const result = await this.json('https://api.rentadriver.ai/oauth/token', { method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ client_id: this.clientId, ...values }).toString() });
    if (!result.access_token || !result.refresh_token || !Number.isFinite(result.expires_in)) throw new Error('Invalid token response.');
    return { access: result.access_token, refresh: result.refresh_token, expires: this.now() + result.expires_in * 1000 };
  }
  api(connection, path, body) {
    if (!['/automation/connection', '/quotes'].includes(path) && !/^\/deliveries\/[a-f0-9-]{36}$/i.test(path)) throw new Error('Unsupported action.');
    return this.json('https://api.rentadriver.ai/v1' + path, { method: body ? 'POST' : 'GET',
      headers: { authorization: `Bearer ${connection.access}`, 'x-workspace-id': connection.workspace, 'content-type': 'application/json' },
      ...(body ? { body: JSON.stringify(body) } : {}) });
  }
  async slack(method, body) {
    if (!['chat.postEphemeral', 'views.open'].includes(method)) throw new Error('Unsupported Slack action.');
    const result = await this.json('https://slack.com/api/' + method, { method: 'POST', headers: { authorization: `Bearer ${this.botToken}`, 'content-type': 'application/json' }, body: JSON.stringify(body) });
    if (!result.ok) throw new Error('Slack could not complete the request.');
    return result;
  }
  async revoke(connection) {
    await this.json('https://api.rentadriver.ai/oauth/revoke', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ token: connection.refresh }).toString() });
  }
}
