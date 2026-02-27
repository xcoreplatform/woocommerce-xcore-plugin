<?php

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
/*
   Plugin Name: Xcore Rest API extension
   Plugin URI: https://xcore.nl/
   description: This plugin adds additional functionality to the Woocommerce Rest API to support the features provided by our Xcore platform.
   @Version: 1.15.0
   @Author: Xcore
   Author URI: https://xcore.nl/
   Requires at least: 6.3.0
   Tested up to: 6.8.1
   License: GPL2
   WC requires at least: 7.5.0
   WC tested up to: 9.8.5
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
