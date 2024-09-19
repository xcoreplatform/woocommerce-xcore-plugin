<?php

class Xcore_Product_Attribute_Terms extends WC_REST_Product_Attribute_Terms_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'products/attributes/(?P<attribute_id>[\d]+)/terms';

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

    private function init()
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                'args'   => array(
                    'attribute_id' => array(
                        'description' => __('Unique identifier for the attribute of the terms.', 'woocommerce'),
                        'type'        => 'integer',
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_items'),
                    'permission_callback' => array($this, 'get_items_permissions_check'),
                    'args'                => $this->get_collection_params(),
                ),
                'schema' => array($this, 'get_public_item_schema'),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                'args'   => array(
                    'id'           => array(
                        'description' => __('Unique identifier for the resource.', 'woocommerce'),
                        'type'        => 'integer',
                    ),
                    'attribute_id' => array(
                        'description' => __('Unique identifier for the attribute of the terms.', 'woocommerce'),
                        'type'        => 'integer',
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_item'),
                    'permission_callback' => array($this, 'get_item_permissions_check'),
                    'args'                => array(
                        'context' => $this->get_context_param(array('default' => 'view')),
                    ),
                ),
                'schema' => array($this, 'get_public_item_schema'),
            )
        );
    }
}