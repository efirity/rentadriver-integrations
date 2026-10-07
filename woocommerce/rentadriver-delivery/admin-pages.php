<?php
/**
 * Native WordPress admin pages: overview, orders, delivery settings and store connection.
 *
 * Everything shown here is read from, or saved to, the RentADriver API with the store ID and rate token; the page
 * never embeds the hosted app. Account sign-in and wallet top-ups stay in the hosted app, opened in a new tab.
 *
 * @package RentADriver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL of a plugin API path for this store.
 *
 * @param string $path Path under /v1/woocommerce/plugin/<store id>/<rate token>/.
 * @return string URL, or an empty string while the store is not connected.
 */
function rentadriver_plugin_url( $path ) {
	$store_id = get_option( 'rentadriver_store_id', '' );
	$token    = get_option( 'rentadriver_rate_token', '' );
	return $store_id && $token ? RENTADRIVER_API . '/v1/woocommerce/plugin/' . rawurlencode( $store_id ) . '/' . rawurlencode( $token ) . '/' . $path : '';
}

/**
 * Call the RentADriver plugin API for this store.
 *
 * @param string     $path   Path under /v1/woocommerce/plugin/<store id>/<rate token>/.
 * @param string     $method HTTP method.
 * @param array|null $body   JSON body.
 * @return array|WP_Error Decoded response, or the reason it failed.
 */
function rentadriver_plugin_api( $path, $method = 'GET', $body = null ) {
	$url = rentadriver_plugin_url( $path );
	if ( ! $url ) {
		return new WP_Error( 'rentadriver_not_connected', __( 'Connect the store first.', 'rentadriver-delivery' ) );
	}
	$args = array(
		'timeout' => 12,
		'headers' => array( 'Accept' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}
	if ( 'GET' === $method ) {
		$res = wp_remote_get( $url, $args );
	} elseif ( 'POST' === $method ) {
		$res = wp_remote_post( $url, $args );
	} else {
		$res = wp_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
	}
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	$code = wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		$message = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : __( 'RentADriver did not answer. Try again in a minute.', 'rentadriver-delivery' );
		return new WP_Error( 'rentadriver_api', $message );
	}
	return $data;
}

/**
 * Format minor units with their currency code.
 *
 * @param mixed  $cents    Amount in minor units.
 * @param string $currency ISO currency code.
 * @return string Display amount.
 */
function rentadriver_money( $cents, $currency ) {
	return number_format_i18n( (float) $cents / 100, 2 ) . ' ' . strtoupper( (string) $currency );
}

/**
 * Print a notice scoped to the RentADriver page (never a site-wide admin notice).
 *
 * @param string $type    success | error | warning | info.
 * @param string $message Plain-text message.
 * @param string $action  Optional trusted HTML (a button) appended after the message.
 */
function rentadriver_page_notice( $type, $message, $action = '' ) {
	echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message ) . ( $action ? ' ' . $action : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $action is built from escaped parts by the caller.
}

/**
 * Signed link that opens the hosted app for account sign-in, wallet funding and manual booking.
 *
 * @param string $label   Button text.
 * @param bool   $primary Whether this is the page's primary action.
 * @return string Button HTML, or an empty string for users who cannot approve the handoff.
 */
function rentadriver_app_button( $label, $primary = true ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$app = wp_nonce_url( admin_url( 'admin-post.php?action=rentadriver_app' ), 'rentadriver_app' );
	return '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( $app ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
}

/** Load the settings class outside WooCommerce's shipping screens. */
function rentadriver_settings_method() {
	if ( ! class_exists( 'RentADriver_Shipping_Method' ) && class_exists( 'WC_Shipping_Method' ) ) {
		require_once __DIR__ . '/class-rentadriver-shipping-method.php';
	}
	return class_exists( 'RentADriver_Shipping_Method' ) ? new RentADriver_Shipping_Method() : null;
}

/** Page tabs and submenu items: the same sections, in the same order, as the RentADriver app. */
function rentadriver_admin_tabs() {
	return array(
		'overview' => __( 'Overview', 'rentadriver-delivery' ),
		'orders'   => __( 'Orders', 'rentadriver-delivery' ),
		'settings' => __( 'Settings', 'rentadriver-delivery' ),
		'test'     => __( 'Test rate', 'rentadriver-delivery' ),
	);
}

/**
 * Country name for an ISO code; the store's WooCommerce country when the API does not say.
 *
 * @param string $code Two-letter country code.
 * @return string Country name, or a generic phrase when unknown.
 */
function rentadriver_country_name( $code ) {
	$code = strtoupper( (string) ( $code ? $code : explode( ':', (string) get_option( 'woocommerce_default_country', '' ) )[0] ) );
	if ( $code && function_exists( 'WC' ) && WC()->countries && isset( WC()->countries->countries[ $code ] ) ) {
		return WC()->countries->countries[ $code ];
	}
	return $code ? $code : __( 'this store’s country', 'rentadriver-delivery' );
}

