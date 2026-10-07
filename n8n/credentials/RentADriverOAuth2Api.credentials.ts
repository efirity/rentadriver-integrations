import type { ICredentialType, ICredentialTestRequest, INodeProperties } from 'n8n-workflow';

export class RentADriverOAuth2Api implements ICredentialType {
  name = 'rentADriverOAuth2Api';
  extends = ['oAuth2Api'];
  displayName = 'RentADriver OAuth2 API';
  icon = 'file:../nodes/RentADriver/rentadriver.svg' as const;
  documentationUrl = 'https://rentadriver.ai/docs';
  test: ICredentialTestRequest = { request: { baseURL: 'https://api.rentadriver.ai/v1', url: '/automation/connection', headers: { 'x-workspace-id': '={{$credentials.workspaceId}}' } } };
  properties: INodeProperties[] = [
    { displayName: 'Grant Type', name: 'grantType', type: 'hidden', default: 'pkce' },
    { displayName: 'Authorization URL', name: 'authUrl', type: 'hidden', default: 'https://rentadriver.ai/oauth/authorize' },
    { displayName: 'Access Token URL', name: 'accessTokenUrl', type: 'hidden', default: 'https://api.rentadriver.ai/oauth/token' },
    { displayName: 'Scope', name: 'scope', type: 'hidden', default: 'read write' },
    { displayName: 'Client Secret', name: 'clientSecret', type: 'hidden', typeOptions: { password: true }, default: '', required: false },
    { displayName: 'Authentication', name: 'authentication', type: 'hidden', default: 'body' },
    { displayName: 'Auth URI Query Parameters', name: 'authQueryParameters', type: 'hidden', default: '' },
    { displayName: 'Country Workspace ID', name: 'workspaceId', type: 'string', default: '', required: true,
      description: 'Country workspace to use. Consent determines sandbox/live mode; use Get Connection to verify it.' },
  ];
}
