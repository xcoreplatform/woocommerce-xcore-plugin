<?php

class Xcore_Custom_Types
{
    protected static $_instance    = null;
    public           $version      = '1';
    public           $namespace    = 'wc-xcore/v1';
    public           $rest_base    = 'custom_types';
    private          $_xcoreHelper = null;

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

	public function init()
	{
		register_rest_route(
            $this->namespace,
            $this->rest_base . '/(?P<type>[\S]+)' . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
            ]
        );

		register_rest_route(
            $this->namespace,
            $this->rest_base . '/(?P<type>[\S]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
            )
        );

        register_rest_route(
            $this->namespace,
            $this->rest_base . '/(?P<type>[\S]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'create_item_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->rest_base . '/(?P<type>[\S]+)' . '/(?P<id>[\d]+)',
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'update_item'),
                'permission_callback' => [$this, 'update_item_permissions_check'],
            )
        );
	}

	public function create_item($request)
	{
		$postType   = $request->get_param('type');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$request->set_param('post_type', $postType);

		return (new WP_REST_Posts_Controller($postType))->create_item($request);
	}

	public function get_item($request)
	{
		$postType = $request->get_param('type');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$request->set_param('post_type', $postType);
		$request->set_param('id', $request['id']);

		return ( new WP_REST_Posts_Controller( $postType ) )->get_item($request);
	}

	public function get_items(WP_REST_Request $request)
	{
		$postType = $request->get_param('type');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$request->set_param('post_type', $postType);
		$controller    = new WP_REST_Posts_Controller($postType);
		$hasPermission = $controller->get_items_permissions_check($request);

        if ($hasPermission !== true) {
            return $hasPermission;
        }

        $perPage = $request->get_param('per_page');

        if (is_numeric($perPage) && $perPage > 1) {
            return $controller->get_items($request);
        }

		$key   = $request->get_param('meta_key');
		$value = $request->get_param('meta_value');

		$args = array(
		    'post_type'      => [$postType],
		    'fields'         => 'ids',
		    'posts_per_page' => 1,
		    'meta_query'  => [
		        [
		            'key'     => $key,
		            'value'   => $value,
		            'compare' => '='
		        ]
		    ]
		);

		if ($postType === 'attachment') {
			$args['post_status'] = ['inherit'];
		}

		if ($request->get_param('exact_match') === false || $request->get_param('exact_match') === 0) {
			$args['meta_query'][0]['compare'] = 'LIKE';
		}

		$posts  = (new WP_Query($args))->get_posts();
		$postId = is_array($posts) ? reset($posts) : $posts;
		if (!$postId || is_wp_error($postId)) {
			return new WP_Error( 'rest_not_found', __( sprintf('%s not found', $postType), 'xcore' ), array( 'status' => 404 ) );
		}

		$request->set_param('id', $postId);

        return $controller->get_item($request);
	}

	public function update_item($request)
	{
		$postType   = $request->get_param('type');
		$post_types = get_post_type_object($postType);

		if (!$post_types) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$request->set_param('post_type', $postType);
		$request->set_param('id', $request['id']);
		$controller = new WP_REST_Posts_Controller($postType);

		$hasPermission = $controller->update_item_permissions_check($request);

		if ($hasPermission !== true) {
			return $hasPermission;
		}

        return $controller->update_item($request);
	}

	public function get_item_permissions_check($request)
	{
		$postType = $request->get_param('type');
		$postId   = $request->get_param('id');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$post = get_post_type_object($postType);

		if (!$post->show_in_rest || !current_user_can($post->cap->edit_post, $postId)) {
			return new WP_Error(
				'woocommerce_rest_cannot_view',
				__('Sorry, you cannot access this resource.', 'xcore'),
				['status' => rest_authorization_required_code()]
			);
		}

		return true;
	}

	public function get_items_permissions_check($request)
	{
		$postType = $request->get_param('type');
		$post     = get_post_type_object($postType);

		if (!$post) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		if (!current_user_can($post->cap->edit_posts)) {
			return new WP_Error(
				'woocommerce_rest_cannot_view',
				__('Sorry, you cannot access this resource.', 'xcore'),
				['status' => rest_authorization_required_code()]
			);
		}

		return true;
	}

	public function update_item_permissions_check($request)
	{
		$postType = $request->get_param('type');
		$postId   = $request->get_param('id');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400] );
		}

		$post = get_post_type_object($postType);

		if (!$post->show_in_rest || !current_user_can($post->cap->edit_post, $postId)) {
			return new WP_Error(
				'woocommerce_rest_cannot_view',
				__('Sorry, you cannot access this resource.', 'xcore'),
				['status' => rest_authorization_required_code()]
			);
		}

		return true;
	}

	public function create_item_permissions_check($request)
	{
		$postId = $request->get_param('id');

		if ($postId) {
			return new WP_Error(
				'rest_post_exists', __('Cannot create existing post.'), array('status' => 400)
			);
		}

		$postType = $request->get_param('type');

		if (!post_type_exists($postType)) {
			return new WP_Error('rest_post_invalid_type', __('Invalid post type.'), ['status' => 400]);
		}

		$post = get_post_type_object($postType);

		if (!$post->show_in_rest || !current_user_can($post->cap->edit_others_posts)) {
			return new WP_Error(
				'rest_cannot_edit_others',
				__('Sorry, you are not allowed to create posts as this user.'),
				array('status' => rest_authorization_required_code())
			);
		}

		return true;
	}
}