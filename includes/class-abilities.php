<?php
/**
 * WordPress Abilities API integration for Upload from URL.
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers MCP-public abilities for remote image upload.
 */
class WebP_Converter_Abilities {

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
	 * Registers category and ability hooks.
	 */
	public function register() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'webp-converter',
			array(
				'label'       => __( 'WebP Converter', 'wp-webp-converter' ),
				'description' => __( 'Abilities for uploading remote images into the Media Library with WebP conversion.', 'wp-webp-converter' ),
			)
		);
	}

	/**
	 * Registers plugin abilities.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'webp-converter/upload-from-url',
			array(
				'label'               => __( 'Upload Image from URL', 'wp-webp-converter' ),
				'description'         => __( 'Downloads an HTTPS image URL into the Media Library, converts JPEG/PNG to WebP when supported, and returns the attachment id and URL. Optional alt and title are supported.', 'wp-webp-converter' ),
				'category'            => 'webp-converter',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'url'   => array(
							'type'        => 'string',
							'description' => 'Remote HTTPS image URL to download into the Media Library.',
						),
						'alt'   => array(
							'type'        => 'string',
							'description' => 'Optional alt text for the attachment.',
						),
						'title' => array(
							'type'        => 'string',
							'description' => 'Optional attachment title.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer' ),
						'url'               => array( 'type' => 'string' ),
						'mime_type'         => array( 'type' => 'string' ),
						'alt'               => array( 'type' => 'string' ),
						'width'             => array( 'type' => 'integer' ),
						'height'            => array( 'type' => 'integer' ),
						'converted_to_webp' => array( 'type' => 'boolean' ),
						'source_url'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_upload_from_url' ),
				'permission_callback' => static function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/**
	 * Executes the upload-from-url ability.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_upload_from_url( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$url   = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
		$alt   = isset( $input['alt'] ) ? sanitize_text_field( (string) $input['alt'] ) : '';
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

		$attachment_id = $this->uploader->sideload_image( $url, $alt, $title );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->uploader->format_attachment_response( $attachment_id, $url );
	}
}
