<?php
/**
 * eShip
 *
 * Provides eShip shipping to WooCommerce.
 *
 * @class 		WC_eShip_Method
 * @package		WooCommerce
 * @category	Shipping Module
 * @author		HElton Renda
 *
 **/
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_eShip_Method extends WC_Shipping_Method
{
	/**
	* Constructor
	*/
	public function __construct() {
		$this->id 			= 'eship';
		$this->method_title = __( 'eShip', 'woocommerce-eship' );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled				= $this->get_option( 'enabled' );
		$this->title 				= $this->get_option( 'title' );
		$this->store_address 		= $this->get_option( 'store_address' );
		$this->store_address_fields = $this->get_option( 'store_address_fields' );
		$this->username				= $this->get_option( 'username' );
		$this->password				= $this->get_option( 'password' );
		$this->sanbox_mode 			= $this->get_option( 'sanbox_mode' );
		$this->rate_display			= $this->get_option( 'rate_display' );
		$this->insurance 			= $this->get_option( 'insurance' );
		$this->markup_percentage	= $this->get_option( 'markup_percentage' );
		$this->enable_branding		= $this->get_option( 'enable_branding' );

		if ( empty( $this->store_address_fields ) )
			$this->store_address_fields = '{"street_number":"","address1":"","suburb":"","city":"","zip":""}';

		$this->currency 			= get_option( 'woocommerce_currency' );
		$this->country 				= WC()->countries->get_base_country();

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		}

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_shipping_methods', array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_other_shipping_when_is_available' ) );

		// Clear transients?
		//global $wpdb;
		//$wpdb->query("
		//	DELETE FROM `wp_options`
		//	WHERE option_name LIKE '_transient_wc_ship%'
		//");
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled' => array(
				'title' 		=> __( 'Enable eShip', 'woocommerce' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Enable this shipping method?', 'woocommerce' ),
				'default' 		=> 'no'
			),
			'title' => array(
				'title' 		=> __( 'Title', 'woocommerce' ),
				'type' 			=> 'text',
				'description' 	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'		=> __( 'eShip', 'woocommerce' ),
				'desc_tip'		=> true,
			),

			'store_address' => array(
				'title' 		=> __( 'Store Address', 'woocommerce' ),
				'type' 			=> 'address',
				'description' 	=> __( 'The address of your store, where packages will be picked up.', 'woocommerce' ),
				'placeholder' 	=> 'eg. 1 main road, 8001, Cape Town',
				'desc_tip'		=> true,
			),

			'store_address_fields' => array(
				'title' 		=> '',
				'type' 			=> 'address_hidden',
				'description' 	=> '',
				'placeholder' 	=> '',
			),

			'username' => array(
				'title' 		=> __( 'Username', 'woocommerce' ),
				'type' 			=> 'text',
				'description' 	=> __( 'Your eShip username.', 'woocommerce' ),
				'desc_tip'		=> true,
			),

			'password' => array(
				'title' 		=> __( 'Password', 'woocommerce' ),
				'type' 			=> 'text',
				'description' 	=> __( 'Your eShip password.', 'woocommerce' ),
				'desc_tip'		=> true,
			),

			'sanbox_mode' => array(
				//'title' 		=> __( 'Sandbox Mode', 'woocommerce' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Enable sandbox mode?', 'woocommerce' ),
				'default' 		=> 'yes',
				'description' 	=> __( 'Uncheck this box when testing is done.', 'woocommerce' ),
				'desc_tip'		=> true,
			),

			'rate_display' => array(
				'title' 		=> __( 'Rate Display', 'woocommerce' ),
				'type' 			=> 'select',
				'default' 		=> 'cheapest',
				'description' 	=> __( 'How the customer will see returned quotes.', 'woocommerce' ),
				'desc_tip'		=> true,
				'options'		=> array(
					'cheapest'	=> 'Show the cheapest rate only, anonymously',
					'both'		=> 'Show the cheapest standard and express rates.',
				)
			),

			'insurance' => array(
				'title' 		=> __( 'Insurance', 'woocommerce' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Add Insurance to the shipping cost?', 'woocommerce' ),
				'default' 		=> 'no',
				'description' 	=> __( 'Automatically add courier Insurance to the shipping cost.', 'woocommerce' ),
				'desc_tip'		=> true,
			),

			'markup_percentage' => array(
				'title' 		=> __( 'Markup Percentage', 'woocommerce' ),
				'type' 			=> 'decimal',
				'description' 	=> __( 'A percentage to add to the total for shipping', 'woocommerce' ),
				'desc_tip'		=> true,
				'placeholder' 	=> 0
			),

			'enable_branding' => array(
				'title' 		=> __( 'Enable Branding', 'woocommerce' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Show eShip branding in emails?', 'woocommerce' ),
				'default' 		=> 'yes'
			),
		);

		$this->form_fields = apply_filters( 'woocommerce_eship_fields', $fields );
	}

	/**
	 * Admin Panel Options.
	 * - Options for bits like 'title' and availability on a country-by-country basis.
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		if ( true === ( $valid = $this->is_valid_for_use() ) ) {
			parent::admin_options();
		} else {
?>
<div class="inline error">
	<p>
		<strong><?php _e( 'Shipping Disabled', 'woocommerce' ); ?></strong>: <?php echo $valid; ?>
	</p>
</div>
<?php
			   }
	}

	function process_admin_options() {
		parent::process_admin_options();
	}

	/**
	 * Check if this shipping method is enabled and available in the user's country.
	 *
	 * @return bool
	 */
	public function is_valid_for_use() {
		// Can only ship from South Africa
		if ( 'ZA' != $this->country ) {
			return sprintf( __(
				'WooCommerce <a href="%s">base location</a> is not South Africa - eShip only supports South Africa',
				'woocommerce-eship'
			), admin_url( 'admin.php?page=wc-settings&tab=general' ) );
		}
		// Only supports ZAR as shop currency
		if ( 'ZAR' != $this->currency ) {
			return sprintf( __(
				'WooCommerce <a href="%s">currency</a> is not South African Rand (ZAR) - eShip only supports ZAR.',
				'woocommerce-eship'
			), admin_url( 'admin.php?page=wc-settings&tab=general' ) );
		}

		return true;
	}

	/**
	 * is_available function.
	 * @param array $package
	 * @return bool
	 */
	public function is_available( $package = array() ) {
		// Obviously you cant use this if its not enabled
		if ( 'no' == $this->enabled ) {
			return false;
		}

		// Can only ship to South African customers
		if ( WC()->customer->get_shipping_country() !== 'ZA' ) {
			return false;
		}

		if ( empty( $this->store_address ) ) {
			return false;
		}

		//TODO: check API handshake

		// Enabled logic
		return true === $this->is_valid_for_use() ? true : false;
	}

	/**
	 * calculate_shipping function.
	 * @return array
	 */
	public function calculate_shipping( $package ) {

		if ( $package['destination']['country'] === 'ZA' ) {

			if ( ! isset( $package['destination']['postcode'] ) || empty( $package['destination']['postcode'] ) )
				return;

			if ( ! is_checkout() ) {

				$quotes = $this->get_quotes( $package, array(
					'zip' => $package['destination']['postcode']
				) );

			} else {

				$street_number = trim( explode( ' ', $package['destination']['address'] )[0] );
				$quotes = $this->get_quotes( $package, array(
					'street_number' => $street_number,
					'address1' 		=> trim( str_replace( $street_number, '', $package['destination']['address'] ) ),
					'suburb' 		=> '',
					'city' 			=> $package['destination']['city'],
					'zip' 			=> $package['destination']['postcode']
				) );

				if ( empty( $quotes ) ) {
					return;
				}

			}

			switch ( $this->rate_display ) {
				case 'both':
					$cheapest = null;
					foreach ( $quotes as $bid ) {
						if ( $bid->express >= 1 )
							continue;

						if ( $cheapest == null ) {
							$cheapest = $bid;
							continue;
						}

						if ( $bid->cost < $cheapest->cost )
							$cheapest = $bid;
					}

					if ( ! empty( $cheapest ) )
						$this->_add_rate( 'standard', 'Standard', $cheapest );

					$cheapest = null;
					foreach ( $quotes as $bid ) {
						if ( $bid->express <= 0 )
							continue;

						if ( $cheapest == null ) {
							$cheapest = $bid;
							continue;
						}

						if ( $bid->cost < $cheapest->cost )
							$cheapest = $bid;
					}

					if ( ! empty( $cheapest ) )
						$this->_add_rate( 'express', 'Express', $cheapest );

					break;
				case 'cheapest':
				default:
					$cheapest = null;
					foreach ( $quotes as $bid ) {

						if ( $cheapest == null ) {
							$cheapest = $bid;
							continue;
						}

						if ( $bid->cost < $cheapest->cost )
							$cheapest = $bid;
					}

					if ( ! empty( $cheapest ) )
						$this->_add_rate( 'cheapest', 'Standard', $cheapest );

					break;
			}
		}
	}

	public function hide_other_shipping_when_is_available( $methods ) {

		if ( isset( $methods[ 'free_shipping' ] ) )
			return array( 'free_shipping' => $methods[ 'free_shipping' ] );

		$found_methods = array();
		foreach ( $methods as $id => $method ) {
			if ( strpos( $id, $this->id ) !== false )
				$found_methods[ $id ] = $method;
		}

		if ( empty( $found_methods ) )
			return $methods;

		if ( isset( $methods[ 'local_pickup' ] ) )
			$found_methods[ 'local_pickup' ] = $methods[ 'local_pickup' ];

		return $found_methods;
	}

	private function get_quotes( $package = array(), $delivery_address = array() ) {
		global $wc_eship;

		if ( empty( $package ) || empty( $delivery_address ) ) {
			return false;
		}

		// Enabled logic
		$is_available = true;
		$request = array(
			'description'		=> '',
			'weight'      		=> 0,
			'width'      		=> 0,
			'height'      		=> 0,
			'length'      		=> 0,
			'quantity'      	=> 1,
			'insurance'   		=> $this->insurance == 'yes' ? 1 : 0,
			'pickup_address' 	=> json_decode( $this->store_address_fields, true ),
			'delivery_address' 	=> $delivery_address,
		);

		// loop through the items
		foreach ( $package[ 'contents' ] as $key => $item ) {

			$product = $item[ 'data' ];
			if ( ! $product->needs_shipping() ) {
				continue; // No shipping needed, virtual product?
			}

			$width  = wc_get_dimension( $product->get_width(), 'cm' );
			$height = wc_get_dimension( $product->get_height(), 'cm' );
			$length = wc_get_dimension( $product->get_length(), 'cm' );
			$weight = wc_get_weight( $product->get_weight(), 'kg' );

			// Product cannot be quoted
			if ( empty( $width ) || empty( $height ) || empty( $length ) || empty( $weight ) ) {
				return false;
			}

			$request['weight'] 	+= $weight 	* $item[ 'quantity' ];
			$request['width']	+= $width 	* $item[ 'quantity' ];
			$request['height'] 	+= $height 	* $item[ 'quantity' ];
			$request['length'] 	+= $length 	* $item[ 'quantity' ];

			$request['description'] .= $product->post->post_title . ' (' . $item[ 'quantity' ] . '), ';
		}

		if ( ! is_checkout() ) {
			$quotes = $wc_eship->api( 'get_quotes', array(
				'weight' 			=> $request['weight'],
				'pickup_address' 	=> array(
					'zip' => $request['pickup_address']['zip']
				),
				'delivery_address' 	=> array(
					'zip' => $request['delivery_address']['zip']
				)
			), 'post' );
		} else {
			if ( false !== ( $listing_id = get_transient( 'wc_eship_' . md5( json_encode( $request ) ) ) ) ) {
				$request['listing_id'] = $listing_id;
			}

			$quotes = $wc_eship->api( 'get_bids', $request, 'post' );
			set_transient( 'wc_eship_' . md5( json_encode( $request ) ), $quotes->listing_id, 300 );
			$quotes = $quotes->bids;
		}

		if ( empty( $quotes ) || ! is_array( $quotes ) ) {
			return false;
		}

		return $quotes;
	}

	private function _add_rate( $id, $label, $quote ) {
		$rate = new WC_Shipping_Rate(
			$this->id . '_' . $id,
			( empty( $this->title ) ? '' : $this->title . ' ' ) . $label,
			$quote->cost + ( $quote->cost * ( $this->markup_percentage / 100 ) ),
			false,
			$this->id
		);

		$quote->markup_percentage = $this->markup_percentage;
		$rate->quote = $quote;
		$this->rates[] = $rate;
	}

	/**
	 * Generate Text Input HTML.
	 *
	 * @param  mixed $key
	 * @param  mixed $data
	 * @since  1.0.0
	 * @return string
	 */
	public function generate_address_html( $key, $data ) {

		$field    = $this->get_field_key( $key );
		$defaults = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array()
		);

		$data = wp_parse_args( $data, $defaults );
		$data['type'] = 'text';

		ob_start();
