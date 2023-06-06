<?php

defined('ABSPATH') || exit;

class Xcore_Orders extends WC_REST_Orders_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'orders';
    /** @var Xcore_Helper $_xcoreHelper */
    private $_xcoreHelper;

    public function __construct($helper)
    {
        $this->init();
    }

    /**
     * Register all order routes
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

        register_rest_route(
            $this->namespace,
            $this->base . '/statuses',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_orders_statuses'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'update_item'),
                'permission_callback' => array($this, 'update_item_permissions_check'),
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
            )
        );
    }

    /**
     * @return array
     */
    public function get_orders_statuses()
    {
        return wc_get_order_statuses();
    }

	/**
	 * Set alternate default values
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		$params['per_page']['default']      = 50;
		$params['order']['default']         = 'asc';
		$params['orderby']['default']       = 'modified';
		$params['dates_are_gmt']['default'] = true;
		return $params;
	}
}