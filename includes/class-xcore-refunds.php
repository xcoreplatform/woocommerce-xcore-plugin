<?php
defined('ABSPATH') || exit;

class Xcore_Refunds extends WC_REST_Order_Refunds_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'refunds';

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
        parent::__construct();
    }

    public function init()
    {
        add_action('rest_api_init', function () {
            register_rest_route($this->namespace, $this->base, array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            ));

            register_rest_route($this->namespace, 'orders' . '/(?P<order_id>[\d]+)' . '/' . $this->base . '/(?P<id>[\d]+)', array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => $this->get_collection_params(),
            ));

            register_rest_route($this->namespace, 'orders' . '/(?P<order_id>[\d]+)' . '/' . $this->base, array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            ));
        });
    }

    public function get_items($request)
    {
        if (isset($request['order_id'])) {
            return parent::get_items($request);
        }

        $limit = (int)$request['limit'] ?: 50;

        $field = 'post_modified_gmt';
        $date  = $request['date_modified'] ?: '2001-01-01 00:00:00';

        $orders = new WP_Query(array(
                                   'numberposts'    => -1,
                                   'post_type'      => 'shop_order_refund',
                                   'post_status'    => array_keys(wc_get_order_statuses()),
                                   'posts_per_page' => $limit,
                                   'orderby'        => $field,
                                   'order'          => 'ASC',
                                   'date_query'     => array(
                                       array(
                                           'column' => $field,
                                           'after'  => $date
                                       )
                                   )
                               ));

        $result = [];

        foreach ($orders->get_posts() as $order) {
            $data['id']            = $order->ID;
            $data['parent_id']     = $order->post_parent;
            $data['date_created']  = new WC_DateTime($order->post_date_gmt);
            $data['date_modified'] = new WC_DateTime($order->post_modified_gmt);

            $result[] = $data;
        }

        return $result;
    }

    public function get_item($request)
    {
        $refund_object = $this->get_object($request['id']);
        $response      = $this->prepare_object_for_response($refund_object, $request);

        return $response;
    }

    public function prepare_object_for_response($object, $request)
    {
        $this->request       = $request;
        $this->request['dp'] = is_null($this->request['dp']) ? wc_get_price_decimals() : absint($this->request['dp']);
        $order               = wc_get_order((int)$request['order_id']);

        if (!$order) {
            return new WP_Error('woocommerce_rest_invalid_order_id', __('Invalid order ID.', 'woocommerce'), 404);
        }

        if (!$object || $object->get_parent_id() !== $order->get_id()) {
            return new WP_Error('woocommerce_rest_invalid_order_refund_id', __('Invalid order refund ID.', 'woocommerce'), 404);
        }

        $data    = $this->get_formatted_item_data($object);
        $context = !empty($request['context']) ? $request['context'] : 'view';
        $data    = $this->add_additional_fields_to_object($data, $request);
        $data    = $this->filter_response_by_context($data, $context);

        // Wrap the data in a response object.
        $response = rest_ensure_response($data);

        // Add original order to response
        $orderInstance                    = new Xcore_Orders();
        $orderInstance->request           = $request;
        $order_data                       = $orderInstance->get_formatted_item_data($order);
        $response->data['parent_id']      = $order->get_id();
        $response->data['original_order'] = $order_data;

        $response->add_links($this->prepare_links($object, $request));

        /**
         * Filter the data for a response.
         *
         * The dynamic portion of the hook name, $this->post_type,
         * refers to object type being prepared for the response.
         *
         * @param WP_REST_Response $response The response object.
         * @param WC_Data $object            Object data.
         * @param WP_REST_Request $request   Request object.
         */
        return apply_filters("woocommerce_rest_prepare_{$this->post_type}_object", $response, $object, $request);
    }

    protected function get_formatted_item_data($object)
    {
        $data              = $object->get_data();
        $format_decimal    = array('amount');
        $format_date       = array('date_created');
        $format_line_items = array('line_items', 'shipping_lines');

        // Format decimal values.
        foreach ($format_decimal as $key) {
            $data[$key] = wc_format_decimal($data[$key], $this->request['dp']);
        }

        // Format date values.
        foreach ($format_date as $key) {
            $datetime            = $data[$key];
            $data[$key]          = wc_rest_prepare_date_response($datetime, false);
            $data[$key . '_gmt'] = wc_rest_prepare_date_response($datetime);
        }

        // Format line items.
        foreach ($format_line_items as $key) {
            $data[$key] = array_values(array_map(array($this, 'get_order_item_data'), $data[$key]));
        }

        return array(
            'id'               => $object->get_id(),
            'date_created'     => $data['date_created'],
            'date_created_gmt' => $data['date_created_gmt'],
            'amount'           => $data['amount'],
            'reason'           => $data['reason'],
            'refunded_by'      => $data['refunded_by'],
            'refunded_payment' => $data['refunded_payment'],
            'meta_data'        => $data['meta_data'],
            'line_items'       => $data['line_items'],
            'shipping_lines'   => $data['shipping_lines'],
        );
    }
}