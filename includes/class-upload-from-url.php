<?php
/**
 * Sideloads remote images into the Media Library from a URL.
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI + AJAX for "Upload from URL" into the Media Library.
 */
class WebP_Converter_Upload_From_Url {

	/**
	 * Allowed image extensions for remote sideloads.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array(
		'jpg',
		'jpeg',
		'jpe',
		'png',
		'gif',
		'webp',
	);

	/**
	 * Allowed MIME types for remote sideloads.
	 *
	 * @var string[]
	 */
	const ALLOWED_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Whether assets were already enqueued this request.
	 *
	 * @var bool
	 */
	private $enqueued = false;

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'wp_ajax_webp_converter_upload_from_url', array( $this, 'ajax_upload_from_url' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_with_media' ) );
	}

	/**
	 * Enqueues on common media-related admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$load_on = array(
			'upload.php',
			'media-new.php',
			'post.php',
			'post-new.php',
		);

		/**
		 * Filters whether Upload from URL assets should load on this admin screen.
		 *
		 * @param bool   $should_load Whether to enqueue.
		 * @param string $hook_suffix Current admin page hook.
		 */
		$should_load = apply_filters(
			'webp_converter_upload_from_url_should_enqueue',
			in_array( $hook_suffix, $load_on, true ),
			$hook_suffix
		);

		if ( ! $should_load ) {
			return;
		}

