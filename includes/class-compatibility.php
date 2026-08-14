<?php
/**
 * Imagick / WebP environment checks.
 *
 * @package WebP_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies PHP Imagick can read and write WebP.
 */
class WebP_Converter_Compatibility {

	/**
	 * Cached result of the environment probe.
	 *
	 * @var bool|null
	 */
	private $supported = null;

	/**
	 * Whether Imagick is present and can encode WebP.
	 *
	 * @return bool
	 */
	public function is_supported() {
		if ( null !== $this->supported ) {
			return $this->supported;
		}

		$this->supported = $this->probe();

		return $this->supported;
	}

	/**
	 * Runs class, format, and write probes.
	 *
	 * @return bool
	 */
	private function probe() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$formats = Imagick::queryFormats( 'WEBP' );
		} catch ( Exception $e ) {
			return false;
		}

		if ( empty( $formats ) ) {
			return false;
		}

		try {
			$image = new Imagick();
			$image->newImage( 1, 1, new ImagickPixel( 'white' ) );
			$image->setImageFormat( 'WEBP' );
			$blob = $image->getImageBlob();
			$image->clear();
			$image->destroy();
		} catch ( Exception $e ) {
			return false;
		}

		return is_string( $blob ) && '' !== $blob;
	}
}
