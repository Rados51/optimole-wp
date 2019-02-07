<?php

/**
 * Class Optml_App_Replacer
 *
 * @package    \Optml\Inc
 * @author     Optimole <friends@optimole.com>
 */
abstract class Optml_App_Replacer {

	/**
	 * Holds an array of image sizes.
	 *
	 * @var array
	 */
	protected static $image_sizes = array();
	/**
	 * Holds width/height to crop array based on possible image sizes.
	 *
	 * @var array
	 */
	protected static $size_to_crop = array();
	/**
	 * Settings handler.
	 *
	 * @var Optml_Settings $settings
	 */
	protected $settings = null;
	/**
	 * Defines which is the maximum width accepted in the optimization process.
	 *
	 * @var int
	 */
	protected $max_width = 3000;
	/**
	 * Defines which is the maximum width accepted in the optimization process.
	 *
	 * @var int
	 */
	protected $max_height = 3000;
	/**
	 * A cached version of `wp_upload_dir`
	 *
	 * @var null
	 */
	protected $upload_resource = null;

	/**
	 * Possible domain sources to optimize.
	 *
	 * @var array Domains.
	 */
	protected $possible_sources = array();

	/**
	 * Whitelisted domains sources to optimize from, according to optimole service.
	 *
	 * @var array Domains.
	 */
	protected $allowed_sources = array();

	/**
	 * Holds site mapping array,
	 * if there is already a cdn and we want to fetch the images from there
	 * and not from he original site.
	 *
	 * @var array Site mappings.
	 */
	protected $site_mappings = array();
	/**
	 * Whether the site is whitelisted or not. Used when signing the urls.
	 *
	 * @var bool Domains.
	 */
	protected $is_allowed_site = array();

	/**
	 * Size to crop maping.
	 *
	 * @return array Size mapping.
	 */
	protected static function size_to_crop() {
		if ( null != self::$size_to_crop && is_array( self::$size_to_crop ) ) {
			return self::$size_to_crop;
		}

		foreach ( self::image_sizes() as $size_data ) {
			self::$size_to_crop[ $size_data['width'] . $size_data['height'] ] = $size_data['crop'];
		}

		return self::$size_to_crop;
	}

