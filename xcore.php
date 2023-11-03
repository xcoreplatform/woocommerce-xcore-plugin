<?php

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
/*
   Plugin Name: xCore Rest API extension
   Plugin URI: https://xcore.nl/
   description: This plugin adds additional functionality to the Woocommerce Rest API to support the features provided by our xCore platform.
   @Version: 1.12.4-rc.1
   @Author: Dealer4Dealer
   Author URI: https://xcore.nl/
   Requires at least: 5.3.0
   Tested up to: 6.2.2
   License: GPL2
   WC requires at least: 5.8.0
   WC tested up to: 7.7.2
   */

if (!defined('ABSPATH')) {
    exit;
}

if( ! function_exists('get_plugin_data') ){
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

if (!is_plugin_active( 'woocommerce/woocommerce.php')) {
	add_action('admin_notices', static function () {
		?>
        <div class="error notice">
            <p><b><?php
					_e( 'xCore Rest API extension requires WooCommerce to be activated to work.',
						'https://www.xcore.nl' ); ?></b></p>
        </div>
		<?php
	});
}

add_action(
    'woocommerce_loaded',
    static function () {
        if (!class_exists('Xcore')) {
	        include_once __DIR__ . '/includes/helpers/abstract-xcore-data-helper.php';
	        include_once __DIR__ . '/includes/helpers/class-xcore-helper.php';
	        include_once __DIR__ . '/includes/class-xcore.php';

	        Xcore::get_instance();
        }
    }
);

add_action(
    'before_woocommerce_init',
    function() {
        if (class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);
