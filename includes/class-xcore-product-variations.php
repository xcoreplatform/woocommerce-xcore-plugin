<?php
defined('ABSPATH') || exit;

class Xcore_Product_Variations extends WC_REST_Product_Variations_Controller
{
    public $tmpObject = null;
    public $_xcoreHelper = null;

    public function __construct($helper)
    {
        $this->_xcoreHelper = $helper;
        parent::__construct();
    }
}