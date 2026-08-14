<?php
/**
 * Plugin Name: WP WebP Converter
 * Description: Converts newly uploaded JPEG and PNG attachments to WebP via Imagick, using WordPress's native upload flow.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Moondroo Web Services
 * Author URI: https://moondroo.com
 * License: GPL-2.0-or-later
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEBP_CONVERTER_FILE', __FILE__ );
define( 'WEBP_CONVERTER_DIR', plugin_dir_path( __FILE__ ) );

require_once WEBP_CONVERTER_DIR . 'includes/class-compatibility.php';
require_once WEBP_CONVERTER_DIR . 'includes/class-converter.php';

/**
 * Registers plugin hooks.
 */
function webp_converter_init() {
	$compatibility = new WebP_Converter_Compatibility();
	$converter     = new WebP_Converter( $compatibility );

	add_filter( 'wp_handle_upload', array( $converter, 'convert_upload' ), 10, 2 );
	add_filter( 'wp_generate_attachment_metadata', array( $converter, 'convert_attachment_metadata' ), 10, 3 );
	add_filter( 'image_editor_output_format', array( $converter, 'map_output_format' ), 10, 3 );
}

add_action( 'plugins_loaded', 'webp_converter_init' );
