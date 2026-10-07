import { pathToFileURL } from 'node:url';
export function manifest(base) {
  const url = new URL(base);
  if (url.protocol !== 'https:' || url.pathname !== '/' || url.search || url.hash || url.username || url.password) throw new Error('Use an HTTPS origin.');
  return { display_information: { name: 'RentADriver', description: 'Private delivery quotes and status', background_color: '#2954e8' },
    features: { bot_user: { display_name: 'RentADriver', always_online: false }, slash_commands: [{ command: '/rentadriver', description: 'Connect, quote or check a delivery', usage_hint: 'connect <workspace ID> | quote | delivery <ID> | status | disconnect', url: url.origin + '/slack/commands', should_escape: false }] },
    oauth_config: { scopes: { bot: ['commands', 'chat:write'] } },
    settings: { interactivity: { is_enabled: true, request_url: url.origin + '/slack/interactions' }, org_deploy_enabled: false, socket_mode_enabled: false, token_rotation_enabled: false } };
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) console.log(JSON.stringify(manifest(process.env.PUBLIC_BASE_URL), null, 2));
