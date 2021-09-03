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
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/batch',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'batch_items'],
                'permission_callback' => [$this, 'batch_items_permissions_check'],
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
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

        register_rest_route(
            $this->namespace,
            'products/categories',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_product_categories'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            $this->namespace,
            'products/categories' . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_product_category'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function batch_items($request)
    {
        return parent::batch_items($request);
    }

    public function create_item($request)
    {
        $file = $this->processMedia($request);
        if ($file) {
            $request = $this->attachFile($file, $request);
        }

        return parent::create_item($request);
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
     * @return array
     */
    public function get_items($request)
    {
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
        $object = $this->get_object((int)$request['id']);

        if (!$object) {
            return new WP_Error('404', 'No item found with ID: ' . $request['id'], ['status' => '404']);
        }

        if (isset($request['stock_quantity'])) {
            return $this->updateStock($request, $object);
        }

        $file = $this->processMedia($request);
        if ($file) {
            $request = $this->attachFile($file, $request);
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

        if ($file) {
            $this->cleanUp($file);
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

            return ['product_category' => apply_filters('woocommerce_api_product_category_response', $product_category, $id, null, $term, $this)];
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

    public function filter_stock_updates($data, $postarr, $unsanitized_postarr)
    {
        if (isset($postarr['post_modified_gmt'])) {
            $data['post_modified_gmt'] = $postarr['post_modified_gmt'];
        }
        return $data;
    }

    private function processMedia($request)
    {
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

        return new WP_REST_Response($request['stock_quantity'], 200);
    }

    private function attachFile($file, $request)
    {
        $images = [];
        if ($file) {
            $images[]['src']   = $file;
            $request['images'] = $images;
        }

        return $request;
    }

    private function cleanUp($file)
    {
        unlink($file);
    }
}