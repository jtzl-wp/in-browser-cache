<?php
/**
 * JTZL SW File Handler
 *
 * Handles the service worker file serving and related functionality.
 *
 * @package ServiceWorker
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_File_Handler
 *
 * Handles the service worker file serving and related functionality.
 *
 * @since 0.1.0
 */
class JTZL_SW_File_Handler {

	/**
	 * Constructor.
	 *
	 * Initializes the class by adding necessary hooks for rewrite rules, query vars, and template redirection.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_service_worker' ) );
		add_filter( 'redirect_canonical', array( $this, 'prevent_sw_redirect' ), 10, 2 );
	}

	/**
	 * Adds a rewrite rule to serve the service worker file.
	 *
	 * This method registers a custom rewrite rule that maps requests for `service-worker.js`
	 * to a query variable that will be handled by the `serve_service_worker` method.
	 *
	 * @since 0.1.0
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^service-worker\.js(\?.*)?$', 'index.php?jtzl_sw_serve_sw=1', 'top' );
		add_rewrite_rule( '^sw-health-check\.json(\?.*)?$', 'index.php?jtzl_sw_health_check=1', 'top' );
	}

	/**
	 * Adds custom query variables to the WordPress query.
	 *
	 * This method registers a custom query variable `jtzl_sw_serve_sw` that will be used
	 * to determine if the service worker file should be served.
	 *
	 * @since 0.1.0
	 *
	 * @param array $vars Existing query variables.
	 * @return array Modified query variables including the custom one.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'jtzl_sw_serve_sw';
		$vars[] = 'jtzl_sw_health_check';
		return $vars;
	}

	/**
	 * Serves the service worker file or health check endpoint if the query variable is set.
	 *
	 * This method checks if the `jtzl_sw_serve_sw` query variable is set and serves the
	 * service worker JavaScript file with appropriate headers. It also replaces placeholders
	 * in the file with dynamic values from plugin options, including dynamic WordPress URLs
	 * for AJAX and REST API endpoints.
	 *
	 * All dynamic content is properly escaped using WordPress escaping functions to prevent
	 * XSS vulnerabilities and ensure safe JavaScript output.
	 *
	 * @since 0.1.0
	 * @since 1.0.0 Added dynamic AJAX URL and WP JSON URL generation.
	 */
	public function serve_service_worker() {
		// Handle health check endpoint.
		if ( get_query_var( 'jtzl_sw_health_check' ) ) {
			$this->serve_health_check();
			return;
		}

		if ( get_query_var( 'jtzl_sw_serve_sw' ) ) {
			$sw_template_path = plugin_dir_path( __DIR__ ) . 'build/service-worker.js';

			if ( ! file_exists( $sw_template_path ) ) {
				// Log template file missing error.
				error_log( 'JTZL Service Worker: Template file missing at ' . $sw_template_path );
				status_header( 404 );
				exit;
			}

			$options            = get_option( 'jtzl_sw_options' );
			$max_cache_lifetime = isset( $options['max_cache_lifetime'] ) ? (int) $options['max_cache_lifetime'] : 30;
			$max_cache_size     = isset( $options['max_cache_size'] ) ? (int) $options['max_cache_size'] : 50;

			// Generate REST API URLs - these will be escaped later for JavaScript context.
			$rest_url          = rest_url( 'jtzl-sw/v1/metrics' );
			$nonce_url         = rest_url( 'jtzl-sw/v1/nonce' );
			$cache_version_url = rest_url( 'jtzl-sw/v1/cache-version' );
			$rest_nonce        = wp_create_nonce( 'wp_rest' );

			// Generate dynamic WordPress URLs using proper WordPress functions.
			$ajax_url    = admin_url( 'admin-ajax.php' );
			$wp_json_url = rest_url( 'wp/v2/' );

			// Performance intervals (convert minutes to milliseconds).
			$cache_version_interval = isset( $options['cache_version_check_interval'] ) ? (int) $options['cache_version_check_interval'] : 3;
			$metrics_sync_interval  = isset( $options['metrics_sync_interval'] ) ? (int) $options['metrics_sync_interval'] : 5;
			$health_check_interval  = isset( $options['health_check_interval'] ) ? (int) $options['health_check_interval'] : 10;

			$content = file_get_contents( $sw_template_path );

			// Replace placeholders with properly escaped values.
			// All dynamic values are escaped using appropriate WordPress escaping functions
			// to prevent XSS vulnerabilities and ensure safe JavaScript output.
			// Define required placeholders for validation.
			$required_placeholders = array(
				'%%MAX_CACHE_LIFETIME%%',
				'%%MAX_CACHE_SIZE%%',
				'%%REST_URL%%',
				'%%REST_NONCE%%',
				'%%NONCE_URL%%',
				'%%CACHE_VERSION_URL%%',
				'%%CACHE_VERSION_INTERVAL%%',
				'%%METRICS_SYNC_INTERVAL%%',
				'%%HEALTH_CHECK_INTERVAL%%',
				'%%AJAX_URL%%',
				'%%WP_JSON_URL%%',
			);

			$content = str_replace(
				$required_placeholders,
				array(
					(int) $max_cache_lifetime,           // Integer values are safe.
					(int) $max_cache_size,               // Integer values are safe.
					addslashes( esc_url_raw( $rest_url ) ),                 // URL validated and escaped for single-quoted JS strings.
					addslashes( $rest_nonce ),               // Nonce escaped for single-quoted JS strings.
					addslashes( esc_url_raw( $nonce_url ) ),                // URL validated and escaped for single-quoted JS strings.
					addslashes( esc_url_raw( $cache_version_url ) ),        // URL validated and escaped for single-quoted JS strings.
					(int) ( $cache_version_interval * 60 * 1000 ), // Integer calculation is safe.
					(int) ( $metrics_sync_interval * 60 * 1000 ),  // Integer calculation is safe.
					(int) ( $health_check_interval * 60 * 1000 ),  // Integer calculation is safe.
					addslashes( esc_url_raw( $ajax_url ) ),                 // URL validated and escaped for single-quoted JS strings.
					addslashes( esc_url_raw( $wp_json_url ) ),              // URL validated and escaped for single-quoted JS strings.
				),
				$content
			);

			// Validate that all required placeholders have been replaced.
			$validation_errors = array();
			foreach ( $required_placeholders as $placeholder ) {
				if ( strpos( $content, $placeholder ) !== false ) {
					$validation_errors[] = $placeholder;
				}
			}

			// Log any template processing failures.
			if ( ! empty( $validation_errors ) ) {
				$error_message = 'JTZL Service Worker: Template processing failed. Unreplaced placeholders: ' . implode( ', ', $validation_errors );
				error_log( $error_message );

				// Return 500 error for template processing failures.
				status_header( 500 );
				exit;
			}

			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
			header( 'X-Content-Type-Options: nosniff' );

			/*
			 * Output the service worker JavaScript content.
			 *
			 * This outputs a complete JavaScript file where:
			 * 1. The base content comes from a controlled template file (build/service-worker.js)
			 * 2. All dynamic values are properly escaped for JavaScript context during str_replace()
			 * 3. Content-Type header is set to application/javascript
			 * 4. All placeholders have been validated and replaced
			 *
			 * Since this is a complete JavaScript file (not HTML with embedded JS),
			 * and all dynamic content has been pre-escaped, direct output is appropriate.
			 */
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $content;
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	/**
	 * Serves the health check endpoint for service worker auto-deregister functionality.
	 *
	 * This endpoint allows the service worker to check if the plugin is still active.
	 * If the plugin is deactivated, this endpoint will return a deactivated status,
	 * triggering the service worker's auto-deregister mechanism.
	 *
	 * @since 0.2.4
	 */
	private function serve_health_check() {
		// Check if plugin is deactivated.
		$is_deactivated = get_transient( 'jtzl_sw_deactivated' );
		$deactivated_at = get_option( 'jtzl_sw_deactivated_at' );

		// Get plugin options to verify plugin state.
		$options        = get_option( 'jtzl_sw_options' );
		$plugin_enabled = isset( $options['enabled'] ) && (bool) $options['enabled'];

		$response = array(
			'status'         => 'active',
			'timestamp'      => time(),
			'plugin_enabled' => $plugin_enabled,
			'version'        => JTZL_SW_VERSION,
		);

		// If plugin is deactivated or disabled, return deactivated status.
		if ( $is_deactivated || ! $plugin_enabled ) {
			$response['status']         = 'deactivated';
			$response['deactivated_at'] = $deactivated_at;
			$response['reason']         = $is_deactivated ? 'plugin_deactivated' : 'plugin_disabled';
		}

		// Set appropriate headers.
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Return JSON response.
		echo wp_json_encode( $response );
		exit;
	}

	/**
	 * Prevents redirects for the service worker file and health check endpoint.
	 *
	 * This method checks if the requested URL is for the service worker file or
	 * health check endpoint and prevents any redirects to ensure they are served correctly.
	 *
	 * @since 0.1.0
	 *
	 * @param string|bool $redirect_url  The URL to redirect to, or false to prevent redirection.
	 * @param string      $requested_url The requested URL.
	 *
	 * @return string|bool The redirect URL or false to prevent redirection.
	 */
	public function prevent_sw_redirect( $redirect_url, $requested_url ) {
		// If the requested URL is our service worker or health check, prevent any redirect.
		if ( strpos( $requested_url, '/service-worker.js' ) !== false ||
			strpos( $requested_url, '/sw-health-check.json' ) !== false ) {
			return false;
		}
		return $redirect_url;
	}
}
