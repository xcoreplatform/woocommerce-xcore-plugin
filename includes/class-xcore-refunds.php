<?php

defined('ABSPATH') || exit;

class Xcore_Refunds extends WC_REST_Order_Refunds_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'refunds';
    /** @var Xcore_Helper $_xcoreHelper */
    private $_xcoreHelper;

    public function __construct($helper)
    {
        $this->init();
        $this->initHooks();
        $this->registerCustomFields();
        parent::__construct();
    }

    /**
     * Register all refund routes
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
            'orders' . '/(?P<order_id>[\d]+)' . '/' . $this->base . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );

        register_rest_route(
            $this->namespace,
            'orders' . '/(?P<order_id>[\d]+)' . '/' . $this->base,
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );
    }

    public function initHooks()
    {
        add_filter( 'pre_get_posts', [ $this, 'xcoreGetAllRefunds' ], 10, 1 );
    }


    public function xcoreGetAllRefunds( WP_Query $query )
	{
		$query->query_vars['post_parent__in'] = [];
		return $query;
	}

    /**
     * @param WC_Data         $object
     * @param WP_REST_Request $request
     * @return mixed|void|WP_Error|WP_REST_Response
     */
    public function prepare_object_for_response($object, $request)
    {
        $parentId = $object->get_parent_id();

        if ($parentId) {
            $request->set_param( 'order_id', $parentId);
        }

        return parent::prepare_object_for_response( $object, $request);
    }

    /**
	 * We rely heavily on the ability to retrieve data by its modification date. This
	 * adds the functionality to do so for both orders and products.
	 * Since Woocommerce 5.8.0 the option has been added to filter products by modified
	 * date using modified_after
	 *
	 * @param $args
	 * @param $request
	 *
	 * @return array
	 */
	public function xcoreFilterByDateModified( $args, $request )
	{
		$args['date_query'][0]['inclusive'] = true;

		if ($request->get_param('modified_after')) {
			return $args;
		}

		$objectId      = $request->get_param( 'id' );
		$date_modified = $request->get_param( 'date_modified' ) ?: '2001-01-01 00:00:00';

		if ( $objectId ) {
			$args['post__in'][] = $objectId;
		}
		$args['post_status'] = array_keys( wc_get_order_statuses() );

		$args['date_query'][0]['column']    = 'post_modified_gmt';
		$args['date_query'][0]['after']     = $date_modified;

		return $args;
	}

    /**
     * @param WC_Data $object
     * @return array
     */
    protected function get_formatted_item_data($object)
    {
        $data = parent::get_formatted_item_data( $object);
        $data['date_modified'] = wc_rest_prepare_date_response($object->get_date_modified(), false);
        $data['date_modified_gmt'] = wc_rest_prepare_date_response($object->get_date_modified());

        return $data;
    }

    public function registerCustomFields()
	{
		$defaultArgs = [
			'get_callback' => [$this, 'setCustomFields'],
			'update_callback' => '',
			'show_in_rest' => false,
			'auth_callback' => [$this, 'permissionCheck'],
			'schema' => [
				'type' => 'object',
				'arg_options' => [
					'sanitize_callback' => '',
					'validate_callback' => ''
				]
			]
		];

		register_rest_field( 'shop_order_refund', 'original_order', $defaultArgs);
		register_rest_field( 'shop_order_refund', 'parent_id', $defaultArgs);
	}

	public function setCustomFields($response, $field, $request)
	{
		if ($field === 'original_order') {
			$id = $request->get_param('order_id');
			$orderRequest = new WP_REST_Request('GET', sprintf('%s/orders/%s', $this->namespace, $id));
			$orderRequest->set_param( 'id', $id);
			$api = new Xcore_Orders($this->_xcoreHelper);
			$response = $api->get_item( $orderRequest);

			if ($response instanceof WP_REST_Response) {
				return $response->get_data();
			}
			return null;
		}

		if ($field === 'parent_id') {
			return isset($response['original_order']) ? $response['original_order']['id'] : null;
		}
	}

	public function permissionCheck($request)
	{
		return true;
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