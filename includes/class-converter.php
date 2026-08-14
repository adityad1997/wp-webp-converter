<?php
/**
 * Converts raster uploads and leftover attachment files to WebP.
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native-upload WebP conversion.
 */
class WebP_Converter {

	/**
	 * Source MIME types that should become WebP.
	 *
	 * @var string[]
	 */
	const CONVERTIBLE_MIMES = array(
		'image/jpeg',
		'image/png',
	);

	/**
	 * Source filename extensions that should become WebP.
	 *
	 * @var string[]
	 */
	const CONVERTIBLE_EXTENSIONS = array(
		'jpg',
		'jpeg',
		'jpe',
		'png',
	);

	/**
	 * WebP quality matching WordPress core default.
	 *
	 * @var int
	 */
	const QUALITY = 86;

	/**
	 * Environment checks.
	 *
	 * @var WebP_Converter_Compatibility
	 */
	private $compatibility;

	/**
	 * @param WebP_Converter_Compatibility $compatibility Compatibility helper.
	 */
	public function __construct( WebP_Converter_Compatibility $compatibility ) {
		$this->compatibility = $compatibility;
	}

	/**
	 * Converts a JPEG/PNG immediately after WordPress places it in uploads.
	 *
	 * @param array  $upload  Upload data with file, url, and type.
	 * @param string $context Upload context: upload or sideload.
	 * @return array
	 */
	public function convert_upload( $upload, $context ) {
		unset( $context );

		if ( ! is_array( $upload ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		if ( ! $this->compatibility->is_supported() ) {
			return $upload;
		}

		if ( ! in_array( $upload['type'], self::CONVERTIBLE_MIMES, true ) ) {
			return $upload;
		}

		$converted = $this->convert_file( $upload['file'] );

		if ( ! $converted ) {
			return $upload;
		}

		$upload['url']  = str_replace( wp_basename( $upload['file'] ), wp_basename( $converted ), $upload['url'] );
		$upload['file'] = $converted;
		$upload['type'] = 'image/webp';

		return $upload;
	}

	/**
	 * Converts leftover JPEG/PNG files in attachment metadata.
	 *
	 * @param array  $metadata      Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       create or update.
	 * @return array
	 */
	public function convert_attachment_metadata( $metadata, $attachment_id, $context = 'create' ) {
		unset( $context );

		if ( ! is_array( $metadata ) || ! $this->compatibility->is_supported() ) {
			return $metadata;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return $metadata;
		}

		$basedir          = trailingslashit( $uploads['basedir'] );
		$canonical_changed = false;

		if ( ! empty( $metadata['file'] ) ) {
			$absolute = $this->absolute_path( $metadata['file'], $basedir );

			if ( $this->should_convert_path( $absolute ) ) {
				$converted = $this->convert_file( $absolute );

				if ( $converted ) {
					$metadata['file']     = _wp_relative_upload_path( $converted );
					$metadata['filesize'] = wp_filesize( $converted );
					$canonical_changed    = true;
					update_attached_file( $attachment_id, $converted );
				}
			}
		}

		if ( ! empty( $metadata['original_image'] ) && ! empty( $metadata['file'] ) ) {
			$dir      = trailingslashit( dirname( $this->absolute_path( $metadata['file'], $basedir ) ) );
			$original = $dir . wp_basename( $metadata['original_image'] );

			if ( $this->should_convert_path( $original ) ) {
				$converted = $this->convert_file( $original );

				if ( $converted ) {
					$metadata['original_image'] = wp_basename( $converted );
				}
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && ! empty( $metadata['file'] ) ) {
			$dir = trailingslashit( dirname( $this->absolute_path( $metadata['file'], $basedir ) ) );

			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$size_path = $dir . wp_basename( $size_data['file'] );

				if ( ! $this->should_convert_path( $size_path ) ) {
					if ( $this->has_webp_extension( $size_path ) ) {
						$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';
					}
					continue;
				}

				$converted = $this->convert_file( $size_path );

				if ( ! $converted ) {
					continue;
				}

				$metadata['sizes'][ $size_name ]['file']      = wp_basename( $converted );
				$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';
				$metadata['sizes'][ $size_name ]['filesize']  = wp_filesize( $converted );
			}
		}

		if ( $canonical_changed ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/webp',
				)
			);
		}

		return $metadata;
	}

	/**
	 * Forces WordPress image editor saves of JPEG/PNG to WebP.
	 *
	 * @param array  $output_format Mime map.
	 * @param string $filename      Source filename.
	 * @param string $mime_type     Source mime type.
	 * @return array
	 */
	public function map_output_format( $output_format, $filename, $mime_type ) {
		unset( $filename, $mime_type );

		if ( ! is_array( $output_format ) ) {
			$output_format = array();
		}

		if ( ! $this->compatibility->is_supported() ) {
			return $output_format;
		}

		$output_format['image/jpeg'] = 'image/webp';
		$output_format['image/png']  = 'image/webp';

		return $output_format;
	}

	/**
	 * Converts a single file to WebP with Imagick. Fail-open.
	 *
	 * @param string $source Absolute path to the source image.
	 * @return string|false Absolute path to the WebP file, or false on failure.
	 */
	private function convert_file( $source ) {
		if ( ! is_string( $source ) || ! is_readable( $source ) ) {
			return false;
		}

		$dir      = dirname( $source );
		$filename = wp_basename( $source );
		$info     = pathinfo( $filename );
		$base     = isset( $info['filename'] ) ? $info['filename'] : $filename;

		if ( ! function_exists( 'wp_unique_filename' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$webp_name = wp_unique_filename( $dir, $base . '.webp' );
		$dest      = trailingslashit( $dir ) . $webp_name;

		try {
			$image = new Imagick();
			$image->readImage( $source );

			if ( is_callable( array( $image, 'setIteratorIndex' ) ) ) {
				$image->setIteratorIndex( 0 );
			}

			$image->setImageFormat( 'WEBP' );
			$image->setImageCompressionQuality( self::QUALITY );
			$image->writeImage( $dest );
			$image->clear();
			$image->destroy();
		} catch ( Exception $e ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}

			return false;
		}

		if ( ! is_readable( $dest ) || 0 === wp_filesize( $dest ) ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}

			return false;
		}

		if ( wp_basename( $source ) !== wp_basename( $dest ) ) {
			wp_delete_file( $source );
		}

		return $dest;
	}

	/**
	 * @param string $relative Relative or absolute path.
	 * @param string $basedir  Uploads basedir with trailing slash.
	 * @return string
	 */
	private function absolute_path( $relative, $basedir ) {
		if ( path_is_absolute( $relative ) ) {
			return $relative;
		}

		return $basedir . ltrim( str_replace( '\\', '/', $relative ), '/' );
	}

	/**
	 * @param string $path File path.
	 * @return bool
	 */
	private function should_convert_path( $path ) {
		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::CONVERTIBLE_EXTENSIONS, true );
	}

	/**
	 * @param string $path File path.
	 * @return bool
	 */
	private function has_webp_extension( $path ) {
		return 'webp' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}
}
