<?php
/**
 * JTZL SW Metrics Endpoint
 *
 * This class handles the REST API endpoints for collecting and retrieving service worker cache metrics.
 *
 * @package ServiceWorker
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_Metrics_Endpoint
 *
 * Handles the REST API endpoints for collecting and retrieving service worker cache metrics.
 *
 * @since 0.1.0
 */
class JTZL_SW_Metrics_Endpoint {
	/**
	 * Constructor.
	 *
	 * Initializes the class by registering the REST API routes.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST API routes for the service worker cache metrics.
	 *
	 * This method defines the endpoints for submitting metrics and retrieving dashboard data.
	 *
	 * @since 0.1.0
	 */
	public function register_routes() {
		register_rest_route(
			'jtzl-sw/v1',
			'/metrics',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'jtzl-sw/v1',
			'/dashboard-metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_dashboard_metrics' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			'jtzl-sw/v1',
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_fresh_nonce' ),
				'permission_callback' => array( $this, 'check_nonce_permission' ),
			)
		);

		register_rest_route(
			'jtzl-sw/v1',
			'/cache-version',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cache_version' ),
				'permission_callback' => array( $this, 'check_nonce_permission' ),
			)
		);

		register_rest_route(
			'jtzl-sw/v1',
			'/cdn-error',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_cdn_error' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Checks if the request has permission to access the metrics endpoint.
	 *
	 * This method verifies the nonce for service worker requests and allows access if valid.
	 * It also checks if the request is from the same origin as the site URL.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return bool True if permission is granted, false otherwise.
	 */
	public function check_permission( WP_REST_Request $request ) {
		// Allow service worker requests with valid nonce.
		// Service workers do not have user context, so we rely on nonce verification.
		$nonce    = $request->get_header( 'X-WP-Nonce' );
		$is_valid = wp_verify_nonce( $nonce, 'wp_rest' );

		// For service worker requests, we allow if nonce is valid OR if it is from same origin.
		if ( true === $is_valid ) {
			return true;
		}

		// Fallback: check if request is from same origin for service worker compatibility.
		$origin   = $request->get_header( 'Origin' );
		$site_url = get_site_url();

		if ( ( ! empty( $origin ) ) && ( ! empty( $site_url ) ) && ( wp_parse_url( $origin, PHP_URL_HOST ) === wp_parse_url( $site_url, PHP_URL_HOST ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if the request has permission to get a fresh nonce.
	 *
	 * This method allows same-origin requests to get a fresh nonce for metrics sync.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return bool True if permission is granted, false otherwise.
	 */
	public function check_nonce_permission( WP_REST_Request $request ) {
		// Verify the nonce for service worker requests.
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			// Allow same-origin requests to get a fresh nonce.
			$origin   = $request->get_header( 'Origin' );
			$referer  = $request->get_header( 'Referer' );
			$site_url = get_site_url();

			// Check origin or referer for same-origin requests.
			if ( ( ! empty( $origin ) ) && ( ! empty( $site_url ) ) && ( wp_parse_url( $origin, PHP_URL_HOST ) === wp_parse_url( $site_url, PHP_URL_HOST ) ) ) {
				return true;
			}

			if ( ( ! empty( $referer ) ) && ( ! empty( $site_url ) ) && ( wp_parse_url( $referer, PHP_URL_HOST ) === wp_parse_url( $site_url, PHP_URL_HOST ) ) ) {
				return true;
			}

			// For service worker requests, be more permissive but still validate the host.
			$http_host = $request->get_header( 'Host' );
			if ( ( ! empty( $http_host ) ) && ( ! empty( $site_url ) ) && ( wp_parse_url( $site_url, PHP_URL_HOST ) === $http_host ) ) {
				return true;
			}

			// Last resort: allow if no better security context is available.
			// This is needed for service workers that do not send origin/referer headers.
			// But we still need to validate the host matches our site.
			if ( ( empty( $origin ) ) && ( empty( $referer ) ) && ( ! empty( $http_host ) ) && ( ! empty( $site_url ) ) && ( wp_parse_url( $site_url, PHP_URL_HOST ) === $http_host ) ) {
				return true;
			}
			return false;
		}
		return true;
	}

	/**
	 * Returns a fresh nonce for the service worker.
	 *
	 * This method generates a new nonce that the service worker can use for metrics sync.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The response object containing the fresh nonce.
	 */
	public function get_fresh_nonce() {
		$fresh_nonce = wp_create_nonce( 'wp_rest' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'nonce'   => $fresh_nonce,
			),
			200
		);
	}

	/**
	 * Returns the current cache version for the service worker.
	 *
	 * This method returns the current cache version that service workers can use
	 * to determine if they need to clear their caches.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The response object containing the cache version.
	 */
	public function get_cache_version() {
		// Skip table check for performance - cache version uses wp_options, not custom tables.
		$version = JTZL_SW_DB::get_cache_version();

		$response = new WP_REST_Response(
			array(
				'success' => true,
				'version' => $version,
			),
			200
		);

		// Add cache headers to reduce server load.
		$response->header( 'Cache-Control', 'public, max-age=60' ); // 1-minute cache
		$response->header( 'Expires', gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );

		return $response;
	}

	/**
	 * Handles the POST request to collect service worker cache metrics.
	 *
	 * This method processes the incoming metrics data, updates or inserts it into the database,
	 * and returns a response indicating success or failure. It supports both the legacy format
	 * (array of individual events) and the new format (aggregated + assets structure).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object containing success status.
	 */
	public function handle_request( $request ) {
		global $wpdb;

		// Ensure table exists before proceeding.
		JTZL_SW_DB::ensure_table_exists();

		$table_name   = $wpdb->prefix . 'jtzl_sw_cache_metrics';
		$assets_table = $wpdb->prefix . 'jtzl_sw_cached_assets';
		$params       = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			// Invalid payload type received.
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid payload: expected array, got ' . gettype( $params ),
				),
				400
			);
		}

		if ( empty( $params ) ) {
			// Empty payload received.
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Empty payload received',
				),
				400
			);
		}

		// Check if this is the new format with aggregated and assets properties.
		if ( isset( $params['aggregated'] ) && isset( $params['assets'] ) ) {
			return $this->handle_new_format_request( $params );
		}

		// Handle legacy format (array of individual events).
		return $this->handle_legacy_format_request( $params );
	}

	/**
	 * Handles the new metrics format with aggregated data and per-asset frequency data.
	 *
	 * @since 0.5.0
	 *
	 * @param array $params The request parameters containing aggregated and assets data.
	 * @return WP_REST_Response The response object containing success status.
	 */
	private function handle_new_format_request( $params ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . 'jtzl_sw_cache_metrics';
		$metric_date = current_time( 'Y-m-d' );
		$aggregated  = $params['aggregated'];
		$assets      = $params['assets'];

		// Validate aggregated data structure.
		if ( ! is_array( $aggregated ) ) {
			// Invalid aggregated data type.
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid aggregated data: expected array',
				),
				400
			);
		}

		// Extract aggregated metrics with defaults.
		$hits        = isset( $aggregated['hits'] ) ? absint( $aggregated['hits'] ) : 0;
		$misses      = isset( $aggregated['misses'] ) ? absint( $aggregated['misses'] ) : 0;
		$bytes_saved = isset( $aggregated['bytesSaved'] ) ? absint( $aggregated['bytesSaved'] ) : 0;
		$cdn_hits    = isset( $aggregated['cdnHits'] ) ? absint( $aggregated['cdnHits'] ) : 0;
		$cdn_misses  = isset( $aggregated['cdnMisses'] ) ? absint( $aggregated['cdnMisses'] ) : 0;

		// Process per-asset frequency data.
		if ( is_array( $assets ) && ! empty( $assets ) ) {
			$processed_assets = 0;
			$failed_assets    = 0;

			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					++$failed_assets;
					continue;
				}

				$url       = isset( $asset['url'] ) ? esc_url_raw( $asset['url'] ) : '';
				$type      = isset( $asset['type'] ) ? sanitize_text_field( $asset['type'] ) : '';
				$size      = isset( $asset['size'] ) ? absint( $asset['size'] ) : 0;
				$hit_count = isset( $asset['hitCount'] ) ? absint( $asset['hitCount'] ) : 1;

				if ( empty( $url ) ) {
					// Skipping asset with empty URL.
					++$failed_assets;
					continue;
				}

				// Use current UTC time for last_accessed since this is aggregated data.
				$last_accessed = gmdate( 'Y-m-d H:i:s' );

				// Process hits for this asset.
				$upsert_result = JTZL_SW_DB::upsert_asset_hit_count( $url, $type, $size, $last_accessed, $hit_count );
				if ( ! $upsert_result ) {
					// Failed to upsert asset hit count.
					++$failed_assets;
					continue; // Continue to the next asset if upsert fails for this one.
				} else {
					++$processed_assets;
					// Asset processed successfully.
				}
			}
		}

		// Update aggregated metrics in the main metrics table.
		try {
			// Attempting to insert or update record.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table aggregation write
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO `{$table_name}` (metric_date, hits, misses, bytes_saved, cdn_hits, cdn_misses)
					VALUES (%s, %d, %d, %d, %d, %d)
					ON DUPLICATE KEY UPDATE
						hits = hits + VALUES(hits),
						misses = misses + VALUES(misses),
						bytes_saved = bytes_saved + VALUES(bytes_saved),
						cdn_hits = cdn_hits + VALUES(cdn_hits),
						cdn_misses = cdn_misses + VALUES(cdn_misses)",
					$metric_date,
					$hits,
					$misses,
					$bytes_saved,
					$cdn_hits,
					$cdn_misses
				)
			);

			if ( false === $result ) {
				// Database operation failed.
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Database operation failed: ' . $wpdb->last_error,
					),
					500
				);
			}
		} catch ( Exception $e ) {
			// Database operation exception occurred.
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Database operation failed: ' . $e->getMessage(),
				),
				500
			);
		}

		// Invalidate dashboard caches after write.
		wp_cache_delete( 'dashboard_totals', 'jtzl_sw' );
		wp_cache_delete( 'dashboard_history_7', 'jtzl_sw' );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Handles the legacy metrics format (array of individual events).
	 *
	 * This method maintains backward compatibility with the original format.
	 *
	 * @since 0.5.0
	 *
	 * @param array $params The request parameters containing individual event data.
	 * @return WP_REST_Response The response object containing success status.
	 */
	private function handle_legacy_format_request( $params ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . 'jtzl_sw_cache_metrics';
		$metric_date = current_time( 'Y-m-d' );

		// Initialize counters for both origin and CDN assets.
		$origin_hits        = 0;
		$origin_misses      = 0;
		$origin_bytes_saved = 0;
		$cdn_hits           = 0;
		$cdn_misses         = 0;

		foreach ( $params as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$hit    = ! empty( $log['hit'] );
			$size   = isset( $log['size'] ) && is_numeric( $log['size'] ) ? absint( $log['size'] ) : 0;
			$source = isset( $log['source'] ) && 'cdn' === $log['source'] ? 'cdn' : 'origin';
			$url    = isset( $log['resourceURL'] ) ? esc_url_raw( $log['resourceURL'] ) : '';

			if ( 'cdn' === $source ) {
				$cdn_hits   += $hit ? 1 : 0;
				$cdn_misses += $hit ? 0 : 1;
				// Skip CDN bytes saved - unreliable due to CORS restrictions.
			} else {
				$origin_hits        += $hit ? 1 : 0;
				$origin_misses      += $hit ? 0 : 1;
				$origin_bytes_saved += $hit ? $size : 0;
			}

			if ( $hit && $url ) {
				$type          = isset( $log['type'] ) ? sanitize_text_field( $log['type'] ) : '';
				$timestamp     = isset( $log['timestamp'] ) ? sanitize_text_field( $log['timestamp'] ) : '';
				$ts            = $timestamp ? strtotime( $timestamp ) : false;
				$last_accessed = ( false === $ts ) ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', $ts );
				JTZL_SW_DB::upsert_asset_hit_count( $url, $type, $size, $last_accessed );
			}
		}

		// Update aggregated metrics in the main metrics table.
		try {
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO `{$table_name}` (metric_date, hits, misses, bytes_saved, cdn_hits, cdn_misses)
					VALUES (%s, %d, %d, %d, %d, %d)
					ON DUPLICATE KEY UPDATE
						hits = hits + VALUES(hits),
						misses = misses + VALUES(misses),
						bytes_saved = bytes_saved + VALUES(bytes_saved),
						cdn_hits = cdn_hits + VALUES(cdn_hits),
						cdn_misses = cdn_misses + VALUES(cdn_misses)",
					$metric_date,
					$origin_hits,
					$origin_misses,
					$origin_bytes_saved,
					$cdn_hits,
					$cdn_misses
				)
			);

			if ( false === $result ) {
				// Database operation failed.
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Database operation failed: ' . $wpdb->last_error,
					),
					500
				);
			}
		} catch ( Exception $e ) {
			// Database operation exception occurred.
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Database operation failed: ' . $e->getMessage(),
				),
				500
			);
		}

		// Invalidate dashboard caches after write.
		wp_cache_delete( 'dashboard_totals', 'jtzl_sw' );
		wp_cache_delete( 'dashboard_history_7', 'jtzl_sw' );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Retrieves dashboard metrics for the service worker cache.
	 *
	 * This method fetches total metrics and recent history from the database
	 * for display on the admin dashboard.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return WP_REST_Response The response object containing metrics data.
	 */
	public function get_dashboard_metrics( WP_REST_Request $request ) {
		global $wpdb;

		// Ensure table exists before proceeding.
		JTZL_SW_DB::ensure_table_exists();

		// Try cache for totals.
		$totals = wp_cache_get( 'dashboard_totals', 'jtzl_sw' );
		if ( false === $totals ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- computed aggregate read
			$totals = $wpdb->get_row( "SELECT SUM(hits) as hits, SUM(misses) as misses, SUM(bytes_saved) as bytes_saved, SUM(cdn_hits) as cdn_hits, SUM(cdn_misses) as cdn_misses FROM {$wpdb->prefix}jtzl_sw_cache_metrics" );
			wp_cache_set( 'dashboard_totals', $totals, 'jtzl_sw', 60 );
		}

		$from_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$history   = wp_cache_get( 'dashboard_history_7', 'jtzl_sw' );
		if ( false === $history ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- bounded historical read
			$history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jtzl_sw_cache_metrics WHERE metric_date >= %s ORDER BY metric_date ASC", $from_date ) );
			wp_cache_set( 'dashboard_history_7', $history, 'jtzl_sw', 60 );
		}

		// Get top assets by hit count frequency instead of last accessed.
		$top_assets = JTZL_SW_DB::get_top_assets_by_frequency( 10 );

		// return raw data for debugging.
		return new WP_REST_Response(
			array(
				'totals'     => $totals,
				'history'    => $history,
				'top_assets' => $top_assets,
			),
			200
		);
	}

	/**
	 * Check if the user has admin permissions to access dashboard metrics.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return bool|WP_Error True if permission is granted, otherwise a WP_Error.
	 */
	public function check_admin_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', 'Bad nonce.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Handles CDN error logging from the service worker.
	 *
	 * @since 2.0.0
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object containing success status.
	 */
	public function handle_cdn_error( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid payload: expected array',
				),
				400
			);
		}

		// Extract and sanitize error data.
		$url          = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '';
		$event_type   = isset( $params['event_type'] ) ? sanitize_text_field( $params['event_type'] ) : 'unknown';
		$details      = isset( $params['details'] ) && is_array( $params['details'] ) ? $params['details'] : array();
		$timestamp    = isset( $params['timestamp'] ) ? sanitize_text_field( $params['timestamp'] ) : '';
		$user_agent   = isset( $params['user_agent'] ) ? sanitize_text_field( $params['user_agent'] ) : '';
		$cdn_base_url = isset( $params['cdn_base_url'] ) ? esc_url_raw( $params['cdn_base_url'] ) : '';

		if ( empty( $url ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'URL is required',
				),
				400
			);
		}

		// Map service worker event types to PHP error types.
		$error_type_mapping = array(
			'fetch_failed'      => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
			'handler_error'     => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['UNKNOWN'],
			'bypass_activated'  => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
			'fallback_failed'   => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['HTTP_ERROR'],
			'conversion_failed' => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONFIGURATION'],
			'fallback_error'    => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
			'cache_fallback'    => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
			'cache_error'       => JTZL_SW_CDN_Error_Handler::ERROR_TYPES['UNKNOWN'],
		);

		$error_type = isset( $error_type_mapping[ $event_type ] ) ?
			$error_type_mapping[ $event_type ] :
			JTZL_SW_CDN_Error_Handler::ERROR_TYPES['UNKNOWN'];

		// Determine severity from details.
		$severity = JTZL_SW_CDN_Error_Handler::SEVERITY_LEVELS['ERROR'];
		if ( isset( $details['severity'] ) ) {
			$valid_severities = array( 'critical', 'error', 'warning', 'info' );
			if ( in_array( $details['severity'], $valid_severities, true ) ) {
				$severity = $details['severity'];
			}
		}

		// Create error message.
		$error_message = sprintf( 'CDN error from service worker: %s', $event_type );
		if ( isset( $details['error'] ) ) {
			$error_message .= ' - ' . sanitize_text_field( $details['error'] );
		}

		// Prepare context data (filter sensitive information).
		$context = array(
			'service_worker_event' => $event_type,
			'cdn_url'              => $url,
			'cdn_base_url'         => $cdn_base_url,
			'timestamp'            => $timestamp,
			'user_agent_type'      => $this->get_user_agent_type( $user_agent ),
		);

		// Add safe details.
		if ( isset( $details['error_type'] ) ) {
			$context['error_type'] = sanitize_text_field( $details['error_type'] );
		}
		if ( isset( $details['response_code'] ) ) {
			$context['response_code'] = absint( $details['response_code'] );
		}

		// Log the CDN error.
		JTZL_SW_CDN_Error_Handler::log_cdn_error(
			$error_type,
			$error_message,
			$context,
			$severity
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'CDN error logged successfully',
			),
			200
		);
	}

	/**
	 * Get a safe user agent type for logging (no PII).
	 *
	 * @since 2.0.0
	 * @param string $user_agent Full user agent string.
	 * @return string Safe user agent type.
	 */
	private function get_user_agent_type( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return 'unknown';
		}

		// Extract browser type only (no version numbers or detailed info).
		if ( strpos( $user_agent, 'Chrome' ) !== false ) {
			return 'chrome';
		} elseif ( strpos( $user_agent, 'Firefox' ) !== false ) {
			return 'firefox';
		} elseif ( strpos( $user_agent, 'Safari' ) !== false ) {
			return 'safari';
		} elseif ( strpos( $user_agent, 'Edge' ) !== false ) {
			return 'edge';
		} else {
			return 'other';
		}
	}
}