		wp_enqueue_media();
		$this->enqueue_scripts();
	}

	/**
	 * Enqueues whenever core media scripts are loaded (Elementor, ACF, etc.).
	 */
	public function enqueue_with_media() {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$this->enqueue_scripts();
	}

	/**
	 * Registers and enqueues plugin CSS/JS once per request.
	 */
	private function enqueue_scripts() {
		if ( $this->enqueued ) {
			return;
		}

		$this->enqueued = true;

		$script_path = WEBP_CONVERTER_DIR . 'assets/js/upload-from-url.js';
		$style_path  = WEBP_CONVERTER_DIR . 'assets/css/upload-from-url.css';
		$script_ver  = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0';
		$style_ver   = file_exists( $style_path ) ? (string) filemtime( $style_path ) : '1.0.0';

		wp_enqueue_style(
			'webp-converter-upload-from-url',
			plugins_url( 'assets/css/upload-from-url.css', WEBP_CONVERTER_FILE ),
			array( 'media-views' ),
			$style_ver
		);

		wp_enqueue_script(
			'webp-converter-upload-from-url',
			plugins_url( 'assets/js/upload-from-url.js', WEBP_CONVERTER_FILE ),
			array( 'jquery', 'media-views', 'media-models', 'underscore' ),
			$script_ver,
			true
		);

		wp_localize_script(
			'webp-converter-upload-from-url',
			'webpConverterUploadFromUrl',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'webp_converter_upload_from_url' ),
				'pagenow' => isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '',
				'i18n'    => array(
					'title'          => __( 'Upload from URL', 'wp-webp-converter' ),
					'button'         => __( 'Upload', 'wp-webp-converter' ),
					'orPasteUrl'     => __( 'or', 'wp-webp-converter' ),
					'urlPlaceholder' => 'https://',
					'uploading'      => __( 'Uploading…', 'wp-webp-converter' ),
					'uploadFailed'   => __( 'Upload failed. Please try again.', 'wp-webp-converter' ),
					'invalidUrl'     => __( 'Please enter a valid image URL.', 'wp-webp-converter' ),
				),
			)
		);
	}

	/**
	 * AJAX: download a remote image and create a Media Library attachment.
	 */
	public function ajax_upload_from_url() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to upload files.', 'wp-webp-converter' ) ),
				403
			);
		}

		check_ajax_referer( 'webp_converter_upload_from_url', 'nonce' );

		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$alt   = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please enter a valid image URL.', 'wp-webp-converter' ) )
			);
		}

		$attachment_id = $this->sideload_image( $url, $alt, $title );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error(
				array( 'message' => $attachment_id->get_error_message() )
			);
		}

		if ( ! function_exists( 'wp_prepare_attachment_for_js' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment = wp_prepare_attachment_for_js( $attachment_id );

		if ( ! $attachment ) {
			wp_send_json_error(
				array( 'message' => __( 'Upload failed.', 'wp-webp-converter' ) )
			);
		}

		wp_send_json_success( $attachment );
	}

	/**
	 * Downloads a remote image and creates an attachment.
	 *
	 * @param string $url   Remote image URL (HTTPS required).
	 * @param string $alt   Optional alt text.
	 * @param string $title Optional attachment title.
	 * @return int|WP_Error Attachment ID on success.
	 */
	public function sideload_image( $url, $alt = '', $title = '' ) {
		$url = esc_url_raw( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'webp_converter_invalid_url',
				__( 'Please enter a valid image URL.', 'wp-webp-converter' ),
				array( 'status' => 400 )
			);
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return new WP_Error(
				'webp_converter_https_required',
				__( 'Only HTTPS image URLs are supported.', 'wp-webp-converter' ),
				array( 'status' => 400 )
			);
		}

		$size_error = $this->reject_if_content_length_too_large( $url );
		if ( is_wp_error( $size_error ) ) {
			return $size_error;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'webp_converter_download_failed',
				$tmp->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$file_size = filesize( $tmp );
		if ( false !== $file_size && $file_size > WEBP_CONVERTER_MAX_UPLOAD_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $this->file_too_large_error();
		}

		$filename = $this->guess_filename( $url, $tmp );

		if ( is_wp_error( $filename ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $filename;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		add_post_meta( $attachment_id, '_source_url', $url, true );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $title,
				)
			);
		}

		return (int) $attachment_id;
	}

	/**
	 * Builds the normalized response payload for REST and Abilities.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_url    Original remote URL.
	 * @return array|WP_Error
	 */
	public function format_attachment_response( $attachment_id, $source_url = '' ) {
		$attachment_id = (int) $attachment_id;
		$post          = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'webp_converter_attachment_missing',
				__( 'Upload failed.', 'wp-webp-converter' ),
				array( 'status' => 500 )
			);
		}

		if ( '' === $source_url ) {
			$source_url = (string) get_post_meta( $attachment_id, '_source_url', true );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		$meta      = wp_get_attachment_metadata( $attachment_id );
		$alt       = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			'id'                => $attachment_id,
			'url'               => (string) wp_get_attachment_url( $attachment_id ),
			'mime_type'         => is_string( $mime_type ) ? $mime_type : '',
			'alt'               => $alt,
			'width'             => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'            => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'converted_to_webp' => ( 'image/webp' === $mime_type ),
			'source_url'        => $source_url,
		);
	}

	/**
	 * Rejects the remote URL early when Content-Length exceeds the max.
	 *
	 * @param string $url Remote URL.
	 * @return true|WP_Error
	 */
	private function reject_if_content_length_too_large( $url ) {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'    => 15,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		$length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( '' === $length || ! is_numeric( $length ) ) {
			return true;
		}

		if ( (int) $length > WEBP_CONVERTER_MAX_UPLOAD_BYTES ) {
			return $this->file_too_large_error();
		}

		return true;
	}

	/**
	 * @return WP_Error
	 */
	private function file_too_large_error() {
		$max_mb = (int) round( WEBP_CONVERTER_MAX_UPLOAD_BYTES / MB_IN_BYTES );

		return new WP_Error(
			'webp_converter_file_too_large',
			sprintf(
				/* translators: %d: Maximum upload size in megabytes. */
				__( 'The remote image exceeds the maximum upload size of %d MB.', 'wp-webp-converter' ),
				$max_mb
			),
			array( 'status' => 413 )
		);
	}

	/**
	 * Derives a safe image filename from the URL or downloaded file MIME type.
	 *
	 * @param string $url Remote URL.
	 * @param string $tmp Temporary file path from download_url().
	 * @return string|WP_Error
	 */
	private function guess_filename( $url, $tmp ) {
		$allowed = self::ALLOWED_EXTENSIONS;
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base    = $path ? wp_basename( $path ) : '';
		$ext     = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );

		if ( $base && in_array( $ext, $allowed, true ) ) {
			return sanitize_file_name( $base );
		}

		$filetype = wp_check_filetype_and_ext( $tmp, $base ? $base : 'image.jpg' );
		$mime     = isset( $filetype['type'] ) ? $filetype['type'] : '';

		if ( ! $mime || ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			$image_info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $image_info ) && ! empty( $image_info['mime'] ) ) {
				$mime = $image_info['mime'];
			}
		}

		if ( ! $mime || ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error(
				'webp_converter_invalid_image',
				__( 'The remote file is not a supported image type.', 'wp-webp-converter' ),
				array( 'status' => 400 )
			);
		}

		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		$extension = isset( $map[ $mime ] ) ? $map[ $mime ] : 'jpg';
		$name      = $base ? pathinfo( $base, PATHINFO_FILENAME ) : 'remote-image';

		if ( '' === $name || '.' === $name ) {
			$name = 'remote-image';
		}

		return sanitize_file_name( $name . '.' . $extension );
	}
}
