<?php

defined('ABSPATH') || exit;

class Xcore_Helper extends Xcore_Data_Helper
{
    /*
     * A list of plugins that we (could) support or could cause conflicts. The intended
     * use is to provide us with extra insight if something goes wrong.
     */
    private $check_plugins = [
        'pricelist'    => [
            'WooCommerce Wholesale Prices'         => 'woocommerce-wholesale-prices/woocommerce-wholesale-prices.bootstrap.php',
            'WooCommerce Wholesale Prices Premium' => 'woocommerce-wholesale-prices-premium/woocommerce-wholesale-prices-premium.bootstrap.php',
            'Woocommerce Wholesale Pro'            => 'woocommerce-wholesale-pro/woocommerce-wholesale-pro.php',
            'Dynamic Pricing'                      => 'woocommerce-dynamic-pricing/woocommerce-dynamic-pricing.php',
        ],
        'productTypes' => [
            'WooCommerce product bundles' => 'woocommerce-product-bundles.php',
        ],
        'translation'  => [
            'WooCommerce Multilingual (WPML)' => 'woocommerce-multilingual/wpml-woocommerce.php',
            'WPML Media'                      => 'wpml-media-translation/plugin.php',
        ],
        'multiStore'   => [
            'WooCommerce Multistore' => 'woocommerce-multistore.php',
        ],
        'security'     => [
            'iThemes Security' => 'better-wp-security/better-wp-security.php',
        ],
        'misc'         => [
            'Custom order numbers for woocommerce' => 'custom-order-numbers-for-woocommerce.php',
        ],
    ];

    /**
     * @param $data
     * @param $key
     */
    public function add_tax_rate(&$data, $key)
    {
        if (array_key_exists($key, $data)) {
            foreach ($data[$key] as &$item) {
                foreach ($item['taxes'] as &$tax) {
                    $rates   = ($key == 'shipping_lines' && WC()->cart) ? WC_Tax::get_shipping_tax_rates() : WC_Tax::get_rates($item['tax_class']);
                    $rate    = 0.0000;
                    $rate_id = null;

                    if (isset($tax['id']) && $tax['id']) {
                        $rate_id = $tax['id'];
                    }

                    try {
                        $rate = WC_Tax::get_rate_percent($rate_id);
                        $rate = str_replace('%', '', $rate);
                    } catch (Exception $e) {
                        $rate = 0.0000;
                    }

                    $tax['rate'] = (isset($rates[$rate_id])) ? number_format($rates[$rate_id]['rate'], 4) : number_format($rate, 4);
                }
            }
        }
    }

    /**
     * @param bool $add_plugin_data
     *
     * @return stdClass
     */
    public function get_info($add_plugin_data = false)
    {
        $info            = new stdClass();
        $info->wordpress = $this->get_site_info();
        $add_plugin_data !== true ?: $info->plugins = $this->check_plugins();

        $info->misc = [
            'current_time'     => current_time('mysql', false),
            'current_time_gmt' => current_time('mysql', true),
        ];

        return $info;
    }

    private function get_site_info()
    {
        global $wp_version;
        $info = $this->get_base_data();

        try {
            $info['base'] = [
                "version"             => $wp_version,
                "plugin_version"      => Xcore::get_instance()->xcore_api_version(null),
                "woocommerce_version" => WC()->version,
                "multisite"           => is_multisite(),
                //"rest_url"            => get_rest_url(),
                "theme"               => get_stylesheet(),
                "permalink_structure" => get_option('permalink_structure'),
            ];

            $info['dir'] = [
                //"upload_dir"  => wp_get_upload_dir(),
                "wp_temp_dir" => get_temp_dir(),
            ];

            $info['timezone'] = [
                //'wp_timezone_string'          => wp_timezone_string(),
                //'wp_timezone'                 => wp_timezone(),
                'wp_timezone_override_offset' => wp_timezone_override_offset(),
                'wc_timezone_offset'          => wc_timezone_offset(),
            ];
        } catch (Exception $e) {
            return $info;
        }

        return $info;
    }

    private function set_dummy_prop($prop, $data = null)
    {
        $this->data[$prop] = $data;
    }

    public function convertDate($prop, $date)
    {
        $this->set_dummy_prop($prop);
        $this->set_date_prop($prop, $date);
        return wc_rest_prepare_date_response($this->data[$prop]);
    }

    private function check_plugins()
    {
        $pluginData    = [];

        foreach ($this->check_plugins as $category => $plugins) {
            $pluginData[$category] = $this->getPluginData($plugins);
        }

        return $pluginData;
    }

    private function getPluginData($plugins)
    {
        $data = [];
        foreach ($plugins as $name => $path) {
            $isActive = is_plugin_active($path);

            $x['name'] = $name;
            $x['code'] = dirname($path);
            $x['active']  = $isActive;
            $x['version']  = 'unavailable';
            if($isActive) {
                $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $path, false, false);
                $x['version']  = $pluginData['Version'];
            }
            $data[] = $x;
        }
        return $data;
    }

    private function get_base_data()
    {
        $data['base'] = [
            'plugin_version'      => Xcore::get_instance()->xcore_api_version(null),
            "version"             => '',
            "woocommerce_version" => '',
            "multisite"           => '',
            "rest_url"            => '',
            "theme"               => '',
            "permalink_structure" => '',
        ];

        $data['dir'] = [
            "upload_dir"  => '',
            "wp_temp_dir" => '',
        ];

        $data['timezone'] = [
            'wp_timezone_string'          => '',
            'wp_timezone'                 => '',
            'wp_timezone_override_offset' => '',
            'wc_timezone_offset'          => '',
        ];

        return $data;
    }
}