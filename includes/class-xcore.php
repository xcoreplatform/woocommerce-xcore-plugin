<?php

defined( 'ABSPATH' ) || exit;
#[AllowDynamicProperties]
class Xcore {
	private        $_version     = '1.15.1';
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
		add_action( 'admin_menu', [ $this, 'register_importer' ] );
		add_action( 'init', [ $this, 'add_exact_itemgroup_taxonomy' ], 0 );
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

		add_filter( 'woocommerce_dynamic_pricing_tabs', [$this, 'addExactItemGroupDynamicPricingTab'], 10, 1);
		add_filter( 'woocommerce_dynamic_pricing_is_cumulative', '__return_false');
		add_action( 'rest_api_init', [ $this, 'set_taxonomy_rest_api_fields' ] );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', [$this, 'addExactItemGroupImportMapping'], 10, 2);
		add_filter( 'woocommerce_csv_product_import_mapping_options', [$this, 'addExactItemGroupImportMappingOptions'], 10, 2);
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [$this, 'process_import'], 10, 2 );
		add_filter( 'wc_dynamic_pricing_get_discount_taxonomies', [$this, 'setDynamicPricingDiscountTaxonomies'], 1, 1);

		$this->includes();

		if ( ! $this->isXcoreRequest() ) {
			return;
		}


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

    public function addExactItemGroupImportMapping($columns)
    {
        $columns['ItemGroup: CodeDescription'] = 'xcore_exact_itemgroup';
        $columns['Artikelgroep']               = 'xcore_exact_itemgroup';
		$columns['ItemGroup:']                 = 'xcore_exact_itemgroup';
		$columns['ItemGroup']                  = 'xcore_exact_itemgroup';
		return $columns;
    }
    public function addExactItemGroupImportMappingOptions($options)
    {
        $options['xcore_exact_itemgroup'] = 'Exact Online itemgroup';
		return $options;
    }
    public function process_import($object, $data)
    {
        if (!$object instanceof \WC_Product) {
            return $object;
        }

        if (!empty($data['xcore_exact_itemgroup'])) {
            // Save object to assign correct product id
            $object->save();
            wp_set_object_terms($object->get_id(),  $data['xcore_exact_itemgroup'], 'xcore_exact_itemgroup');
        }

        return $object;
    }

    public function setDynamicPricingDiscountTaxonomies($taxonomies)
    {
		$taxonomies[] = 'xcore_exact_itemgroup';
        return $taxonomies;
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
		include_once __DIR__ . '/class-xcore-order-notes.php';
		include_once __DIR__ . '/class-xcore-refunds.php';
		include_once __DIR__ . '/class-xcore-shipping-methods.php';
		include_once __DIR__ . '/class-xcore-payment-methods.php';
		include_once __DIR__ . '/class-xcore-tax-classes.php';
		include_once __DIR__ . '/class-xcore-documents.php';
		include_once __DIR__ . '/class-xcore-custom-types.php';
		include_once __DIR__ . '/Compatibility/class-xcore-compatibility.php';
		include_once __DIR__ . '/class-xcore-importer.php';
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
			'Xcore_Order_Notes',
			'Xcore_Refunds',
			'Xcore_Shipping_Methods',
			'Xcore_Payment_Methods',
			'Xcore_Tax_Classes',
			'Xcore_Documents',
			'Xcore_Custom_Types',
			'Xcore_Compatibility',
			'Xcore_Importer',
		];

		foreach ( $classes as $class ) {
			if ($class === 'Xcore_Compatibility') {
				$this->$class = new $class();
			} else {
				$this->$class = new $class($this->_xcoreHelper);
			}
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

	public function getTaxonomyTerms( $request ) {
		$taxonomy = $request->has_param( 'taxonomy' ) ? $request->get_param( 'taxonomy' ) : null;

		$args  = [
			'orderby'    => 'term_id',
			'order'      => 'ASC',
			'hide_empty' => false,
			'fields'     => 'all',
			'get'        => 'all',
			'taxonomy'   => $taxonomy,
		];
		$terms = get_terms( $args );

		return rest_ensure_response( $terms );
	}

	public function checkTermPermissions( $request ) {
		$taxonomy = $request->has_param( 'taxonomy' ) ? $request->get_param( 'taxonomy' ) : null;

		if ( ! $taxonomy ) {
			return false;
		}

		if ( ! wc_rest_check_product_term_permissions( $taxonomy, 'read' ) ) {
			return new WP_Error(
				'woocommerce_rest_cannot_view',
				__( 'Sorry, you cannot list resources.', 'woocommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	public function add_exact_itemgroup_taxonomy() {
		$labels = array(
			'title'             => 'Exact itemgroup',
			'name'              => 'Itemgroup',
			'singular_name'     => 'Itemgroup',
			'search_items'      => 'Search itemgroup',
			'all_items'         => 'All itemgroups',
			'edit_item'         => 'Edit itemgroup',
			'update_item'       => 'Update itemgroup',
			'add_new_item'      => 'Add new itemgroup',
			'new_item_name'     => 'New itemgroup name',
			'menu_name'         => 'Exact Itemgroups',
		);

		$capabilities = array(
			'manage_terms' => 'manage_woocommerce',
			'edit_terms'   => 'manage_woocommerce',
			'delete_terms' => 'manage_woocommerce',
			'assign_terms' => 'manage_woocommerce',
		);

		// Now register the taxonomy
		$args = array(
			'labels'            => $labels,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'capabilities'      => $capabilities,


		);
		register_taxonomy( 'xcore_exact_itemgroup', [ 'product' ], $args );
		register_taxonomy_for_object_type( 'xcore_exact_itemgroup', 'product' );
	}


	public function set_taxonomy_rest_api_fields() {
		register_rest_field( 'product', "xcore_exact_itemgroup", array(
			'get_callback'    => [ $this, 'taxonomy_get_callback' ],
			'update_callback' => [ $this, 'taxonomy_update_callback' ],
			'schema'          => null,
		) );
	}

	public function taxonomy_get_callback($post, $attr) {

		$terms = [];
		foreach (wp_get_post_terms($post['id'], $attr) as $term) {
			$terms[] = [
				'id'   => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			];
		}
		return $terms;
	}

	public function taxonomy_update_callback($values, $post, $attr):void
	{
		foreach ($values as $index => $term) {
			if (!array_key_exists( 'name', $term)) {
				continue;
			}

			if ($id = term_exists($term['slug'] ?? $term['name'])) {
				unset($term['name']);
				$result = wp_update_term($id, $attr, $term);
			} else {
				$args = [
					'alias_of'    => array_key_exists( 'alias_of', $term ) ? $term['alias_of'] : '',
					'description' => array_key_exists( 'description', $term ) ? $term['description'] : '',
					'parent'      => array_key_exists( 'parent', $term ) ? (int) $term['parent'] : 0,
					'slug'        => array_key_exists( 'slug', $term ) ? $term['slug'] : '',
				];

				$result = wp_insert_term($term['name'], $attr, $args);
			}

			if ( $result instanceof WP_Error) {
				$termId = get_term_by('slug', $term['slug'], $attr)->term_id;
			} else {
				$termId = array_key_exists( 'term_id', $result) ? $result['term_id'] : 0;
			}

			if (!$termId) {
				continue;
			}

			$values[$index] = [
				'id' => (int) $termId,
			];
		}

		$termIds = array_map( static function ($term) {
			if (!array_key_exists( 'id', $term) || !is_numeric($term['id'])) {
				return null;
			}

			return (int) $term['id'];
		}, $values );

		wp_set_object_terms($post->id, $termIds, $attr);
	}

	private function log($level, $logMsg)
    {
        $source = ['source' => 'xcore-rest-api'];
        $logger = wc_get_logger();

		if (is_array($logMsg) || is_object( $logMsg)) {
			$logMsg = print_r($logMsg, true);
		}

        if ($logger) {
            $logger->log($level, $logMsg, $source);
        }
    }

	private function getWdpBaseData($title, $roles, $taxonomies, $rules)
	{
		$taxonomyIds = $this->checkTaxonomyTerms($taxonomies);

		return [
			'admin_title' => $title,
			'conditions_type' => 'all',
			'conditions'      => [
				'1' => [
					'type' => 'apply_to',
					'args' => [
						'applies_to' => 'roles',  // everyone | unauthenticated | roles
						'roles'      => $roles,
					],
				],
			],
			'collector'       => [
				'type' =>'cat',
				'args' => [
					'cats'  => $taxonomyIds,
				]
			],
			'mode'            => 'continuous',  // continuous (Bulk) | block (Special offer)
			'targets'         => $taxonomyIds,
			'date_from'       => '',
			'date_to'         => '',
			'rules'           => $rules,
			'block_rules'     => [
				'1' => [
					'from'      => '',
					'adjust'    => '',
					'type'      => 'fixed_adjustment',
					'amount'    => '',
					'repeating' => 'no',
				],
			],
		];
	}

	private function createRule($from = '', $to = '', $amount = '', $type = 'percentage_discount')
	{
		$keys = ['from', 'to', 'amount', 'type'];
		return array_combine($keys, [$from, $to, $amount, $type]);
	}

	public function register_importer() {
		register_importer( 'wc_custom_meta_importer',
			'Xcore prijslijsten importeren',
			'Importeer de prijslijsten uit je financiele administratie in Woocommerce.',
			[ $this, 'import_page' ] );
	}

	public function import_page() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( __( 'Je hebt geen toestemming om dit te doen.' ) );
		}

		if ( isset( $_POST['submit'] ) && ! empty( $_FILES['import_file']['tmp_name'] ) ) {
			$this->handle_import(
				$_FILES['import_file']['tmp_name'],
				$_FILES['import_file']['name'],
				$_POST['pricelist_name'],
				$_POST['csv_separator'],
				$_POST['user_roles']
			);
		}

		echo '<form id="wp-import-form" method="post" enctype="multipart/form-data">';
		echo '<div class="wrap">';
		echo '<h2>Xcore prijslijsten</h2>';
		echo '<label for="pricelist_name">Prijslijst naam:</label><br />';
		echo '<input type="text" name="pricelist_name" required />';
		echo '<br />';
		echo '<label for="separator">CSV scheidingsteken:</label><br />';
		echo '<select name="csv_separator">';
		echo '<option value="," selected>,</option>';
		echo '<option value=";">;</option>';
		echo '<option value="TAB">TAB</option>';
		echo '</select>';
		echo '<br />';
		echo '<input type="file" name="import_file" required />';
		echo '<br />';
		echo '<div>';
		echo '<label for="user_roles">Apply to Roles:</label>';
		echo '<select name="user_roles[]" id="user_roles" multiple required>';
		wp_dropdown_roles();

		echo '</select>';
		echo '</div>';
		submit_button( 'Importeer' );
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Should be split into admin and rest-api folders
	 *
	 * @param $file_path
	 * @param $fileName
	 * @param $priceListName
	 * @param $separator
	 * @param $roles
	 *
	 * @return void
	 */

	private function handle_import( $file_path, $fileName, $priceListName, $separator, $roles) {
		$header = null;
		$headerCount = 0;
		$data   = [];
		$taxonomies = [];
		$rules = [];
		$fileName = explode('_', $fileName)[1] ?? basename($fileName);
		try {
			$file = new SplFileObject( $file_path, 'r');
			$file->setFlags( SplFileObject::READ_CSV);
			$file->setFlags( SplFileObject::SKIP_EMPTY);
			$file->setCsvControl($separator === 'TAB' ? "\t" : $separator, '"');
			while (!$file->eof()) {

				$line = $file->fgetcsv();

				if (empty($line)) {
					continue;
				}

				if (!$header) {
					$header = $line;
					$headerCount = count($line);
				} elseif ( ! empty($line) ) {

					if (count($line) < $headerCount) {
						continue;
					}

					$taxonomies[] = $line[0];

					for ($i = 1; $i < $headerCount-1; $i += 2) {

						$key     = count($rules) +1;
						$qty     =  str_replace(',','.', $line[$i]);
						$amount  = $line[$i + 1];
						$amount  = str_replace(['"', ','],['','.'], $amount);

						$rules[$key] = $this->createRule($qty, '', $amount);
					}
				}
			}
			$id = sprintf('set_xcore_exact_item_group_%s', $fileName);
			$priceListData[$id] = $this->getWdpBaseData($priceListName, $roles, $taxonomies, $rules);

			$this->saveTierPrices($priceListData);
		} catch (\Error $exception) {
			echo '<div class="notice notice-success"><p>Something went wrong.</p></br><b>'.$exception->getMessage().'</b></div>';
			return;
		}
		echo '<div class="notice notice-success"><p>Import voltooid.</p></div>';
	}

	/**
	 * Should be moved as well
	 *
	 * @param $tabs
	 *
	 * @return mixed
	 */
	public function addExactItemGroupDynamicPricingTab($tabs)
	{
		$tabs['xcore_exact_itemgroup'] = [
			'tab_title' => 'Exact Itemgroups',
			'tabs' => [
				[
					'title'       => 'Taxonomy Pricing',
					'description' => __( 'Use taxonomy pricing to configure bulk price adjustments based on a product\'s taxonomy. Taxonomy pricing rules will apply before Membership (role-based pricing discounts), and will be cumulative with any Membership rules by default.   The cumulative filter can be used to change this behavior.', 'woocommerce-dynamic-pricing' ),
					'function'    => 'taxonomy_basic_tab'
				],
				[
					'title'       => 'Advanced Taxonomy Pricing',
					'description' => __( 'Use advanced taxonomy pricing to configure price adjustments on items in a customers cart based on quantities.  Adjustments are calculated when the rule matches the configured quantities and will be applied to all items in the cart matching the selected taxonomy.   
					     Advanced category adjustments take precedence over bulk taxonomy adjustments.', 'woocommerce-dynamic-pricing' ),
					'function'    => 'taxonomy_advanced_tab'
				]
			]
		];

		return $tabs;
	}


	private function saveTierPrices($tierPrices)
	{
		$optionKey = '_a_taxonomy_xcore_exact_itemgroup_pricing_rules';
		$data      = get_option($optionKey);

		if ($data && is_array( $data ) && count( $data ) > 0 ) {
			foreach ($tierPrices as $value) {
				$x = array_key_first( $value);
				$data[$x] = $value[$x];
			}
		}
		return update_option($optionKey, $data);
	}

	public function processOptions($request)
	{
		$key        = $request->get_param('id');
		$data       = $request->get_param('options');
		$optionName = '_a_category_pricing_rules';

		if ($request->get_param('exact_itemgroup')) {
			$setKey         = array_key_first($data[0]);
			$priceListData  = $data[0][$setKey];
			$collectorTerms = $priceListData['collector']['args']['cats'];
			$targetTerms    = $priceListData['targets'];

			$priceListData['collector'] = [
				'args' => [
					'cats' => $this->checkTaxonomyTerms($collectorTerms)
				]
			];
			$priceListData['targets'] = $this->checkTaxonomyTerms( $targetTerms);

			$data[0][$setKey] = $priceListData;
			$optionName       = '_a_taxonomy_xcore_exact_itemgroup_pricing_rules';
		}
		$sets = get_option($optionName);

		if (!$sets) {
			return update_option($optionName, $data[0]);
		}

		if ($sets && is_array( $sets ) && count( $sets ) > 0 ) {
			foreach ($data as $value) {
				$x = array_key_first( $value);
				$sets[$x] = $value[$x];
			}
		}
		return update_option($optionName, $sets);
	}

	private function checkTaxonomyTerms($terms)
	{
		foreach ($terms as $index => $term) {
			if (!is_numeric($term)) {
				if ($result = term_exists( $term, 'xcore_exact_itemgroup')) {
					$terms[$index] = $result['term_id'];
				} else {
					$result = wp_insert_term($term, 'xcore_exact_itemgroup');
					if (!is_wp_error($result)) {
						$terms[$index] = $result['term_id'];
					}
				}
			}
		}
		return $terms;
	}

}
