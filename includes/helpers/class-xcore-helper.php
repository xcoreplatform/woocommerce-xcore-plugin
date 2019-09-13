<?php

defined( 'ABSPATH' ) || exit;

class Xcore_Helper extends Xcore_Data_Helper
{
    /**
     * @param $data
     * @param $key
     */
    public function add_tax_rate(&$data, $key)
    {
        if(array_key_exists($key, $data)) {
            foreach($data[$key] as &$item) {
                foreach($item['taxes'] as &$tax) {
                    $rates = ($key == 'shipping_lines' && WC()->cart) ? WC_Tax::get_shipping_tax_rates() : WC_Tax::get_rates( $item['tax_class']);
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
}