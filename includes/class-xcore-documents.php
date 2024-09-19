<?php

defined('ABSPATH') || exit;

class Xcore_Documents
{
    protected static $_instance       = null;
    public           $version         = '1';
    public           $namespace       = 'wc-xcore/v1';
    public           $base            = 'documents';
    private          $_xcoreHelper    = null;
    protected        $data            = [
        'document_id'            => '',
        'document_type'          => '',
        'document_description'   => '',
        'date_created'           => '',
        'order_id'               => null,
        'customer_id'            => null,
        'custom_upload_base_dir' => null,
        'custom_upload_sub_dir'  => null,
        'files'                  => [],
    ];
    private          $processed_files = [];
    private          $user_id_valid   = false;
    private          $order_id_valid  = false;
    private          $required_args   = [
        'document_id',
        'document_type',
        'date_created',
        'files',
    ];

    public function __construct($helper)
    {
        $this->_xcoreHelper = $helper;
        $this->init();
    }

    /**
     * Register all document routes
     */
    public function init()
    {
        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<order_id>[\d]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            $this->namespace,
            $this->base . '/(?P<id>[\d]+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function get_item($request)
    {
        $document = apply_filters('xcore_rest_document_download', $request);

        if (is_null($document)) {
            $orderId = $request->get_param('order_id');
            return new WP_Error(
                'xcore_documents_unable_download',
                sprintf('Unable to download document for order %s', $orderId),
                ['status' => '400']
            );
        }

        $data['data']                      = $document['data'];
        $data['file_name']                 = $document['file_name'];
        $data['file_extension']            = $document['file_type'];
        $data['media_data_base64_encoded'] = base64_encode($document['file']);

        $response = new WP_REST_Response();
        $response->set_data($data);

        return rest_ensure_response($response);
    }

    public function create_item(WP_REST_Request $request)
    {
        $response = $this->set_data($request);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($file_count = (count($this->data['files']) > 1)) {
            return new WP_Error(
                'xcore_documents_unsupported_file_count',
                sprintf('Unable to process request, unsupported file count (%s)', $file_count),
                ['status' => '400']
            );
        }

        $this->user_id_valid  = $this->is_user_valid($this->data['customer_id']);

        $file_process_result = $this->process_files();
        if (is_wp_error($file_process_result)) {
            return $file_process_result;
        }

        $data     = new stdClass();
        $data->id = $this->data['document_id'];

        return new WP_REST_Response($data, 200);
    }

    public function update_item($request)
    {
        return new WP_Error('501', 'PUT not yet implemented', ['status' => '501']);
    }

    private function set_data($request)
    {
        $dataKeys = array_keys($this->data);

        foreach ($dataKeys as $key) {
            $hasParam   = $request->has_param($key);
            $isRequired = $this->is_required($key);
            if (!$hasParam) {
                if ($isRequired) {
                    return new WP_Error(
                        'xcore_documents_missing_entity',
                        sprintf('Unable to process request, missing %s', $key),
                        ['status' => '404']
                    );
                }
                continue;
            }

            $value = $request->get_param($key);

            if (!$value && $isRequired) {
                return new WP_Error(
                    'xcore_documents_missing_entity_value',
                    sprintf('Unable to process request, %s has an invalid value (%s)', $key, $value),
                    ['status' => '404']
                );
            }

			if ($key === 'order_id' && $value) {
				$searchResult = wc_order_search( $value );
				if ($searchResult) {
					$value = reset($searchResult);
				}
			}
            $this->data[$key] = $value;
        }
        return true;
    }

    private function is_required($field)
    {
        return in_array($field, $this->required_args);
    }

    private function process_files()
    {
        foreach ($this->data['files'] as $file) {
            $result = $this->upload_file($file);

            if (!$result || $result['error']) {
                return new WP_Error(
                    'xcore_documents_upload_failed',
                    sprintf(
                        'Unable to process request, failed to upload document file (%s), error: %s',
                        $result['original_filename'],
                        $result['error']
                    ),
                    ['status' => '404']
                );
            }

            $this->attach_file($result);
        }
        return true;
    }

    private function upload_file($file)
    {
        $base64Document    = $file['media_data_base64_encoded'];
        $filename          = $this->getFileName($file);
        $documentData      = base64_decode($base64Document);
        $filenameSanitized = sanitize_file_name($filename);
        $date              = date("Y/m", strtotime($this->data['date_created']));

        add_filter('upload_dir', [$this, 'setCustomUploadPath']);
        $fileUpload                         = wp_upload_bits($filenameSanitized, null, $documentData, $date);
        $fileUpload['document_id']          = $this->data['document_id'];
        $fileUpload['document_description'] = $this->data['document_description'];
        $fileUpload['original_filename']    = $file['original_filename'];
        remove_filter('upload_dir', [$this, 'setCustomUploadPath']);

        return $fileUpload;
    }

    public function setCustomUploadPath($dirs)
    {
        if (!$this->data['custom_upload_base_dir'] && !$this->data['custom_upload_sub_dir']) {
            return $dirs;
        }

        $newBaseDir = $this->data['custom_upload_base_dir'];
        $newSubDir  = $this->data['custom_upload_sub_dir'];

        if ($newBaseDir) {
            $trimmedBaseDir  = trim($newBaseDir, '/');
            $dirs['basedir'] = sprintf('%s/%s', dirname($dirs['basedir']), $trimmedBaseDir);
            $dirs['baseurl'] = sprintf('%s/%s', dirname($dirs['baseurl']), $trimmedBaseDir);
        }

        if ($newSubDir) {
            $trimmedSubDir = trim($newSubDir, '/');
            $dirs['path']  = sprintf('%s/%s', $dirs['basedir'], $trimmedSubDir);
            $dirs['url']   = sprintf('%s/%s', $dirs['baseurl'], $trimmedSubDir);
        }

        return $dirs;
    }

    private function attach_file($file)
    {
        if (!$this->check_file($file)) {
            return false;
        }

		$meta_key        = sprintf('xcore_%s', $this->data['document_type']);
	    $document_id     = $this->data['document_id'];

        if ($this->user_id_valid && $this->data['customer_id']) {
            $customer = new WC_Customer($this->data['customer_id']);
			$this->addDocumentMeta($customer, $file, $meta_key, $document_id);
        }

        if (is_numeric($this->data['order_id'])) {
            $order = wc_get_order($this->data['order_id']);

            if ($order instanceof \WC_Order) {
	            $this->addDocumentMeta($order, $file, $meta_key, $document_id);
            }
        }

        return true;
    }

    private function check_file($file)
    {
        if ($file['error']) {
            $this->log( 'debug', sprintf('Error processing file %s. %s', $file['file'], $file['error']));
            return false;
        }

        if (!file_exists($file['file'])) {
            $this->log( 'debug', sprintf('File %s not found, unable to add as metadata', $file['file']));
            return false;
        }
        return true;
    }

    private function is_user_valid($user_id)
    {
        global $wpdb;

        if (!is_numeric($user_id)) {
            return false;
        }

        if (wp_cache_get($user_id, 'users')) {
            return true;
        }

        if ($wpdb->get_var($wpdb->prepare("SELECT EXISTS (SELECT 1 FROM $wpdb->users WHERE ID = %d)", $user_id))) {
            return true;
        }

        return false;
    }

    private function addDocumentMeta($object, $newFile, $meta_key, $document_id)
    {
        $currentMeta = $object->get_meta($meta_key, true);
		$objectType  = $object instanceof \WC_Order ? 'order' : 'customer';

		if ($currentMeta === false) {
			$this->log( 'debug', sprintf('Unable to get metadata for %s (%s)', $objectType, $object->get_id()));
            return;
        }

		$files = [];
        if (is_array($currentMeta) && array_key_exists('files', $currentMeta)) {
			$files = $currentMeta['files'];
        }

		if (!in_array($document_id, array_column($files, 'document_id'))) {
			$files[] = $newFile;
			$object->update_meta_data($meta_key, ['files' => $files]);
			$result = $object->save();

			if (!is_numeric($result)) {
				$this->log( 'debug', sprintf('Unable to save %s (%s), metadata was not saved', $objectType, $object->get_id()));
			}
		} else {
			$this->log( 'debug', sprintf('Document %s already exists on %s %s, skipping. ', $document_id, $objectType, $object->get_id()));
		}
    }

    private function getFileName($file)
    {
        $fileName = $file['original_filename'];

        if (isset($file['custom_filename']) && $file['custom_filename']) {
            $fileName = $file['custom_filename'];
        }

        return $fileName;
    }

    private function log($level, $logMsg)
    {
        $source = ['source' => 'xcore-rest-api'];
        $logger = wc_get_logger();

        if ($logger) {
            $logger->log($level, $logMsg, $source);
        }
    }
}
