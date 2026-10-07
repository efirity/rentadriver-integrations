<?php
/**
 * Plugin Name: RentADriver Delivery
 * Plugin URI:  https://rentadriver.ai/integrations/woocommerce
 * Description: Shows a live "Same-day by RentADriver" shipping rate at checkout for addresses inside your delivery radius. Orders paid with it are booked automatically by the RentADriver connector.
 * Version:     0.5.10
 * Author:      RentADriver
 * Author URI:  https://rentadriver.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rentadriver-delivery
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package RentADriver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/console-access.php';
require_once __DIR__ . '/admin-pages.php';

define( 'RENTADRIVER_API', get_option( 'rentadriver_api_base', 'https://api.rentadriver.ai' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
/** Register the shipping method. */
add_action(
	'woocommerce_shipping_init',
	function () {
		if ( ! class_exists( 'WC_Shipping_Method' ) ) {
			return;
		}

		require_once __DIR__ . '/class-rentadriver-shipping-method.php';
	}
);
add_filter(
	'woocommerce_shipping_methods',
	function ( $methods ) {
		$methods['rentadriver_sameday'] = 'RentADriver_Shipping_Method';
		return $methods;
	}
);

/** Add a dedicated RentADriver entry to the WordPress admin sidebar. */
add_action(
	'admin_menu',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		add_menu_page(
			__( 'RentADriver settings', 'rentadriver-delivery' ),
			__( 'RentADriver', 'rentadriver-delivery' ),
			'manage_woocommerce',
			'rentadriver-settings',
			'rentadriver_render_admin_page',
			'dashicons-location-alt',
			'56.4'
		);
		// Hover submenu with the same sections as the page tabs (the first item reuses the parent slug, as WooCommerce does).
		add_submenu_page( 'rentadriver-settings', __( 'RentADriver settings', 'rentadriver-delivery' ), __( 'Overview', 'rentadriver-delivery' ), 'manage_woocommerce', 'rentadriver-settings', 'rentadriver_render_admin_page' );
		foreach ( rentadriver_admin_tabs() as $tab => $label ) {
			if ( 'overview' !== $tab ) {
				add_submenu_page( 'rentadriver-settings', $label, $label, 'manage_woocommerce', 'admin.php?page=rentadriver-settings&tab=' . $tab );
			}
		}
	}
);

/**
 * Highlight the submenu item that matches the open tab.
 *
 * @param string|null $submenu_file Current submenu file.
 * @return string|null Submenu file to highlight.
 */
function rentadriver_current_submenu( $submenu_file ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only menu highlighting.
	if ( 'rentadriver-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ) {
		return $submenu_file;
	}
	$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$tab = 'connection' === $tab ? 'settings' : $tab;
	return in_array( $tab, array( 'orders', 'settings', 'test' ), true ) ? 'admin.php?page=rentadriver-settings&tab=' . $tab : 'rentadriver-settings';
}
add_filter( 'submenu_file', 'rentadriver_current_submenu' );

/** Keep legacy shipping bookmarks on the single RentADriver settings page. */
function rentadriver_redirect_shipping_settings() {
	// Read-only routing; connection credentials are verified by the existing callback after redirect.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! current_user_can( 'manage_woocommerce' ) ||
		'wc-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ||
		'shipping' !== sanitize_text_field( wp_unslash( $_GET['tab'] ?? '' ) ) ||
		'rentadriver_sameday' !== sanitize_text_field( wp_unslash( $_GET['section'] ?? '' ) ) ) {
		return;
	}
	$url = admin_url( 'admin.php?page=rentadriver-settings' );
	// Preserve only the signed install callback, never arbitrary redirect destinations.
	foreach ( array( 'rd_connected', 'rd_handoff', 'rd_nonce' ) as $key ) {
		if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
			$url = add_query_arg( $key, sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), $url );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( $url, 303 );
	exit;
}
add_action( 'admin_init', 'rentadriver_redirect_shipping_settings', 1 );

/** Description under the rate at checkout (classic checkout). */
add_action(
	'woocommerce_after_shipping_rate',
	function ( $rate ) {
		if ( strpos( $rate->get_id(), 'rentadriver_sameday' ) !== 0 ) {
			return;
		}
		$meta = $rate->get_meta_data();
		if ( ! empty( $meta['rd_description'] ) ) {
			echo '<p class="rd-rate-desc" style="margin:.25em 0 0;font-size:.85em;color:#666">' . esc_html( $meta['rd_description'] ) . '</p>';
		}
	},
	10,
	1
);

