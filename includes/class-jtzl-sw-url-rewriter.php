<?php
/**
 * URL Rewriter for Active CDN Support
 *
 * Handles asset URL transformation to use CDN base URLs while preserving
 * query parameters and filtering out non-rewritable URLs.
 *
 * @package JTZL_Service_Worker
 * @since 2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Rewriter class for CDN asset URL transformation.
 *
 * @since 2.0.0
 */
class JTZL_SW_URL_Rewriter {

	/**
	 * CDN base URL for asset rewriting.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private $cdn_base_url;

	/**
	 * Site URL for comparison and filtering.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private $site_url;

	/**
	 * Rewritable file extensions.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $rewritable_extensions = array(
		// Stylesheets and Scripts.
		'.css',
		'.js',

		// Images (optimized web formats only).
		'.png',
		'.jpg',
		'.jpeg',
		'.gif',
		'.webp',
		'.svg',
		'.ico',

		// Web Fonts.
		'.woff',
		'.woff2',
		'.ttf',
		'.otf',

		// Note: Removed .pdf, .zip, .mp4, .webm, .mp3, .wav
		// These are typically downloads or large media that shouldn't
		// be automatically CDN-rewritten as they may be:
		// - Private/protected downloads
		// - Large files that could overwhelm CDN bandwidth
		// - Content that users expect from the origin server
		//
		// If specific media files need CDN delivery, they should be
		// handled through explicit CDN configuration, not automatic rewriting.
	);

	/**
	 * URL patterns to exclude from rewriting.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $exclude_patterns = array(
		'/\/wp-admin\//',
		'/\/wp-json\//',
		'/admin-ajax\.php/',
		'/\?.*nocache/',
		'/\?.*debug=true/',
		'/wp-login\.php/',
		'/wp-cron\.php/',
		'/xmlrpc\.php/',
	);

	/**
	 * Query parameters to preserve during URL rewriting.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $preserve_params = array(
		'ver',
		'v',
		'version',
		'cache',
		'bust',
		't',
		'timestamp',
		'_',
	);

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 * @param string $cdn_base_url CDN base URL for rewriting.
	 */
	public function __construct( $cdn_base_url ) {
		$this->cdn_base_url = rtrim( $cdn_base_url, '/' );
		$this->site_url     = rtrim( get_site_url(), '/' );
	}

	/**
	 * Rewrite asset URL to use CDN base URL.
	 *
	 * @since 2.0.0
	 * @param string $url Original asset URL.
	 * @return string Rewritten URL or original URL if not rewritable.
	 */
	public function rewrite_asset_url( $url ) {
		// Check if URL should be rewritten.
		if ( ! $this->should_rewrite_url( $url ) ) {
			return $url;
		}

		// Parse the original URL.
		$parsed_url = wp_parse_url( $url );
		if ( false === $parsed_url || empty( $parsed_url['path'] ) ) {
			return $url;
		}

		// Build the CDN URL.
		$cdn_url = $this->cdn_base_url . $parsed_url['path'];

		// Preserve query parameters.
		if ( ! empty( $parsed_url['query'] ) ) {
			$cdn_url = $this->preserve_query_params( $url, $cdn_url );
		}

		// Preserve fragment if present.
		if ( ! empty( $parsed_url['fragment'] ) ) {
			$cdn_url .= '#' . $parsed_url['fragment'];
		}

		return $cdn_url;
	}

