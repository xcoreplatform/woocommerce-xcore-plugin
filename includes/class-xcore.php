<?php

defined( 'ABSPATH' ) || exit;

class Xcore {
	private $_version            = '1.12.4';
	private static $_instance    = null;
    private        $_xcoreHelper = null;

	public static function get_instance()
	{
		if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
	}

	public function __construct()
	{
		$this->_xcoreHelper = new Xcore_Helper();
		$this->init();
		$this->initHooks();
		$this->enablePluginSupport();
	}

	/**
	 * Initiate rest_api_init and listen for product updates
	 * of a variation and update the date/time
	 */
	public function init()
	{
	    add_filter('woocommerce_after_product_object_save', function($product, $dataStore) {
			global $wp_filter;
			if (class_exists('Xcore_Products') && has_filter('wp_insert_post_data', [$this->Xcore_Products, 'filter_stock_updates'])) {
				return $product;
			}

			if ($product->get_type() == 'variable' && $product->get_id()) {
				global $wpdb;
		
				$data = [
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
				];
		
				$where = [
				'post_parent' => $product->get_id(),
				'post_type'   => 'product_variation',
				];
		
				$wpdb->update($wpdb->posts, $data, $where);
			}
			$has_run = true;

			return $product;
	        }, 10, 2);

		if ( ! $this->isXcoreRequest() ) {
			return;
		}

		$this->includes();

		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'wc-xcore/v1',
					'version',
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'xcore_api_version' ],
						'permission_callback' => '__return_true',
					]
				);

				register_rest_route(
					'wc-xcore/v1',
					'info',
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_website_info' ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
					]
				);

				register_rest_route(
					'wc-xcore/v1',
					'shop_languages',
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this->_xcoreHelper, 'getSiteLanguages' ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
					]
				);

				register_rest_route(
					'wc-xcore/v1',
					'taxonomy' . '/(?P<taxonomy>[\w-]+)',
					[
						'methods'  => WP_REST_Server::READABLE,
						'callback' => [WP_REST_Taxonomies_Controller::class, 'get_item'],
						'permission_callback' => [WP_REST_Taxonomies_Controller::class, 'get_items_permissions_check'],

					]
				);

				register_rest_route(
					'wc-xcore/v1',
					'options',
					[
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => [ $this, 'processOptions' ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
					]
				);
				$this->init_classes();
			}
		);
	}

	private function initHooks()
    {
        if (!$this->isXcoreRequest()) {
            return;
        }

        add_filter('woocommerce_rest_shop_order_object_query', [$this, 'xcoreFilterByDateModified'], 10, 2);
        add_filter('woocommerce_rest_shop_order_refund_object_query', [$this, 'xcoreFilterByDateModified'], 10, 2);
        add_filter('woocommerce_rest_product_object_query', [$this, 'xcoreFilterByDateModified'], 10, 2);
        add_filter( 'woocommerce_rest_customer_query', [$this, 'xCoreSearchUserByMeta'], 10, 2);
        add_filter( 'woocommerce_before_customer_object_save', [$this, 'getCustomerByUserName'], 10, 2);
    }

    public function enablePluginSupport()
	{
		if (!$this->isXcoreRequest()) {
            return;
        }

        if (class_exists('\SitePress') && class_exists('\woocommerce_wpml')) {
            $this->enableWpmlRestSupport();
        }

        if ( class_exists( 'WPO_WCPDF' ) ) {
			$this->enableWcpdfSupport();
		}

        if (is_plugin_active('customer-specific-pricing-for-woocommerce/customer-specific-pricing-for-woocommerce.php')) {
            $this->enableCspRestSupport();
        }
	}

	public function processOptions($request)
	{
		$key =  $request->get_param('id');
		$data = $request->get_param('options');

		$sets = get_option( '_a_category_pricing_rules' );

		if (!$sets) {
			return update_option( '_a_category_pricing_rules', $data[0]);
		}

		if ($sets && is_array( $sets ) && count( $sets ) > 0 ) {
			foreach ($data as $key => $value) {
				$x = array_key_first( $value);
				$sets[$x] = $value[$x];
			}
		}
		return update_option( '_a_category_pricing_rules', $sets);
	}

	public function xCoreSearchUserByMeta($arguments, $request)
    {
        if (!$this->isXcoreRequest() || !isset($request['meta_key']) || !isset($request['meta_value'])) {
            return $arguments;
        }

        $meta_key       = $request['meta_key'];
        $meta_value     = $request['meta_value'];
        $must_be_unique = isset($request['unique']) ? $request['unique'] : true;

        $args = array(
            'order'      => 'ASC',
            'orderby'    => 'display_name',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key'     => $meta_key,
                    'value'   => $meta_value,
                    'compare' => '='
                )
            )
        );
        return array_merge($args, $arguments);
    }

	public function getCustomerByUserName( WC_Customer $customer, $dataStore )
	{
		if ( $customer->get_id() && ! $this->isXcoreRequest() ) {
			return $customer;
		}

		$userName = $customer->get_username();

		if ( ! $userName ) {
			return $customer;
		}

		$customerId = username_exists( $userName );
		if ( $customerId ) {
			$customer->set_id( $customerId );
		}

		return $customer;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return string
	 */

	public function xcore_api_version()
	{
		return $this->_version;
	}

	/**
     * @return bool
     */
    private function isXcoreRequest()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $restPrefix = trailingslashit(rest_get_url_prefix());
        return (false !== strpos($_SERVER['REQUEST_URI'], $restPrefix . 'wc-xcore/'));
    }

	public function get_website_info( $request )
	{
		$include_plugin_data = isset( $request['include_plugin_data'] ) && (bool) $request['include_plugin_data'];

		return $this->_xcoreHelper->get_info( $include_plugin_data );
	}

	/**
	 * Include all classes
	 */
	public function includes()
	{
		include_once __DIR__ . '/class-xcore-products.php';
		include_once __DIR__ . '/class-xcore-product-variations.php';
		include_once __DIR__ . '/class-xcore-product-attributes.php';
		include_once __DIR__ . '/class-xcore-product-attribute-terms.php';
		include_once __DIR__ . '/class-xcore-product-categories.php';
		include_once __DIR__ . '/class-xcore-customers.php';
		include_once __DIR__ . '/class-xcore-orders.php';
		include_once __DIR__ . '/class-xcore-refunds.php';
		include_once __DIR__ . '/class-xcore-shipping-methods.php';
		include_once __DIR__ . '/class-xcore-payment-methods.php';
		include_once __DIR__ . '/class-xcore-tax-classes.php';
		include_once __DIR__ . '/class-xcore-documents.php';
	}

	/**
	 * Initiate all classes to register the necessary routes
	 */
	public function init_classes()
	{
		$classes = [
			'Xcore_Products',
			'Xcore_Product_Variations',
			'Xcore_Product_Attributes',
			'Xcore_Product_Attribute_Terms',
			'Xcore_Product_Categories',
			'Xcore_Customers',
			'Xcore_Orders',
			'Xcore_Refunds',
			'Xcore_Shipping_Methods',
			'Xcore_Payment_Methods',
			'Xcore_Tax_Classes',
			'Xcore_Documents',
		];

		foreach ( $classes as $class ) {
			$this->$class = new $class( $this->_xcoreHelper );
		}
	}

	public function get_items_permissions_check($request)
    {
        if (!wc_rest_check_manager_permissions('settings', 'read')) {
            return new WP_Error(
                'woocommerce_rest_cannot_view',
                __('Sorry, you cannot list resources.', 'woocommerce'),
                ['status' => rest_authorization_required_code()]
            );
        }

        return true;
    }

	/**
	 * @return Xcore_Helper
	 */
	public function getHelper()
	{
		return $this->_xcoreHelper;
	}

	public function enableWcpdfSupport() {
		add_filter( 'xcore_rest_document_download', [ $this, 'getWcpdfAttachment' ] );
	}

	/**
	 * @return void
	 */
	public function enableWpmlRestSupport()
	{
		add_filter( 'xcore_site_information', function ( $data ) {
			$data['base']['wpml_current_language'] = apply_filters( 'wpml_current_language', null );
			$data['base']['wpml_default_language'] = apply_filters( 'wpml_default_language', null );

			return $data;
		} );

		add_filter(
            'woocommerce_rest_is_request_to_rest_api',
            function($isWcApiRequest) {
                $restPrefix = trailingslashit(rest_get_url_prefix());
                // Check if request is intended for us.
                $isXcoreRequest = (false !== strpos($_SERVER['REQUEST_URI'], $restPrefix . 'wc-xcore/'));
                return $isXcoreRequest ? true : $isWcApiRequest;
            }
        );

		add_filter('xcore_api_webshop_languages', function($languages) {
            $obj = [
                'default'   => apply_filters('wpml_default_language', null),
                'languages' => apply_filters('wpml_active_languages', null),
            ];

            return rest_ensure_response($obj);
        });
	}

	/**
     * @return void
     */
    public function enableCspRestSupport()
    {
        $cspSettings = get_option('wdm_csp_settings');

        if (isset($cspSettings['csp_api_status']) && $cspSettings['csp_api_status'] === 'enable') {
            /**
	         * Disable hash validation
	         */
	        add_filter('cspapi_is_api_hash_valid', '__return_true');
	        add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'addCspResponseData' ], 10, 3 );
	        add_filter('xcore_rest_product_update_request', [$this, 'processCspData']);
        }
    }

    /**
     * @param $response
     * @param $itemObject
     * @param $request
     *
     * @return mixed|WP_Error
     */
    public function addCspResponseData($response, $itemObject, $request)
    {
        $cspClass              = \CSPAPI\Includes\API\CspMappingsCustomerBased::getInstance();
        $request['product_id'] = $itemObject->get_id();
        $cspResponse           = $cspClass->getCspForProduct($request);

        if ($cspResponse instanceof WP_Error) {
            return $cspResponse;
        }

        $response->data['csp_data'] = $cspResponse->data;

        return $response;
    }

    /**
     * @param $request
     *
     * @return mixed|WP_Error
     */
    public function processCspData($request)
    {
        if (!isset($request['csp_data'])) {
            return $request;
        }

        $cspClass    = \CSPAPI\Includes\API\CspMappingsCustomerBased::getInstance();
        $cspData = $request['csp_data'];

        $cspResponse = null;

        if (array_key_exists("create", $cspData)) {
            $request['csp_data'] = $cspData['create'];
        }

        $cspResponse = $cspClass->addCsp($request);

        if (!($cspResponse instanceof WP_Error) && array_key_exists("delete", $cspData)) {
            $request['csp_data'] = $cspData['delete'];
            $cspResponse = $cspClass->deleteCsp($request);
        }

        if (!$cspResponse instanceof WP_Error) {
            $cspResponse->data['csp_data'] = $cspResponse->data;
        }

        return $cspResponse;
    }

    public function getWcpdfAttachment( $request ) {
		$orderId = $request->get_param( 'order_id' );
		$type    = $request->get_param( 'document_type' );

		if ( function_exists( 'wcpdf_get_document' ) ) {
			$document = wcpdf_get_document( $type ?: 'invoice', $orderId );

			if ( is_object( $document ) && method_exists( $document, 'get_pdf' ) ) {
				return [
					'file_name' => $document->get_filename(),
					'file_type' => 'PDF',
					'file'      => $document->get_pdf(),
					'data'      => $document->get_number(),
				];
			}
		}

		return null;
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

		$args['date_query'][0]['column']    = 'post_modified_gmt';
		$args['date_query'][0]['after']     = $date_modified;

		return $args;
	}
}
