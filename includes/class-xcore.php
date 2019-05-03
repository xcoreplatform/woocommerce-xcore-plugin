<?php

defined('ABSPATH') || exit;

class Xcore
{
    private          $_version         = '1.5.1';
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
        $this->includes();
        $this->products();
        $this->customers();
        $this->orders();
        $this->refunds();
        $this->shipping_methods();
        $this->payment_methods();
        $this->tax_classes();
        $this->init();
    }

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
        });
    }

    public function xcore_api_version($data)
    {
        return $this->_version;
    }

    public function includes()
    {
        include_once dirname(__FILE__) . '/class-xcore-products.php';
        include_once dirname(__FILE__) . '/class-xcore-customers.php';
        include_once dirname(__FILE__) . '/class-xcore-orders.php';
        include_once dirname(__FILE__) . '/class-xcore-refunds.php';
        include_once dirname(__FILE__) . '/class-xcore-shipping-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-payment-methods.php';
        include_once dirname(__FILE__) . '/class-xcore-tax-classes.php';
        include_once dirname(__FILE__) . '/helpers/class-xcore-helper.php';
    }

    public function products()
    {
        Xcore_Products::instance();
    }

    public function customers()
    {
        Xcore_Customers::instance();
    }

    public function orders()
    {
        Xcore_Orders::instance();
    }

    public function refunds()
    {
        Xcore_Refunds::instance();
    }

    public function shipping_methods()
    {
        Xcore_Shipping_Methods::instance();
    }

    public function payment_methods()
    {
        Xcore_Payment_Methods::instance();
    }

    public function tax_classes()
    {
        Xcore_Tax_Classes::instance();
    }
}
