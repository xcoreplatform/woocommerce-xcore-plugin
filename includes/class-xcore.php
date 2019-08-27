<?php

defined('ABSPATH') || exit;

class Xcore
{
    private          $_version         = '1.6.2';
    protected static $_instance        = null;
    protected static $_productInstance = null;

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
     * Initiate rest_api_init and listen for product updates
     * of a variation and update the date/time
     */

    public function init()
    {
        add_filter('woocommerce_before_product_object_save', function ($product) {
            if ($product->get_type() == 'variation') {
                $date = new WC_DateTime(current_time('mysql'));
                $product->set_date_modified($date);
            }
            return $product;
        }, 10, 1);

        add_action('rest_api_init', function () {
            register_rest_route('wc-xcore/v1', 'version', array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'xcore_api_version'),
            ));

            $this->includes();
            $this->init_classes();
        });
    }

    /**
     * @param WP_REST_Request $request
     * @return string
     */

    public function xcore_api_version($request)
    {
        return $this->_version;
    }

    /**
     * Include all classes
     */

    public function includes()
    {
        include_once dirname(__FILE__) . '/class-xcore-products.php';
        include_once dirname(__FILE__) . '/class-xcore-product-attributes.php';
        include_once dirname(__FILE__) . '/class-xcore-product-attribute-terms.php';
        include_once dirname(__FILE__) . '/class-xcore-customers.php';
        include_once dirname(__FILE__) . '/class-xcore-orders.php';
        include_once dirname(__FILE__) . '/class-xcore-refunds.php';
        include_once dirname(__FILE__) . '/class-xcore-shipping-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-payment-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-tax-classes.php';
        include_once dirname(__FILE__) . '/helpers/class-xcore-helper.php';
    }

    /**
     * Initiate all classes to register the necessary routes
     */
    public function init_classes()
    {
        $classes = array(
            'Xcore_Products',
            'Xcore_Product_Attributes',
            'Xcore_Product_Attribute_Terms',
            'Xcore_Customers',
            'Xcore_Orders',
            'Xcore_Refunds',
            'Xcore_Shipping_Methods',
            'Xcore_Payment_Methods',
            'Xcore_Tax_Classes'
        );

        foreach($classes as $class) {
            $this->$class = new $class();
        }
    }
}
