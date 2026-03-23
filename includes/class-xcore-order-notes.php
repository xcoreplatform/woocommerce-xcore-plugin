<?php

defined('ABSPATH') || exit;

class Xcore_Order_Notes extends WC_REST_Order_Notes_Controller
{
	protected static $_instance = null;
	public           $version   = '1';
	public           $namespace = 'wc-xcore/v1';
	public           $rest_base = 'orders/(?P<order_id>[\d]+)/notes';

	public static function instance()
	{
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct()
	{
		$this->registerRoutes();
	}

	public function registerRoutes()
	{
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_item'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_items'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'create_item'),
				'permission_callback' => array($this, 'create_item_permissions_check'),
			    'args'                => $this->get_collection_params(),
			)
		);
	}
}