<?php
/**
 * REST API endpoint for uploading an image from a remote URL.
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers POST /wp-json/webp-converter/v1/upload-from-url.
 */
class WebP_Converter_REST_Upload_From_Url {

	/**
	 * Shared upload service.
	 *
	 * @var WebP_Converter_Upload_From_Url
	 */
	private $uploader;

	/**
	 * @param WebP_Converter_Upload_From_Url $uploader Upload service.
	 */
	public function __construct( WebP_Converter_Upload_From_Url $uploader ) {
		$this->uploader = $uploader;
	}

	/**
	 * Registers REST routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the upload-from-url route.
	 */
	public function register_routes() {
		register_rest_route(
			'webp-converter/v1',
			'/upload-from-url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'url'   => array(
						'description'       => __( 'Remote HTTPS image URL.', 'wp-webp-converter' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'alt'   => array(
						'description'       => __( 'Optional alt text.', 'wp-webp-converter' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title' => array(
						'description'       => __( 'Optional attachment title.', 'wp-webp-converter' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function permission_check() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Handles the REST upload request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_upload( WP_REST_Request $request ) {
		$url   = (string) $request->get_param( 'url' );
		$alt   = (string) $request->get_param( 'alt' );
		$title = (string) $request->get_param( 'title' );

		$attachment_id = $this->uploader->sideload_image( $url, $alt, $title );

		if ( is_wp_error( $attachment_id ) ) {
			return $this->normalize_error( $attachment_id );
		}

		$payload = $this->uploader->format_attachment_response( $attachment_id, $url );

		if ( is_wp_error( $payload ) ) {
			return $this->normalize_error( $payload );
		}

		return new WP_REST_Response( $payload, 201 );
	}

	/**
	 * Ensures WP_Error includes an HTTP status for REST.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private function normalize_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = 400;

		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		} else {
			$code = $error->get_error_code();
			if ( 'webp_converter_file_too_large' === $code ) {
				$status = 413;
			} elseif ( 'webp_converter_download_failed' === $code ) {
				$status = 502;
			} elseif ( 'webp_converter_attachment_missing' === $code ) {
				$status = 500;
			}
		}

		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
