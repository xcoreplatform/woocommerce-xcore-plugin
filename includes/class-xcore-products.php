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
        register_rest_route($this->namespace, $this->base, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_items'),
            'permission_callback' => array($this, 'get_items_permissions_check'),
            'args'                => $this->get_collection_params(),
        ));

        register_rest_route($this->namespace, $this->base, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'create_item'),
            'permission_callback' => array($this, 'create_item_permissions_check'),
            'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
        ));

        register_rest_route($this->namespace, $this->base, array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'update_item'),
            'permission_callback' => array($this, 'update_item_permissions_check'),
            'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
        ));

        register_rest_route($this->namespace, $this->base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_item'),
            'permission_callback' => array($this, 'get_item_permissions_check'),
            'args'                => $this->get_collection_params(),
        ));

        register_rest_route($this->namespace, $this->base . '/findby/sku/(?P<id>[\S]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'find_item_by_sku'),
            'permission_callback' => array($this, 'get_item_permissions_check'),
            'args'                => $this->get_collection_params(),
        ));

        register_rest_route($this->namespace, $this->base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'update_item'),
            'permission_callback' => array($this, 'update_item_permissions_check'),
            'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
        ));

        register_rest_route($this->namespace, 'products/types', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_product_types'),
        ));

        register_rest_route($this->namespace, 'products/categories', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_product_categories'),
        ));

        register_rest_route($this->namespace, 'products/categories' . '/(?P<id>[\d]+)', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_product_category'),
        ));
    }

    /**
     * Sadly there's no way of obtaining a list with all available product types, including variations. This allows
     * us to bypass the problem if a customer wants to process variations as well without impacting performance.
     *
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */

    public function get_item($request)
    {
        $result = parent::get_item($request);
        if (is_wp_error($result)) {
            return $result;
        }

        $class  = WC_Product_Factory::get_classname_from_product_type($result->data['type']);
        $object = new $class;

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
     * @return array
     */
    public function get_items($request)
    {
        $limit = (int)$request['limit'] ?: 50;
        $date  = $request['date_modified'] ?: '2001-01-01 00:00:00';

        $products = new WP_Query(array(
                                     'numberposts'    => -1,
                                     'post_type'      => array('product', 'product_variation'),
                                     'posts_per_page' => $limit,
                                     'orderby'        => 'post_modified',
                                     'order'          => 'ASC',
                                     'date_query'     => array(
                                         array(
                                             'column' => 'post_modified_gmt',
                                             'after'  => $date
                                         )
                                     )
                                 ));

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

    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function update_item($request)
    {
        $object = $this->get_object((int)$request['id']);

        if (!$object) {
            return new WP_Error('404', 'No item found with ID: ' . $request['id'], array('status' => '404'));
        }

        if ($object->is_type('variation')) {
            $product = new WC_Product_Variation($request['id']);

            if (isset($request['stock_quantity'])) {
                $product->set_stock_quantity($request['stock_quantity']);
            }

            if (isset($request['manage_stock'])) {
                $product->set_manage_stock($request['manage_stock']);
            }

            if (isset($request['backorders'])) {
                $product->set_backorders($request['backorders']);
            }

            $product->save();

            return parent::prepare_object_for_response($product, $request);
        }

        return parent::update_item($request);
    }

    /**
     * @param $request
     * @return WP_Error|WP_REST_Response
     */
    public function find_item_by_sku($request)
    {
        $product_reference = urldecode($request['id']);
        $product_id        = wc_get_product_id_by_sku($product_reference);
        $object            = parent::get_object($product_id);

        if ($object) {
            $result = parent::prepare_object_for_response($object, $request);

            if ($object instanceof WC_Product_Variation) {
                $result->data['xcore_is_variation'] = true;
            } else {
                $result->data['xcore_is_variation'] = false;
            }
            return $result;
        }
        return new WP_Error('404', 'No item found with SKU: ' . $product_reference, array('status' => '404'));
    }

    /**
     * @param WP_REST_Request $request
     * @return array
     */
    public function get_product_types($request)
    {
        return wc_get_product_types();
    }

    /**
     * @param $request
     * @return mixed|void
     */
    public function get_product_categories($request)
    {
        $orderby    = 'name';
        $order      = 'asc';
        $hide_empty = false;

        $cat_args = array(
            'orderby'    => $orderby,
            'order'      => $order,
            'hide_empty' => $hide_empty,
            'fields'     => 'ids',
        );

        $terms = get_terms('product_cat', $cat_args);

        foreach ($terms as $term_id) {
            $product_categories[] = current($this->get_product_category($request, $term_id));
        }
        return apply_filters('woocommerce_api_product_categories_response', $product_categories, $terms, null, $this);
    }

    /**
     * @param $request
     * @param null $id
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
                throw new WC_API_Exception('woocommerce_api_invalid_product_category_id', __('Invalid product category ID', 'woocommerce'), 400);
            }

            // Permissions check
            if (!current_user_can('manage_product_terms')) {
                throw new WC_API_Exception('woocommerce_api_user_cannot_read_product_categories', __('You do not have permission to read product categories', 'woocommerce'), 401);
            }

            $term = get_term($id, 'product_cat');

            if (is_wp_error($term) || is_null($term)) {
                throw new WC_API_Exception('woocommerce_api_invalid_product_category_id', __('A product category with the provided ID could not be found', 'woocommerce'), 404);
            }

            $term_id = intval($term->term_id);

            // Get category display type
            $display_type = function_exists('get_term_meta') ? get_term_meta($term_id, 'display_type', true) : get_metadata('woocommerce_term', $term_id, 'display_type', true);

            // Get category image
            $image    = '';
            $image_id = function_exists('get_term_meta') ? get_term_meta($term_id, 'thumbnail_id', true) : get_metadata('woocommerce_term', $term_id, 'thumbnail_id', true);
            if ($image_id) {
                $image = wp_get_attachment_url($image_id);
            }

            $product_category = array(
                'id'          => $term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent'      => $term->parent,
                'description' => $term->description,
                'display'     => $display_type ? $display_type : 'default',
                'image'       => $image ? esc_url($image) : '',
                'count'       => intval($term->count),
            );

            return array('product_category' => apply_filters('woocommerce_api_product_category_response', $product_category, $id, null, $term, $this));
        } catch (WC_API_Exception $e) {
            return new WP_Error($e->getErrorCode(), $e->getMessage(), array('status' => $e->getCode()));
        }
    }
}