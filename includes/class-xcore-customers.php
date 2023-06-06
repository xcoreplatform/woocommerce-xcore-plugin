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
            $this->base . '/meta',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'find_by_meta'),
                'permission_callback' => array($this, '__return_true'),
                'args'                => $this->get_collection_params(),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'create_item'),
                'permission_callback' => array($this, 'create_item_permissions_check'),
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'update_item'),
                'permission_callback' => array($this, 'update_item_permissions_check'),
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/roles',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_roles'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            )
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            array(
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
            )
        );
    }

    public function get_roles($request)
    {
        global $wp_roles;
        return $wp_roles->role_names;
    }

    public function find_by_meta($request)
    {
        return parent::get_items($request);
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

        $limit                = 50;
        $timezoneOffset       = wc_timezone_offset();
        $filter_date_modified = '2001-01-01 00:00:00';

        if (isset($request['limit']) && $request['limit']) {
            $limit = (int)$request['limit'];
        }

        if (isset($request['date_modified']) && $request['date_modified']) {
            $filter_date_modified = $request['date_modified'];
        }
        $value = str_ireplace('T', ' ', $filter_date_modified);

        $wp_users_table = $wpdb->users;
        $wp_user_meta   = $wpdb->usermeta;

        $q = "
            SELECT ID as id, user_registered as date_created, 
                CASE 
                    WHEN meta_value IS NOT NULL THEN DATE_SUB(FROM_UNIXTIME(meta_value), INTERVAL %s SECOND)
                    ELSE user_registered 
                END AS date_modified
            FROM {$wp_users_table} AS users 
            LEFT JOIN (
                SELECT user_id, meta_key, meta1.meta_value
                FROM {$wp_user_meta} AS meta1 
                WHERE meta1.meta_key = 'last_update'
            ) AS meta ON (users.ID = meta.user_id) 
            HAVING date_modified > %s
            ORDER BY date_modified ASC LIMIT %d
        ";

        $sql     = $wpdb->prepare($q, array($timezoneOffset, $value, $limit));
        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results;
    }
}