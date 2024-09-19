<?php

defined('ABSPATH') || exit;

class Xcore_Tax_Classes extends WC_REST_Tax_Classes_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'tax_classes';

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct()
    {
        $this->init();
    }

    /**
     * Register all tax class routes
     */
    public function init()
    {
        register_rest_route(
            $this->namespace,
            $this->base,
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );
    }

}