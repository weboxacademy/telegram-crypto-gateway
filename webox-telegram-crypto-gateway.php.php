<?php
/**
 * Plugin Name: Webox Telegram Crypto Gateway
 * Plugin URI: https://weboxacademy.com
 * Description: Accept cryptocurrency payments in WooCommerce through Telegram Crypto Pay.
 * Version: 1.0.0
 * Author: WeboxAcademy
 * Author URI: https://weboxacademy.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTG_VERSION', '1.0.0' );
define( 'WTG_FILE', __FILE__ );
define( 'WTG_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTG_URL', plugin_dir_url( __FILE__ ) );
define( 'WTG_LOG_SOURCE', 'webox-telegram-crypto' );

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Load plugin.
 */
add_action(
	'plugins_loaded',
	function () {

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once WTG_DIR . 'includes/class-wtg-api.php';
		require_once WTG_DIR . 'includes/class-wtg-gateway.php';
		require_once WTG_DIR . 'includes/class-wtg-webhook.php';
		require_once WTG_DIR . 'includes/class-wtg-admin.php';

		WTG_Webhook::init();
		WTG_Admin::init();

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'WC_Gateway_WTG_Telegram_Crypto';
				return $gateways;
			}
		);
	}
);