	/**
	 * Returns the array of image sizes since `get_intermediate_image_sizes` and image metadata  doesn't include the
	 * custom image sizes in a reliable way.
	 *
	 * @global $wp_additional_image_sizes
	 *
	 * @return array
	 */
	protected static function image_sizes() {

		if ( null != self::$image_sizes && is_array( self::$image_sizes ) ) {
			return self::$image_sizes;
		}

		global $_wp_additional_image_sizes;

		// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes
		$images = array(
			'thumb'  => array(
				'width'  => intval( get_option( 'thumbnail_size_w' ) ),
				'height' => intval( get_option( 'thumbnail_size_h' ) ),
				'crop'   => get_option( 'thumbnail_crop', false ),
			),
			'medium' => array(
				'width'  => intval( get_option( 'medium_size_w' ) ),
				'height' => intval( get_option( 'medium_size_h' ) ),
				'crop'   => false,
			),
			'large'  => array(
				'width'  => intval( get_option( 'large_size_w' ) ),
				'height' => intval( get_option( 'large_size_h' ) ),
				'crop'   => false,
			),
			'full'   => array(
				'width'  => null,
				'height' => null,
				'crop'   => false,
			),
		);

		// Compatibility mapping as found in wp-includes/media.php
		$images['thumbnail'] = $images['thumb'];

		// Update class variable, merging in $_wp_additional_image_sizes if any are set
		if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) ) {
			self::$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
		} else {
			self::$image_sizes = $images;
		}

		return is_array( self::$image_sizes ) ? self::$image_sizes : array();
	}

	/**
	 * The initialize method.
	 */
	public function init() {
		$this->settings = new Optml_Settings();

		if ( ! $this->should_replace() ) {
			return false; // @codeCoverageIgnore
		}
		$this->set_properties();

		return true;
	}

	/**
	 * Check if we should rewrite the urls.
	 *
	 * @return bool If we can replace the image.
	 */
	public function should_replace() {
		if ( Optml_Manager::is_ajax_request() ) {
			return true;
		}
		if ( is_admin() || ! $this->settings->is_connected() || ! $this->settings->is_enabled() || is_customize_preview() ) {
			return false; // @codeCoverageIgnore
		}

		if ( array_key_exists( 'preview', $_GET ) && 'true' == $_GET['preview'] ) {
			return false; // @codeCoverageIgnore
		}

		if ( array_key_exists( 'optml_off', $_GET ) && 'true' == $_GET['optml_off'] ) {
			return false; // @codeCoverageIgnore
		}
		if ( array_key_exists( 'elementor-preview', $_GET ) && ! empty( $_GET['elementor-preview'] ) ) {
			return false; // @codeCoverageIgnore
		}

		return true;
	}

	/**
	 * Set the cdn url based on the current connected user.
	 */
	public function set_properties() {
		$upload_data                         = wp_upload_dir();
		$this->upload_resource               = array(
			'url'       => str_replace( array( 'https://', 'http://' ), '', $upload_data['baseurl'] ),
			'directory' => $upload_data['basedir'],
		);
		$this->upload_resource['url_length'] = strlen( $this->upload_resource['url'] );

		$service_data = $this->settings->get( 'service_data' );

		Optml_Config::init(
			array(
				'key'    => $service_data['cdn_key'],
				'secret' => $service_data['cdn_secret'],
			)
		);

		if ( defined( 'OPTML_SITE_MIRROR' ) && constant( 'OPTML_SITE_MIRROR' ) ) {
			$this->site_mappings = array(
				rtrim( get_site_url(), '/' ) => rtrim( constant( 'OPTML_SITE_MIRROR' ), '/' ),
			);
		}

		$this->possible_sources = $this->extract_domain_from_urls(
			array_merge(
				array( get_site_url() ),
				array_values( $this->site_mappings )
			)
		);

		$this->allowed_sources = $this->extract_domain_from_urls( $service_data['whitelist'] );

		$this->is_allowed_site = count( array_diff_key( $this->possible_sources, $this->allowed_sources ) ) > 0;

		$this->max_height = $this->settings->get( 'max_height' );
		$this->max_width  = $this->settings->get( 'max_width' );
	}

	/**
	 * Extract domains and use them as keys for fast processing.
	 *
	 * @param array $urls Input urls.
	 *
	 * @return array Array of domains as keys.
	 */
	protected function extract_domain_from_urls( $urls = array() ) {
		if ( ! is_array( $urls ) ) {
			return $urls;
		}

		$urls = array_map(
			function ( $value ) {
				$parts = parse_url( $value );

				return isset( $parts['host'] ) ? $parts['host'] : '';
			},
			$urls
		);
		$urls = array_filter( $urls );
		$urls = array_unique( $urls );

		$urls = array_fill_keys( $urls, true );
		// build www versions of urls, just in case we need them for validation.
		foreach ( $urls as $domain => $status ) {
			if ( ! ( substr( $domain, 0, 4 ) === 'www.' ) ) {
				$urls[ 'www.' . $domain ] = true;
			}
		}

		return $urls;
	}

	/**
	 * Check if we can replace the url.
	 *
	 * @param string $url Url to change.
	 *
	 * @return bool Either we can replace this url or not.
	 */
	public function can_replace_url( $url ) {

		if ( ! is_string( $url ) ) {
			return false; // @codeCoverageIgnore
		}
		$url = parse_url( $url );

		if ( ! isset( $url['host'] ) ) {
			return false;
		}
		return isset( $this->possible_sources[ $url['host'] ] ) || isset( $this->allowed_sources[ $url['host'] ] );
	}

	/**
	 * Checks if the file is a image size and return the full url.
	 *
	 * @param string $url The image URL.
	 *
	 * @return string
	 **/
	protected function strip_image_size_from_url( $url ) {

		if ( preg_match( '#(-\d+x\d+)\.(' . implode( '|', array_keys( Optml_Config::$extensions ) ) . '){1}$#i', $url, $src_parts ) ) {
			$stripped_url = str_replace( $src_parts[1], '', $url );
			// Extracts the file path to the image minus the base url
			$file_path = substr( $stripped_url, strpos( $stripped_url, $this->upload_resource['url'] ) + $this->upload_resource['url_length'] );
			if ( file_exists( $this->upload_resource['directory'] . $file_path ) ) {
				$url = $stripped_url;
			}
		}

		return $url;
	}

	/**
	 * Try to determine height and width from strings WP appends to resized image filenames.
	 *
	 * @param string $src The image URL.
	 *
	 * @return array An array consisting of width and height.
	 */
	protected function parse_dimensions_from_filename( $src ) {
		$width_height_string = array();
		$extensions          = array_keys( Optml_Config::$extensions );
		if ( preg_match( '#-(\d+)x(\d+)\.(?:' . implode( '|', $extensions ) . '){1}$#i', $src, $width_height_string ) ) {
			$width  = (int) $width_height_string[1];
			$height = (int) $width_height_string[2];

			if ( $width && $height ) {
				return array( $width, $height );
			}
		}

		return array( false, false );
	}
}
