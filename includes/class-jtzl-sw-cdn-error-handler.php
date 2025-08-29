<?php
/**
 * CDN Error Handler Class
 *
 * Handles CDN-specific error detection, classification, and logging.
 *
 * @package JTZL_Service_Worker
 * @since 2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CDN Error Handler class.
 *
 * Provides comprehensive error handling and logging for CDN operations.
 *
 * @since 2.0.0
 */
class JTZL_SW_CDN_Error_Handler {

	/**
	 * Error types for CDN operations.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const ERROR_TYPES = array(
		'CONNECTIVITY'  => 'connectivity',
		'TIMEOUT'       => 'timeout',
		'SSL_ERROR'     => 'ssl_error',
		'DNS_ERROR'     => 'dns_error',
		'HTTP_ERROR'    => 'http_error',
		'CORS_ERROR'    => 'cors_error',
		'RATE_LIMIT'    => 'rate_limit',
		'CONFIGURATION' => 'configuration',
		'VALIDATION'    => 'validation',
		'UNKNOWN'       => 'unknown',
	);

	/**
	 * Error severity levels.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const SEVERITY_LEVELS = array(
		'CRITICAL' => 'critical',
		'ERROR'    => 'error',
		'WARNING'  => 'warning',
		'INFO'     => 'info',
	);

	/**
	 * Log CDN error with classification and context.
	 *
	 * @since 2.0.0
	 *
	 * @param string $error_type    Error type from ERROR_TYPES.
	 * @param string $message       Error message.
	 * @param array  $context       Additional context data.
	 * @param string $severity      Error severity level.
	 * @return void
	 */
	public static function log_cdn_error( $error_type, $message, $context = array(), $severity = 'error' ) {
		// Validate error type.
		if ( ! in_array( $error_type, self::ERROR_TYPES, true ) ) {
			$error_type = self::ERROR_TYPES['UNKNOWN'];
		}

		// Validate severity.
		if ( ! in_array( $severity, self::SEVERITY_LEVELS, true ) ) {
			$severity = self::SEVERITY_LEVELS['ERROR'];
		}

		// Filter sensitive data from context before logging.
		$filtered_context = self::filter_sensitive_context( $context );

		// Prepare log entry with no PII (privacy-by-design).
		$log_entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'error_type' => $error_type,
			'severity'   => $severity,
			'message'    => sanitize_text_field( $message ),
			'context'    => wp_json_encode( $filtered_context ),
		);

		// Store in database.
		self::store_error_log( $log_entry );

