<?php
defined('ABSPATH') || exit;

class Xcore_Orders extends WC_REST_Orders_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'orders';

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

    public function init()
    {
        add_action('rest_api_init', function () {
            register_rest_route($this->namespace, $this->base, array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            ));

            register_rest_route($this->namespace, $this->base . '/(?P<id>[\d]+)', array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => $this->get_collection_params(),
            ));
        });
    }

    public function get_items($request)
    {
        $limit = (int)$request['limit'] ?: 50;

        $field = 'post_modified_gmt';
        $date  = $request['date_modified'] ?: '2001-01-01 00:00:00';

        $orders = new WP_Query(array(
                                   'numberposts'    => -1,
                                   'post_type'      => 'shop_order',
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
            $data['date_created']  = new WC_DateTime($order->post_date_gmt);
            $data['date_modified'] = new WC_DateTime($order->post_modified_gmt);

            $result[] = $data;
        }

        return $result;
    }
}