<?php

defined('ABSPATH') || exit;

class Xcore_Products extends WC_REST_Products_Controller
{
    protected static $_instance    = null;
    public           $version      = '1';
    public           $namespace    = 'wc-xcore/v1';
    public           $base         = 'products';
    private          $_xcoreHelper = null;

    public function __construct($helper)
    {
        $this->_xcoreHelper = $helper;
        parent::__construct();
        $this->init();
        $this->initHooks();
    }

    /**
     * Register all product routes
     */
    public function init()
    {
        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'create_item_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/batch',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'batch_items'],
                'permission_callback' => [$this, 'batch_items_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/allby/sku/(?P<sku>[\S]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_all_items'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'search_item'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/findby/sku/(?P<id>[\S]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'find_item_by_sku'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
            ]
        );

        register_rest_route(
            $this->namespace,
            'products/types',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_product_types'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function initHooks()
    {
        add_filter( 'pre_get_posts', [ $this, 'xcoreGetAllProductTypes' ], 10, 1 );
        add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'addProductMeta' ], 20, 2 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object',[ $this, 'addProductMeta' ], 20,3 );
    }

	/**
	 * We use a single call to retrieve a list of products to process. This adds
	 * both product and product_variation to our query to obtain a complete list
	 * of products without the need for a second call. This also makes it easier
	 * to update variations without the need to process all variations for a
	 * specific variable product.
	 *
	 * @param WP_Query $query
	 *
	 * @return WP_Query
	 */
    public function xcoreGetAllProductTypes(WP_Query $query)
	{
		if (is_array($query->query_vars['post_type']) && !in_array('product_variation', $query->query_vars['post_type'], true)) {
			$query->query_vars['post_type'][] = 'product_variation';
		} elseif ($query->query_vars['post_type'] === 'product') {
			$query->query_vars['post_type'] = ['product', 'product_variation'];
		}

		return $query;
	}

    public function addProductMeta( $response, $product ) {
		if ( $response->data['status'] == 'draft' && $response->data['date_created'] === null ) {
			$response->data['date_created']     = $response->data['date_modified'];
			$response->data['date_created_gmt'] = $response->data['date_modified_gmt'];
		}

		if ( $product instanceof WC_Product_Variation ) {
			$response->data['xcore_is_variation'] = true;
		} else {
			$response->data['xcore_is_variation'] = false;
		}

		return $response;
	}

    public function batch_items($request)
    {
        return parent::batch_items($request);
    }

	public function create_item( $request ) {
		if ( $request->get_param( 'type' ) == 'variation' && ! $request->get_param( 'parent_id' ) ) {
			return new WP_Error( 'woocommerce_rest_missing_variation_data',
				__( 'Missing parent ID.', 'woocommerce' ),
				400 );
		}

		$controller = $request->get_param( 'type' ) == 'variation' ? new Xcore_Product_Variations( $this->_xcoreHelper ) : null;
        $file = $this->processMedia($request);

        if ($file) {
            $request = $this->attachFile($request, $file);
        }

		if ( $request->get_param( 'type' ) == 'variation' ) {
			$request->set_param( 'product_id', $request->get_param( 'parent_id' ) );
		}

		return $controller ? $controller->create_item( $request ) : parent::create_item( $request );
    }

    /**
     * Sadly there's no way of obtaining a list with all available product types, including (custom) variations.
     * This allows us to bypass the problem if a customer wants to process (custom) variations as well, without
     * impacting performance.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */

    public function get_item($request)
    {
        $result = parent::get_item($request);
        if (is_wp_error($result)) {
            return $result;
        }

        /**
         * I attempted to use the Xcore_Product_Variations::get_items() when dealing with variations, but I noticed
         * (which I should have known had I read the API documention concerning available variation properties) some
         * key attributes are missing when using it (such as type).
         * To avoid missing out on data (added by woocommerce or by any other plug-in) and lose the ability to process
         * specific custom product types, we should leave it as is.
         */
        $class  = WC_Product_Factory::get_classname_from_product_type($result->data['type']);
        $object = new $class(null);

        if ($object instanceof WC_Product_Variation) {
            $result->data['xcore_is_variation'] = true;
        } else {
            $result->data['xcore_is_variation'] = false;
        }

        return $result;
    }

