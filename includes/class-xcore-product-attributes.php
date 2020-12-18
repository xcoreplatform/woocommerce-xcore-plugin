<?php

class Xcore_Product_Attributes extends WC_REST_Product_Attributes_Controller
{
    protected static $_instance = null;
    public           $version   = '1';
    public           $namespace = 'wc-xcore/v1';
    public           $base      = 'products/attributes';

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

    /**
     * Register all product attribute routes
     */
    private function init()
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
            $this->base . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args'                => array(
                    'context' => $this->get_context_param(array('default' => 'view')),
                ),
            )
        );
    }

    /**
     * Get all available product attributes. This includes an option to add all attribute options
     * in the same call, to limit requests.
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public function get_items($request)
    {
        $attributes = parent::get_items($request);

        if ($request['add_options'] == true) {
            foreach ($attributes->data as &$attribute) {
                $taxonomy             = wc_attribute_taxonomy_name_by_id($attribute['id']);
                $options              = get_terms($taxonomy);
                $attribute['options'] = $options;
            }
        }
        return $attributes;
    }
}