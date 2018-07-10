<?php
defined( 'ABSPATH' ) || exit;

class Xcore_Customers extends WC_REST_Customers_Controller
{
	protected static $_instance = null;
	public $version = '1';
	public $namespace = 'wc-xcore/v1';
	public $base = 'customers';

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->init();
	}

	public function init() 
	{
		add_action('rest_api_init', function() {
			register_rest_route( $this->namespace, $this->base, array(   					
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'            => $this->get_collection_params(),		
			));	

			register_rest_route( $this->namespace, $this->base . '/(?P<id>[\d]+)', array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
			));	
		});
	}

	public function get_items( $request ) {
		global $wpdb;   		

   		$limit = (int) $request['limit']?: 50;


   		$key = 'date_modified';	   		
   		$value = $request['date_modified'] ?? 0;
   		$condition = '>';

   		$wp_users_table = $wpdb->prefix . 'users';
   		$wp_user_meta = $wpdb->prefix . 'usermeta';

   		$q = "
   		SELECT 
		ID as id, 		  
	    user_registered as date_created, 
	    CASE 
	    WHEN meta_value IS NOT NULL
	    THEN FROM_UNIXTIME(meta_value, '%%Y-%%m-%%d %%h:%%i:%%s')
	    ELSE user_registered END
	    AS %s
		FROM {$wp_users_table}
			AS users 
		    LEFT JOIN (
				SELECT user_id, meta_key, meta1.meta_value
				FROM {$wp_user_meta} AS meta1 
		        WHERE meta1.meta_key = 'last_update'
		        ) as meta on users.ID = meta.user_id 
			WHERE users.user_registered > %s
		    OR (FROM_UNIXTIME(meta.meta_value, '%%Y-%%m-%%d %%h:%%i:%%s') > %s)
	    
		ORDER BY $key ASC LIMIT %d
		";

		$sql = $wpdb->prepare($q, array($key, $value, $value, $limit));
		$results = $wpdb->get_results($sql, ARRAY_A); 	

		foreach($results as $key => $value) { 				
			$results[$key]['date_created'] = new WC_DateTime($value['date_created']);
			$results[$key]['date_modified'] = new WC_DateTime($value['date_modified']);
		}
		return $results;
	}

}