<?php
/**
 * Plugin Name: Opayo Direct Gateway for Gravity Forms
 * Plugin URI: http://www.patsatech.com/
 * Description: GravityForms Plugin for accepting payment through Opayo Direct Gateway.
 * Version: 1.0.2
 * Author: PatSaTECH
 * Author URI: http://www.patsatech.com
 * Contributors: patsatech
 * Requires at least: 4.5
 * Tested up to: 6.2
 *
 * Text Domain: gf-sagepay-direct-patsatech
 * Domain Path: /languages/
 *
 * @package Opayo Direct Gateway for Gravity Forms
 * @author PatSaTECH
 */

define( 'GF_SAGEPAYDIRECT_VERSION', '1.0.2' );

add_action( 'gform_loaded', array( 'GF_SAGEPAYDIRECT_Bootstrap', 'load' ), 5 );

class GF_SAGEPAYDIRECT_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-sagepay-direct.php' );

		GFAddOn::register( 'GFSagePayDirect' );
	}

}

function gf_SAGEPAYDIRECT() {
	return GFSagePayDirect::get_instance();
}
