<?php
/**
 * Live checkout rates and merchant settings.
 *
 * @package RentADriver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce shipping method backed by the RentADriver API. */
class RentADriver_Shipping_Method extends WC_Shipping_Method {
	/**
	 * Initialize a shipping-zone instance.
	 *
	 * @param int $instance_id Shipping-zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'rentadriver_sameday';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Same-day by RentADriver', 'rentadriver-delivery' );
		$this->method_description = __( 'Live same-day delivery rate from RentADriver for addresses inside your radius. Connect your store from the RentADriver menu in WordPress.', 'rentadriver-delivery' );
		// 'settings' is what puts the method's own page (Connect button, Store ID, Rate token) in the
		// WooCommerce > Settings > Shipping section list. Without it WC_Shipping_Method::has_settings()
		// is false for instance_id 0, the section is never listed and its URL renders an empty page —
		// leaving the merchant no way to connect the store from wp-admin at all.
		$this->supports = array( 'settings', 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}
	/**
	 * Register fields and the WooCommerce save hook.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();
		$this->title   = $this->get_option( 'title', __( 'Same-day by RentADriver', 'rentadriver-delivery' ) );
		$this->enabled = 'yes';
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}
	/**
	 * The settings below are NOT stored in WordPress. They live on the RentADriver store record and are the same
	 * ones the hosted app at rentadriver.ai/woocommerce edits, read and written here through
	 * /v1/woocommerce/plugin/<store id>/<rate token>/settings. Keeping a second copy in wp_options would let the
	 * two screens disagree, and the rate quoted at checkout obeys the server copy — so wp-admin would be lying.
	 * Only the store id and rate token are WordPress options.
	 */
	const REMOTE_FIELDS  = array(
		'rd_enabled'               => array( 'enabled', 'bool' ),
		'rd_rate_label'            => array( 'rate_label', 'str' ),
		'rd_radius_km'             => array( 'radius_km', 'float' ),
		'rd_rate_description'      => array( 'rate_description', 'str' ),
		'rd_cutoff_time'           => array( 'cutoff_time', 'time' ),
		'rd_prep_minutes'          => array( 'prep_minutes', 'int' ),
		'rd_max_size_class'        => array( 'max_size_class', 'str' ),
		'rd_next_day_after_cutoff' => array( 'next_day_after_cutoff', 'bool' ),
		'rd_offer_next_day'        => array( 'offer_next_day', 'bool' ),
		'rd_next_day_label'        => array( 'next_day_label', 'str' ),
		'rd_next_day_hour'         => array( 'next_day_hour', 'int' ),
		'rd_min_subtotal'          => array( 'min_subtotal_cents', 'money' ),
		'rd_auto_book'             => array( 'auto_book', 'str' ),
		'rd_pickup_address'        => array( 'pickup_address', 'str' ),
		'rd_pickup_contact_name'   => array( 'pickup_contact_name', 'str' ),
		'rd_pickup_contact_phone'  => array( 'pickup_contact_phone', 'str' ),
		'rd_driver_instructions'   => array( 'driver_instructions', 'str' ),
		'rd_proof_required'        => array( 'proof_required', 'list' ),
		'rd_fulfill_on'            => array( 'fulfill_on', 'str' ),
		'rd_notify_customer'       => array( 'notify_customer', 'bool' ),
		'rd_complete_on_delivered' => array( 'complete_order_on_delivered', 'bool' ),
		'rd_merchant_alerts'       => array( 'merchant_alerts', 'bool' ),
	);
	const REMOTE_PRICING = array(
		'rd_pricing_mode' => array( 'mode', 'str' ),
		'rd_adjust_pct'   => array( 'adjust_pct', 'float' ),
		'rd_flat'         => array( 'flat_cents', 'money' ),
		'rd_free_over'    => array( 'free_over_cents', 'money_null' ),
	);
	/**
	 * Cached GET of the remote settings for this request.
	 *
	 * @var array|WP_Error|null
	 */
	private $remote = null;

	/**
	 * Why the last settings save did not reach RentADriver, or an empty string.
	 *
	 * @var string
	 */
	private $save_error = '';

