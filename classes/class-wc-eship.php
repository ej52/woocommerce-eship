<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_eShip  {

	/**
	 * Contructor
	 **/
	public function __construct( $file = '' ) {
		$this->file = $file;
		$this->dir = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->method_id 	= 'eship';
		$this->api_url 		= 'https://www.eship.co.za/api/v1/';

		// Load the settings.
		$this->init_settings();

		// Load JS
		add_action( 'wp_footer', array( $this, 'enqueue_scripts' ), 11 );

		// Add eShip info when order is placed
		add_action( 'woocommerce_order_add_shipping', array( $this, 'add_order_consignment' ), 11, 3 );

		// Add metabox to display eShip on order view
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );

		if ( 'yes' === get_option( 'woocommerce_enable_shipping_calc' ) ) {
			add_filter( 'woocommerce_shipping_calculator_enable_city', '__return_false' );
			add_filter( 'woocommerce_shipping_calculator_enable_postcode', '__return_true' );
		}

		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'plugin_action_links' ) );

		if ( 'yes' === $this->settings['enable_branding'] ) {
			add_action( 'woocommerce_email_customer_details', array( $this, 'add_email_branding' ), 99, 4 );
		}
	}

	public function add_email_branding( $order, $sent_to_admin, $plain_text, $email ) {
		$eship = get_post_meta( $order->id, '_eship_consignment', true );
		if ( ! empty( $eship ) ) {
			echo '<div style="font-weight:bold;color:#99b1c7;font-family:Arial;font-size:13px;line-height: 125%;text-align:center;">Shipping Powered by <a href="https://www.eship.co.za/">eShip</a></div>';
		}
	}

	public function plugin_row_meta( $links, $file ) {

		if ( $file == plugin_basename( $this->file ) ) {

			$found = false;
			foreach ( $links as $i => $link ) {
				if ( false !== strpos( $link, 'View details' ) ) {
					$found = true;
				}
			}

			if ( false === $found ) {
				$url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=woocommerce-eship&TB_iframe=true&width=772&height=731' );
				$links[] = '<a href="' . $url . '" class="thickbox">View details</a>';
			}
		}

		return $links;
	}

	public function plugin_action_links( $actions ) {
		return array_merge( array(
			'Settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=wc_eship_method' ) . '">Settings</a>'
		), $actions );
	}

	public function init_settings() {

		$this->settings = get_option( 'woocommerce_' . $this->method_id . '_settings', null );

		if ( $this->settings && is_array( $this->settings ) ) {
			$this->enabled  = isset( $this->settings['enabled'] ) && $this->settings['enabled'] == 'yes' ? 'yes' : 'no';
		}

		require_once( $this->dir . '/updater.php' );
		new PluginUpdater(
			'https://github.com/SemanticaDigital/woocommerce-eship/',
			$this->file
		);

	}

	public function api( $endpoint = '', $params = array(), $method = 'post' ) {

		// No endpoint = no query
		if( ! $endpoint ) {
			return;
		}

		// Parameters must be an array
		if( ! is_array( $params ) ) {
			return;
		}

		// Only valid methods allowed
		if( ! in_array( $method, array( 'post', 'get' ) ) ) {
			return;
		}

		// Set up query URL
		$url = $this->api_url . $endpoint;

		// Set up request arguments
		$args['headers'] = array(
			//'Authorization' => 'Basic ' . base64_encode( $this->settings['api_key'] . ':' ),
			'username' => $this->settings['username'],
			'password' => $this->settings['password'],
		);
		$args['sslverify'] = false;
		$args['timeout'] = 60;
		$args['user-agent'] = 'WooCommerce/' . WC()->version;

		// Process request based on method in use
		switch( $method ) {

			case 'post':

				if( ! empty( $params ) ) {
					$params = json_encode( $params );
					$args['body'] = $params;
					$args['headers']['Content-Length'] = strlen( $args['body'] );
				}

				$args['headers']['Content-Type'] = 'application/json';
				$args['headers']['Content-Length'] = strlen( $args['body'] );

				$response = wp_remote_post( $url, $args );
				//print_r($response); die();
				break;

			case 'get':

				$param_string = '';
				if( ! empty( $params ) ) {
					$params = array_map( 'urlencode', $params );
					$param_string = build_query( $params );
				}

				if( $param_string ) {
					$url = $url . '?' . $param_string;
				}

				$response = wp_remote_get( $url, $args );

				break;
		}
		//echo PHP_EOL . stripslashes( json_encode($response, JSON_PRETTY_PRINT) ); die();
		// Return null if WP error is generated
		if( is_wp_error( $response ) ) {
			return;
		}

		// Return null if query is not successful
		if( $response['response']['code'] > 202 || ! isset( $response['body'] ) || ! $response['body'] ) {
			return;
		}

		// Return response object
		return json_decode( $response['body'] );
	}

	public function add_order_consignment ( $order_id, $item_id, $shipping_rate ) {

		if ( strpos( $shipping_rate->id, $this->method_id ) !== false ) {
			wc_update_order_item_meta( $item_id, 'method_id', $shipping_rate->method_id );

			$lodged = $this->api( 'accept_bid', array(
				'bid_id' 		=> $shipping_rate->quote->bid_id,
				//'callback'		=> site_url( '?eship=' . $this->settings['api_key'] . '&order=' . $order_id . '&status=' ),
				'sandbox'		=> $this->settings['sanbox_mode'] == 'yes' ? 1 : 0
			), 'post' );

			if( ! empty( $lodged ) && isset( $lodged->tracking_id ) ) {
				$lodged->markup_percentage = $shipping_rate->quote->markup_percentage;
				update_post_meta( $order_id, '_eship_consignment', $lodged );
			}
		}
	}

	public function add_order_metabox() {
		add_meta_box(
			'woocommerce-order-my-custom',
			__( 'eShip Consignment' ),
			array( $this, 'render_order_metabox' ),
			'shop_order',
			'side',
			'high'
		);
	}

	public function render_order_metabox( $post ){
		$eship = get_post_meta( $post->ID, '_eship_consignment', true );
		if ( ! empty( $eship ) ) :
?>
<style>
	#eship_info {
		width: 100%;
	}
	#eship_info td {
		width: 50%;
	}