    /**
     * Returns an array with item information
     *
     * @param WP_REST_Request $request Full data about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_items($request)
    {
		return parent::get_items( $request );
        $request->set_param( 'post_type', [ 'product', 'product_variation' ]);
        $request->set_param( 'orderby', 'modified ID');
        $request->set_param( 'order', 'asc');
        return parent::get_items( $request );

		$request->set_param( 'order', 'asc');
		$request->set_param( 'orderby', 'modified ID');
		$request->set_param( 'post_type', [ 'product', 'product_variation' ]);
		$request->set_param( 'status', [ 'any' ]);

//        $defaultParams = [
//			'order'    => 'asc',
//			'orderby'  => 'modified ID',
//			'per_page' => 250,
//			'status'   => [ 'any' ],
//			'page'     => 1,
//		];
//
//		$request->set_default_params( $defaultParams );

		if ( version_compare( WC()->version, '5.8.0', '>=' ) ) {
			if ( $request['date_modified'] ) {
				$date                      = $request['date_modified'];
				$request['modified_after'] = $date;
			}
		}

		return parent::get_items( $request );

        $limit        = 50;
        $date         = '2001-01-01 00:00:00';
        $product_only = isset($request['product_only']) ? (int)$request['product_only'] : 0;

        if (isset($request['limit']) && $request['limit']) {
            $limit = (int)$request['limit'];
        }

        if (isset($request['date_modified']) && $request['date_modified']) {
            $date = $request['date_modified'];
        }

        $products = new WP_Query(
            [
                'numberposts'    => -1,
                'post_type'      => $product_only ? ['product'] : ['product', 'product_variation'],
                'posts_per_page' => $limit,
                'orderby'        => 'post_modified',
                'order'          => 'ASC',
                'date_query'     => [
                    [
                        'column'    => 'post_modified_gmt',
                        'after'     => $date,
                        'inclusive' => true,
                    ],
                ],
            ]
        );

        $result = [];

        foreach ($products->get_posts() as $product) {
            $data['id']            = $product->ID;
            $data['sku']           = get_post_meta($product->ID, '_sku', true) ?: null;
            $data['type']          = (get_the_terms($product->ID, 'product_type')[0]) ? get_the_terms($product->ID, 'product_type')[0]->name : null;
            $data['parent']        = $product->post_parent;
            $data['date_created']  = new WC_DateTime($product->post_date_gmt);
            $data['date_modified'] = new WC_DateTime($product->post_modified_gmt);

            $result[] = $data;
        }

        return $result;
    }

    public function get_all_items($request)
    {
        if (!isset($request['sku'])) {
            return new WP_Error('404', 'No SKU set', ['status' => '404']);
        }

        return parent::get_items($request);
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_item($request)
    {
        /** @var WC_Product|null|false $object */
        $object = $this->get_object((int)$request['id']);

        if (!$object) {
            return new WP_Error('404', 'No item found with ID: ' . $request['id'], ['status' => '404']);
        }

        apply_filters('xcore_rest_product_update_request', $request);

        if (isset($request['stock_quantity'])) {
            return $this->updateStock($request, $object);
        }

        $wpDirectories = wp_get_upload_dir();
        $baseDir  = $wpDirectories['basedir'];
        $baseUrl  = $wpDirectories['baseurl'];

        $files = [];
        if ( isset( $request['xcore_media'] ) ) {
            $files = $this->processFiles($request['xcore_media'], $baseDir);

            if(is_wp_error($files)) {
                return $files;
            }

            $this->attachFiles($request, $files, $baseUrl, $object);
        }

        if ($object->is_type('variation')) {
            if (!$object->get_parent_id()) {
                return new WP_Error('woocommerce_rest_missing_variation_data', __('Missing parent ID.', 'woocommerce'), 400);
            }
            $request->set_param('product_id', $object->get_parent_id());

            $controller = new Xcore_Product_Variations($this->_xcoreHelper);
            $response   = $controller->update_item($request);
        } else {
            $response = parent::update_item($request);
        }

        if ($files) {
            $this->cleanUp($files, $baseDir);
        }

