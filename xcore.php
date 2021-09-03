<?php

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
/*
   Plugin Name: xCore Rest API extension
   Plugin URI: http://xcore.dealer4dealer.nl
   description: Extend WC Rest API to support xCore requests
   @Version: 1.10.2
   @Author: Dealer4Dealer
   Author URI: http://www.dealer4dealer.nl
   Requires at least: 4.7.5
   Tested up to: 5.6.0
   License: GPL2
   WC requires at least: 3.3.0
   WC tested up to: 4.8.0
   */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if WooCommerce is active
 **/
if (!is_plugin_active('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'woocommerce_not_activated');
    return;
}

add_action(
    'woocommerce_loaded',
    function () {
        include_once dirname(__FILE__) . '/includes/helpers/abstract-xcore-data-helper.php';
        include_once dirname(__FILE__) . '/includes/helpers/class-xcore-helper.php';
        include_once dirname(__FILE__) . '/includes/class-xcore.php';

        if (class_exists('Xcore')) {
            return Xcore::get_instance();
        }
    }
);

function woocommerce_not_activated()
{
    ?>
    <div class="error notice">
        <p><b><?php
                _e('xCore Rest API extension requires WooCommerce to be activated to work.', 'http://www.dealer4dealer.nl'); ?></b></p>
    </div>
    <?php
}