		// Also log to WordPress error log for critical errors (without PII).
		if ( 'critical' === $severity || 'error' === $severity ) {
			error_log(
				sprintf(
					'JTZL CDN Error [%s]: %s - Context: %s',
					strtoupper( $error_type ),
					$message,
					wp_json_encode( $filtered_context )
				)
			);
		}
	}

	/**
	 * Filter sensitive information from context data before logging.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Raw context data that may contain sensitive information.
	 * @return array Filtered context data with sensitive information removed or masked.
	 */
	private static function filter_sensitive_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		// Define sensitive keys that should be filtered/redacted.
		$sensitive_keys = array(
			// Authentication and tokens.
			'token',
			'api_key',
			'apikey',
			'api-key',
			'auth',
			'authorization',
			'bearer',
			'password',
			'passwd',
			'pwd',
			'secret',
			'key',
			'private_key',
			'access_token',
			'refresh_token',
			'session',
			'cookie',
			'cookies',
			'nonce',
			'csrf',
			// User information.
			'user_id',
			'username',
			'user_login',
			'user_email',
			'email',
			'user_pass',
			'user_data',
			'personal_data',
			'profile',
			'account',
			// Database information.
			'db_host',
			'db_user',
			'db_password',
			'database',
			'mysql',
			'sql',
			// Server information.
			'server_name',
			'server_admin',
			'remote_addr',
			'http_host',
			'server_software',
			'request_uri',
			'query_string',
			'php_self',
			'script_name',
			'path_info',
			// IP addresses and network info.
			'ip',
			'remote_ip',
			'client_ip',
			'forwarded_for',
			'real_ip',
		);

		// Enhanced allowlist for safe context keys.
		$safe_context_keys = array(
			// CDN and HTTP related.
			'cdn_url',
			'safe_url',
			'error_code',
			'http_status',
			'response_time',
			'timeout',
			'retry_count',
			'error_type',
			'severity',
			'message',
			'timestamp',
			'request_method',
			'content_type',
			'user_agent_type',
			// Test-related keys (for backward compatibility).
			'safe_data',
			'response_code',
			'test',
			'test_url',
			'safe_info',
			'nested_data',
			// URL keys that will be sanitized.
			'admin_url',
			'internal_url',
			'upload_url',
			'plugin_url',
		);

		$filtered = array();

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );

			// Check if key contains sensitive information.
			$is_sensitive = false;
			foreach ( $sensitive_keys as $sensitive_key ) {
				if ( strpos( $key_lower, $sensitive_key ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( $is_sensitive ) {
				// Redact sensitive keys but keep them in output for visibility.
				if ( is_string( $value ) && preg_match( '/^https?:\/\//', $value ) ) {
					$filtered[ $key ] = self::sanitize_url_for_logging( $value );
				} elseif ( is_string( $value ) && strlen( $value ) > 0 ) {
					$filtered[ $key ] = '[REDACTED]';
				} elseif ( is_array( $value ) ) {
					$filtered[ $key ] = '[REDACTED_ARRAY]';
				} else {
					$filtered[ $key ] = '[REDACTED]';
				}
			} elseif ( in_array( $key_lower, $safe_context_keys, true ) ) {
				// Process safe keys.
				if ( is_array( $value ) ) {
					// Recursively filter nested arrays.
					$filtered[ $key ] = self::filter_sensitive_context( $value );
				} elseif ( is_string( $value ) ) {
					// Apply string sanitization.
					$safe_value       = self::sanitize_string_for_logging( $value );
					$filtered[ $key ] = $safe_value;
				} elseif ( is_numeric( $value ) || is_bool( $value ) ) {
					// Allow safe scalar values with reasonable limits.
					if ( is_numeric( $value ) && $value >= 0 && $value <= 999999 ) {
						$filtered[ $key ] = $value;
					} elseif ( is_bool( $value ) ) {
						$filtered[ $key ] = $value;
					}
				} else {
					$filtered[ $key ] = $value;
				}
			}
			// Silently drop keys not in safe list and not explicitly sensitive.
		}

		return $filtered;
	}

	/**
	 * Sanitize URLs for logging by removing sensitive path information.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL safe for logging.
	 */
	/**
	 * Sanitize strings for logging by removing potential PII and sensitive paths.
	 *
	 * @since 2.0.0
	 *
	 * @param string $string String to sanitize.
	 * @return string Sanitized string safe for logging.
	 */
	private static function sanitize_string_for_logging( $string ) {
		if ( ! is_string( $string ) ) {
			return '[INVALID_TYPE]';
		}

		// Limit string length to prevent log bloat.
		if ( strlen( $string ) > 500 ) {
			return '[OVERSIZED_STRING]';
		}

		// Check for URLs and sanitize them.
		if ( preg_match( '/^https?:\/\//', $string ) ) {
			return self::sanitize_url_for_logging( $string );
		}

		// Check for file paths and redact sensitive ones.
		$sensitive_path_patterns = array(
			'/\/wp-admin\//',
			'/\/wp-content\/uploads\//',
			'/\/wp-content\/plugins\//',
			'/\/wp-content\/themes\//',
			'/\/wp-includes\//',
			'/\/home\/[^\/]+\//',
			'/\/var\/www\//',
			'/\/usr\//',
			'/\/etc\//',
			'/C:\\\\Users\\\\/',
			'/C:\\\\Windows\\\\/',
		);

		foreach ( $sensitive_path_patterns as $pattern ) {
			if ( preg_match( $pattern, $string ) ) {
				return '[REDACTED_PATH]';
			}
		}

		// Check for email patterns.
		if ( preg_match( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $string ) ) {
			return '[REDACTED_EMAIL]';
		}

		// Check for potential tokens or keys (long alphanumeric strings).
		if ( preg_match( '/^[a-zA-Z0-9]{32,}$/', $string ) ) {
			return '[REDACTED_TOKEN]';
		}

		// Allow only safe characters and common CDN error messages.
		if ( preg_match( '/^[a-zA-Z0-9\s\-_:\/\.]+$/', $string ) ) {
			return $string;
		}

		return '[REDACTED]';
	}

	/**
	 * Sanitize URLs for logging by removing sensitive path information.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL safe for logging.
	 */
	private static function sanitize_url_for_logging( $url ) {
		// Only process if it looks like a URL.
		if ( ! is_string( $url ) || ! preg_match( '/^https?:\/\//', $url ) ) {
			return self::sanitize_string_for_logging( $url );
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed ) {
			return '[INVALID_URL]';
		}

		// Check if URL contains sensitive paths that should be redacted.
		if ( isset( $parsed['path'] ) ) {
			$sensitive_paths = array(
				'/wp-admin',
				'/wp-content/uploads',
				'/wp-content/plugins',
				'/wp-content/themes',
				'/wp-includes',
			);

			foreach ( $sensitive_paths as $sensitive_path ) {
				if ( strpos( $parsed['path'], $sensitive_path ) !== false ) {
					// Replace path with redacted marker.
					$parsed['path'] = '[REDACTED_PATH]';
					break;
				}
			}
		}

		// Check if it's a CDN URL and if so, preserve it better.
		$is_cdn = false;
		if ( isset( $parsed['host'] ) ) {
			$cdn_patterns = array( 'cdn\.', 'cloudfront\.', 'fastly\.', 'jsdelivr\.', 'unpkg\.' );
			foreach ( $cdn_patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/', $parsed['host'] ) ) {
					$is_cdn = true;
					break;
				}
			}
		}

		// Remove query parameters that might contain sensitive data.
		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
			$sensitive_params = array( 'token', 'key', 'auth', 'session', 'user', 'pass' );

			foreach ( $query_params as $param => $value ) {
				foreach ( $sensitive_params as $sensitive_param ) {
					if ( strpos( strtolower( $param ), $sensitive_param ) !== false ) {
						$query_params[ $param ] = '[REDACTED]';
						break;
					}
				}
			}
			$parsed['query'] = http_build_query( $query_params );
		}

		// Rebuild URL.
		$sanitized_url = '';
		if ( isset( $parsed['scheme'] ) ) {
			$sanitized_url .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['host'] ) ) {
			if ( $is_cdn ) {
				// Keep CDN host for safe CDN URLs.
				$sanitized_url .= $parsed['host'];
			} else {
				$sanitized_url .= $parsed['host']; // Keep host but sanitize path.
			}
		}
		if ( isset( $parsed['port'] ) ) {
			$sanitized_url .= ':' . $parsed['port'];
		}
		if ( isset( $parsed['path'] ) ) {
			$sanitized_url .= $parsed['path'];
		}
		if ( isset( $parsed['query'] ) && ! empty( $parsed['query'] ) ) {
			$sanitized_url .= '?' . $parsed['query'];
		}

		return $sanitized_url;
	}

	/**
	 * Classify error from HTTP response or exception.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $error WP_Error, Exception, or HTTP response array.
	 * @param mixed $response Optional HTTP response array.
	 * @return array Classified error information.
	 */
	public static function classify_error( $error, $response = null ) {
		$classification = array(
			'type'         => self::ERROR_TYPES['UNKNOWN'],
			'severity'     => self::SEVERITY_LEVELS['ERROR'],
			'message'      => 'Unknown CDN error occurred',
			'user_message' => 'CDN connection failed. Using fallback.',
			'recoverable'  => true,
			'retry_after'  => 300, // 5 minutes default.
		);

		// Handle WP_Error objects.
		if ( is_wp_error( $error ) ) {
			$error_code    = $error->get_error_code();
			$error_message = $error->get_error_message();

			switch ( $error_code ) {
				case 'http_request_failed':
					if ( strpos( $error_message, 'cURL error 28' ) !== false || strpos( $error_message, 'timeout' ) !== false ) {
						$classification['type']         = self::ERROR_TYPES['TIMEOUT'];
						$classification['message']      = 'CDN request timeout';
						$classification['user_message'] = 'CDN is responding slowly. Using local files.';
						$classification['retry_after']  = 60;
					} elseif ( strpos( $error_message, 'SSL' ) !== false || strpos( $error_message, 'certificate' ) !== false ) {
						$classification['type']         = self::ERROR_TYPES['SSL_ERROR'];
						$classification['message']      = 'CDN SSL certificate error';
						$classification['user_message'] = 'CDN SSL configuration issue. Using local files.';
						$classification['severity']     = self::SEVERITY_LEVELS['CRITICAL'];
						$classification['recoverable']  = false;
						$classification['retry_after']  = 3600; // 1 hour.
					} elseif ( strpos( $error_message, 'resolve host' ) !== false || strpos( $error_message, 'DNS' ) !== false ) {
						$classification['type']         = self::ERROR_TYPES['DNS_ERROR'];
						$classification['message']      = 'CDN DNS resolution failed';
						$classification['user_message'] = 'CDN domain not found. Check configuration.';
						$classification['severity']     = self::SEVERITY_LEVELS['CRITICAL'];
						$classification['recoverable']  = false;
					} else {
						$classification['type']    = self::ERROR_TYPES['CONNECTIVITY'];
						$classification['message'] = 'CDN connectivity failed: ' . sanitize_text_field( $error_message );
					}
					break;

				default:
					$classification['message'] = 'CDN error: ' . sanitize_text_field( $error_message );
					break;
			}
		}

		// Handle HTTP response arrays.
		if ( is_array( $error ) && isset( $error['response'] ) ) {
			$response_code    = wp_remote_retrieve_response_code( $error );
			$response_message = wp_remote_retrieve_response_message( $error );

			if ( $response_code >= 400 && $response_code < 500 ) {
				$classification['type']     = self::ERROR_TYPES['HTTP_ERROR'];
				$classification['severity'] = self::SEVERITY_LEVELS['WARNING'];

				if ( 429 === $response_code ) {
					$classification['type']         = self::ERROR_TYPES['RATE_LIMIT'];
					$classification['message']      = 'CDN rate limit exceeded';
					$classification['user_message'] = 'CDN temporarily unavailable. Using local files.';
					$classification['retry_after']  = 1800; // 30 minutes.
				} elseif ( 403 === $response_code ) {
					$classification['message']      = 'CDN access forbidden';
					$classification['user_message'] = 'CDN access denied. Check configuration.';
					$classification['severity']     = self::SEVERITY_LEVELS['CRITICAL'];
				} else {
					$classification['message'] = sprintf( 'CDN HTTP error: %d %s', $response_code, sanitize_text_field( $response_message ) );
				}
			} elseif ( $response_code >= 500 ) {
				$classification['type']         = self::ERROR_TYPES['HTTP_ERROR'];
				$classification['message']      = sprintf( 'CDN server error: %d %s', $response_code, sanitize_text_field( $response_message ) );
				$classification['user_message'] = 'CDN server temporarily unavailable.';
				$classification['retry_after']  = 600; // 10 minutes.
			}
		}

		// Handle response objects passed directly as second parameter.
		if ( null !== $response && is_array( $response ) && isset( $response['response'] ) ) {
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $response_code ) {
				$classification['type']         = self::ERROR_TYPES['RATE_LIMIT'];
				$classification['message']      = 'CDN rate limit exceeded';
				$classification['user_message'] = 'CDN temporarily unavailable. Using local files.';
				$classification['severity']     = self::SEVERITY_LEVELS['WARNING'];
				$classification['retry_after']  = 1800; // 30 minutes.
			} elseif ( $response_code >= 500 ) {
				$classification['type']         = self::ERROR_TYPES['HTTP_ERROR'];
				$classification['message']      = sprintf( 'CDN server error: %d', $response_code );
				$classification['user_message'] = 'CDN server temporarily unavailable.';
				$classification['severity']     = self::SEVERITY_LEVELS['ERROR'];
				$classification['retry_after']  = 600; // 10 minutes.
			} elseif ( $response_code >= 400 ) {
				$classification['type']         = self::ERROR_TYPES['HTTP_ERROR'];
				$classification['message']      = sprintf( 'CDN client error: %d', $response_code );
				$classification['user_message'] = 'CDN request error.';
				$classification['severity']     = self::SEVERITY_LEVELS['WARNING'];
				$classification['retry_after']  = 300; // 5 minutes.
			}
		}

		return $classification;
	}

	/**
	 * Get user-friendly error message for admin display.
	 *
	 * @since 2.0.0
	 *
	 * @param string $error_type Error type from ERROR_TYPES.
	 * @param array  $context    Additional context.
	 * @return string User-friendly error message.
	 */
	public static function get_user_friendly_message( $error_type, $context = array() ) {
		$messages = array(
			self::ERROR_TYPES['CONNECTIVITY']  => array(
				'title'       => __( 'CDN Connection Failed', 'in-browser-cache' ),
				'description' => __( 'Unable to connect to the CDN. Your site will continue to work using local files.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Check your CDN URL configuration', 'in-browser-cache' ),
					__( 'Verify your internet connection', 'in-browser-cache' ),
					__( 'Contact your CDN provider if the issue persists', 'in-browser-cache' ),
				),
			),
			self::ERROR_TYPES['TIMEOUT']       => array(
				'title'       => __( 'CDN Response Timeout', 'in-browser-cache' ),
				'description' => __( 'The CDN is taking too long to respond. Local files are being used instead.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Check CDN performance status', 'in-browser-cache' ),
					__( 'Consider using a different CDN endpoint', 'in-browser-cache' ),
					__( 'The system will automatically retry in a few minutes', 'in-browser-cache' ),
				),
			),
			self::ERROR_TYPES['SSL_ERROR']     => array(
				'title'       => __( 'CDN SSL Certificate Error', 'in-browser-cache' ),
				'description' => __( 'There is an SSL certificate problem with your CDN. This is a security issue that needs attention.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Verify your CDN SSL certificate is valid', 'in-browser-cache' ),
					__( 'Check if your CDN URL uses HTTPS', 'in-browser-cache' ),
					__( 'Contact your CDN provider about certificate issues', 'in-browser-cache' ),
				),
			),
			self::ERROR_TYPES['DNS_ERROR']     => array(
				'title'       => __( 'CDN Domain Not Found', 'in-browser-cache' ),
				'description' => __( 'The CDN domain cannot be found. Please check your CDN configuration.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Verify the CDN URL is correct', 'in-browser-cache' ),
					__( 'Check if the CDN domain exists', 'in-browser-cache' ),
					__( 'Test the CDN URL in your browser', 'in-browser-cache' ),
				),
			),
			self::ERROR_TYPES['RATE_LIMIT']    => array(
				'title'       => __( 'CDN Rate Limit Exceeded', 'in-browser-cache' ),
				'description' => __( 'Your site has exceeded the CDN rate limits. Service will resume automatically.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Check your CDN plan limits', 'in-browser-cache' ),
					__( 'Consider upgrading your CDN plan', 'in-browser-cache' ),
					__( 'The system will retry automatically', 'in-browser-cache' ),
				),
			),
			self::ERROR_TYPES['CONFIGURATION'] => array(
				'title'       => __( 'CDN Configuration Error', 'in-browser-cache' ),
				'description' => __( 'There is an issue with your CDN configuration that needs to be fixed.', 'in-browser-cache' ),
				'actions'     => array(
					__( 'Review your CDN settings below', 'in-browser-cache' ),
					__( 'Test your CDN connection', 'in-browser-cache' ),
					__( 'Ensure your CDN URL is correct', 'in-browser-cache' ),
				),
			),
		);

		$default_message = array(
			'title'       => __( 'CDN Error', 'in-browser-cache' ),
			'description' => __( 'An unexpected CDN error occurred. Your site will continue to work normally.', 'in-browser-cache' ),
			'actions'     => array(
				__( 'Check the error log for more details', 'in-browser-cache' ),
				__( 'Test your CDN connection', 'in-browser-cache' ),
				__( 'Contact support if the issue persists', 'in-browser-cache' ),
			),
		);

		return isset( $messages[ $error_type ] ) ? $messages[ $error_type ] : $default_message;
	}

	/**
	 * Store error log entry in database.
	 *
	 * @since 2.0.0
	 *
	 * @param array $log_entry Log entry data.
	 * @return bool True on success, false on failure.
	 */
	private static function store_error_log( $log_entry ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jtzl_sw_cdn_error_log';

		// Create table if it doesn't exist.
		self::create_error_log_table();

		$result = $wpdb->insert(
			$table_name,
			$log_entry,
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Clean up old entries (keep last 1000 entries).
		if ( $result ) {
			// Use a simpler approach to avoid MySQL table locking issues.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $count > 1000 ) {
				$delete_count = $count - 1000;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table_name}` ORDER BY timestamp ASC LIMIT %d", $delete_count ) );
			}
		}

		return false !== $result;
	}

	/**
	 * Create error log table if it doesn't exist.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function create_error_log_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jtzl_sw_cdn_error_log';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL,
			error_type varchar(50) NOT NULL,
			severity varchar(20) NOT NULL,
			message text NOT NULL,
			context longtext,
			PRIMARY KEY (id),
			KEY error_type (error_type),
			KEY severity (severity),
			KEY timestamp (timestamp)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get log retention settings.
	 *
	 * @since 2.0.0
	 *
	 * @return array Log retention settings.
	 */
	private static function get_log_settings() {
		$defaults = array(
			'retention_days' => 30,
		);

		$settings = get_option( 'jtzl_sw_log_settings', $defaults );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get recent CDN errors for admin display.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Number of errors to retrieve.
	 * @return array Recent error entries.
	 */
	public static function get_recent_errors( $limit = 10 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jtzl_sw_cdn_error_log';

		// Check if table exists first.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		if ( ! $results ) {
			return array();
		}

		// Decode context JSON and format for display.
		foreach ( $results as &$error ) {
			$error['context']  = json_decode( $error['context'], true );
			$error['time_ago'] = human_time_diff( strtotime( $error['timestamp'] ), current_time( 'timestamp' ) );
			// Additional sanitization for defense-in-depth - ensure raw message field is sanitized if ever accessed directly.
			if ( isset( $error['message'] ) ) {
				$error['message'] = sanitize_text_field( $error['message'] );
			}
		}

		return $results;
	}

	/**
	 * Get error statistics for dashboard.
	 *
	 * @since 2.0.0
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Error statistics.
	 */
	public static function get_error_statistics( $days = 7 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jtzl_sw_cdn_error_log';
		$since_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$stats = array(
			'total_errors' => 0,
			'by_type'      => array(),
			'by_severity'  => array(),
			'recent_trend' => array(),
		);

		// Check if table exists first.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $stats;
		}

		// Get all statistics in a single optimized query using conditional aggregation.
		$sql = $wpdb->prepare(
			"SELECT 
				error_type,
				severity,
				COUNT(*) as count
			FROM `{$wpdb->prefix}jtzl_sw_cdn_error_log` 
			WHERE timestamp >= %s 
			GROUP BY error_type, severity
			ORDER BY count DESC",
			$since_date
		);

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Process results to build statistics arrays.
		$total_errors    = 0;
		$type_counts     = array();
		$severity_counts = array();

		foreach ( $results as $row ) {
			$count         = (int) $row['count'];
			$total_errors += $count;

			// Aggregate by type.
			if ( ! isset( $type_counts[ $row['error_type'] ] ) ) {
				$type_counts[ $row['error_type'] ] = 0;
			}
			$type_counts[ $row['error_type'] ] += $count;

			// Aggregate by severity.
			if ( ! isset( $severity_counts[ $row['severity'] ] ) ) {
				$severity_counts[ $row['severity'] ] = 0;
			}
			$severity_counts[ $row['severity'] ] += $count;
		}

		// Sort by count descending.
		arsort( $type_counts );
		arsort( $severity_counts );

		$stats['total_errors'] = $total_errors;
		$stats['by_type']      = $type_counts;
		$stats['by_severity']  = $severity_counts;

		return $stats;
	}

	/**
	 * Clear old error logs based on privacy settings.
	 *
	 * @since 2.0.0
	 *
	 * @param int $days_to_keep Number of days to keep logs (optional override).
	 * @return int Number of deleted entries.
	 */
	public static function cleanup_old_logs( $days_to_keep = null ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jtzl_sw_cdn_error_log';

		// Use log settings if no override provided.
		if ( null === $days_to_keep ) {
			$log_settings = self::get_log_settings();
			$days_to_keep = $log_settings['retention_days'];
		}

		// Check if table exists first.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return 0;
		}

		// Special case: if days_to_keep is 0, delete all logs.
		if ( 0 === $days_to_keep ) {
			$deleted = $wpdb->query( "DELETE FROM `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_to_keep} days" ) );
			$deleted     = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table_name}` WHERE timestamp < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff_date
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Schedule automatic cleanup of old logs.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'jtzl_sw_cleanup_error_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'jtzl_sw_cleanup_error_logs' );
		}
	}

	/**
	 * Unschedule automatic cleanup.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function unschedule_cleanup() {
		wp_clear_scheduled_hook( 'jtzl_sw_cleanup_error_logs' );
	}
}