        return $response;
    }

    /**
     * @param $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function search_item($request)
    {
        $sku = $request->has_param('sku') ? $request->get_param('sku') : null;

        $product_id = wc_get_product_id_by_sku($sku);
        $object     = parent::get_object($product_id);

        if ($object) {
            $result = parent::prepare_object_for_response($object, $request);

            if ($object instanceof WC_Product_Variation) {
                $result->data['xcore_is_variation'] = true;
            } else {
                $result->data['xcore_is_variation'] = false;
            }
            return $result;
        }
        return new WP_Error('404', 'No item found with SKU: ' . $sku, ['status' => '404']);
    }

    /**
     * @param $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function find_item_by_sku($request)
    {
        $product_reference = urldecode($request['id']);
        $product_id        = wc_get_product_id_by_sku($product_reference);

        if (!$product_id) {
            return new WP_Error('404', 'No item found with SKU: ' . $product_reference, ['status' => '404']);
        }

        $request['id'] = $product_id;
        return $this->get_item($request);
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function get_product_types($request)
    {
        return wc_get_product_types();
    }

    /**
     * @param $request
     *
     * @return mixed|void
     */
    public function get_product_categories($request)
    {
        $orderby    = 'name';
        $order      = 'asc';
        $hide_empty = false;

        $cat_args = [
            'orderby'    => $orderby,
            'order'      => $order,
            'hide_empty' => $hide_empty,
            'fields'     => 'ids',
        ];

        $terms = get_terms('product_cat', $cat_args);

        foreach ($terms as $term_id) {
            $product_categories[] = current($this->get_product_category($request, $term_id));
        }
        return apply_filters('woocommerce_api_product_categories_response', $product_categories, $terms, null, $this);
    }

    /**
     * @param      $request
     * @param null $id
     *
     * @return array|WP_Error
     */
    public function get_product_category($request, $id = null)
    {
        if (!isset($id)) {
            $id = $request['id'];
        }

        try {
            $id = absint($id);

            // Validate ID
            if (empty($id)) {
                throw new WC_REST_Exception('woocommerce_api_invalid_product_category_id', __('Invalid product category ID', 'woocommerce'), 400);
            }

            // Permissions check
            if (!current_user_can('manage_product_terms')) {
                throw new WC_REST_Exception(
                    'woocommerce_api_user_cannot_read_product_categories',
                    __('You do not have permission to read product categories', 'woocommerce'),
                    401
                );
            }

            $term = get_term($id, 'product_cat');

            if (is_wp_error($term) || is_null($term)) {
                throw new WC_REST_Exception(
                    'woocommerce_api_invalid_product_category_id',
                    __('A product category with the provided ID could not be found', 'woocommerce'),
                    404
                );
            }

            $term_id = intval($term->term_id);

            // Get category display type
            $display_type = function_exists('get_term_meta') ? get_term_meta($term_id, 'display_type', true) : get_metadata(
                'woocommerce_term',
                $term_id,
                'display_type',
                true
            );

            // Get category image
            $image    = '';
            $image_id = function_exists('get_term_meta') ? get_term_meta($term_id, 'thumbnail_id', true) : get_metadata(
                'woocommerce_term',
                $term_id,
                'thumbnail_id',
                true
            );
            if ($image_id) {
                $image = wp_get_attachment_url($image_id);
            }

            $product_category = [
                'id'          => $term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent'      => $term->parent,
                'description' => $term->description,
                'display'     => $display_type ? $display_type : 'default',
                'image'       => $image ? esc_url($image) : '',
                'count'       => intval($term->count),
            ];

			return [
				'product_category' => apply_filters( 'woocommerce_api_product_category_response',
					$product_category,
					$id,
					null,
					$term,
					$this )
			];
        } catch (WC_API_Exception $e) {
            return new WP_Error($e->getErrorCode(), $e->getMessage(), ['status' => $e->getCode()]);
        }
    }

    public function upload_item_media($media, $uploadBasedir)
    {
        $type    = $media['media_type'];
        $sku     = $media['formatted_sku'];
        $tmpDir  = $uploadBasedir;
        $tmpFile = 'tmp_xcore_' . $sku . '.' . $media['file_extension'];

        switch ($type) {
            case 'image':
                $base64Image = $media['media_data_base64_encoded'];
                $imageData   = base64_decode($base64Image);
                $file        = file_put_contents($tmpDir . "/" . $tmpFile, $imageData);

                if ($file) {
                    return $tmpFile;
                }
                return false;
                break;
        }
        return false;
    }

	public function filter_stock_updates( $data, $postarr, $unsanitized_postarr ) {
        if (isset($postarr['post_modified_gmt'])) {
			$data['post_modified_gmt'] = wc_rest_prepare_date_response( $postarr['post_modified_gmt'] );
        }
        return $data;
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

	private function attachFiles( $request, $files, $baseUrl, $product = null ) {
		$images = $product ? $this->get_images( $product ) : [];

        if (isset($files['productImage']) && $files['productImage']) {
            $currentImage     = current($images);
            $currentImageName = $currentImage && isset($currentImage['name']) ? $currentImage['name'] : null;

            if ($currentImageName && !$this->fileAlreadyExists($files['productImage'], [$currentImageName]) ) {
                array_unshift($images, [ "src" => sprintf('%s/%s', $baseUrl, $files['productImage']) ]);
            }
        }

        if (isset($files['images']) && is_array($files['images'])) {
            $currentImageNames = array_column($images, 'name');
            foreach ($files['images'] as $image) {
                if ($this->fileAlreadyExists($image, $currentImageNames)) {
                    continue;
                }
                $images[] = [ "src" => sprintf('%s/%s', $baseUrl, $image) ];
            }
        }

        $request->set_param( 'images', $images );

        if (isset($files['downloads']) && is_array($files['downloads'])) {
            $downloads            = $product ? $this->get_downloads( $product ) : [];
            $currentDownloadNames = array_column($downloads, 'name');
            foreach ($files['downloads'] as $file) {
                if ( in_array( $file, $currentDownloadNames, true ) ) {
                    continue;
                }

                $downloads[] = [
                    "name" => $file,
                    "file" => sprintf('%s/%s', $baseUrl, $file)
                ];
            }

            $request->set_param( 'downloadable', true );
            $request->set_param( 'downloads', $downloads );
        }
	}

	private function fileAlreadyExists($file, $currentFiles)
    {
        $tmpFile = pathinfo(str_replace(' ', '20', $file), PATHINFO_FILENAME);

        foreach ($currentFiles as $currentFile) {
            if (strpos($currentFile, $tmpFile) === 0)
            {
                return true;
            }
        }
        return false;
    }

	private function processFiles( $files, $location )
	{
		$setAsProductImage = false;
        /**
         *
         * Compatibility fix. Single product images are associative arrays while
         * documents are multidimensional arrays
         *
         * */
        $first = reset($files);
        if ($first && !is_array($first)) {
            $setAsProductImage = true;
            $files = [$files];
        }

        $savedFiles = [
            'productImage' => null,
            'images'       => [],
            'downloads'    => [],
        ];

        foreach ( $files as $file ) {
            $extension = $file['file_extension'];

            if ( ! isset( $file['original_filename'] ) ) {
                $fileName = sprintf( 'tmp_xcore_%s.%s', $file['formatted_sku'], $extension );
            } else {
                $fileName = $file['original_filename'];
            }

            $filePath = sprintf( '%s/%s', $location, $fileName );
            $fileData = base64_decode( $file['media_data_base64_encoded'] );

            if ( file_put_contents( $filePath, $fileData ) === false ) {
                continue;
            }

            if (isset( $file['set_as_product_image'] )) {
                $setAsProductImage = (bool) $file['set_as_product_image'];
            }


            if (wp_getimagesize($filePath) !== false) {
                if ($setAsProductImage) {
                    $savedFiles['productImage'] = $fileName;
                } else {
                    $savedFiles['images'][] = $fileName;
                }
            } else {
                $savedFiles['downloads'][] = $fileName;
            }
        }
        return $savedFiles;
	}

	private function processMedia( $request ) {
        if (!isset($request['xcore_media'])) {
            return false;
        }

        $dirs = wp_get_upload_dir();
        $file = $this->upload_item_media($request['xcore_media'], $dirs['basedir']);

        if ($file) {
            return $dirs['baseurl'] . "/" . $file;
        }

        return false;
    }

    private function updateStock($request, $product)
    {
        add_filter('wp_insert_post_data', [$this, 'filter_stock_updates'], 10, 3);
        $date    = $product->get_date_modified();
        $product->set_stock_quantity($request['stock_quantity']);
        $product->set_date_modified((string)$date);
        $product->save();

        remove_filter('wp_insert_post_data', [$this, 'filter_stock_updates']);

		return new WP_REST_Response( $request['stock_quantity'], 200 );
	}

	private function attachFile( $request, $file, $product = null ) {
		$images    = $product ? $this->get_images( $product ) : [];
		$images[0] = [ "src" => $file ];

		$request->set_param( 'images', $images );

		return $request;
    }

    private function cleanUp($files, $baseDir)
    {
        if (isset($files['productImage'])) {
            $file = $files['productImage'];

            if (!is_string($file)) {
                wp_die($file);
            }
            unlink( sprintf('%s/%s', $baseDir, $files['productImage']) );
        }

        if (isset($files['images']) && $files['images']) {
            foreach ($files['images'] as $image) {
                unlink( sprintf('%s/%s', $baseDir, $image));
            }
        }

        if (isset($files['downloads']) && $files['downloads']) {
            foreach ($files['downloads'] as $file) {
                unlink(sprintf('%s/%s', $baseDir, $file));
            }
        }
    }
}