/** Exchange a one-use installation handoff after a nonce-protected administrator round trip. */
add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'manage_options' ) || 'rentadriver-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) || empty( $_GET['rd_connected'] ) || empty( $_GET['rd_handoff'] ) ) {
			return;
		}
		// Only accept credentials arriving on a round trip this site started (nonce minted on the settings page).
		if ( empty( $_GET['rd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['rd_nonce'] ) ), 'rd_connect' ) ) {
			$GLOBALS['rentadriver_connection_error'] = __( 'That RentADriver connection link has expired. Press Connect again from the plugin settings.', 'rentadriver-delivery' );
			return;
		}
		$result = wp_remote_post(
			RENTADRIVER_API . '/v1/woocommerce/plugin/install-exchange',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'handoff' => sanitize_text_field( wp_unslash( $_GET['rd_handoff'] ) ) ) ),
			)
		);
		if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			wp_die( esc_html__( 'The connection expired. Reopen RentADriver and connect again.', 'rentadriver-delivery' ) );
		}
		$connection = json_decode( wp_remote_retrieve_body( $result ), true );
		if ( empty( $connection['store_id'] ) || empty( $connection['rate_token'] ) ) {
			wp_die( esc_html__( 'Invalid connection response. Try connecting again.', 'rentadriver-delivery' ) );
		}
		$renewed = get_option( 'rentadriver_store_id', '' ) === $connection['store_id'];
		update_option( 'rentadriver_store_id', sanitize_text_field( $connection['store_id'] ) );
		update_option( 'rentadriver_rate_token', sanitize_text_field( $connection['rate_token'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=rentadriver-settings&rd_notice=' . ( $renewed ? 'renewed' : 'connected' ) ), 303 );
		exit;
	}
);

/** Product weight (kg) + virtual flag on line items, so the connector sizes the parcel without extra API calls. */
add_action(
	'woocommerce_checkout_create_order_line_item',
	function ( $item, $cart_item_key, $values ) {
		$p = $values['data'] ?? null;
		if ( ! $p ) {
			return;
		}
		$w = (float) wc_get_weight( (float) $p->get_weight(), 'kg' );
		if ( $w > 0 ) {
			$item->add_meta_data( '_rd_weight_kg', round( $w, 3 ), true );
		}
		if ( ! $p->needs_shipping() ) {
			$item->add_meta_data( '_rd_virtual', 'yes', true );
		}
	},
	10,
	3
);

/** Tracking box on the order edit screen. */
add_action(
	'add_meta_boxes',
	function () {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'rentadriver_delivery',
				__( 'RentADriver delivery', 'rentadriver-delivery' ),
				function ( $post_or_order ) {
					$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
					if ( ! $order ) {
						return;
					}
					$d = $order->get_meta( '_rentadriver_delivery' );
					if ( ! $d ) {
						echo '<p>' . esc_html__( 'No delivery booked yet. Book it from the RentADriver app.', 'rentadriver-delivery' ) . '</p>';
						return; }
					echo '<p><strong>' . esc_html( $d['short_code'] ?? '' ) . '</strong> · ' . esc_html( str_replace( '_', ' ', $d['status'] ?? '' ) ) . '</p>';
					if ( ! empty( $d['tracking_url'] ) ) {
						echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $d['tracking_url'] ) . '">' . esc_html__( 'Open tracking', 'rentadriver-delivery' ) . '</a></p>';
					}
				},
				$screen,
				'side'
			);
		}
	}
);

/**
 * Plugins-screen row, the way WooCommerce presents its own: "Settings" beside Deactivate, and docs/support in the
 * meta line. Without these the only way to reach the settings is to remember the Shipping tab.
 * ("View details" is deliberately absent — that modal is served by the wordpress.org plugin API, and this plugin is
 * distributed from the repo. It appears by itself if the plugin is ever listed there.)
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=rentadriver-settings' ) ) . '">' . esc_html__( 'Settings', 'rentadriver-delivery' ) . '</a>' );
		return $links;
	}
);
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}
		return array_merge(
			$links,
			array(
				'<a href="https://rentadriver.ai/docs" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'rentadriver-delivery' ) . '</a>',
				'<a href="https://rentadriver.ai/docs/reference" target="_blank" rel="noopener">' . esc_html__( 'API docs', 'rentadriver-delivery' ) . '</a>',
				'<a href="https://rentadriver.ai/integrations/woocommerce" target="_blank" rel="noopener">' . esc_html__( 'How it works', 'rentadriver-delivery' ) . '</a>',
				'<a href="mailto:support@rentadriver.ai">' . esc_html__( 'Support', 'rentadriver-delivery' ) . '</a>',
			)
		);
	},
	10,
	2
);
