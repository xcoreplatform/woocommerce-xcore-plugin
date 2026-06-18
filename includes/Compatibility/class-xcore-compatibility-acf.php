<?php

defined( 'ABSPATH' ) || exit;

include_once __DIR__ . '/abstract-xcore-compatibility.php';

class Xcore_Compatibility_ACF extends Abstract_Xcore_Compatibility
{
	private static $pluginName = [
		'advanced-custom-fields/acf.php',
		'advanced-custom-fields-pro/acf.php',
	];

	protected $acf = null;

	public function __construct()
	{
		$this->init();
	}

	public function init()
	{
		if (!$this->isActive(self::$pluginName)) {
			return;
		}

		$this->addHooks();
	}

	private function addHooks()
	{
		add_filter( 'acf/settings/rest_api_enabled', '__return_true' );
		add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'addAcfData' ], 10, 3);
		add_filter( 'xcore_rest_product_update_request', [ $this, 'updateAcfData' ], 10, 1);
	}

	public function updateAcfData(WP_REST_Request $request)
	{
		$acfData = $request->get_param('acf');

		if (!is_array($acfData)) {
			return $request;
		}

		if (!$this->canUpdateAcfFields($request)) {
			return $request;
		}

		$objectId = $request->get_param('id');

		if (empty($objectId)) {
			return $request;
		}

		$errors = false;
		foreach ($acfData as $field => $value) {
			if (!update_field($field, $value, $objectId)) {
				if (!$errors) {
					$errors = new WP_Error();
				}

				$errors->add(400, 'Failed updating ACF field', array('field_name' => $field));
			}
		}

		return $errors ?: $request;
	}

	public function addAcfData(WP_REST_Response $response, $itemObject, WP_REST_Request $request)
	{
		$postId = $request->get_param('id');

		if (empty($postId) || isset($response->data['acf'])) {
			return $response;
		}

		if (!$this->acf()) {
			$this->log('error','Unable to initiate ACF_Rest_Api, not adding ACF fields');
			return $response;
		}

		$dummyRequest = new WP_REST_Request();
		$dummyRequest->set_param('id', $request->get_param('id'));
		$dummyRequest->set_route(sprintf('/wp/v2/posts/%s', $request->get_param('id')));

		$this->acf()->initialize($response, $itemObject, $dummyRequest);
		$fields = $this->acf()->load_fields($itemObject->get_data(), '', $dummyRequest, 'product');
		$response->data['acf'] = $fields;

		return $response;
	}

	private function acf(): ?ACF_Rest_Api
	{
		if (!class_exists(\ACF_Rest_Api::class)) {
			return null;
		}

		if (is_null($this->acf)) {
			$this->acf = new ACF_Rest_Api();
		}
		return $this->acf;
	}

	private function canUpdateAcfFields(WP_REST_Request $request): bool
	{
		if (!function_exists('update_field')) {
			$this->log("error","Unable to update ACF fields, function 'update_field' not available");
			return false;
		}
		return true;
	}
}