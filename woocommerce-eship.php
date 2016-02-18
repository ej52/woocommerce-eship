<?php
/*
Plugin Name: WooCommerce eShip
Plugin URI: https://eship.co.za/
Description: eShip deliveries for WooCommerce
Version: 1.0.0
Author: <a href="http://www.semantica.co.za/">Semantica</a> (<a href="https://github.com/ej52/">ej52</a>)
Requires at least: 4.0
Tested up to: 4.2.2
Copyright: © 2015 eShip
License: GNU General Public License v2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

add_action( 'plugins_loaded', 'woocommerce_eship_init', 0 );

/**
 * Initialise the shipping module
 **/
function woocommerce_eship_init() {
	if ( ! class_exists( 'WC_Shipping_Method' ) ) {
		return;
	}

	require_once( plugin_basename( 'classes/class-wc-eship.php' ) );

	global $wc_eship;
	$wc_eship = new WC_eShip( __FILE__ );

	require_once( plugin_basename( 'classes/class-wc-eship-method.php' ) );

	add_filter('woocommerce_shipping_methods', 'woocommerce_eship_add' );
}
/**
 * Add the shipping module to WooCommerce
 **/
function woocommerce_eship_add( $methods ) {
	$methods[] = 'WC_eShip_Method';
	return $methods;
}
