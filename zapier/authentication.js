'use strict';
const { request, uuid } = require('./client');
async function token(z, bundle, refresh) {
  const body = { client_id: process.env.CLIENT_ID, grant_type: refresh ? 'refresh_token' : 'authorization_code' };
  if (!body.client_id) throw new Error('Configure the OAuth client before connecting.');
  if (refresh) body.refresh_token = bundle.authData.refresh_token;
  else {
    // SDK redaction discovers authData values. These execution-only values are
    // never returned as saved authentication fields. Redact the one-use handoff too.
    bundle.authData.pkce_secret = bundle.inputData.code_verifier;
    bundle.authData.oauth_code_secret = bundle.inputData.code;
    Object.assign(body, { code: bundle.inputData.code, redirect_uri: bundle.inputData.redirect_uri, code_verifier: bundle.inputData.code_verifier });
  }
  const response = await z.request({ url: 'https://api.rentadriver.ai/oauth/token', method: 'POST', body,
    headers: { 'content-type': 'application/x-www-form-urlencoded' }, skipThrowForStatus: true });
  if (response.status !== 200 || !response.data.access_token || !response.data.refresh_token) throw new z.errors.Error('RentADriver sign-in expired or was revoked. Connect again.', 'AuthenticationError', response.status);
  return { access_token: response.data.access_token, refresh_token: response.data.refresh_token,
    workspace_id: uuid(bundle.authData.workspace_id, 'Country workspace ID') };
}
module.exports = {
  type: 'oauth2',
  fields: [{ key: 'workspace_id', label: 'Country workspace ID', required: true, type: 'string', isNoSecret: true,
    helpText: 'Copy the country workspace ID from [your RentADriver console](https://rentadriver.ai/dashboard). Select the same account and workspace when signing in.' }],
  oauth2Config: {
    authorizeUrl: { url: 'https://rentadriver.ai/oauth/authorize', params: { client_id: '{{process.env.CLIENT_ID}}', response_type: 'code' } },
    scope: 'read write', enablePkce: true, autoRefresh: true,
    getAccessToken: (z, bundle) => token(z, bundle, false),
    refreshAccessToken: (z, bundle) => token(z, bundle, true),
  },
  test: async (z, bundle) => (await request(z, bundle, '/automation/connection')).connection,
  connectionLabel: '{{account_name}} · {{country_code}} · {{mode}}',
};
