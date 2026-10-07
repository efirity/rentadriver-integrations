import { NodeConnectionTypes, NodeOperationError } from 'n8n-workflow';
import type { IExecuteFunctions, INodeExecutionData, INodeProperties, INodeType, INodeTypeDescription } from 'n8n-workflow';
import { buildRequest, InputError, type Operation } from './request';

const field = (name: string, displayName: string, operation: Operation, extra: Partial<INodeProperties> = {}): INodeProperties => ({
  name, displayName, type: 'string', default: '', required: true, displayOptions: { show: { operation: [operation] } }, ...extra,
});
export class RentADriver implements INodeType {
  description: INodeTypeDescription = {
    displayName: 'RentADriver', name: 'rentADriver', group: ['transform'], version: 1,
    icon: { light: 'file:rentadriver.svg', dark: 'file:rentadriver.svg' },
    subtitle: '={{$parameter["operation"]}}',
    // Do not expose funded booking to an AI agent without workflow-level approval.
    usableAsTool: undefined,
    description: 'Get quotes, create delivery drafts and read delivery events',
    defaults: { name: 'RentADriver' }, inputs: [NodeConnectionTypes.Main], outputs: [NodeConnectionTypes.Main],
    credentials: [
      { name: 'rentADriverOAuth2Api', required: true, displayOptions: { show: { authentication: ['oauth2'] } } },
      { name: 'rentADriverApi', required: true, displayOptions: { show: { authentication: ['apiKey'] } } },
    ],
    properties: [
      { displayName: 'Authentication', name: 'authentication', type: 'options', default: 'oauth2', noDataExpression: true, options: [
        { name: 'OAuth2 (Recommended)', value: 'oauth2' }, { name: 'API Key (Advanced)', value: 'apiKey' },
      ] },
      { displayName: 'Operation', name: 'operation', type: 'options', default: 'connection', noDataExpression: true, options: [
        { name: 'Create a Delivery From a Quote', value: 'create', action: 'Create a delivery from a quote' },
        { name: 'Get a Delivery', value: 'delivery', action: 'Get a delivery' },
        { name: 'Get a Delivery Quote', value: 'quote', action: 'Get a delivery quote' },
        { name: 'Get Connection', value: 'connection', action: 'Get connection', description: 'Verify account, country workspace, currency and sandbox/live mode' },
        { name: 'Get Delivery Events', value: 'events', action: 'Get delivery events' },
      ] },
      field('pickup', 'Pickup Address', 'quote'), field('dropoff', 'Drop-off Address', 'quote'),
      field('description', 'Package Description', 'quote'),
      field('size', 'Package Size', 'quote', { type: 'options', default: 'M', options: ['S', 'M', 'L', 'XL'].map(value => ({ name: value, value })) }),
      field('quoteId', 'Quote ID', 'create'),
      field('sourceId', 'Source ID', 'create', { description: 'Stable upstream order/event ID. Preserve it and all booking values on retries.' }),
      field('currency', 'Quote Currency', 'create', { description: 'Uppercase three-letter currency from the approved quote' }),
      field('maximumPrice', 'Maximum Price (Minor Units)', 'create', { type: 'number', default: 0, typeOptions: { minValue: 0, numberPrecision: 0 }, description: 'Explicit price ceiling. For example, 1250 means 12.50 in a two-decimal currency.' }),
      field('fund', 'Fund and Dispatch', 'create', { type: 'boolean', default: false, description: 'Whether to spend from the existing wallet and dispatch in live mode. Disabled creates an unfunded draft.' }),
      field('dryRun', 'Validate Only', 'create', { type: 'boolean', default: false, description: 'Whether to validate without creating a delivery' }),
      field('deliveryId', 'Delivery ID', 'delivery'),
      field('after', 'After Cursor', 'events', { default: '0', description: 'Keep as text. Process the entire batch before saving next_cursor; repeat until the batch is empty.' }),
    ],
  };
  async execute(this: IExecuteFunctions): Promise<INodeExecutionData[][]> {
    const output: INodeExecutionData[] = [];
    const credentialName = this.getNodeParameter('authentication', 0) === 'apiKey' ? 'rentADriverApi' : 'rentADriverOAuth2Api';
    const credentials = await this.getCredentials(credentialName);
    const operation = this.getNodeParameter('operation', 0) as Operation;
    const fields: Record<Operation, string[]> = {
      connection: [], quote: ['pickup', 'dropoff', 'description', 'size'],
      create: ['quoteId', 'sourceId', 'currency', 'maximumPrice', 'fund', 'dryRun'], delivery: ['deliveryId'], events: ['after'],
    };
    for (let i = 0; i < this.getInputData().length; i++) {
      try {
        if (!(operation in fields)) throw new NodeOperationError(this.getNode(), 'Unsupported operation', { itemIndex: i });
        const values = Object.fromEntries(fields[operation].map(name => [name, this.getNodeParameter(name, i)]));
        const request = buildRequest(operation, credentials.workspaceId, values);
        // No automatic retry of a booking with changed inputs and no arbitrary API hosts.
        const response = await this.helpers.httpRequestWithAuthentication.call(this, credentialName, request);
        output.push({ json: response, pairedItem: { item: i } });
      } catch (error) {
        // Never export transport errors: they can contain credential-bearing request headers.
        const message = error instanceof InputError ? error.message
          : 'RentADriver request failed. Check the connection, approved quote and request values before retrying.';
        if (!this.continueOnFail()) throw new NodeOperationError(this.getNode(), message, { itemIndex: i });
        output.push({ json: { error: message }, pairedItem: { item: i } });
      }
    }
    return [output];
  }
}
