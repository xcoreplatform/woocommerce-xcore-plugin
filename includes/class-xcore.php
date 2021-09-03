<?php

defined('ABSPATH') || exit;

class Xcore
{
    private        $_version     = '1.10.2';
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
    }

    /**
     * Initiate rest_api_init and listen for product updates
     * of a variation and update the date/time
     */
    public function init()
    {
        add_filter(
            'woocommerce_before_product_object_save',
            function($product, $dataStore) {
                if (has_filter('wp_insert_post_data', [Xcore_Products::class, 'filter_stock_updates'])) {
                    return $product;
                }

                if ($product->get_type() == 'variation') {
                    $date = new WC_DateTime(current_time('mysql'));
                    $product->set_date_modified($date);
                }
                return $product;
            },
            10,
            2
        );

        add_action(
            'rest_api_init',
            function() {
                register_rest_route(
                    'wc-xcore/v1',
                    'version',
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [$this, 'xcore_api_version'],
                        'permission_callback' => '__return_true',
                    ]
                );

                register_rest_route(
                    'wc-xcore/v1',
                    'info',
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [$this, 'get_website_info'],
                        'permission_callback' => [$this, 'get_items_permissions_check'],
                    ]
                );

                $this->includes();
                $this->init_classes();
            }
        );
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return string
     */

    public function xcore_api_version($request)
    {
        return $this->_version;
    }

    public function get_website_info($request)
    {
        $include_plugin_data = isset($request['include_plugin_data']) ? (bool)$request['include_plugin_data'] : false;
        return $this->_xcoreHelper->get_info($include_plugin_data);
    }

    /**
     * Include all classes
     */
    public function includes()
    {
        include_once dirname(__FILE__) . '/class-xcore-products.php';
        include_once dirname(__FILE__) . '/class-xcore-product-variations.php';
        include_once dirname(__FILE__) . '/class-xcore-product-attributes.php';
        include_once dirname(__FILE__) . '/class-xcore-product-attribute-terms.php';
        include_once dirname(__FILE__) . '/class-xcore-customers.php';
        include_once dirname(__FILE__) . '/class-xcore-orders.php';
        include_once dirname(__FILE__) . '/class-xcore-refunds.php';
        include_once dirname(__FILE__) . '/class-xcore-shipping-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-payment-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-tax-classes.php';
        include_once dirname(__FILE__) . '/class-xcore-documents.php';
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
            'Xcore_Customers',
            'Xcore_Orders',
            'Xcore_Refunds',
            'Xcore_Shipping_Methods',
            'Xcore_Payment_Methods',
            'Xcore_Tax_Classes',
            'Xcore_Documents',
        ];

        foreach ($classes as $class) {
            $this->$class = new $class($this->_xcoreHelper);
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
}
