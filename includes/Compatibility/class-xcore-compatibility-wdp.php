<?php

defined( 'ABSPATH' ) || exit;

include_once __DIR__ . '/abstract-xcore-compatibility.php';

class Xcore_Compatibility_WDP extends Abstract_Xcore_Compatibility
{
	private static $pluginName = [
		'woocommerce-dynamic-pricing/woocommerce-dynamic-pricing.php',
	];

	public function init()
	{
		if (!$this->isActive(self::$pluginName)) {
			return;
		}

		$this->addHooks();
		$this->addRestRoute();
	}

	private function addRestRoute()
	{
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'wc-xcore/v1',
					'options',
					[
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => [ $this, 'processOptions' ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
					]
				);
			}
		);
	}

	private function addHooks()
	{
		add_filter( 'wc_dynamic_pricing_get_discount_taxonomies', [$this, 'setDynamicPricingDiscountTaxonomies'], 1, 1);
		add_filter( 'wc_dynamic_pricing_get_taxonomy_advanced_class', [$this, 'setDynamicPricingDiscountTaxonomies'], 1, 1);
		add_filter( 'woocommerce_dynamic_pricing_tabs', [$this, 'addExactItemGroupDynamicPricingTab'], 10, 1);
		add_filter( 'woocommerce_dynamic_pricing_is_cumulative', '__return_false');
	}

	public function setDynamicPricingDiscountTaxonomies($taxonomies)
	{
		$this->log('info', 'hit');
		$taxonomies[] = 'xcore_exact_itemgroup';
		return $taxonomies;
	}

	public function addExactItemGroupDynamicPricingTab($tabs)
	{
		$tabs['xcore_exact_itemgroup'] = [
			'tab_title' => 'Exact Itemgroups',
			'tabs' => [
				[
					'title'       => 'Taxonomy Pricing',
					'description' => __( 'Use taxonomy pricing to configure bulk price adjustments based on a product\'s taxonomy. Taxonomy pricing rules will apply before Membership (role-based pricing discounts), and will be cumulative with any Membership rules by default.   The cumulative filter can be used to change this behavior.', 'woocommerce-dynamic-pricing' ),
					'function'    => 'taxonomy_basic_tab'
				],
				[
					'title'       => 'Advanced Taxonomy Pricing',
					'description' => __( 'Use advanced taxonomy pricing to configure price adjustments on items in a customers cart based on quantities.  Adjustments are calculated when the rule matches the configured quantities and will be applied to all items in the cart matching the selected taxonomy.   
					     Advanced category adjustments take precedence over bulk taxonomy adjustments.', 'woocommerce-dynamic-pricing' ),
					'function'    => 'taxonomy_advanced_tab'
				]
			]
		];

		return $tabs;
	}

	public function processOptions($request)
	{
		$key        = $request->get_param('id');
		$data       = $request->get_param('options');
		$optionName = '_a_category_pricing_rules';

		if ($request->get_param('exact_itemgroup')) {
			$setKey         = array_key_first($data[0]);
			$pricelistData  = $data[0][$setKey];
			$collectorTerms = $pricelistData['collector']['args']['cats'];
			$targetTerms    = $pricelistData['targets'];

			$pricelistData['collector'] = [
				'args' => [
					'cats' => $this->checkTaxonomyTerms($collectorTerms)
				]
			];
			$pricelistData['targets'] = $this->checkTaxonomyTerms( $targetTerms);

			$data[0][$setKey] = $pricelistData;
			$optionName       = '_a_taxonomy_xcore_exact_itemgroup_pricing_rules';
		}
		$sets = get_option($optionName);

		if (!$sets) {
			return update_option($optionName, $data[0]);
		}

		if ($sets && is_array( $sets ) && count( $sets ) > 0 ) {
			foreach ($data as $value) {
				$x = array_key_first( $value);
				$sets[$x] = $value[$x];
			}
		}
		return update_option($optionName, $sets);
	}

	private function checkTaxonomyTerms($terms)
	{
		foreach ($terms as $index => $term) {
			if (!is_numeric($term)) {
				if ($result = term_exists( $term, 'xcore_exact_itemgroup')) {
					$terms[$index] = $result['term_id'];
				} else {
					get_term_by('slug', $term, 'xcore_exact_itemgroup')->term_id;
				}
			}
		}
		return $terms;
	}
}