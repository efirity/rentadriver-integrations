import type { IAuthenticateGeneric, ICredentialType, ICredentialTestRequest, INodeProperties } from 'n8n-workflow';

export class RentADriverApi implements ICredentialType {
  name = 'rentADriverApi';
  displayName = 'RentADriver API';
  icon = 'file:../nodes/RentADriver/rentadriver.svg' as const;
  documentationUrl = 'https://rentadriver.ai/docs';
  properties: INodeProperties[] = [
    { displayName: 'API Key', name: 'apiKey', type: 'string', typeOptions: { password: true }, default: '', required: true },
    { displayName: 'Country Workspace ID', name: 'workspaceId', type: 'string', default: '', required: true },
  ];
  test: ICredentialTestRequest = { request: { baseURL: 'https://api.rentadriver.ai/v1', url: '/automation/connection' } };
  authenticate: IAuthenticateGeneric = {
    type: 'generic', properties: { headers: { 'x-api-key': '={{$credentials.apiKey}}', 'x-workspace-id': '={{$credentials.workspaceId}}' } },
  };
}
