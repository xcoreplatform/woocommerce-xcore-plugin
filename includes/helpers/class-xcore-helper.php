<?php

defined( 'ABSPATH' ) || exit;

class Xcore_Helper {

    static public function add_tax_rate(&$data, $key)
    {
        if(array_key_exists($key, $data)) {
            foreach($data[$key] as &$item) {
                foreach($item['taxes'] as &$tax) {
                    $rates = ($key == 'shipping_lines') ? WC_Tax::get_shipping_tax_rates() : WC_Tax::get_rates( $item['tax_class']);
                    $rate_id = $tax['id'] ?? null;
                    $rate = 0.0000;

                    try {
                        $rate = WC_Tax::get_rate_percent($rate_id);
                        $rate = str_replace('%', '', $rate);
                    } catch (Exception $e) {
                        $rate = 0.0000;
                    }

                    $tax['rate'] = (isset($rates[$rate_id])) ? number_format($rates[$rate_id]['rate'], 4): number_format($rate, 4);
                }
            }
        }

    }

}