</style>
<table id="eship_info">
	<tr>
		<td><b>Waybill: &nbsp;</b></td>
		<td><?php echo $eship->tracking_id;?></td>
	</tr>
	<tr>
		<td><b>Courier: &nbsp;</b></td>
		<td><?php echo $eship->courier;?></td>
	</tr>
	<tr>
		<td><b>Express: &nbsp;</b></td>
		<td><?php echo $eship->express ? 'Yes' : 'No';?></td>
	</tr>
	<tr>
		<td><b>Insured: &nbsp;</b></td>
		<td><?php echo $eship->insurance ? 'Yes' : 'No';?></td>
	</tr>
	<tr>
		<td><b>Markup: &nbsp;</b></td>
		<td><?php echo wc_price( ( $eship->cost * ( $eship->markup_percentage / 100 ) ) );?> (<?php echo $eship->markup_percentage;?>%)</td>
	</tr>
	<tr>
		<td><b>Cost: &nbsp;</b></td>
		<td><?php echo wc_price( $eship->cost );?></td>
	</tr>
	<tr>
		<td colspan="2"> &nbsp;</td>
	</tr>
	<tr>
		<td><b>Status: &nbsp;</b></td>
		<td><?php echo $eship->status;?></td>
	</tr>
</table>
<?php
		else :
		echo '<p style="text-align:center;font-weight:bold;color:#999;">Not shipped via eShip</p>';
		endif;
	}

	public function enqueue_scripts () {

		if ( is_checkout() ) {
			// Load eShip checkout script
			wp_register_script( 'wc_eship_checkout', $this->assets_url . 'js/checkout.js', array( 'jquery', 'wc-checkout', 'google-autocomplete' ), '1.0.0' );
			wp_enqueue_script( 'wc_eship_checkout' );
		}

		/*if( is_admin() && (
			isset( $_GET['page'] ) && 'wc-settings' == $_GET['page']
		) && (
			isset( $_GET['tab'] ) && 'shipping' == $_GET['tab']
		) && (
			isset( $_GET['section'] ) && 'wc_eship_method' == $_GET['section']
		) ) {
			// Load admin scripts
		}*/
	}
}
?>
