<?php
defined('ABSPATH') || exit;

class Xcore_Customers extends WC_REST_Customers_Controller
{
    protected static $_instance    = null;
    public           $version      = '1';
    public           $namespace    = 'wc-xcore/v1';
    public           $base         = 'customers';
    private          $_xcoreHelper = null;

    public function __construct($helper)
    {
        $this->_xcoreHelper = $helper;
        $this->init();
    }

    /**
     * Register all customer routes
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

        register_rest_route($this->namespace, $this->base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'update_item'),
            'permission_callback' => array($this, 'update_item_permissions_check'),
            'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
        ));

        register_rest_route($this->namespace, $this->base . '/roles', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_roles'),
            'permission_callback' => array($this, 'get_items_permissions_check'),
            'args'                => $this->get_collection_params(),
        ));

        register_rest_route($this->namespace, $this->base . '/(?P<id>[\d]+)', array(
            'args' => array(
                'id' => array(
                    'description' => __('Unique identifier for the resource.', 'woocommerce'),
                    'type'        => 'integer',
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => array(
                    'context' => $this->get_context_param(array('default' => 'view')),
                ),
            ),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return array|object|WP_Error|WP_REST_Response|null
     * @throws Exception
     */
    public function get_items($request)
    {
        global $wpdb;

        /**
         * If e-mail is given with the request, let woocommerce handle it.
         */
        if ($request['email']) {
            return parent::get_items($request);
        }

        $limit = (int)$request['limit'] ?: 50;

        $key   = 'date_modified';
        $value = $request['date_modified'] ?: 0;
        $value = str_ireplace('T', ' ', $value);

        $wp_users_table = $wpdb->prefix . 'users';
        $wp_user_meta   = $wpdb->prefix . 'usermeta';

        $q = "
            SELECT ID as id, user_registered as date_created, 
                CASE 
                    WHEN meta_value IS NOT NULL THEN FROM_UNIXTIME(meta_value)
                    ELSE user_registered 
                END AS date_modified
            FROM {$wp_users_table} AS users 
            LEFT JOIN (
                SELECT user_id, meta_key, meta1.meta_value
                FROM {$wp_user_meta} AS meta1 
                WHERE meta1.meta_key = 'last_update'
            ) AS meta ON (users.ID = meta.user_id) 
            HAVING date_modified > %s
            ORDER BY 'date_modified' ASC LIMIT %d
		";

        $sql     = $wpdb->prepare($q, array($key, $value, $value, $limit));
        $results = $wpdb->get_results($sql, ARRAY_A);

        foreach ($results as $key => $value) {
            $results[$key]['date_created'] = $value['date_created'];
            $results[$key]['date_modified'] = $this->_xcoreHelper->convertDate('date_modified', $value['date_modified']);
        }
        return $results;
    }

}