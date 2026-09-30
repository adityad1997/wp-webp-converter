<?php
/**
 * Plugin Name: WP WebP Converter
 * Description: Converts newly uploaded JPEG and PNG attachments to WebP via Imagick, and adds Upload from URL into the Media Library.
 * Version: 1.2.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Moondroo Web Services
 * Author URI: https://moondroo.com
 * License: GPL-2.0-or-later
 * Text Domain: wp-webp-converter
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEBP_CONVERTER_FILE', __FILE__ );
define( 'WEBP_CONVERTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBP_CONVERTER_MAX_UPLOAD_BYTES', 50 * MB_IN_BYTES );

require_once WEBP_CONVERTER_DIR . 'includes/class-compatibility.php';
require_once WEBP_CONVERTER_DIR . 'includes/class-converter.php';
require_once WEBP_CONVERTER_DIR . 'includes/class-upload-from-url.php';
require_once WEBP_CONVERTER_DIR . 'includes/class-rest-upload-from-url.php';
require_once WEBP_CONVERTER_DIR . 'includes/class-abilities.php';

/**
 * Registers plugin hooks.
 */
function webp_converter_init() {
	$compatibility = new WebP_Converter_Compatibility();
	$converter     = new WebP_Converter( $compatibility );
	$upload_url    = new WebP_Converter_Upload_From_Url();
	$rest_upload   = new WebP_Converter_REST_Upload_From_Url( $upload_url );

	add_filter( 'wp_handle_upload', array( $converter, 'convert_upload' ), 10, 2 );
	add_filter( 'wp_generate_attachment_metadata', array( $converter, 'convert_attachment_metadata' ), 10, 3 );
	add_filter( 'image_editor_output_format', array( $converter, 'map_output_format' ), 10, 3 );

	$upload_url->register();
	$rest_upload->register();

	if ( function_exists( 'wp_register_ability' ) ) {
		$abilities = new WebP_Converter_Abilities( $upload_url );
		$abilities->register();
	}
}

add_action( 'plugins_loaded', 'webp_converter_init' );