	/**
	 * Check if URL should be rewritten.
	 *
	 * @since 2.0.0
	 * @param string $url URL to check.
	 * @return bool True if URL should be rewritten, false otherwise.
	 */
	public function should_rewrite_url( $url ) {
		// Empty URL or CDN base URL not set.
		if ( empty( $url ) || empty( $this->cdn_base_url ) ) {
			return false;
		}

		// Parse URL for validation.
		$parsed_url = wp_parse_url( $url );
		if ( false === $parsed_url ) {
			return false;
		}

		// Check if URL is external (different domain).
		if ( $this->is_external_url( $url ) ) {
			return false;
		}

		// Check if URL matches exclude patterns.
		if ( $this->matches_exclude_patterns( $url ) ) {
			return false;
		}

		// Check if URL has rewritable extension.
		if ( ! $this->has_rewritable_extension( $url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Preserve query parameters from original URL to rewritten URL.
	 *
	 * @since 2.0.0
	 * @param string $original_url Original URL with query parameters.
	 * @param string $rewritten_url Rewritten URL without query parameters.
	 * @return string Rewritten URL with preserved query parameters.
	 */
	public function preserve_query_params( $original_url, $rewritten_url ) {
		$original_parsed = wp_parse_url( $original_url );

		if ( empty( $original_parsed['query'] ) ) {
			return $rewritten_url;
		}

		// Parse query parameters.
		parse_str( $original_parsed['query'], $query_params );

		// Filter to preserve only specified parameters.
		$preserved_params = array();
		foreach ( $query_params as $key => $value ) {
			if ( $this->should_preserve_query_param( $key, $value ) ) {
				$preserved_params[ $key ] = $value;
			}
		}

		// Add preserved parameters to rewritten URL.
		if ( ! empty( $preserved_params ) ) {
			$query_string   = http_build_query( $preserved_params );
			$rewritten_url .= '?' . $query_string;
		}

		return $rewritten_url;
	}

	/**
	 * Check if URL is external (different domain from site).
	 *
	 * @since 2.0.0
	 * @param string $url URL to check.
	 * @return bool True if URL is external, false otherwise.
	 */
	private function is_external_url( $url ) {
		$parsed_url  = wp_parse_url( $url );
		$site_parsed = wp_parse_url( $this->site_url );

		// If no host in URL, it's relative (not external).
		if ( empty( $parsed_url['host'] ) ) {
			return false;
		}

		// Compare hosts.
		return $parsed_url['host'] !== $site_parsed['host'];
	}

	/**
	 * Check if URL matches any exclude patterns.
	 *
	 * @since 2.0.0
	 * @param string $url URL to check.
	 * @return bool True if URL matches exclude patterns, false otherwise.
	 */
	private function matches_exclude_patterns( $url ) {
		foreach ( $this->exclude_patterns as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if URL has a rewritable file extension.
	 *
	 * @since 2.0.0
	 * @param string $url URL to check.
	 * @return bool True if URL has rewritable extension, false otherwise.
	 */
	private function has_rewritable_extension( $url ) {
		// Remove query parameters and fragments for extension check.
		$url_without_query    = strtok( $url, '?' );
		$url_without_fragment = strtok( $url_without_query, '#' );

		foreach ( $this->rewritable_extensions as $extension ) {
			if ( substr( $url_without_fragment, -strlen( $extension ) ) === $extension ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a query parameter should be preserved.
	 *
	 * @since 2.0.0
	 * @param string $param_key Query parameter key.
	 * @param string $param_value Query parameter value.
	 * @return bool True if parameter should be preserved, false otherwise.
	 */
	private function should_preserve_query_param( $param_key, $param_value ) {
		// Check if it's in the standard preserve list.
		if ( in_array( $param_key, $this->preserve_params, true ) ) {
			return true;
		}

		// Check if it should be preserved by pattern.
		if ( $this->should_preserve_param( $param_key ) ) {
			return true;
		}

		// Don't preserve debug parameters with false values.
		if ( 'debug' === $param_key && 'false' === $param_value ) {
			return false;
		}

		return false;
	}

	/**
	 * Check if a query parameter should be preserved by pattern.
	 *
	 * @since 2.0.0
	 * @param string $param_key Query parameter key.
	 * @return bool True if parameter should be preserved, false otherwise.
	 */
	private function should_preserve_param( $param_key ) {
		// Preserve WordPress-specific parameters.
		$wp_params = array( 'wp_theme', 'wp_customize', 'preview', 'preview_id' );

		// Preserve cache-busting parameters (common patterns).
		$cache_patterns = array( '/^cache/', '/bust$/', '/^_/' );

		if ( in_array( $param_key, $wp_params, true ) ) {
			return true;
		}

		foreach ( $cache_patterns as $pattern ) {
			if ( preg_match( $pattern, $param_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get rewritable extensions.
	 *
	 * @since 2.0.0
	 * @return array Array of rewritable file extensions.
	 */
	public function get_rewritable_extensions() {
		return $this->rewritable_extensions;
	}

	/**
	 * Get exclude patterns.
	 *
	 * @since 2.0.0
	 * @return array Array of URL patterns to exclude from rewriting.
	 */
	public function get_exclude_patterns() {
		return $this->exclude_patterns;
	}

	/**
	 * Get preserve parameters.
	 *
	 * @since 2.0.0
	 * @return array Array of query parameters to preserve.
	 */
	public function get_preserve_params() {
		return $this->preserve_params;
	}

	/**
	 * Add custom rewritable extension.
	 *
	 * @since 2.0.0
	 * @param string $extension File extension to add (with dot).
	 */
	public function add_rewritable_extension( $extension ) {
		// Validate extension format.
		if ( ! is_string( $extension ) || ! preg_match( '/^\.[a-zA-Z0-9]+$/', $extension ) ) {
			return;
		}

		// Prevent adding potentially problematic extensions.
		$blocked_extensions = array( '.exe', '.php', '.asp', '.jsp', '.cgi', '.pl' );
		if ( in_array( strtolower( $extension ), $blocked_extensions, true ) ) {
			return;
		}

		if ( ! in_array( $extension, $this->rewritable_extensions, true ) ) {
			$this->rewritable_extensions[] = $extension;
		}
	}

	/**
	 * Add custom exclude pattern.
	 *
	 * @since 2.0.0
	 * @param string $pattern Regex pattern to exclude.
	 */
	public function add_exclude_pattern( $pattern ) {
		if ( ! in_array( $pattern, $this->exclude_patterns, true ) ) {
			$this->exclude_patterns[] = $pattern;
		}
	}

	/**
	 * Add custom preserve parameter.
	 *
	 * @since 2.0.0
	 * @param string $param Parameter name to preserve.
	 */
	public function add_preserve_param( $param ) {
		if ( ! in_array( $param, $this->preserve_params, true ) ) {
			$this->preserve_params[] = $param;
		}
	}
}