?>
<tr valign="top">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr( $field ); ?>_autocomplete"><?php echo wp_kses_post( $data['title'] ); ?></label>
		<?php echo $this->get_tooltip_html( $data ); ?>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
			<input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>" type="<?php echo esc_attr( $data['type'] ); ?>" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>_autocomplete" style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php echo esc_attr( $this->get_option( $key ) ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?> />
			<?php echo $this->get_description_html( $data ); ?>
		</fieldset>
		<script>
			function initAutocomplete() {
				// Create the autocomplete object, restricting the search to geographical
				// location types.
				autocomplete = new google.maps.places.Autocomplete(
					( document.getElementById('<?php echo esc_attr( $field ); ?>_autocomplete') ),
					{types: ['geocode']}
				);

				// When the user selects an address from the dropdown, populate the address
				// fields in the form.
				autocomplete.addListener('place_changed', fillInAddress);
			}

			var address_components = {
				street_number: {
					type: 'short_name',
					field: 'street_number'
				},
				route: {
					type: 'long_name',
					field: 'address1'
				},
				sublocality_level_1: {
					type: 'long_name',
					field: 'suburb'
				},
				locality: {
					type: 'long_name',
					field: 'city'
				},
				postal_code: {
					type: 'short_name',
					field: 'zip'
				},
			};

			function fillInAddress() {
				// Get the place details from the autocomplete object.
				var place = autocomplete.getPlace();
				var address_fields = {
					street_number: '',
					address1: '',
					suburb: '',
					city: '',
					zip: ''
				};

				// Get each component of the address from the place details
				// and fill the corresponding field on the form.
				for (var i = 0; i < place.address_components.length; i++) {
					var addressType = place.address_components[i].types[0];
					if ( address_components[ addressType ] ) {
						var val = place.address_components[ i ][ address_components[ addressType ].type ];
						address_fields[ address_components[ addressType ].field ] = val;
					}
				}
				console.log(address_fields);
				document.getElementById('woocommerce_eship_store_address_fields').value = JSON.stringify( address_fields );
			}

		</script>
		<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAexCmJcT8EbYWcRB7hPKnqPYILX45KmmM&signed_in=true&libraries=places&callback=initAutocomplete" async defer></script>
	</td>
</tr>
<?php

		return ob_get_clean();
	}

	public function generate_address_hidden_html( $key, $data ) {

		$field    = $this->get_field_key( $key );
		$defaults = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array()
		);

		$data = wp_parse_args( $data, $defaults );
		$data['type'] = 'hidden';

		ob_start();
?>
<tr valign="top" style="display:none;">
	<td colspan="2">
		<input class="input-text regular-input" type="<?php echo esc_attr( $data['type'] ); ?>" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $this->get_option( $key ) ); ?>" <?php disabled( $data['disabled'], true ); ?> />
	</td>
</tr>
<?php

		return ob_get_clean();
	}
}
