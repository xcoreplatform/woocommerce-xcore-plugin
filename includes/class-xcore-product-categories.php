<?php

class Xcore_Product_Categories extends WC_REST_Product_Categories_Controller
{
    /**
     * @var Xcore_Product_Categories
     */
    protected static $_instance;
    public           $namespace = 'wc-xcore/v1';
    public           $rest_base = 'products/categories';

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct()
    {
        $this->register_routes();
    }

    public function get_items($request)
    {
        $request['per_page'] = 250;
        return parent::get_items($request);
    }
}