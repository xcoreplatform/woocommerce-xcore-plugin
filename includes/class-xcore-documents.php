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
        'document_id'          => '',
        'document_type'        => '',
        'document_description' => '',
        'date_created'         => '',
        'order_id'             => null,
        'customer_id'          => null,
        'files'                => [],
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
            $this->base,
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
        return new WP_Error('501', 'GET not yet implemented', ['status' => '501']);
    }

    public function create_item($request)
    {
        $response = $this->set_data($request);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($file_count = count($this->data['files']) > 1) {
            return new WP_Error(
                'xcore_documents_unsupported_file_count',
                sprintf('Unable to process request, unsupported file count (%s)', $file_count),
                ['status' => '400']
            );
        }

        $this->user_id_valid  = $this->is_user_valid($this->data['customer_id']);
        $this->order_id_valid = $this->is_order_valid($this->data['order_id']);

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
        $filename          = $this->generateFilename($file);
        $documentData      = base64_decode($base64Document);
        $filenameSanitized = sanitize_file_name($filename);
        $date              = date("Y/m", strtotime($this->data['date_created']));

        $fileUpload                         = wp_upload_bits($filenameSanitized, null, $documentData, $date);
        $fileUpload['document_id']          = $this->data['document_id'];
        $fileUpload['document_description'] = $this->data['document_description'];
        $fileUpload['original_filename']    = $file['original_filename'];

        return $fileUpload;
    }

    private function attach_file($file)
    {
        if (!$this->check_file($file)) {
            return false;
        }

        if ($this->user_id_valid) {
            $this->attach($file, 'customer');
        }

        if ($this->order_id_valid) {
            $this->attach($file, 'order');
        }

        return true;
    }

    private function check_file($file)
    {
        if ($file['error']) {
            return false;
        }

        if (!file_exists($file['file'])) {
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

    private function is_order_valid($order_id)
    {
        global $wpdb;
        if (!is_numeric($order_id)) {
            return false;
        }

        if ($wpdb->get_var($wpdb->prepare("SELECT EXISTS (SELECT 1 FROM $wpdb->posts WHERE ID = %d)", $order_id))) {
            return true;
        }

        return false;
    }

    private function attach($file, $type)
    {
        $meta_key        = sprintf('xcore_%s', $this->data['document_type']);
        $document_id     = $this->data['document_id'];
        $id              = ($type == 'customer') ? $this->data['customer_id'] : $this->data['order_id'];
        $args['files'][] = $file;

        $this->add_meta_data($id, $type, $args, $meta_key, $document_id);
    }

    private function add_meta_data($id, $type, $data, $meta_key, $document_id)
    {
        $meta_type   = ($type == 'customer') ? 'user' : 'post';
        $currentMeta = get_metadata($meta_type, $id, $meta_key, true);

        if ($currentMeta === false) {
            return;
        }

        if (empty($currentMeta)) {
            add_metadata($meta_type, $id, $meta_key, $data, true);
            return;
        }

        if (!isset($currentMeta['files'])) {
            update_metadata($meta_type, $id, $meta_key, $data, $currentMeta);
            return;
        }

        if (isset($currentMeta['files']) && array_search($document_id, array_column($currentMeta['files'], 'document_id')) === false) {
            $metaFiles = $currentMeta;
            array_push($metaFiles['files'], $data['files'][0]);
            update_metadata($meta_type, $id, $meta_key, $metaFiles, $currentMeta);
        }
    }

    private function generateFilename($file)
    {
        $fileExtension = $file['file_extension'];
        $documentType  = $this->data['document_type'];
        $documentId    = $this->data['document_id'];

        return sprintf('%s_%s.%s', $documentType, $documentId, $fileExtension);
    }
}
