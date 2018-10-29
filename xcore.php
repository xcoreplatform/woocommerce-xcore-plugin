<?php
/*
   Plugin Name: xCore Rest API extension
   Plugin URI: http://xcore.dealer4dealer.nl
   description: Extend WC Rest API to support xCore requests
   @Version: 1.3.0
   @Author: Dealer4Dealer
   Author URI: http://www.dealer4dealer.nl
   Requires at least: 4.7.5
   Tested up to: 4.9.8
   License: GPL2
   */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xcore')) {
    include_once dirname(__FILE__) . '/includes/class-xcore.php';
}

add_action('plugins_loaded', 'run_xcore', 10, 1);
function run_xcore()
{
    if (class_exists('Xcore')) {
        return Xcore::instance();
    }
}