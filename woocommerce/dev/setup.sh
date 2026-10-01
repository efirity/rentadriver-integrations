#!/usr/bin/env bash
# One-shot local WooCommerce store for testing the RentADriver plugin end to end.
# Usage:  ./setup.sh [--reset]        (from apps/integrations/woocommerce/dev)
# Then follow the printed steps: start the API, connect the store with the maintainers' connect helper, paste the two options.
set -euo pipefail
cd "$(dirname "$0")"

HOST="${RD_HOST:-$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en40 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')}"
[ -n "$HOST" ] || { echo "Could not detect a LAN IP; export RD_HOST=<ip> and re-run." >&2; exit 1; }
WP_PORT="${RD_WP_PORT:-8080}"; TLS_PORT="${RD_TLS_PORT:-8443}"; API_PORT="${RD_API_PORT:-8787}"
printf 'RD_HOST=%s\nRD_WP_PORT=%s\nRD_TLS_PORT=%s\n' "$HOST" "$WP_PORT" "$TLS_PORT" > .env
STORE="https://$HOST:$TLS_PORT"
wp() { docker compose run --rm -T cli "$@"; }

[ "${1:-}" = "--reset" ] && docker compose down -v

# WooCommerce refuses REST credentials unless the store is on TLS, so serve WordPress behind nginx with a cert that
# carries the host IP in its SAN (no SNI needed, so the API can call the store by IP).
if [ ! -f certs/cert.pem ]; then
  mkdir -p certs
  openssl req -x509 -newkey rsa:2048 -nodes -keyout certs/key.pem -out certs/cert.pem -days 825 \
    -subj "/CN=rentadriver-woo-dev" -addext "subjectAltName=IP:$HOST,IP:127.0.0.1,DNS:localhost" 2>/dev/null
  echo "generated a self-signed certificate for $HOST"
fi

docker compose up -d db wp proxy
echo "waiting for WordPress…"; for _ in $(seq 1 40); do curl -sk -o /dev/null "$STORE/" && break; sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url="$STORE" --title="RD Test Store" --admin_user=admin --admin_password=admin --admin_email=dev@example.com --skip-email
fi
wp option update home "$STORE" >/dev/null; wp option update siteurl "$STORE" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
wp plugin is-installed woocommerce 2>/dev/null || wp plugin install woocommerce --activate
wp plugin activate woocommerce rentadriver-delivery >/dev/null 2>&1 || true

# A Melbourne store: inside a RentADriver launch city, so quotes come back covered.
wp option update woocommerce_store_address "Queen Victoria Market, Queen Street" >/dev/null
wp option update woocommerce_store_city "Melbourne" >/dev/null
wp option update woocommerce_store_postcode "3000" >/dev/null
wp option update woocommerce_default_country "AU:VIC" >/dev/null
wp option update woocommerce_currency "AUD" >/dev/null
wp option update woocommerce_weight_unit "kg" >/dev/null
wp option update rentadriver_api_base "http://$HOST:$API_PORT" >/dev/null

wp eval 'WC()->shipping(); $z = new WC_Shipping_Zone(0); foreach ($z->get_shipping_methods() as $m) if ($m->id === "rentadriver_sameday") { echo "method already on the zone\n"; return; } $z->add_shipping_method("rentadriver_sameday"); $z->save(); echo "added the RentADriver method to the default zone\n";'
wp eval 'if (!wc_get_products(["name"=>"Test Vase","limit"=>1])) { $p = new WC_Product_Simple(); $p->set_name("Test Vase"); $p->set_regular_price(45); $p->set_weight(0.9); $p->save(); echo "created the test product\n"; }'

KEYS=$(wp eval '
global $wpdb; $t = $wpdb->prefix . "woocommerce_api_keys";
$row = $wpdb->get_row("SELECT key_id FROM $t WHERE description = \"RentADriver (local dev)\"");
if ($row) { $wpdb->delete($t, ["key_id" => $row->key_id]); }
$ck = "ck_" . wc_rand_hash(); $cs = "cs_" . wc_rand_hash();
$wpdb->insert($t, ["user_id"=>1,"description"=>"RentADriver (local dev)","permissions"=>"read_write","consumer_key"=>wc_api_hash($ck),"consumer_secret"=>$cs,"truncated_key"=>substr($ck,-7)], ["%d","%s","%s","%s","%s","%s"]);
echo $ck . " " . $cs;' | tr -d '\r' | tail -1)

cat <<EOF

store:      $STORE   (admin / admin)
API base:   http://$HOST:$API_PORT   (the plugin calls this)
keys:       $KEYS

Next:
  1) start the API (self-signed store cert, so TLS verification is off for it):
       cd ../../../.. && API_PORT=$API_PORT API_PUBLIC_URL=http://$HOST:$API_PORT NODE_TLS_REJECT_UNAUTHORIZED=0 \\
         bun --env-file=.env apps/api/src/index.ts
  2) connect the store (does what the wc-auth callback would) with the maintainers' connect helper:
       API_PUBLIC_URL=http://$HOST:$API_PORT NODE_TLS_REJECT_UNAUTHORIZED=0 <connect helper> --store $STORE --key ${KEYS%% *} --secret ${KEYS##* }
  3) paste the store_id and rate_token it prints into WordPress (the real Connect button does this for you):
       docker compose run --rm cli option update rentadriver_store_id <store_id>
       docker compose run --rm cli option update rentadriver_rate_token <rate_token>
EOF
