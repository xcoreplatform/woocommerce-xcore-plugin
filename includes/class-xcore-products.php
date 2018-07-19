<?php
defined( 'ABSPATH' ) || exit;

class Xcore_Products extends WC_REST_Products_Controller
{
	protected static $_instance = null;
	public $version = '1';
	public $namespace = 'wc-xcore/v1';
	public $base = 'products';

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
        $this->init();
	    parent::__construct();
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
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'            => $this->get_collection_params(),		
			));

			register_rest_route( $this->namespace, $this->base . '/findby/sku/(?P<id>[\S]+)', array(   					
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array( $this, 'find_item_by_sku' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'            => $this->get_collection_params(),		
			));
			
			register_rest_route( $this->namespace, $this->base . '/(?P<id>[\d]+)', array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
			));

			register_rest_route( $this->namespace, 'products/categories', array(   					
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array($this, 'get_product_categories' ),								
			));	

			register_rest_route( $this->namespace, 'products/categories'  . '/(?P<id>[\d]+)', array(   					
				'methods'         => WP_REST_Server::READABLE,
				'callback'        => array($this, 'get_product_category' ),								
			));
					
		});
	}

	/**
	 * Get a collection of items
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$limit = (int) $request['limit'] ?: 50;

   		$field = 'post_modified_gmt';	
   		$date = $request['date_modified'] ?: '2001-01-01 00:00:00';

		$products = new WP_Query(array(
				'numberposts' => -1,
				'post_type' => array('product','product_variation'),
				'posts_per_page' => $limit,
				'orderby' => $field,
				'order' => 'ASC',
				'date_query' => array(
					array(
						'column' => $field,
						'after' => $date
					)
				)
			));

		$result = [];

		foreach($products->get_posts() as $product) {
			$data['id'] = $product->ID;
			$data['type'] = $product->post_type;
			$data['parent'] = $product->post_parent;
			$data['date_created'] = new WC_DateTime($product->post_date_gmt);
			$data['date_modified'] = new WC_DateTime($product->post_modified_gmt);

			$result[] = $data;
		}

		return $result;
	}

    public function update_item($request)
    {
        $object = $this->get_object( (int) $request['id'] );
        if ($object->is_type('variation')) {
            $product = new WC_Product_Variation($request['id']);
            $product->set_stock_quantity($request['stock_quantity']);
            $product->set_manage_stock($request['manage_stock']);
            $product->set_stock_status();
            $product->save();

            return parent::prepare_object_for_response($product, $request);
        }

        return parent::update_item($request);
    }

    public function find_item_by_sku($request) {
		$type = 'sku';
		$meta_key = '';
		$product_reference = $request['id'];

		if($type == 'sku')
			$meta_key = '_sku';
		
		global $wpdb;

  		$product_id = $wpdb->get_var( 
  			$wpdb->prepare( 
  				"SELECT post_id 
  				FROM $wpdb->postmeta 
  				WHERE meta_key='%s' 
  				AND meta_value='%s'", 
  				$meta_key, 
  				$product_reference
  			) 
  		);

  		if(!$product_id)
  			return null;

  		$object = parent::get_object($product_id);
		return parent::prepare_object_for_response($object, $request);	
	}	

	public function get_product_categories($request) {
		$orderby = 'name';
		$order = 'asc';
		$hide_empty = false ;

		$cat_args = array(
		    'orderby'   	=> $orderby,
		    'order'     	=> $order,
		    'hide_empty' 	=> $hide_empty,
		    'fields' 		=> 'ids',
		);

		$terms = get_terms('product_cat', $cat_args );

		foreach ( $terms as $term_id ) {
			$product_categories[] = current( $this->get_product_category($request, $term_id) );
		}
		return apply_filters( 'woocommerce_api_product_categories_response', $product_categories, $terms, null, $this );	
	}

	public function get_product_category($request, $id = null)
	{
		if(!isset($id)) {
			$id = $request['id'];
		}

		try {
			$id = absint( $id );

			// Validate ID
			if ( empty( $id ) ) {
				throw new WC_API_Exception( 'woocommerce_api_invalid_product_category_id', __( 'Invalid product category ID', 'woocommerce' ), 400 );
			}

			// Permissions check
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				throw new WC_API_Exception( 'woocommerce_api_user_cannot_read_product_categories', __( 'You do not have permission to read product categories', 'woocommerce' ), 401 );
			}

			$term = get_term( $id, 'product_cat' );

			if ( is_wp_error( $term ) || is_null( $term ) ) {
				throw new WC_API_Exception( 'woocommerce_api_invalid_product_category_id', __( 'A product category with the provided ID could not be found', 'woocommerce' ), 404 );
			}

			$term_id = intval( $term->term_id );

			// Get category display type
			$display_type = get_woocommerce_term_meta( $term_id, 'display_type' );

			// Get category image
			$image = '';
			if ( $image_id = get_woocommerce_term_meta( $term_id, 'thumbnail_id' ) ) {
				$image = wp_get_attachment_url( $image_id );
			}

			$product_category = array(
				'id'          => $term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => $term->parent,
				'description' => $term->description,
				'display'     => $display_type ? $display_type : 'default',
				'image'       => $image ? esc_url( $image ) : '',
				'count'       => intval( $term->count ),
			);

			return array( 'product_category' => apply_filters( 'woocommerce_api_product_category_response', $product_category, $id, null, $term, $this ) );
		} catch ( WC_API_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}
}