	/**
	 * Load the live server settings into the form, for the native RentADriver settings page.
	 *
	 * @return array|WP_Error|null Remote response, connection error, or null when disconnected.
	 */
	public function load_live_settings() {
		$remote = $this->fetch_remote( true );
		if ( is_array( $remote ) && isset( $remote['settings'] ) ) {
			$this->hydrate( $remote['settings'] );
		}
		return $remote;
	}

	/**
	 * Delivery fields for the native settings page; the store ID and rate token are managed by Connect.
	 *
	 * @return array WooCommerce settings API field definitions.
	 */
	public function delivery_form_fields() {
		return array_diff_key( $this->get_form_fields(), array_flip( array( 'connection', 'store_id', 'rate_token' ) ) );
	}

	/**
	 * The store address from WooCommerce → Settings → General, as one line.
	 *
	 * @return string Address, or an empty string when WooCommerce has none.
	 */
	public static function store_address() {
		$location = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) );
		$country  = $location[0] ?? '';
		$state    = $location[1] ?? '';
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states  = WC()->countries->get_states( $country );
			$state   = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
			$country = WC()->countries->countries[ $country ] ?? $country;
		}
		$parts = array( get_option( 'woocommerce_store_address', '' ), get_option( 'woocommerce_store_address_2', '' ), get_option( 'woocommerce_store_city', '' ), $state, get_option( 'woocommerce_store_postcode', '' ), $country );
		return implode( ', ', array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) ) );
	}

	/**
	 * A row of checkboxes (WooCommerce calls generate_<type>_html for custom field types).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string Table row HTML.
	 */
	public function generate_rd_checklist_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$chosen    = (array) $this->get_option( $key, $data['default'] ?? array() );
		$boxes     = array();
		foreach ( (array) ( $data['options'] ?? array() ) as $value => $label ) {
			$id      = $field_key . '_' . $value;
			$boxes[] = '<label for="' . esc_attr( $id ) . '"><input type="checkbox" name="' . esc_attr( $field_key ) . '[]" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . checked( in_array( (string) $value, array_map( 'strval', $chosen ), true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		return '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $data['title'] ?? '' ) . '</th><td class="forminp"><fieldset id="' . esc_attr( $field_key ) . '"><legend class="screen-reader-text"><span>' . esc_html( $data['title'] ?? '' ) . '</span></legend>'
			. implode( ' &nbsp; ', $boxes ) . $this->get_description_html( $data ) . '</fieldset></td></tr>';
	}

	/**
	 * Keep only known choices from a checkbox row.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string[] Chosen option keys.
	 */
	public function validate_rd_checklist_field( $key, $value ) {
		$fields  = $this->get_form_fields();
		$allowed = array_keys( (array) ( $fields[ $key ]['options'] ?? array() ) );
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), $allowed ) );
	}

	/**
	 * Save one Settings section. Every other setting is sent exactly as RentADriver has it now, so a section form
	 * can never overwrite fields it does not show with an old local copy.
	 *
	 * @param string[] $keys Field keys shown in the submitted section.
	 */
	public function save_section( $keys ) {
		$remote = $this->load_live_settings();
		if ( ! is_array( $remote ) ) {
			$this->save_error = is_wp_error( $remote ) ? $remote->get_error_message() : __( 'Connect the store first.', 'rentadriver-delivery' );
			return;
		}
		// The connection is managed by Connect; never let a stale local copy write it back.
		unset( $this->settings['store_id'], $this->settings['rate_token'] );
		update_option( $this->get_option_key(), $this->settings, 'yes' );
		$all               = $this->form_fields;
		$this->form_fields = array_intersect_key( $all, array_flip( $keys ) );
		$this->process_admin_options();
		$this->form_fields = $all;
	}

	/**
	 * Why the last save failed, for pages outside WooCommerce settings.
	 *
	 * @return string Error message, or an empty string after a successful save.
	 */
	public function last_save_error() {
		return $this->save_error;
	}

	/**
	 * Record a save failure and surface it on the WooCommerce settings screen when that screen is active.
	 *
	 * @param string $message Error message.
	 */
	private function fail_save( $message ) {
		$this->save_error = $message;
		if ( class_exists( 'WC_Admin_Settings' ) && did_action( 'woocommerce_settings_save_shipping' ) ) {
			WC_Admin_Settings::add_error( $message );
		}
	}

	/**
	 * Read the connection credentials.
	 *
	 * @return array Store ID and rate token.
	 */
	private function credentials() {
		return array( get_option( 'rentadriver_store_id', '' ), get_option( 'rentadriver_rate_token', '' ) );
	}
	/**
	 * Build the authenticated settings endpoint.
	 *
	 * @return string Settings URL, or an empty string when disconnected.
	 */
	private function api_endpoint() {
		list($id, $token) = $this->credentials();
		if ( ! $id || ! $token ) {
			return '';
		}
		return RENTADRIVER_API . '/v1/woocommerce/plugin/' . rawurlencode( $id ) . '/' . rawurlencode( $token ) . '/settings';
	}
	/**
	 * GET the live settings; returns the decoded body or a WP_Error/null when unreachable or not connected.
	 *
	 * @param bool $force Bypass the request cache.
	 * @return array|WP_Error|null Remote settings or connection error.
	 */
	private function fetch_remote( $force = false ) {
		if ( null !== $this->remote && ! $force ) {
			return $this->remote;
		}
		$url = $this->api_endpoint();
		if ( ! $url ) {
			$this->remote = null;
			return $this->remote;
		}
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$this->remote = $res;
			return $this->remote;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! is_array( $body ) ) {
			$this->remote = new WP_Error( 'rd_http', isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'RentADriver did not accept the store id / rate token. Press Connect again.', 'rentadriver-delivery' ) );
			return $this->remote;
		}
		$this->remote = $body;
		return $this->remote;
	}
	/**
	 * Format a minor-unit amount for the settings form.
	 *
	 * @param mixed $cents Minor-unit amount.
	 * @return string Display amount.
	 */
	private static function cents_to_major( $cents ) {
		return null === $cents || '' === $cents ? '' : rtrim( rtrim( number_format( (float) $cents / 100, 2, '.', '' ), '0' ), '.' ); }
	/**
	 * Convert a settings amount to minor units.
	 *
	 * @param mixed $major Display amount.
	 * @return int Minor-unit amount.
	 */
	private static function major_to_cents( $major ) {
		return (int) round( (float) $major * 100 ); }

	/**
	 * Put the live server values into $this->settings so the form renders the truth, not a local copy.
	 *
	 * @param array $settings Remote settings.
	 */
	private function hydrate( $settings ) {
		if ( ! is_array( $settings ) ) {
			return;
		}
		foreach ( self::REMOTE_FIELDS as $key => $map ) {
			list($remote, $type) = $map;
			if ( ! array_key_exists( $remote, $settings ) ) {
				continue;
			}
			$this->settings[ $key ] = $this->to_form( $settings[ $remote ], $type );
		}
		$pricing = isset( $settings['pricing'] ) && is_array( $settings['pricing'] ) ? $settings['pricing'] : array();
		foreach ( self::REMOTE_PRICING as $key => $map ) {
			list($remote, $type) = $map;
			if ( ! array_key_exists( $remote, $pricing ) ) {
				continue;
			}
			$this->settings[ $key ] = $this->to_form( $pricing[ $remote ], $type );
		}
	}
	/**
	 * Convert a remote value to its WooCommerce field representation.
	 *
	 * @param mixed  $value Remote value.
	 * @param string $type Field conversion type.
	 * @return mixed Form value.
	 */
	private function to_form( $value, $type ) {
		if ( 'bool' === $type ) {
			return $value ? 'yes' : 'no';
		}
		if ( 'money' === $type || 'money_null' === $type ) {
			return self::cents_to_major( $value );
		}
		if ( 'time' === $type ) {
			return null === $value ? '' : (string) $value;
		}
		if ( 'list' === $type ) {
			return is_array( $value ) ? $value : array();
		}
		return null === $value ? '' : (string) $value;
	}
	/** Build the PATCH body from what the merchant just submitted. */
	private function build_patch() {
		$patch   = array();
		$pricing = array();
		foreach ( self::REMOTE_FIELDS as $key => $map ) {
			list($remote, $type) = $map;
			$v                   = $this->get_option( $key, null );
			if ( null === $v ) {
				continue;
			}
			if ( 'bool' === $type ) {
				$patch[ $remote ] = ( 'yes' === $v || true === $v );
				continue; }
			if ( 'int' === $type ) {
				$patch[ $remote ] = (int) $v;
				continue; }
			if ( 'float' === $type ) {
				$patch[ $remote ] = (float) $v;
				continue; }
			if ( 'money' === $type ) {
				$patch[ $remote ] = self::major_to_cents( $v );
				continue; }
			if ( 'time' === $type ) {
				$patch[ $remote ] = trim( $v ) === '' ? null : trim( $v );
				continue; }
			// proof_required must keep at least one method server-side; sending [] would be rejected outright.
			if ( 'list' === $type ) {
				$v = array_values( array_filter( (array) $v ) );
				if ( $v ) {
					$patch[ $remote ] = $v;
				} continue; }
			$patch[ $remote ] = (string) $v;
		}
		foreach ( self::REMOTE_PRICING as $key => $map ) {
			list($remote, $type) = $map;
			$v                   = $this->get_option( $key, null );
			if ( null === $v ) {
				continue;
			}
			if ( 'money_null' === $type ) {
				$pricing[ $remote ] = trim( (string) $v ) === '' ? null : self::major_to_cents( $v );
				continue; }
			if ( 'money' === $type ) {
				$pricing[ $remote ] = self::major_to_cents( $v );
				continue; }
			if ( 'float' === $type ) {
				$pricing[ $remote ] = (float) $v;
				continue; }
			$pricing[ $remote ] = (string) $v;
		}
		if ( $pricing ) {
			$patch['pricing'] = $pricing;
		}
		return $patch;
	}

	/**
	 * Define checkout, booking and connection settings.
	 */
	public function init_form_fields() {
		$store                      = self::store_address();
		$money                      = get_woocommerce_currency();
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Method title', 'rentadriver-delivery' ),
				'type'        => 'text',
				'default'     => __( 'Same-day by RentADriver', 'rentadriver-delivery' ),
				'description' => __( 'Shown at checkout; the live label from RentADriver replaces it when a rate is returned.', 'rentadriver-delivery' ),
			),
		);
		$this->form_fields          = array(
			'checkout_rate'            => array(
				'type'        => 'title',
				'title'       => __( 'Checkout rate', 'rentadriver-delivery' ),
				'description' => __( 'Where you deliver and what the rate looks like at checkout.', 'rentadriver-delivery' ),
			),
			'rd_enabled'               => array(
				'title'   => __( 'Checkout rate', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show the rate at checkout', 'rentadriver-delivery' ),
				'default' => 'yes',
			),
			'rd_rate_label'            => array(
				'title'   => __( 'Rate name', 'rentadriver-delivery' ),
				'type'    => 'text',
				'default' => 'Same-day by RentADriver',
			),
			'rd_rate_description'      => array(
				'title' => __( 'Description shown under the rate', 'rentadriver-delivery' ),
				'type'  => 'text',
				'css'   => 'width:26em',
			),
			'rd_radius_km'             => array(
				'title'             => __( 'Delivery radius (km)', 'rentadriver-delivery' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0.5',
					'max'  => '60',
					'step' => '0.5',
				),
				'default'           => '12',
			),
			'rd_cutoff_time'           => array(
				'title'       => __( 'Cut-off (store time)', 'rentadriver-delivery' ),
				'type'        => 'text',
				'placeholder' => '16:00',
				'description' => __( '24-hour HH:MM. Leave empty for no cut-off.', 'rentadriver-delivery' ),
			),
			'rd_prep_minutes'          => array(
				'title'             => __( 'Prep time (min)', 'rentadriver-delivery' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => '0',
					'max' => '480',
				),
				'default'           => '30',
			),
			'rd_max_size_class'        => array(
				'title'   => __( 'Max item size', 'rentadriver-delivery' ),
				'type'    => 'select',
				'default' => 'L',
				'options' => array(
					'S'  => __( 'S · small parcel', 'rentadriver-delivery' ),
					'M'  => __( 'M · ≤10 kg', 'rentadriver-delivery' ),
					'L'  => __( 'L · ≤25 kg', 'rentadriver-delivery' ),
					'XL' => __( 'XL · bulky', 'rentadriver-delivery' ),
				),
			),
			'rd_next_day_after_cutoff' => array(
				'title'   => __( 'After the cut-off', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer next-day instead of hiding the rate', 'rentadriver-delivery' ),
				'default' => 'yes',
			),
			'rd_offer_next_day'        => array(
				'title'   => __( 'Tomorrow-morning rate', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Also offer a cheaper scheduled rate', 'rentadriver-delivery' ),
				'default' => 'yes',
			),
			'rd_next_day_label'        => array(
				'title'   => __( 'Tomorrow-morning rate name', 'rentadriver-delivery' ),
				'type'    => 'text',
				'default' => 'Tomorrow morning by RentADriver',
			),
			'rd_next_day_hour'         => array(
				'title'             => __( 'From (hour)', 'rentadriver-delivery' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => '6',
					'max' => '14',
				),
				'default'           => '9',
			),
			/* translators: %s: store currency code. */
			'rd_min_subtotal'          => array(
				/* translators: %s: store currency code. */
				'title'       => sprintf( __( 'Minimum basket (%s)', 'rentadriver-delivery' ), $money ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( '0 = no minimum', 'rentadriver-delivery' ),
			),

			'customer_pays'            => array(
				'type'        => 'title',
				'title'       => __( 'What the customer pays', 'rentadriver-delivery' ),
				'description' => __( 'You always pay RentADriver the live quote; this only changes what the shopper is charged.', 'rentadriver-delivery' ),
			),
			'rd_pricing_mode'          => array(
				'title'   => __( 'Rule', 'rentadriver-delivery' ),
				'type'    => 'select',
				'default' => 'pass_through',
				'options' => array(
					'pass_through' => __( 'Pass the live quote through', 'rentadriver-delivery' ),
					'flat'         => __( 'Charge a flat amount', 'rentadriver-delivery' ),
					'free'         => __( 'Free delivery', 'rentadriver-delivery' ),
				),
			),
			'rd_adjust_pct'            => array(
				'title'             => __( 'Adjust the quote by %', 'rentadriver-delivery' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '-100',
					'max'  => '300',
					'step' => '1',
				),
				'default'           => '0',
				'description'       => __( '−50 halves the price for the customer; +20 adds a handling margin', 'rentadriver-delivery' ),
			),
			/* translators: %s: store currency code. */
			'rd_flat'                  => array(
				/* translators: %s: store currency code. */
				'title'       => sprintf( __( 'Flat amount (%s)', 'rentadriver-delivery' ), $money ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Used when the rule is "Charge a flat amount"', 'rentadriver-delivery' ),
			),
			/* translators: %s: store currency code. */
			'rd_free_over'             => array(
				/* translators: %s: store currency code. */
				'title'       => sprintf( __( 'Free over basket size (%s)', 'rentadriver-delivery' ), $money ),
				'type'        => 'text',
				'description' => __( 'Leave empty to never make it free', 'rentadriver-delivery' ),
			),

			'booking'                  => array(
				'type'  => 'title',
				'title' => __( 'Booking &amp; pickup', 'rentadriver-delivery' ),
			),
			'rd_auto_book'             => array(
				'title'   => __( 'Book automatically', 'rentadriver-delivery' ),
				'type'    => 'select',
				'default' => 'rate_only',
				'options' => array(
					'rate_only' => __( 'When the customer picked the RentADriver option', 'rentadriver-delivery' ),
					'all_paid'  => __( 'Every paid order', 'rentadriver-delivery' ),
					'fulfilled' => __( 'When the order is marked Completed', 'rentadriver-delivery' ),
					'manual'    => __( 'Never — I book from the RentADriver app', 'rentadriver-delivery' ),
				),
			),
			'rd_pickup_address'        => array(
				'title'       => __( 'Pickup address override', 'rentadriver-delivery' ),
				'type'        => 'text',
				'css'         => 'width:26em',
				'placeholder' => $store ? $store : '12 Example St, Suburb, City',
				/* translators: %s: the store address from WooCommerce settings. */
				'description' => $store ? sprintf( __( 'Leave empty to use the store address: %s', 'rentadriver-delivery' ), $store ) : __( 'Leave empty to use the store address from WooCommerce → Settings → General (not set yet).', 'rentadriver-delivery' ),
			),
			'rd_pickup_contact_name'   => array(
				'title' => __( 'Pickup contact', 'rentadriver-delivery' ),
				'type'  => 'text',
			),
			'rd_pickup_contact_phone'  => array(
				'title' => __( 'Pickup phone', 'rentadriver-delivery' ),
				'type'  => 'text',
			),
			'rd_driver_instructions'   => array(
				'title'       => __( 'Instructions for the driver', 'rentadriver-delivery' ),
				'type'        => 'textarea',
				'description' => __( 'Where to collect, parking, who to ask for', 'rentadriver-delivery' ),
			),
			'rd_proof_required'        => array(
				'title'       => __( 'Proof required at drop-off', 'rentadriver-delivery' ),
				'type'        => 'rd_checklist',
				'default'     => array( 'photo', 'recipient_name' ),
				'options'     => array(
					'photo'          => __( 'Photo', 'rentadriver-delivery' ),
					'recipient_name' => __( 'Recipient name', 'rentadriver-delivery' ),
					'signature'      => __( 'Signature', 'rentadriver-delivery' ),
					'otp'            => __( 'One-time code', 'rentadriver-delivery' ),
					'id_check'       => __( 'ID check', 'rentadriver-delivery' ),
				),
				'description' => __( 'At least one is required.', 'rentadriver-delivery' ),
			),
			'rd_fulfill_on'            => array(
				'title'   => __( 'Mark the order shipped', 'rentadriver-delivery' ),
				'type'    => 'select',
				'default' => 'picked_up',
				'options' => array(
					'picked_up' => __( 'When the driver picks it up', 'rentadriver-delivery' ),
					'assigned'  => __( 'As soon as a driver is assigned', 'rentadriver-delivery' ),
				),
			),
			'rd_notify_customer'       => array(
				'title'   => __( 'Customer email', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send the platform&#8217;s shipping email', 'rentadriver-delivery' ),
				'default' => 'yes',
			),
			'rd_complete_on_delivered' => array(
				'title'   => __( 'On delivery', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Complete the order when delivered', 'rentadriver-delivery' ),
				'default' => 'yes',
			),
			'rd_merchant_alerts'       => array(
				'title'   => __( 'Alerts', 'rentadriver-delivery' ),
				'type'    => 'checkbox',
				'label'   => __( 'Email me on needs-funds / no driver / failed hand-off', 'rentadriver-delivery' ),
				'default' => 'yes',
			),

			'connection'               => array(
				'type'  => 'title',
				'title' => __( 'Connection', 'rentadriver-delivery' ),
			),
			'store_id'                 => array(
				'title'       => __( 'Store ID', 'rentadriver-delivery' ),
				'type'        => 'text',
				'description' => __( 'Filled in automatically when you connect.', 'rentadriver-delivery' ),
			),
			'rate_token'               => array(
				'title' => __( 'Rate token', 'rentadriver-delivery' ),
				'type'  => 'password',
			),
		);
	}

	/** Keep any legacy WooCommerce entry point on the native connection screen. */
	public function admin_options() {
		rentadriver_render_admin_page();
	}

	/**
	 * Save settings to the API and invalidate cached checkout rates.
	 *
	 * @return bool Whether the form was processed.
	 */
	public function process_admin_options() {
		$this->save_error = '';
		parent::process_admin_options();
		// Only ever overwrite a credential with a non-empty value. A blank field on this form means "not shown" far
		// more often than "disconnect me", and clearing it silently unlinks the store: the rate at checkout stops,
		// orders stop being booked, and nothing on the page says why. Use Connect/Reconnect to change them.
		foreach ( array(
			'store_id'   => 'rentadriver_store_id',
			'rate_token' => 'rentadriver_rate_token',
		) as $field => $option ) {
			$value = sanitize_text_field( $this->get_option( $field, '' ) );
			if ( '' !== $value ) {
				update_option( $option, $value );
			}
		}
		if ( ! $this->api_endpoint() ) {
			return true;
		}

		$res = wp_remote_request(
			$this->api_endpoint(),
			array(
				'method'  => 'PATCH',
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $this->build_patch() ),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message returned by the API. */
			$this->fail_save( sprintf( __( 'RentADriver could not be reached, so these settings were not saved: %s', 'rentadriver-delivery' ), $res->get_error_message() ) );
			return true; }
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'RentADriver rejected the settings.', 'rentadriver-delivery' );
			/* translators: %s: error message returned by the API. */
			$this->fail_save( sprintf( __( 'RentADriver did not save these settings: %s', 'rentadriver-delivery' ), $msg ) );
			return true;
		}
		// Re-read what the server actually stored (it clamps and merges), so the form shows the truth on reload.
		if ( is_array( $body ) && isset( $body['settings'] ) ) {
			$this->remote = $body;
			$this->hydrate( $body['settings'] );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WooCommerce settings hook.
			update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' ); }
		// calculate_shipping() caches quotes for 2 minutes keyed on the CART, not on these settings, so a changed
		// radius or price rule would keep showing the old rate. Drop our own transients only.
		update_option( 'rentadriver_rate_cache_version', wp_generate_uuid4(), false );
		return true;
	}

	/**
	 * Ask RentADriver for a live rate; silently show nothing when out of radius / after cut-off / unconfigured.
	 *
	 * @param array $package WooCommerce shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$store_id = get_option( 'rentadriver_store_id', '' );
		$token    = get_option( 'rentadriver_rate_token', '' );
		if ( ! $store_id || ! $token || empty( $package['destination']['address'] ) ) {
			return;
		}
		$items    = array();
		$subtotal = 0;
		foreach ( $package['contents'] as $item ) {
			$p = $item['data'];
			if ( ! $p || ! $p->needs_shipping() ) {
				continue;
			}
			$w         = (float) wc_get_weight( (float) $p->get_weight(), 'kg' );
			$items[]   = array(
				'name'              => $p->get_name(),
				'quantity'          => (int) $item['quantity'],
				'weight_kg'         => $w > 0 ? $w : null,
				'price'             => (float) $p->get_price(),
				'requires_shipping' => true,
			);
			$subtotal += (float) $item['line_total'];
		}
		if ( ! $items ) {
			return;
		}
		$body      = array(
			'destination' => array(
				'address_1' => $package['destination']['address'],
				'address_2' => $package['destination']['address_2'] ?? '',
				'city'      => $package['destination']['city'],
				'state'     => $package['destination']['state'],
				'postcode'  => $package['destination']['postcode'],
				'country'   => $package['destination']['country'],
			),
			'items'       => $items,
			'subtotal'    => $subtotal,
			'currency'    => get_woocommerce_currency(),
		);
		$cache_key = 'rentadriver_delivery_rate_' . md5( get_option( 'rentadriver_rate_cache_version', '' ) . wp_json_encode( $body ) );
		$rates     = get_transient( $cache_key );
		if ( false === $rates ) {
			$res = wp_remote_post(
				RENTADRIVER_API . '/v1/woocommerce/rates/' . rawurlencode( $store_id ) . '/' . rawurlencode( $token ),
				array(
					'timeout' => 8,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				return;
			}
			$json  = json_decode( wp_remote_retrieve_body( $res ), true );
			$rates = $json['rates'] ?? array();
			set_transient( $cache_key, $rates, 2 * MINUTE_IN_SECONDS );
		}
		foreach ( $rates as $r ) {
			if ( ! empty( $r['currency'] ) && get_woocommerce_currency() !== $r['currency'] ) {
				continue;
			}
			$this->add_rate(
				array(
					'id'        => $this->get_rate_id( $r['meta']['rd_rate_code'] ?? 'RD_SAMEDAY' ),
					'label'     => ! empty( $r['label'] ) ? $r['label'] : $this->title,
					'cost'      => (float) $r['cost'],
					'package'   => $package,
					'meta_data' => array(
						'rd_rate_code'   => $r['meta']['rd_rate_code'] ?? 'RD_SAMEDAY',
						'rd_description' => $r['description'] ?? '',
					),
				)
			);
		}
	}
}
