<?php

defined('ABSPATH') || exit;

class Xcore_Products extends WC_REST_Products_Controller
{
    protected static $_instance    = null;
    public           $version      = '1';
    public           $namespace    = 'wc-xcore/v1';
    public           $base         = 'products';
    private          $_xcoreHelper = null;

    public function __construct($helper)
    {
        $this->_xcoreHelper = $helper;
        parent::__construct();
        $this->init();
        $this->initHooks();
    }

    /**
     * Register all product routes
     */
    public function init()
    {
        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'create_item_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/batch',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'batch_items'],
                'permission_callback' => [$this, 'batch_items_permissions_check'],
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/allby/sku/(?P<sku>[\S]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_all_items'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'search_item'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/findby/sku/(?P<id>[\S]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'find_item_by_sku'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => $this->get_collection_params(),
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
                'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
            ]
        );

        register_rest_route(
            $this->namespace,
            'products/types',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_product_types'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function initHooks()
    {
        add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'addProductMeta' ], 20, 2 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object',[ $this, 'addProductMeta' ], 20,3 );
    }

    public function addProductMeta( $response, $product ) {
		if ( $response->data['status'] == 'draft' && $response->data['date_created'] === null ) {
			$response->data['date_created']     = $response->data['date_modified'];
			$response->data['date_created_gmt'] = $response->data['date_modified_gmt'];
		}

		if ( $product instanceof WC_Product_Variation ) {
			$response->data['xcore_is_variation'] = true;
		} else {
			$response->data['xcore_is_variation'] = false;
		}

		return $response;
	}

    public function batch_items($request)
    {
        return parent::batch_items($request);
    }

	public function create_item( $request ) {
		if ( $request->get_param( 'type' ) == 'variation' && ! $request->get_param( 'parent_id' ) ) {
			return new WP_Error( 'woocommerce_rest_missing_variation_data',
				__( 'Missing parent ID.', 'woocommerce' ),
				400 );
		}

		$controller = $request->get_param( 'type' ) == 'variation' ? new Xcore_Product_Variations( $this->_xcoreHelper ) : null;

		if (isset($request['xcore_media'])) {
			$this->processRequestFiles( $request );
		}

		if ( $request->get_param( 'type' ) == 'variation' ) {
			$request->set_param( 'product_id', $request->get_param( 'parent_id' ) );
		}

		return $controller ? $controller->create_item( $request ) : parent::create_item( $request );
    }

    /**
     * Sadly there's no way of obtaining a list with all available product types, including (custom) variations.
     * This allows us to bypass the problem if a customer wants to process (custom) variations as well, without
     * impacting performance.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */

    public function get_item($request)
    {
        $result = parent::get_item($request);
        if (is_wp_error($result)) {
            return $result;
        }

        /**
         * I attempted to use the Xcore_Product_Variations::get_items() when dealing with variations, but I noticed
         * (which I should have known had I read the API documention concerning available variation properties) some
         * key attributes are missing when using it (such as type).
         * To avoid missing out on data (added by woocommerce or by any other plug-in) and lose the ability to process
         * specific custom product types, we should leave it as is.
         */
        $class  = WC_Product_Factory::get_classname_from_product_type($result->data['type']);
        $object = new $class(null);

        if ($object instanceof WC_Product_Variation) {
            $result->data['xcore_is_variation'] = true;
        } else {
            $result->data['xcore_is_variation'] = false;
        }

        return $result;
    }

    /**
     * Returns an array with item information
     *
     * @param WP_REST_Request $request Full data about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_items($request)
    {
		return parent::get_items( $request );
    }

    public function get_all_items($request)
    {
        if (!isset($request['sku'])) {
            return new WP_Error('404', 'No SKU set', ['status' => '404']);
        }

        return parent::get_items($request);
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_item($request)
    {
        /** @var WC_Product|null|false $object */
        $object = $this->get_object((int)$request['id']);

        if (!$object) {
            return new WP_Error('404', 'No item found with ID: ' . $request['id'], ['status' => '404']);
        }

        apply_filters('xcore_rest_product_update_request', $request);

        if (isset($request['stock_quantity'])) {
            return $this->updateStock($request, $object);
        }

        if (isset($request['xcore_media'])) {
            $this->processRequestFiles($request, $object);
        }

        if ($object->is_type('variation')) {
            if (!$object->get_parent_id()) {
                return new WP_Error('woocommerce_rest_missing_variation_data', __('Missing parent ID.', 'woocommerce'), 400);
            }

            $this->setCorrectVariationStatus($request);
            $request->set_param('product_id', $object->get_parent_id());

            $controller = new Xcore_Product_Variations($this->_xcoreHelper);
            $response   = $controller->update_item($request);
        } else {
            $response = parent::update_item($request);
        }

        return $response;
    }

    /**
     * @param $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function search_item($request)
    {
        $sku = $request->has_param('sku') ? $request->get_param('sku') : null;

        $product_id = wc_get_product_id_by_sku($sku);
        $object     = parent::get_object($product_id);

        if ($object) {
            $result = parent::prepare_object_for_response($object, $request);

            if ($object instanceof WC_Product_Variation) {
                $result->data['xcore_is_variation'] = true;
            } else {
                $result->data['xcore_is_variation'] = false;
            }
            return $result;
        }
        return new WP_Error('404', 'No item found with SKU: ' . $sku, ['status' => '404']);
    }

    /**
     * @param $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function find_item_by_sku($request)
    {
        $product_reference = urldecode($request['id']);
        $product_id        = wc_get_product_id_by_sku($product_reference);

        if (!$product_id) {
            return new WP_Error('404', 'No item found with SKU: ' . $product_reference, ['status' => '404']);
        }

        $request['id'] = $product_id;
        return $this->get_item($request);
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function get_product_types($request)
    {
        return wc_get_product_types();
    }

    /**
     * @param $request
     *
     * @return mixed|void
     */
    public function get_product_categories($request)
    {
        $orderby    = 'name';
        $order      = 'asc';
        $hide_empty = false;

        $cat_args = [
            'orderby'    => $orderby,
            'order'      => $order,
            'hide_empty' => $hide_empty,
            'fields'     => 'ids',
        ];

        $terms = get_terms('product_cat', $cat_args);

        foreach ($terms as $term_id) {
            $product_categories[] = current($this->get_product_category($request, $term_id));
        }
        return apply_filters('woocommerce_api_product_categories_response', $product_categories, $terms, null, $this);
    }

    /**
     * @param      $request
     * @param null $id
     *
     * @return array|WP_Error
     */
    public function get_product_category($request, $id = null)
    {
        if (!isset($id)) {
            $id = $request['id'];
        }

        try {
            $id = absint($id);

            // Validate ID
            if (empty($id)) {
                throw new WC_REST_Exception('woocommerce_api_invalid_product_category_id', __('Invalid product category ID', 'woocommerce'), 400);
            }

            // Permissions check
            if (!current_user_can('manage_product_terms')) {
                throw new WC_REST_Exception(
                    'woocommerce_api_user_cannot_read_product_categories',
                    __('You do not have permission to read product categories', 'woocommerce'),
                    401
                );
            }

            $term = get_term($id, 'product_cat');

            if (is_wp_error($term) || is_null($term)) {
                throw new WC_REST_Exception(
                    'woocommerce_api_invalid_product_category_id',
                    __('A product category with the provided ID could not be found', 'woocommerce'),
                    404
                );
            }

            $term_id = intval($term->term_id);

            // Get category display type
            $display_type = function_exists('get_term_meta') ? get_term_meta($term_id, 'display_type', true) : get_metadata(
                'woocommerce_term',
                $term_id,
                'display_type',
                true
            );

            // Get category image
            $image    = '';
            $image_id = function_exists('get_term_meta') ? get_term_meta($term_id, 'thumbnail_id', true) : get_metadata(
                'woocommerce_term',
                $term_id,
                'thumbnail_id',
                true
            );
            if ($image_id) {
                $image = wp_get_attachment_url($image_id);
            }

            $product_category = [
                'id'          => $term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent'      => $term->parent,
                'description' => $term->description,
                'display'     => $display_type ? $display_type : 'default',
                'image'       => $image ? esc_url($image) : '',
                'count'       => intval($term->count),
            ];

			return [
				'product_category' => apply_filters( 'woocommerce_api_product_category_response',
					$product_category,
					$id,
					null,
					$term,
					$this )
			];
        } catch (WC_API_Exception $e) {
            return new WP_Error($e->getErrorCode(), $e->getMessage(), ['status' => $e->getCode()]);
        }
    }

    protected function get_images($product)
    {
        $productImages = parent::get_images( $product );

        foreach ($productImages as $key => $productImage) {
            $productImages[$key]['xcore_attachment_source_id'] = $this->getDocumentAttachmentId($productImage['id']);
        }
        return $productImages;
    }

	/**
	 * We use a single call to retrieve a list of products to process. This adds
	 * both product and product_variation to our query to obtain a complete list
	 * of products without the need for a second call. This also makes it easier
	 * to update variations without the need to process all variations for a
	 * specific variable product.
	 *
	 * @param WP_REST_Request $query
	 *
	 * @return array
	 */
    protected function prepare_objects_query($request)
    {
		$args = parent::prepare_objects_query($request);

		if (is_array($args['post_type']) && !in_array('product_variation', $args['post_type'], true)) {
			$args['post_type'][] = 'product_variation';
		} elseif($args['post_type'] === 'product') {
			$args['post_type'] = ['product', 'product_variation'];
		}

		return $args;
    }

	private function getFilesFromRequest($request)
	{
		$xcoreMedia = $request['xcore_media'];

        /**
         *
         * Compatibility fix. Single product images are associative arrays while
         * documents are multidimensional arrays
         *
         * */
        $first = reset($xcoreMedia);
        if ($first && !is_array($first)) {
            $xcoreMedia['set_as_product_image'] = true;
            $xcoreMedia                         = [$xcoreMedia];
        }

        $filteredMedia = apply_filters('xcore_filter_document_files', $xcoreMedia);

        if (count($xcoreMedia) !== count($filteredMedia)) {
			$before  = array_column($xcoreMedia, 'original_filename');
			$after   = array_column($filteredMedia, 'original_filename');
			$removed = array_diff($before, $after);

			$this->log( 'info', sprintf('%s files (%s) were filtered out and will not be processed', count($removed), implode(', ', $removed)));
        }

        if (!$filteredMedia) {
            return null;
        }

		return $filteredMedia;
	}

	private function processFeaturedImage($file, $product)
	{
		if (!$product instanceof WC_Product) {
			return $this->processFile($file, $product);
		}

		$imageId   = $product->get_image_id();
		$imagePost = get_post($imageId);

		if (!$imagePost) {
			return $this->processFile($file, $product);
		}

		$sanitizedFilename = $this->getSanitizedFileName($file['original_filename']);
		$imagePostFilename = $this->getFileNameFromPost($imagePost);
		if ($imagePostFilename && ($imagePostFilename === $sanitizedFilename)) {
			$this->log( 'debug', sprintf('Product has a featured image with the same name (%s), deleting before proceeding.', $imagePostFilename));
			$this->deleteProductAttachments($imageId);
		}

		return $this->processFile($file, $product);
	}

	/*
	 * get all product attachments
	 * delete all without xcore_document_source_id
	 * upload new
	 * add all to product gallery
	 */

	private function processRequestFiles($request, $product = null):void
    {
        $files = $this->getFilesFromRequest($request);

		if (!$files) {
			// Nothing to process.
			return;
		}

		$filesProcessed      = $this->processFiles($files, $product);
		$newProductDownloads = $filesProcessed->productDownloads;
		$newProductImages    = $filesProcessed->productImages;

		if (isset($filesProcessed->featuredImage)) {
			array_unshift( $newProductImages, $filesProcessed->featuredImage);
		}

		$currentDownloads    = $product ? array_column($this->get_downloads($product), 'id') : [];

		if ($newProductDownloads) {
			foreach ($currentDownloads as $downloadId) {
				/**
				 * Do not delete attachments that were added manually by checking if xcore_document_attachment_id is set.
				 */
				if (!$this->getDocumentAttachmentId($downloadId)) {
					$newProductDownloads[] = $downloadId;
				}
			}
		}

		$newProductDownloads = array_unique($newProductDownloads);

		if ($newProductDownloads) {
			$this->deleteProductAttachments(array_diff($currentDownloads, $newProductDownloads));
		}

		$restApiImageIds = [];
		foreach ($newProductImages as $newProductImage) {
			$restApiImageIds[] = ['id' => $newProductImage];
		}
		$this->log('debug', sprintf('Added %s images', count($newProductImages)));

		$restApiDownloadIds = [];
		foreach ($newProductDownloads as $newProductDownload) {
			$sourceUrl = wp_get_attachment_url($newProductDownload);

			$restApiDownloadIds[] = [
				'id'   => $newProductDownload,
				'name' => basename($sourceUrl),
				'file' => $sourceUrl
			];
		}
		$this->log('debug', sprintf('Added %s downloads', count($newProductDownloads)));

		unset($request['xcore_media']);

		if ($restApiImageIds) {
			$request->set_param('images', $restApiImageIds);
		}

		if ($restApiDownloadIds) {
			$request->set_param('downloads', $restApiDownloadIds);
			$request->set_param('downloadable', true);
		}
    }

	private function processFiles($files, $product): stdClass
	{
		$currentImages = $this->getAllProductImageAttachments($product);
		$fileContainer = new stdClass();

		$fileContainer->productImages    = [];
		$fileContainer->productDownloads = [];
		foreach ($files as $file) {
			$setAsProductImage    = $file['set_as_product_image'] ?? null;
			$filename             = $this->getSanitizedFileName($file['original_filename']);
			$existingGalleryImage = $currentImages[$filename] ?? null;
			$orphanPost           = $this->findOrphansByFilename($filename);

			if ($existingGalleryImage) {
				$this->log( 'debug', sprintf('Found existing image %s on product, deleting before proceeding.', $filename));
				$this->deleteProductAttachments($existingGalleryImage);
				unset($currentImages[$filename]);
			} elseif ($orphanPost && isset($orphanPost->ID)) {
					$this->deleteProductAttachments($orphanPost->ID);
			}

			if ($setAsProductImage) {
				$wpAttachmentId = $this->processFeaturedImage($file, $product);
			} else {
				$wpAttachmentId = $this->processFile($file, $product);
			}

			$imagePost     = get_post($wpAttachmentId);
			$imagePostFile = $this->getFileNameFromPost($imagePost);
			if ($imagePostFile !== $filename) {
				$this->log( 'debug', sprintf('Filename %s changed to %s after upload, deleting %s', $filename, $imagePostFile, $imagePostFile));
				$this->deleteProductAttachments($wpAttachmentId);
				continue;
			}

			if (!$this->isValidAttachmentId($wpAttachmentId)) {
				$this->log( 'debug', sprintf('Invalid attachment %s, skipping.', $filename));
				continue;
			}

			if (!wp_attachment_is_image($wpAttachmentId)) {
				$fileContainer->productDownloads[] = $wpAttachmentId;
				continue;
			}

			if ($setAsProductImage) {
				$fileContainer->featuredImage = $wpAttachmentId;
				$currentImageId               = $product->get_image_id();

				foreach ($currentImages as $key => $imageId) {
					if ($currentImageId === $imageId) {
						unset($currentImages[$key]);
						$this->deleteProductAttachments($imageId);
					}
				}
			} else {
				$fileContainer->productImages[] = $wpAttachmentId;
			}
		}

		$fileContainer->productImages = array_unique(array_merge($fileContainer->productImages, array_values($currentImages)));

		return $fileContainer;
	}
	private function checkAttachmentFileExistence($attachmentId)
	{
		if (!$attachmentId) {
			return false;
		}

		$imagePath = get_attached_file($attachmentId);

		if (file_exists($imagePath)) {
			return $attachmentId;
		}

		$this->log( 'debug', sprintf('File %s does not exist, deleting attachment %s', $imagePath, $attachmentId));
		$this->deleteProductAttachments($attachmentId);

		return false;
	}

	private function getExistingAttachmentFromFile($file)
	{
		$postId = $file['product_file_id'] ?? null;

		if ($this->checkAttachmentFileExistence($postId)) {
			return $postId;
		}

		$postId = $this->findAttachtmentById($file['attachment_source_id'] ?? null);

		return $this->checkAttachmentFileExistence($postId);
	}
    private function processFile($file, $product)
    {
		$attachmentId = $this->getExistingAttachmentFromFile($file);

		if ($attachmentId) {
			$this->log( 'debug', sprintf('Existing file %s (%s) found', $file, $attachmentId));
			return $attachmentId;
		}

        return $this->saveFileAsAttachment($file, $product ? $product->get_id() : null);
    }

	public function filter_stock_updates($data, $postarr, $unsanitized_postarr) {
        if (isset($postarr['post_modified_gmt'])) {
			$data['post_modified_gmt'] = wc_rest_prepare_date_response( $postarr['post_modified_gmt'] );
        }
        return $data;
    }

    /**
	 * Set alternate default values
	 */
	public function get_collection_params()
	{
		$params                             = parent::get_collection_params();
		$params['per_page']['default']      = 50;
		$params['order']['default']         = 'asc';
		$params['orderby']['default']       = 'modified';
		$params['dates_are_gmt']['default'] = true;
		$params['type'][]                   = 'variation';
		return $params;
	}

	public function get_item_schema()
	{
		$schema = parent::get_item_schema();

		/*
		 * Add variation as enum to avoid rest_not_in_enum errors
		 */
		$schema['properties']['type']['enum'][] = 'variation';

		return $schema;
	}

	private function hasDuplicateFileName($fileName)
	{
		$imageId = $this->getProductImageIdByFileName($fileName);

		if (!$imageId) {
			return null;
		}

		return $imageId instanceof WP_Post ? $imageId->ID : $imageId;
	}

	private function findOrphansByFilename($filename)
	{
		$args = [
				'post_type'   => ['attachment'],
				'post_status' => ['inherit'],
				's'           =>  $filename,
				'orderby'     => 'date',
				'order'       => 'asc',
			];
		$result = (new WP_Query($args))->get_posts();

		if (!$result || is_wp_error($result)) {
			return null;
		}
		return reset($result);
	}

	private function getProductImageIdByFileName($filename)
	{
		$result = get_children(
			[
				'title'       =>  $filename,
				'post_type'   => ['attachment'],
		        'post_status' => ['inherit'],
			]
		);

		if (!$result || is_wp_error($result)) {
			return null;
		}
		return is_array($result) ? reset($result) : null;
	}

	/*
	 * In some rare cases we do not know we're dealing with a variation and set the
	 * catalog_visibility to show/hide a product. This method remedies this problem by
	 * checking if catalog_visibility is sent with the request and set the status based
	 * on its value.
	 */
	private function setCorrectVariationStatus($request)
	{
		$visibility = $request->get_param('catalog_visibility');

        if ($visibility) {
            $request->set_param('status', $visibility === 'visible' ? 'publish' : 'private');
        }
	}

	private function isValidAttachmentId($imageId):bool
	{
		if (!$imageId || is_wp_error($imageId)) {
			return false;
		}

		$imagePath = get_attached_file($imageId);

		if (!file_exists($imagePath)) {
			$this->log( 'debug', sprintf('File %s does not exist', $imagePath));
			return false;
		}
		return true;
	}

    private function deleteProductAttachments($ids):void
    {
        if (is_numeric($ids)) {
            $ids = [$ids];
        }

        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
			if ($id instanceof WP_Post) {
				$id = $id->ID;
			}

			$file = get_attached_file($id);

            if (wp_delete_attachment($id, true)) {
                $this->log( 'debug', sprintf('Attachment %s (%s) has been deleted',$file, $id));
            } else {
                $this->log( 'debug', sprintf('Unable to delete attachment %s (%s)',$file, $id));
            }
        }
    }

    private function findAttachtmentById($id)
    {
        if (!$id) {
            return null;
        }

		$args = array(
		    'post_type'      => ['attachment'],
		    'post_status'    => ['inherit'],
		    'fields'         => 'ids',
		    'posts_per_page' => 1,
		    'meta_query'  => [
		        [
		            'key'     => 'xcore_document_attachment_id',
		            'value'   => $id,
		            'compare' => '='
		        ]
		    ]
		);

		$result = (new WP_Query($args))->get_posts();

		if (!$result || is_wp_error($result)) {
			return null;
		}
		return is_array($result) ? reset($result) : null;
    }

    private function getAllProductImageAttachments($product)
    {
        if (!$product) {
            return [];
        }

        $productAttachments = [];

        if ($product->get_image_id()) {
            $productAttachments[] = $product->get_image_id();
        }

		$productAttachments = array_merge($productAttachments, $product->get_gallery_image_ids());
		$attachmentFiles    = [];
		foreach ($productAttachments as $attachmentId) {
			if (!$this->checkAttachmentFileExistence($attachmentId)) {
				continue;
			}

			$imageAttachment = wp_get_attachment_image_src($attachmentId, 'full');

			if (!$imageAttachment) {
				continue;
			}

			$imageSrc     = current($imageAttachment);
			$fileBasename = $this->getSanitizedFileName(basename($imageSrc), true);
			if (isset($attachmentFiles[$fileBasename])) {
				/*
				 * Do not add images that belong to the same attachment
				 */
				if ($attachmentFiles[$fileBasename] === $attachmentId) {
					continue;
				}

				$documentIdA = $this->getDocumentAttachmentId($attachmentFiles[$fileBasename]);
				$documentIdB = $this->getDocumentAttachmentId($attachmentId);

				if (($documentIdA && $documentIdB) && $documentIdA === $documentIdB) {
					$this->log( 'debug', sprintf('Duplicate gallery image found, deleting duplicate image %s ',$attachmentId));
					$this->deleteProductAttachments($attachmentId);
					continue;
				}
			}
			$attachmentFiles[$fileBasename] = $attachmentId;
		}

	    return array_unique($attachmentFiles);
    }

    private function getSanitizedFileName($file): string
    {
	    return sanitize_file_name(strtolower($file));
    }

    private function getFileNameFromPost(WP_Post $post)
    {
        $file = isset($post->guid) ? basename($post->guid) : $post->post_title;
        return $this->getSanitizedFileName($file);
    }

    private function saveFileAsAttachment($file, $productId)
    {
		$fileName = $file['original_filename'];

		if (empty($this->getSanitizedFileName($fileName))) {
			$this->log( 'error', sprintf('Invalid filename %s, not processing', $fileName));
			return null;
		}

		$endpoint   = '/wp/v2/media';
		$attributes = ['sslverify' => false];
        $headers    = [
            'Content-Disposition' => 'form-data; filename='.$fileName,
            'Content-Type'        => $file['mime_type']
        ];

        $wpRequest = new WP_REST_Request('POST', $endpoint, $attributes);
		$body      = $file['media_data_base64_encoded'];

		$wpRequest->set_body(base64_decode($body));
		$wpRequest->set_headers( $headers);
		$wpRequest->set_param( 'title', $fileName);

		if ($productId) {
			$wpRequest->set_param( 'post', $productId);
		}

		$response = rest_do_request($wpRequest);

		if ($response instanceof WP_Error) {
			$this->log( 'error', sprintf('%s (%s)', $response->get_error_message(), $response->get_error_code()));
			return null;
		}

		if ($response->get_status() !== 201) {
			$error = $response->as_error();
			$msg   = $error ? $error->get_error_message() : 'Something went wrong';
			$this->log( 'debug', sprintf('Attachment (%s) not created: %s (Response code: %s)', $fileName, $msg, $response->get_status()));
			return null;
		}

		$attachmentId = $response->get_data()['id'];
		$sourceId     = $file['attachment_source_id'] ?? null;

		if ($sourceId && !update_post_meta( $attachmentId, 'xcore_document_attachment_id', $sourceId)) {
			$this->log( 'debug', sprintf('Unable to set source id (%s), deleting attachment  %s (%s).', $sourceId, $fileName, $attachmentId));

			$this->deleteProductAttachments($attachmentId);
			return null;
		}

		$this->log( 'debug', sprintf('Attachment %s (%s) created', $fileName, $attachmentId));

		$data = $response->get_data();
		return $data['id'] ?? null;
    }

    private function updateStock($request, $product)
    {
        add_filter('wp_insert_post_data', [$this, 'filter_stock_updates'], 10, 3);
        $date    = $product->get_date_modified();
        $product->set_stock_quantity($request['stock_quantity']);
        $product->set_date_modified((string)$date);
        $product->save();

        remove_filter('wp_insert_post_data', [$this, 'filter_stock_updates']);

		return new WP_REST_Response( $request['stock_quantity'], 200 );
	}

    private function log($level, $logMsg)
    {
        $source = ['source' => 'xcore-rest-api'];
        $logger = wc_get_logger();

		if (is_array($logMsg) || is_object( $logMsg)) {
			$logMsg = print_r($logMsg, true);
		}

        if ($logger) {
            $logger->log($level, $logMsg, $source);
        }
    }

    private function getDocumentAttachmentId($attachmentId)
    {
        return get_post_meta($attachmentId, 'xcore_document_attachment_id', true);
    }
}