/** Render the RentADriver admin page. */
function rentadriver_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ) );
	}
	$connected = get_option( 'rentadriver_store_id', '' ) && get_option( 'rentadriver_rate_token', '' );
	// Display-only values set by our own redirects; they carry no credentials.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$notice = sanitize_key( wp_unslash( $_GET['rd_notice'] ?? '' ) );
	$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$tabs = rentadriver_admin_tabs();
	if ( 'connection' === $tab ) {
		$tab             = 'settings'; // Links from 0.4.x.
		$_GET['section'] = 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display routing only.
	}
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'overview';
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'RentADriver Delivery', 'rentadriver-delivery' ) . '</h1>';
	if ( ! empty( $GLOBALS['rentadriver_connection_error'] ) ) {
		rentadriver_page_notice( 'error', $GLOBALS['rentadriver_connection_error'] );
	}
	$notices = array(
		'connected' => array( 'success', __( 'Store connected. Next, link your RentADriver account and choose your delivery settings.', 'rentadriver-delivery' ) ),
		'renewed'   => array( 'success', __( 'WooCommerce access renewed. Your linked RentADriver account and delivery settings did not change.', 'rentadriver-delivery' ) ),
		'saved'     => array( 'success', __( 'Delivery settings saved. Checkout uses them right away.', 'rentadriver-delivery' ) ),
	);
	if ( isset( $notices[ $notice ] ) ) {
		rentadriver_page_notice( $notices[ $notice ][0], $notices[ $notice ][1] );
	}
	if ( in_array( $notice, array( 'save_failed', 'test_rate', 'flash' ), true ) ) {
		$key    = 'rentadriver_delivery_notice_' . get_current_user_id();
		$stored = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $stored ) && isset( $stored['type'], $stored['message'] ) ) {
			rentadriver_page_notice( $stored['type'], $stored['message'] );
		}
	}

	if ( ! $connected ) {
		rentadriver_render_connection( false );
		echo '</div>';
		return;
	}

	echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'RentADriver sections', 'rentadriver-delivery' ) . '">';
	foreach ( $tabs as $slug => $label ) {
		$url = admin_url( 'admin.php?page=rentadriver-settings' . ( 'overview' === $slug ? '' : '&tab=' . $slug ) );
		echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '"' . ( $slug === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	if ( 'orders' === $tab ) {
		rentadriver_render_orders();
	} elseif ( 'settings' === $tab ) {
		rentadriver_render_settings();
	} elseif ( 'test' === $tab ) {
		rentadriver_render_test_rate();
	} else {
		rentadriver_render_overview();
	}
	echo '</div>';
}

/** Installed plugin version, read from this plugin's own header. */
function rentadriver_plugin_version() {
	$header = get_file_data( __DIR__ . '/rentadriver-delivery.php', array( 'version' => 'Version' ) );
	return $header['version'];
}

/** The installed version, shown once at the bottom of Settings. */
function rentadriver_render_version() {
	$header = array( 'version' => rentadriver_plugin_version() );
	/* translators: %s: installed plugin version. */
	echo '<p class="description">' . esc_html( sprintf( __( 'RentADriver Delivery plugin version %s', 'rentadriver-delivery' ), $header['version'] ) ) . '</p>';
}

/**
 * Text link that opens the hosted app (account sign-in, wallet funding, manual booking) in a new tab.
 *
 * @param string $label Link text.
 * @return string Link HTML, or an empty string for users who cannot approve the handoff.
 */
function rentadriver_app_link( $label ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$app = wp_nonce_url( admin_url( 'admin-post.php?action=rentadriver_app' ), 'rentadriver_app' );
	return '<a href="' . esc_url( $app ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'rentadriver-delivery' ) . '</span> &#8599;</a>';
}

/** Overview: account, mode, wallet, coverage and this week's activity, plus the two settings changed most often. */
function rentadriver_render_overview() {
	$data = rentadriver_plugin_api( 'settings' );
	if ( is_wp_error( $data ) ) {
		/* translators: %s: error message. */
		rentadriver_page_notice( 'error', sprintf( __( 'Could not load your RentADriver status: %s', 'rentadriver-delivery' ), $data->get_error_message() ) );
		return;
	}
	$account  = is_array( $data['account'] ?? null ) ? $data['account'] : null;
	$link     = $data['account_link'] ?? ( $account ? 'ok' : 'none' );
	$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
	$store    = is_array( $data['store'] ?? null ) ? $data['store'] : array();
	$coverage = is_array( $data['coverage'] ?? null ) ? $data['coverage'] : array();
	$week     = is_array( $data['week'] ?? null ) ? $data['week'] : array();

	if ( 'broken' === $link ) {
		rentadriver_page_notice( 'error', __( 'Your RentADriver account link stopped working (its API key was revoked). Checkout rates and automatic booking are paused until you reconnect the account.', 'rentadriver-delivery' ), rentadriver_app_button( __( 'Reconnect account (new tab)', 'rentadriver-delivery' ) ) );
	} elseif ( 'none' === $link ) {
		rentadriver_page_notice( 'warning', __( 'Link a RentADriver account to show rates at checkout and book deliveries.', 'rentadriver-delivery' ), rentadriver_app_button( __( 'Link account (new tab)', 'rentadriver-delivery' ) ) );
	}
	if ( ! empty( $store['last_error'] ) ) {
		/* translators: %s: error message. */
		rentadriver_page_notice( 'warning', sprintf( __( 'Setup needs attention: %s', 'rentadriver-delivery' ), $store['last_error'] ) );
	}

	$section_url = function ( $section, $field = '' ) {
		return admin_url( 'admin.php?page=rentadriver-settings&tab=settings&section=' . $section ) . ( $field ? '#woocommerce_rentadriver_sameday_' . $field : '' );
	};
	$link_html   = function ( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	};
	$sep         = ' &middot; ';
	$rows        = array();

	$open   = rentadriver_app_link( $account ? __( 'Open account', 'rentadriver-delivery' ) : __( 'Link account', 'rentadriver-delivery' ) );
	$name   = $account ? esc_html( trim( ( $account['name'] ?? '' ) . ( empty( $account['email'] ) ? '' : ' (' . $account['email'] . ')' ) ) ) : esc_html__( 'Not linked', 'rentadriver-delivery' );
	$rows[] = array( __( 'RentADriver account', 'rentadriver-delivery' ), $name . ( $open ? $sep . $open : '' ), '' );
	$rows[] = array( __( 'Mode', 'rentadriver-delivery' ), empty( $data['sandbox'] ) ? esc_html__( 'Live — real drivers, paid from your wallet', 'rentadriver-delivery' ) : esc_html__( 'Sandbox — simulated drivers, no charges', 'rentadriver-delivery' ), '' );
	if ( $account ) {
		$balance = esc_html( rentadriver_money( $account['wallet_balance_cents'] ?? 0, $account['currency'] ?? '' ) );
		if ( ! empty( $account['workspace_required'] ) ) {
			$country = rentadriver_country_name( $store['location']['country_code'] ?? ( $store['country_code'] ?? '' ) );
			/* translators: %s: country name, e.g. Moldova. */
			$fund = rentadriver_app_link( sprintf( __( 'Enable %s', 'rentadriver-delivery' ), $country ) );
			/* translators: 1: country name, 2: currency code. */
			$why    = sprintf( __( 'Your RentADriver account has no %1$s wallet yet. Wallets are kept per country: enable %1$s in your RentADriver account, then add funds in %2$s.', 'rentadriver-delivery' ), $country, strtoupper( (string) ( $account['currency'] ?? '' ) ) );
			$rows[] = array( __( 'Wallet balance', 'rentadriver-delivery' ), $balance . ( $fund ? $sep . $fund : '' ), esc_html( $why ) );
		} elseif ( empty( $data['sandbox'] ) ) {
			$fund   = rentadriver_app_link( __( 'Add funds', 'rentadriver-delivery' ) );
			$empty  = (int) ( $account['wallet_balance_cents'] ?? 0 ) <= 0;
			$rows[] = array( __( 'Wallet balance', 'rentadriver-delivery' ), $balance . ( $fund ? $sep . $fund : '' ), $empty ? esc_html__( 'Live deliveries are paid from this balance. Orders booked with an empty wallet wait as “Needs funds”.', 'rentadriver-delivery' ) : '' );
		} else {
			$rows[] = array( __( 'Wallet balance', 'rentadriver-delivery' ), $balance, esc_html__( 'Sandbox deliveries are free.', 'rentadriver-delivery' ) );
		}
	}
	if ( ! empty( $coverage['area']['name'] ) ) {
		/* translators: %s: radius in km. */
		$area   = $coverage['area']['name'] . ( isset( $settings['radius_km'] ) ? ' · ' . sprintf( __( '%s km radius', 'rentadriver-delivery' ), $settings['radius_km'] ) : '' );
		$rows[] = array( __( 'Delivery area', 'rentadriver-delivery' ), esc_html( $area ) . $sep . $link_html( $section_url( 'rate', 'rd_radius_km' ), __( 'Change radius', 'rentadriver-delivery' ) ), '' );
	} else {
		$rows[] = array( __( 'Delivery area', 'rentadriver-delivery' ), esc_html__( 'Outside coverage', 'rentadriver-delivery' ) . $sep . $link_html( $section_url( 'pickup', 'rd_pickup_address' ), __( 'Set pickup address', 'rentadriver-delivery' ) ), esc_html__( 'No rate is shown at checkout until the pickup address is inside a RentADriver city. It uses your WooCommerce store address unless you set another one.', 'rentadriver-delivery' ) );
	}
	/* translators: 1: rate requests, 2: requests inside coverage, 3: deliveries booked. */
	$week_text = sprintf( __( '%1$d checkout rate requests (%2$d in coverage) · %3$d deliveries booked', 'rentadriver-delivery' ), (int) ( $week['rate_requests'] ?? 0 ), (int) ( $week['rate_requests_covered'] ?? 0 ), (int) ( $week['orders_booked'] ?? 0 ) );
	$rows[]    = array( __( 'Last 7 days', 'rentadriver-delivery' ), esc_html( $week_text ) . $sep . $link_html( admin_url( 'admin.php?page=rentadriver-settings&tab=orders' ), __( 'View orders', 'rentadriver-delivery' ) ), '' );

	echo '<h2>' . esc_html__( 'Status', 'rentadriver-delivery' ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( $rows as $r ) {
		// Every value above is escaped where it is built.
		echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . $r[1] . ( $r[2] ? '<p class="description">' . $r[2] . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</tbody></table>';
	rentadriver_sync_form( $store, 'overview' );

	if ( 'ok' !== $link ) {
		return; // Nothing below works until the account is linked; the notice above says how.
	}
	$modes   = array(
		'rate_only' => __( 'When the customer chose RentADriver at checkout', 'rentadriver-delivery' ),
		'all_paid'  => __( 'Every paid order', 'rentadriver-delivery' ),
		'fulfilled' => __( 'When the order is marked Completed', 'rentadriver-delivery' ),
		'manual'    => __( 'Never — book by hand', 'rentadriver-delivery' ),
	);
	$current = $settings['auto_book'] ?? 'rate_only';
	echo '<h2>' . esc_html__( 'Quick settings', 'rentadriver-delivery' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rentadriver_quick_update">';
	wp_nonce_field( 'rentadriver_quick_update' );
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'Checkout rate', 'rentadriver-delivery' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Checkout rate', 'rentadriver-delivery' ) . '</legend><label for="rentadriver-enabled"><input type="checkbox" name="enabled" id="rentadriver-enabled" value="yes"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> ' . esc_html__( 'Show the RentADriver rate at checkout', 'rentadriver-delivery' ) . '</label></fieldset></td></tr>';
	echo '<tr><th scope="row"><label for="rentadriver-auto-book">' . esc_html__( 'Automatic booking', 'rentadriver-delivery' ) . '</label></th><td><select id="rentadriver-auto-book" name="auto_book">';
	foreach ( $modes as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></td></tr></tbody></table>';
	submit_button( __( 'Save changes', 'rentadriver-delivery' ) );
	echo '</form>';
}

/** Save the Overview's quick settings (checkout rate on/off, automatic booking). */
function rentadriver_quick_update() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rentadriver_quick_update' );
	$mode = isset( $_POST['auto_book'] ) ? sanitize_key( wp_unslash( $_POST['auto_book'] ) ) : '';
	if ( in_array( $mode, array( 'rate_only', 'all_paid', 'fulfilled', 'manual' ), true ) ) {
		$patch  = array(
			'enabled'   => ! empty( $_POST['enabled'] ),
			'auto_book' => $mode,
		);
		$result = rentadriver_plugin_api( 'settings', 'PATCH', $patch );
	} else {
		$result = new WP_Error( 'rentadriver_field', __( 'Choose when to book automatically.', 'rentadriver-delivery' ) );
	}
	if ( is_wp_error( $result ) ) {
		/* translators: %s: error message. */
		rentadriver_flash( 'error', sprintf( __( 'RentADriver did not save this change: %s', 'rentadriver-delivery' ), $result->get_error_message() ) );
	} else {
		// Checkout caches rates for two minutes; a changed setting must apply at once.
		update_option( 'rentadriver_rate_cache_version', wp_generate_uuid4(), false );
		rentadriver_flash( 'success', __( 'Saved. Checkout uses the change right away.', 'rentadriver-delivery' ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rentadriver-settings&rd_notice=flash' ), 303 );
	exit;
}
add_action( 'admin_post_rentadriver_quick_update', 'rentadriver_quick_update' );

/**
 * Sync button with the store address RentADriver has on file.
 *
 * @param array  $store Store as RentADriver has it (from the status response), or empty when unknown.
 * @param string $back  Where to return: overview or connection.
 */
function rentadriver_sync_form( $store, $back ) {
	$address = is_array( $store ) ? (string) ( $store['location']['address'] ?? '' ) : '';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rentadriver_sync_store"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	wp_nonce_field( 'rentadriver_sync_store' );
	echo '<p><button type="submit" class="button" data-rentadriver-busy="' . esc_attr__( 'Syncing…', 'rentadriver-delivery' ) . '">' . esc_html__( 'Sync store details', 'rentadriver-delivery' ) . '</button></p>';
	/* translators: %s: store address as RentADriver has it. */
	echo '<p class="description">' . ( $address ? esc_html( sprintf( __( 'RentADriver has your store at: %s.', 'rentadriver-delivery' ), $address ) ) . ' ' : '' ) . esc_html__( 'Syncing re-reads the store address, currency and timezone from WooCommerce. It also runs by itself when you save WooCommerce → Settings → General.', 'rentadriver-delivery' ) . '</p></form>';
}

/** Re-read the store details from WooCommerce now. */
function rentadriver_sync_store() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rentadriver_sync_store' );
	$back   = isset( $_POST['back'] ) && 'connection' === sanitize_key( wp_unslash( $_POST['back'] ) ) ? 'admin.php?page=rentadriver-settings&tab=settings&section=connection' : 'admin.php?page=rentadriver-settings';
	$result = rentadriver_plugin_api( 'sync', 'POST', array() );
	if ( is_wp_error( $result ) ) {
		/* translators: %s: error message. */
		rentadriver_flash( 'error', sprintf( __( 'Store details were not synced: %s', 'rentadriver-delivery' ), $result->get_error_message() ) );
	} else {
		$address = (string) ( $result['store']['location']['address'] ?? '' );
		$problem = (string) ( $result['store']['last_error'] ?? '' );
		/* translators: %s: store address as RentADriver now has it. */
		$message = $address ? sprintf( __( 'Store details synced. RentADriver now has your store at: %s.', 'rentadriver-delivery' ), $address ) : __( 'Store details synced.', 'rentadriver-delivery' );
		rentadriver_flash( $problem ? 'warning' : 'success', $problem ? $message . ' ' . $problem : $message );
		update_option( 'rentadriver_rate_cache_version', wp_generate_uuid4(), false );
	}
	wp_safe_redirect( admin_url( $back . ( false === strpos( $back, '?' ) ? '?' : '&' ) . 'rd_notice=flash' ), 303 );
	exit;
}
add_action( 'admin_post_rentadriver_sync_store', 'rentadriver_sync_store' );

/** WooCommerce does not notify RentADriver when the store address changes; sync in the background when it is saved. */
function rentadriver_sync_after_general_settings() {
	$url = rentadriver_plugin_url( 'sync' );
	if ( $url ) {
		wp_remote_post(
			$url,
			array(
				'timeout'  => 1,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => '{}',
			)
		);
		update_option( 'rentadriver_rate_cache_version', wp_generate_uuid4(), false );
	}
}
add_action( 'woocommerce_update_options_general', 'rentadriver_sync_after_general_settings' );

/** Orders sent to RentADriver, with delivery status and tracking. */
function rentadriver_render_orders() {
	$data = rentadriver_plugin_api( 'orders' );
	if ( is_wp_error( $data ) ) {
		/* translators: %s: error message. */
		rentadriver_page_notice( 'error', sprintf( __( 'Could not load orders: %s', 'rentadriver-delivery' ), $data->get_error_message() ) );
		return;
	}
	$orders = is_array( $data['orders'] ?? null ) ? $data['orders'] : array();
	echo '<p>' . esc_html__( 'Orders RentADriver has seen, newest first. To book an order that was not booked automatically, open RentADriver → Orders.', 'rentadriver-delivery' ) . ' ' . rentadriver_app_button( __( 'Open RentADriver orders (new tab)', 'rentadriver-delivery' ), false ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in rentadriver_app_button().
	if ( ! $orders ) {
		echo '<p><em>' . esc_html__( 'No orders yet. Paid orders appear here once RentADriver receives them from WooCommerce.', 'rentadriver-delivery' ) . '</em></p>';
		return;
	}
	$labels = array(
		'pending'     => __( 'Waiting', 'rentadriver-delivery' ),
		'skipped'     => __( 'Not booked', 'rentadriver-delivery' ),
		'booked'      => __( 'Booked', 'rentadriver-delivery' ),
		'needs_funds' => __( 'Needs funds', 'rentadriver-delivery' ),
		'failed'      => __( 'Failed', 'rentadriver-delivery' ),
		'cancelled'   => __( 'Cancelled', 'rentadriver-delivery' ),
	);
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array( __( 'Order', 'rentadriver-delivery' ), __( 'Customer', 'rentadriver-delivery' ), __( 'Booking', 'rentadriver-delivery' ), __( 'Delivery', 'rentadriver-delivery' ), __( 'Price', 'rentadriver-delivery' ), __( 'Received', 'rentadriver-delivery' ) ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $orders as $o ) {
		$delivery = is_array( $o['delivery'] ?? null ) ? $o['delivery'] : null;
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $o['order_id'] ?? 0 ) ) : null;
		$number   = '#' . ltrim( (string) ( $o['order_number'] ?? '' ), '#' );
		echo '<tr><td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $number ) . '</a>' : esc_html( $number ) ) . '</td>';
		echo '<td>' . esc_html( $o['customer_name'] ?? '' ) . '</td>';
		echo '<td>' . esc_html( $labels[ $o['status'] ?? '' ] ?? ( $o['status'] ?? '' ) );
		if ( ! empty( $o['last_error'] ) ) {
			echo '<br><small>' . esc_html( $o['last_error'] ) . '</small>';
		}
		echo '</td><td>';
		if ( $delivery ) {
			echo esc_html( ( $delivery['short_code'] ?? '' ) . ' · ' . str_replace( '_', ' ', (string) ( $delivery['status'] ?? '' ) ) );
			if ( ! empty( $delivery['tracking_url'] ) ) {
				echo '<br><a href="' . esc_url( $delivery['tracking_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Tracking', 'rentadriver-delivery' ) . '</a>';
			}
		} else {
			echo '—';
		}
		echo '</td><td>' . ( $delivery && isset( $delivery['price_cents'] ) ? esc_html( rentadriver_money( $delivery['price_cents'], $delivery['currency'] ?? '' ) ) : '—' ) . '</td>';
		$created = isset( $o['created_at'] ) ? strtotime( $o['created_at'] ) : false;
		echo '<td>' . ( $created ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) ) : '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/** Settings sections, in sub-navigation order: slug => [ label, intro, field keys ]. */
function rentadriver_settings_sections() {
	return array(
		'rate'       => array( __( 'Checkout rate', 'rentadriver-delivery' ), __( 'Where you deliver and how the rate looks at checkout.', 'rentadriver-delivery' ), array( 'rd_enabled', 'rd_rate_label', 'rd_rate_description', 'rd_radius_km', 'rd_max_size_class', 'rd_min_subtotal', 'rd_cutoff_time', 'rd_prep_minutes' ) ),
		'nextday'    => array( __( 'Next day', 'rentadriver-delivery' ), __( 'What happens after the cut-off, and the optional cheaper tomorrow-morning rate.', 'rentadriver-delivery' ), array( 'rd_next_day_after_cutoff', 'rd_offer_next_day', 'rd_next_day_label', 'rd_next_day_hour' ) ),
		'pricing'    => array( __( 'Customer price', 'rentadriver-delivery' ), __( 'You always pay RentADriver the live quote; this only changes what the shopper is charged.', 'rentadriver-delivery' ), array( 'rd_pricing_mode', 'rd_adjust_pct', 'rd_flat', 'rd_free_over' ) ),
		'booking'    => array( __( 'Booking', 'rentadriver-delivery' ), __( 'When drivers are booked and how the WooCommerce order is updated.', 'rentadriver-delivery' ), array( 'rd_auto_book', 'rd_fulfill_on', 'rd_notify_customer', 'rd_complete_on_delivered', 'rd_merchant_alerts' ) ),
		'pickup'     => array( __( 'Pickup & proof', 'rentadriver-delivery' ), __( 'Where the driver collects the order and what they record at the door.', 'rentadriver-delivery' ), array( 'rd_pickup_address', 'rd_pickup_contact_name', 'rd_pickup_contact_phone', 'rd_driver_instructions', 'rd_proof_required' ) ),
		'connection' => array( __( 'Connection', 'rentadriver-delivery' ), '', array() ),
	);
}

/**
 * The requested Settings section, falling back to the first one.
 *
 * @param string $requested Section slug from the request.
 * @return string Valid section slug.
 */
function rentadriver_settings_section( $requested ) {
	return array_key_exists( $requested, rentadriver_settings_sections() ) ? $requested : 'rate';
}

/** Settings: sub-navigation, then one section at a time; the plugin version sits at the bottom. */
function rentadriver_render_settings() {
	$sections = rentadriver_settings_sections();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display routing only.
	$current = rentadriver_settings_section( sanitize_key( wp_unslash( $_GET['section'] ?? '' ) ) );
	$links   = array();
	foreach ( $sections as $slug => $section ) {
		$url     = admin_url( 'admin.php?page=rentadriver-settings&tab=settings&section=' . $slug );
		$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $slug === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $section[0] ) . '</a>';
	}
	echo '<ul class="subsubsub">' . implode( ' | </li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

	if ( 'connection' === $current ) {
		rentadriver_render_connection( true );
		rentadriver_render_disclosure();
		rentadriver_render_version();
		return;
	}
	$method = rentadriver_settings_method();
	if ( ! $method ) {
		rentadriver_page_notice( 'error', __( 'WooCommerce shipping is not available.', 'rentadriver-delivery' ) );
		return;
	}
	$remote = $method->load_live_settings();
	if ( ! is_array( $remote ) ) {
		$message = is_wp_error( $remote ) ? $remote->get_error_message() : __( 'Connect the store first.', 'rentadriver-delivery' );
		/* translators: %s: error message. */
		rentadriver_page_notice( 'error', sprintf( __( 'Could not load your delivery settings, so they cannot be edited right now: %s', 'rentadriver-delivery' ), $message ) );
		return;
	}
	echo '<h2>' . esc_html( $sections[ $current ][0] ) . '</h2>';
	echo '<p>' . esc_html( $sections[ $current ][1] ) . ' ' . esc_html__( 'Saved to RentADriver; the RentADriver app shows the same settings.', 'rentadriver-delivery' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="rentadriver_save_settings"><input type="hidden" name="section" value="' . esc_attr( $current ) . '">';
	wp_nonce_field( 'rentadriver_save_settings' );
	echo '<table class="form-table">';
	$method->generate_settings_html( array_intersect_key( $method->delivery_form_fields(), array_flip( $sections[ $current ][2] ) ) );
	echo '</table>';
	submit_button( __( 'Save changes', 'rentadriver-delivery' ) );
	echo '</form>';
	rentadriver_render_version();
}

/** Test rate: what a customer at an address would be offered with the saved settings. */
function rentadriver_render_test_rate() {
	echo '<p>' . esc_html__( 'Preview a checkout quote with your saved settings. Nothing is booked and your wallet is not charged.', 'rentadriver-delivery' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="rentadriver_test_rate">';
	wp_nonce_field( 'rentadriver_test_rate' );
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="rentadriver-test-address">' . esc_html__( 'Delivery address', 'rentadriver-delivery' ) . '</label></th><td><input id="rentadriver-test-address" name="address" type="text" class="regular-text" required minlength="3" maxlength="300" placeholder="' . esc_attr__( 'Street, city, postcode', 'rentadriver-delivery' ) . '"></td></tr>';
	/* translators: %s: store currency code. */
	echo '<tr><th scope="row"><label for="rentadriver-test-subtotal">' . esc_html( sprintf( __( 'Basket subtotal (%s)', 'rentadriver-delivery' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ) ) . '</label></th><td><input id="rentadriver-test-subtotal" name="subtotal" type="number" min="0" step="0.01" value="50" class="small-text"></td></tr>';
	echo '</tbody></table>';
	submit_button( __( 'Test rate', 'rentadriver-delivery' ) );
	echo '</form>';
	rentadriver_render_test_order();
}

/**
 * Test order (sandbox mode only): a WooCommerce order marked as a test order, paid with no money, so a merchant or a
 * reviewer can follow the whole flow (booking, driver, tracking notes, completion). Sandbox deliveries write back only to
 * test orders; real orders are never touched.
 */
function rentadriver_render_test_order() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$data = rentadriver_plugin_api( 'settings' );
	if ( is_wp_error( $data ) ) {
		return;
	}
	echo '<h2>' . esc_html__( 'Test order', 'rentadriver-delivery' ) . '</h2>';
	if ( empty( $data['sandbox'] ) ) {
		echo '<p class="description">' . esc_html__( 'Test orders are available in sandbox mode. Switch the store to sandbox in the RentADriver app to try the full delivery flow without real drivers or charges.', 'rentadriver-delivery' ) . '</p>';
		return;
	}
	echo '<p>' . esc_html__( 'Creates a paid WooCommerce test order (no payment is taken) shipped with Same-day by RentADriver. A simulated driver is booked, and the order gets the tracking notes and is completed on delivery, like a real order. Sandbox deliveries never update real orders.', 'rentadriver-delivery' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="rentadriver_create_test_order">';
	wp_nonce_field( 'rentadriver_create_test_order' );
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="rentadriver-test-order-address">' . esc_html__( 'Delivery address', 'rentadriver-delivery' ) . '</label></th><td><input id="rentadriver-test-order-address" name="address" type="text" class="regular-text" required minlength="3" maxlength="300" placeholder="' . esc_attr__( 'Street, city, postcode', 'rentadriver-delivery' ) . '"><p class="description">' . esc_html__( 'An address inside your delivery area.', 'rentadriver-delivery' ) . '</p></td></tr>';
	echo '</tbody></table>';
	echo '<p class="submit"><button type="submit" class="button" data-rentadriver-busy="' . esc_attr__( 'Creating…', 'rentadriver-delivery' ) . '">' . esc_html__( 'Create test order', 'rentadriver-delivery' ) . '</button></p>';
	echo '</form>';
}

/** Create the test order (sandbox mode only; checked again with RentADriver). */
function rentadriver_create_test_order() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rentadriver_create_test_order' );
	$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	$data    = rentadriver_plugin_api( 'settings' );
	if ( is_wp_error( $data ) ) {
		/* translators: %s: error message. */
		rentadriver_flash( 'error', sprintf( __( 'Test order not created: %s', 'rentadriver-delivery' ), $data->get_error_message() ) );
	} elseif ( empty( $data['sandbox'] ) ) {
		rentadriver_flash( 'error', __( 'Test order not created: the store is in live mode. Switch it to sandbox in the RentADriver app first.', 'rentadriver-delivery' ) );
	} elseif ( strlen( $address ) < 3 ) {
		rentadriver_flash( 'error', __( 'Test order not created: enter a delivery address.', 'rentadriver-delivery' ) );
	} else {
		$order = rentadriver_build_test_order( $address );
		/* translators: %s: order number. */
		rentadriver_flash( 'success', sprintf( __( 'Test order #%s created. With automatic booking on, a simulated driver is booked in a moment; follow it on the Orders tab.', 'rentadriver-delivery' ), $order->get_order_number() ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rentadriver-settings&tab=test&rd_notice=flash' ), 303 );
	exit;
}
add_action( 'admin_post_rentadriver_create_test_order', 'rentadriver_create_test_order' );

/**
 * The order itself: one "Test parcel" line, Same-day by RentADriver shipping, the address typed by the merchant, the
 * protected `_rentadriver_test_order` meta (checkout cannot set it) and no payment.
 *
 * @param string $address Delivery address as typed.
 * @return WC_Order
 */
function rentadriver_build_test_order( $address ) {
	$user    = wp_get_current_user();
	$country = function_exists( 'wc_get_base_location' ) ? ( wc_get_base_location()['country'] ?? '' ) : '';
	$order   = wc_create_order();
	$item    = new WC_Order_Item_Product();
	$item->set_name( __( 'Test parcel (RentADriver test order)', 'rentadriver-delivery' ) );
	$item->set_quantity( 1 );
	$item->set_subtotal( 0 );
	$item->set_total( 0 );
	$order->add_item( $item );
	$ship = new WC_Order_Item_Shipping();
	$ship->set_method_title( __( 'Same-day by RentADriver', 'rentadriver-delivery' ) );
	$ship->set_method_id( 'rentadriver_sameday' );
	$ship->set_total( 0 );
	$order->add_item( $ship );
	$contact = array(
		'first_name' => __( 'Test', 'rentadriver-delivery' ),
		'last_name'  => __( 'Customer', 'rentadriver-delivery' ),
		'address_1'  => $address,
		'country'    => $country,
		'email'      => $user->user_email,
	);
	$order->set_address( $contact, 'billing' );
	unset( $contact['email'] );
	$order->set_address( $contact, 'shipping' );
	$order->set_payment_method( 'rentadriver_test' );
	$order->set_payment_method_title( __( 'Test order (no payment)', 'rentadriver-delivery' ) );
	$order->update_meta_data( '_rentadriver_test_order', 'yes' );
	$order->calculate_totals( false );
	$order->add_order_note( __( 'RentADriver test order: no payment was taken. Sandbox deliveries update only test orders like this one.', 'rentadriver-delivery' ) );
	$order->set_date_paid( time() );
	$order->set_status( 'processing' );
	$order->save();
	return $order;
}

/** External-service disclosure with the privacy policy and terms. */
function rentadriver_render_disclosure() {
	echo '<p class="description">' . esc_html__( 'RentADriver is an external delivery service operated by Efirity. An account is required, and live deliveries are paid. Connecting authorizes the service to access your store and process delivery data as described in its privacy policy.', 'rentadriver-delivery' ) . '</p>';
	echo '<p class="description"><a href="https://rentadriver.ai/privacy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'rentadriver-delivery' ) . '</a> | <a href="https://rentadriver.ai/terms" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of service', 'rentadriver-delivery' ) . '</a></p>';
}

/**
 * Connection section: connect, renew WooCommerce access, and open the hosted app.
 *
 * @param bool $connected Whether the store is connected.
 */
function rentadriver_render_connection( $connected ) {
	$return_to = wp_nonce_url( admin_url( 'admin.php?page=rentadriver-settings' ), 'rd_connect', 'rd_nonce' );
	$connect   = RENTADRIVER_API . '/v1/woocommerce/install?store=' . rawurlencode( home_url( '/' ) ) . '&return_to=' . rawurlencode( html_entity_decode( $return_to ) );
	if ( ! $connected ) {
		rentadriver_render_disclosure();
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<p>' . esc_html__( 'Ask a WordPress administrator to connect the store or open the RentADriver app.', 'rentadriver-delivery' ) . '</p>';
		return;
	}
	if ( ! $connected ) {
		echo '<h2>' . esc_html__( 'Connect your store', 'rentadriver-delivery' ) . '</h2>';
		echo '<p>' . esc_html__( 'Step 1: allow RentADriver to read orders and update tracking in WooCommerce. Step 2: link your RentADriver account and choose your delivery settings.', 'rentadriver-delivery' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $connect ) . '" data-rentadriver-busy="' . esc_attr__( 'Connecting…', 'rentadriver-delivery' ) . '">' . esc_html__( 'Connect store', 'rentadriver-delivery' ) . '</a></p>';
		return;
	}
	echo '<h2>' . esc_html__( 'Connection', 'rentadriver-delivery' ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'RentADriver app', 'rentadriver-delivery' ) . '</th><td>' . rentadriver_app_button( __( 'Open RentADriver (new tab)', 'rentadriver-delivery' ), false ) . '<p class="description">' . esc_html__( 'Sign in, switch the linked account, add wallet funds and book orders by hand.', 'rentadriver-delivery' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in rentadriver_app_button().
	echo '<tr><th scope="row">' . esc_html__( 'WooCommerce access', 'rentadriver-delivery' ) . '</th><td>' . esc_html__( 'Connected', 'rentadriver-delivery' );
	echo '<details><summary>' . esc_html__( 'Troubleshooting: renew WooCommerce access', 'rentadriver-delivery' ) . '</summary>';
	echo '<p class="description">' . esc_html__( 'Only needed if RentADriver stopped receiving orders, for example after its key was deleted under WooCommerce → Settings → Advanced → REST API. This does not change your linked RentADriver account.', 'rentadriver-delivery' ) . '</p>';
	echo '<p><a class="button" href="' . esc_url( $connect ) . '" data-rentadriver-busy="' . esc_attr__( 'Connecting…', 'rentadriver-delivery' ) . '">' . esc_html__( 'Renew WooCommerce access', 'rentadriver-delivery' ) . '</a></p></details></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Store details', 'rentadriver-delivery' ) . '</th><td>';
	$status = rentadriver_plugin_api( 'settings' );
	rentadriver_sync_form( is_wp_error( $status ) ? array() : ( $status['store'] ?? array() ), 'connection' );
	echo '</td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Shipping zones', 'rentadriver-delivery' ) . '</th><td><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) . '">' . esc_html__( 'Manage shipping zones', 'rentadriver-delivery' ) . '</a><p class="description">' . esc_html__( 'Add Same-day by RentADriver to each shipping zone where you want to offer delivery.', 'rentadriver-delivery' ) . '</p></td></tr>';
	echo '</tbody></table>';
}

/**
 * Remember a message for the next page view of this administrator.
 *
 * @param string $type    Notice type.
 * @param string $message Plain-text message.
 */
function rentadriver_flash( $type, $message ) {
	set_transient(
		'rentadriver_delivery_notice_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		120
	);
}

/** Save delivery settings through the shared settings class. */
function rentadriver_save_settings() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rentadriver_save_settings' );
	$method = rentadriver_settings_method();
	$error  = $method ? '' : __( 'WooCommerce shipping is not available.', 'rentadriver-delivery' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
	$section = rentadriver_settings_section( isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '' );
	$keys    = rentadriver_settings_sections()[ $section ][2];
	if ( $method && $keys ) {
		$method->save_section( $keys );
		$error = $method->last_save_error();
	}
	if ( $error ) {
		rentadriver_flash( 'error', $error );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rentadriver-settings&tab=settings&section=' . $section . '&rd_notice=' . ( $error ? 'save_failed' : 'saved' ) ), 303 );
	exit;
}
add_action( 'admin_post_rentadriver_save_settings', 'rentadriver_save_settings' );

/** Ask RentADriver what a customer at an address would be offered. */
function rentadriver_test_rate() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage RentADriver settings.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rentadriver_test_rate' );
	$address  = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	$subtotal = isset( $_POST['subtotal'] ) ? (int) round( (float) sanitize_text_field( wp_unslash( $_POST['subtotal'] ) ) * 100 ) : 5000;
	$result   = rentadriver_plugin_api(
		'test-rate',
		'POST',
		array(
			'address'        => $address,
			'subtotal_cents' => max( 0, $subtotal ),
		)
	);
	if ( is_wp_error( $result ) ) {
		/* translators: %s: error message. */
		rentadriver_flash( 'error', sprintf( __( 'Rate test failed: %s', 'rentadriver-delivery' ), $result->get_error_message() ) );
	} elseif ( empty( $result['options'] ) ) {
		/* translators: 1: address, 2: reason. */
		rentadriver_flash( 'warning', sprintf( __( 'No rate for %1$s: %2$s', 'rentadriver-delivery' ), $address, $result['reason'] ?? __( 'not available', 'rentadriver-delivery' ) ) );
	} else {
		$parts = array();
		foreach ( $result['options'] as $option ) {
			$parts[] = ( $option['title'] ?? '' ) . ' — ' . rentadriver_money( $option['amount_cents'] ?? 0, $option['currency'] ?? '' );
		}
		/* translators: 1: address, 2: offered rates. */
		rentadriver_flash( 'success', sprintf( __( 'A customer at %1$s would see: %2$s', 'rentadriver-delivery' ), $address, implode( '; ', $parts ) ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=rentadriver-settings&tab=test&rd_notice=test_rate' ), 303 );
	exit;
}
add_action( 'admin_post_rentadriver_test_rate', 'rentadriver_test_rate' );

/**
 * Loading state for actions that wait on RentADriver (Sync store details, Connect store, Renew WooCommerce access):
 * while the request runs the control is disabled, its label changes to its data-rentadriver-busy text and the core
 * spinner shows next to it. Added to WordPress's own "common" admin script on the RentADriver page only, so the plugin
 * ships no script file and no script tag.
 *
 * @param string $hook Current admin page hook.
 */
function rentadriver_busy_script( $hook ) {
	if ( 'toplevel_page_rentadriver-settings' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'common' );
	wp_add_inline_script( 'common', rentadriver_busy_js() );
}
add_action( 'admin_enqueue_scripts', 'rentadriver_busy_script' );

/** The loading-state script (plain JavaScript, core classes only). */
function rentadriver_busy_js() {
	return <<<'JS'
(function () {
	function busy(el) {
		if (el.getAttribute('aria-disabled') === 'true') { return; }
		el.setAttribute('data-rentadriver-label', el.textContent);
		el.textContent = el.getAttribute('data-rentadriver-busy');
		el.setAttribute('aria-disabled', 'true');
		el.classList.add('disabled');
		var spin = document.createElement('span');
		spin.className = 'spinner is-active rentadriver-busy-spinner';
		spin.style.float = 'none';
		el.insertAdjacentElement('afterend', spin);
		if (el.tagName === 'BUTTON') { setTimeout(function () { el.disabled = true; }, 0); }
	}
	document.addEventListener('submit', function (e) {
		var el = e.target.querySelector('[data-rentadriver-busy]');
		if (el && !e.defaultPrevented) { busy(el); }
	});
	document.addEventListener('click', function (e) {
		var el = e.target.closest && e.target.closest('a[data-rentadriver-busy]');
		if (!el) { return; }
		if (el.getAttribute('aria-disabled') === 'true') { e.preventDefault(); return; }
		if (!e.defaultPrevented && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) { busy(el); }
	});
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) { return; }
		document.querySelectorAll('[data-rentadriver-label]').forEach(function (el) {
			el.textContent = el.getAttribute('data-rentadriver-label');
			el.removeAttribute('aria-disabled');
			el.classList.remove('disabled');
			el.disabled = false;
		});
		document.querySelectorAll('.rentadriver-busy-spinner').forEach(function (s) { s.remove(); });
	});
}());
JS;
}
