<?php
/**
 * Administrator-approved, one-use console handoff.
 *
 * @package RentADriver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build the signed admin-post URL used to open the linked console account. */
function rentadriver_console_url() {
	return wp_nonce_url( admin_url( 'admin-post.php?action=rentadriver_console' ), 'rentadriver_console' );
}

/** Create a proof only after checking the current WordPress administrator and nonce. */
function rentadriver_open_console() {
	rentadriver_handoff( false );
}

/** Open the shared merchant app using an administrator-approved session. */
function rentadriver_open_app() {
	rentadriver_handoff( true );
}
add_action( 'admin_post_rentadriver_app', 'rentadriver_open_app' );

/**
 * Approve the linked account for a console or hosted app session.
 *
 * @param bool $app Whether to open the hosted merchant app.
 */
function rentadriver_handoff( $app ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'A WordPress administrator must open the RentADriver console.', 'rentadriver-delivery' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $app ? 'rentadriver_app' : 'rentadriver_console' );
	$store_id = get_option( 'rentadriver_store_id', '' );
	$token    = get_option( 'rentadriver_rate_token', '' );
	$state    = wp_remote_get( RENTADRIVER_API . '/v1/woocommerce/plugin/' . rawurlencode( $store_id ) . '/' . rawurlencode( $token ) . '/settings', array( 'timeout' => 12 ) );
	if ( is_wp_error( $state ) || 200 !== wp_remote_retrieve_response_code( $state ) ) {
		wp_die( esc_html__( 'RentADriver could not verify this store. On the RentADriver page, use Troubleshooting → Renew WooCommerce access, then try again.', 'rentadriver-delivery' ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $state ), true );
	if ( ! $app && empty( $body['account']['id'] ) ) {
		wp_die( esc_html__( 'Link a RentADriver account first.', 'rentadriver-delivery' ) );
	}
	$proof  = bin2hex( random_bytes( 32 ) );
	$option = 'rentadriver_delivery_console_' . hash( 'sha256', $proof );
	add_option(
		$option,
		array(
			'store_id'   => $store_id,
			'account_id' => isset( $body['account']['id'] ) ? $body['account']['id'] : null,
			'sandbox'    => ! empty( $body['sandbox'] ),
			'expires_at' => time() + 60,
		),
		'',
		false
	);
	// The API calls back using the stored WooCommerce REST credentials. A rate token alone cannot approve console access.
	$result = wp_remote_post(
		RENTADRIVER_API . ( $app ? '/v1/woocommerce/plugin/app-link' : '/v1/woocommerce/plugin/console-link' ),
		array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'store_id' => $store_id,
					'proof'    => $proof,
				)
			),
		)
	);
	delete_option( $option );
	if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
		wp_die( esc_html__( 'RentADriver could not confirm this WordPress approval. On the RentADriver page, use Troubleshooting → Renew WooCommerce access as an administrator, then try again.', 'rentadriver-delivery' ) );
	}
	$link = json_decode( wp_remote_retrieve_body( $result ), true );
	$url  = isset( $link['url'] ) ? $link['url'] : '';

	// Fixed destination prevents a configured API endpoint from turning this action into an open redirect.
	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'rentadriver.ai' !== wp_parse_url( $url, PHP_URL_HOST ) || ( $app ? '/woocommerce' : '/commerce-console' ) !== wp_parse_url( $url, PHP_URL_PATH ) ) {
		wp_die( esc_html__( 'Invalid console sign-in response.', 'rentadriver-delivery' ) );
	}
	nocache_headers();
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed HTTPS host and path validated above.
	wp_redirect( $url, 303 );
	exit;
}
add_action( 'admin_post_rentadriver_console', 'rentadriver_open_console' );

/**
 * Atomically consume the local approval, authenticated by WooCommerce REST API keys.
 *
 * @param WP_REST_Request $request Proof request.
 * @return array|WP_Error Verified account binding, or a rejected proof.
 */
function rentadriver_consume_console_proof( $request ) {
	$proof = $request->get_param( 'proof' );
	if ( ! is_string( $proof ) || ! preg_match( '/^[a-f0-9]{64}$/', $proof ) ) {
		return new WP_Error( 'rd_console_proof', 'Invalid console approval.', array( 'status' => 403 ) );
	}
	$option = 'rentadriver_delivery_console_' . hash( 'sha256', $proof );
	$value  = get_option( $option );
	// delete_option reports affected rows: only one concurrent request can win.
	if ( ! is_array( $value ) || ! delete_option( $option ) || time() >= $value['expires_at'] || get_option( 'rentadriver_store_id', '' ) !== $value['store_id'] ) {
		return new WP_Error( 'rd_console_proof', 'Console approval expired. Try again from WordPress.', array( 'status' => 403 ) );
	}
	return $value;
}
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wc/v3',
			'/rentadriver/console-proof',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
				'callback'            => 'rentadriver_consume_console_proof',
			)
		);
	}
);
