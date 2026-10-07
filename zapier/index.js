'use strict';
const { request, uuid, quoteInput, draftInput } = require('./client');
const sampleId = '00000000-0000-4000-8000-000000000001';
const connection = async (z, bundle) => (await request(z, bundle, '/automation/connection')).connection;
const fields = keys => keys.map(([key, label, type = 'string', required = true]) => ({ key, label, type, required }));
module.exports = {
  version: require('./package.json').version, platformVersion: require('zapier-platform-core').version,
  authentication: require('./authentication'), flags: { cleanInputData: false },
  creates: {
    connection: { key: 'connection', noun: 'Connection', display: { label: 'Check Connection', description: 'Verify the connected account, country workspace and sandbox/live mode.' }, operation: {
      perform: connection, inputFields: [], sample: { account_id: sampleId, account_name: 'Example Store', workspace_id: sampleId, country_code: 'GB', currency: 'GBP', mode: 'sandbox', role: 'operator' },
    } },
    quote: { key: 'quote', noun: 'Quote', display: { label: 'Get Delivery Quote', description: 'Quote one pickup and one drop-off without booking or charging.' }, operation: {
      perform: async (z, b) => (await request(z, b, '/quotes', 'POST', quoteInput(b.inputData, b.authData.workspace_id))).quote,
      inputFields: [...fields([['pickup', 'Pickup Address'], ['dropoff', 'Drop-off Address'], ['description', 'Package Description']]), { key: 'size', label: 'Package Size', choices: ['S', 'M', 'L', 'XL'], default: 'M' }],
      sample: { id: sampleId, currency: 'GBP', client_price_cents: 1250 },
    } },
    draft: { key: 'draft', noun: 'Delivery', display: { label: 'Create Draft Delivery', description: 'Create an unfunded delivery from an exact approved quote. Does not dispatch a driver.' }, operation: {
      perform: (z, b) => request(z, b, '/automation/deliveries', 'POST', draftInput(b.inputData)),
      inputFields: [...fields([['quote_id', 'Quote ID'], ['source_id', 'Stable Source ID'], ['currency', 'Quote Currency'], ['max_price_cents', 'Maximum Price (Minor Units)', 'integer']]), { key: 'validate_only', label: 'Validate Only', type: 'boolean', default: 'false', helpText: 'Validate without creating a delivery.' }],
      sample: { success: true, delivery: { id: sampleId, status: 'draft' } },
    } },
    delivery: { key: 'delivery', noun: 'Delivery', display: { label: 'Get Delivery', description: 'Read the status of a delivery in the connected workspace.' }, operation: {
      perform: async (z, b) => (await request(z, b, '/deliveries/' + uuid(b.inputData.delivery_id, 'Delivery ID'))).delivery,
      inputFields: fields([['delivery_id', 'Delivery ID']]), sample: { id: sampleId, status: 'draft' },
    } },
  },
  triggers: {
    delivery_event: { key: 'delivery_event', noun: 'Delivery Event', display: { label: 'Delivery Event', description: 'Triggers when a delivery event occurs in the connected workspace.' }, operation: {
      type: 'hook',
      inputFields: [{ key: 'event', label: 'Event', required: true, choices: ['delivery.created', 'delivery.assigned', 'delivery.picked_up', 'delivery.delivered', 'delivery.cancelled'] }],
      performSubscribe: async (z, b) => {
        const result = await request(z, b, '/automation/subscriptions', 'POST', { url: b.targetUrl, events: [b.inputData.event] });
        // Use authenticated event lookup rather than exporting the webhook signing secret to Zapier.
        return { id: result.subscription.id };
      },
      performUnsubscribe: (z, b) => request(z, b, '/automation/subscriptions/' + uuid(b.subscribeData.id, 'Subscription ID'), 'DELETE'),
      perform: async (z, b) => {
        const id = b.cleanedRequest?.data?.data?.event_id;
        if (typeof id !== 'string' || !/^[1-9][0-9]{0,18}$/.test(id) || BigInt(id) > 9223372036854775807n) return [];
        const result = await request(z, b, '/automation/events', 'GET', undefined, { event_id: id, limit: 1 });
        return result.events.filter(e => e.id === id && e.event === b.inputData.event);
      },
      performList: async (z, b) => (await request(z, b, '/automation/events', 'GET', undefined, { recent: true, limit: 100 })).events.filter(e => e.event === b.inputData.event),
      sample: { id: '1', cursor: '1', event: 'delivery.delivered', data: { event_id: '1', delivery_id: sampleId, status: 'delivered' } },
    } },
  },
};
