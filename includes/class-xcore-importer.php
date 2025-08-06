<?php

if ( !defined( 'ABSPATH' ) ) exit;

class Xcore_Importer {

	public function __construct($x) {
		add_action( 'admin_menu', [ $this, 'register_importer' ] );
	}

	public function register_importer() {
		register_importer( 'wc_custom_meta_importer',
			'WC Custom Meta Importer',
			'Importeert custom meta data voor WooCommerce producten.',
			[ $this, 'import_page' ] );
	}

	public function import_page() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( __( 'Je hebt geen toestemming om dit te doen.' ) );
		}

		if ( isset( $_POST['submit'] ) && ! empty( $_FILES['import_file']['tmp_name'] ) ) {
			$this->handle_import( $_FILES['import_file']['tmp_name'] );
		}

		echo '<div class="wrap">';
		echo '<h2>WooCommerce Custom Meta Importer</h2>';
		echo '<form method="post" enctype="multipart/form-data">';
		echo '<input type="file" name="import_file" required />';
		submit_button( 'Importeer' );
		echo '</form>';
		echo '</div>';
	}

	private function handle_import( $file_path ) {
		$header = null;
		$data   = [];

		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			while ( ( $row = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
				if ( ! $header ) {
					$header = $row;
				} else {
					$data[] = array_combine( $header, $row );
				}
			}
			fclose( $handle );
		}

		foreach ( $data as $row ) {
			$product_id = intval( $row['product_id'] ?? 0 );
			if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
				continue;
			}

			foreach ( $row as $key => $value ) {
				if ( $key !== 'product_id' ) {
					update_post_meta( $product_id, sanitize_key( $key ), sanitize_text_field( $value ) );
				}
			}
		}

		echo '<div class="notice notice-success"><p>Import voltooid.</p></div>';
	}
}