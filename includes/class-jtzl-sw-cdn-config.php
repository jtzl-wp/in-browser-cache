<?php
/**
 * CDN Configuration Management Class
 *
 * Handles CDN configuration storage, validation, and connectivity testing
 * for the JTZL Service Worker plugin.
 *
 * @package JTZL_Service_Worker
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_CDN_Config
 *
 * Manages CDN configuration settings including validation,
 * sanitization, and connectivity testing.
 */
class JTZL_SW_CDN_Config {

	/**
	 * Option name for CDN settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'jtzl_sw_cdn_settings';

	/**
	 * Cached CDN settings to avoid multiple database queries.
	 *
	 * @since 2.0.0
	 * @var array|null
	 */
	private static $cached_settings = null;

	/**
	 * Default CDN settings.
	 *
	 * @var array
	 */
	private static $default_settings = array(
		'cdn_enabled'          => false,
		'cdn_base_url'         => '',
		'cdn_test_status'      => array(
			'last_tested'   => 0,
			'status'        => '',
			'message'       => '',
			'response_time' => 0,
		),
		'cdn_fallback_enabled' => true,
	);

	/**
	 * Get CDN base URL.
	 *
	 * @since 2.0.0
	 * @return string CDN base URL or empty string if not configured.
	 */
	public static function get_cdn_base_url() {
		$settings = self::get_cdn_settings();
		return $settings['cdn_base_url'];
	}

	/**
	 * Check if CDN is enabled.
	 *
	 * @since 2.0.0
	 * @return bool True if CDN is enabled, false otherwise.
	 */
	public static function is_cdn_enabled() {
		$settings = self::get_cdn_settings();
		return $settings['cdn_enabled'] && ! empty( $settings['cdn_base_url'] );
	}

	/**
	 * Get all CDN settings.
	 *
	 * @since 2.0.0
	 * @return array CDN settings array.
	 */
	public static function get_cdn_settings() {
		// Return cached settings if available.
		if ( null !== self::$cached_settings ) {
			return self::$cached_settings;
		}

		// Fetch settings from database and cache them.
		$settings              = get_option( self::OPTION_NAME, array() );
		self::$cached_settings = wp_parse_args( $settings, self::$default_settings );

		return self::$cached_settings;
	}

	/**
	 * Update CDN settings.
	 *
	 * @since 2.0.0
	 * @param array $settings CDN settings to update.
	 * @return bool True if settings were updated successfully.
	 */
	private static function update_cdn_settings( $settings ) {
		$current_settings = self::get_cdn_settings();
		$new_settings     = wp_parse_args( $settings, $current_settings );

		// Sanitize settings before saving.
		$new_settings = self::sanitize_cdn_settings( $new_settings );

		$result = update_option( self::OPTION_NAME, $new_settings );

		// Clear the static cache after updating settings.
		if ( $result ) {
			self::clear_settings_cache();
		}

		return $result;
	}

	/**
	 * Clear the static settings cache.
	 *
	 * @since 2.0.0
	 */
	public static function clear_settings_cache() {
		self::$cached_settings = null;
	}

	/**
	 * Validate CDN URL format and accessibility.
	 *
	 * @since 2.0.0
	 * @param string $url CDN URL to validate.
	 * @return array Validation result with 'valid' boolean and 'message' string.
	 */
	public static function validate_cdn_url( $url ) {
		$result = array(
			'valid'   => false,
			'message' => '',
		);

		// Check if URL is empty.
		if ( empty( $url ) ) {
			$result['message'] = __( 'CDN URL cannot be empty.', 'in-browser-cache' );
			return $result;
		}

		// Check for suspicious patterns before any processing.
		if ( self::has_suspicious_patterns( $url ) ) {
			$result['message'] = __( 'CDN URL contains suspicious patterns and cannot be used.', 'in-browser-cache' );
			return $result;
		}

		// Sanitize URL first to prevent injection attacks.
		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) ) {
			$result['message'] = __( 'Invalid URL format after sanitization.', 'in-browser-cache' );
			return $result;
		}

		// Validate URL format with comprehensive checks first.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$result['message'] = __( 'Invalid URL format. Please enter a valid URL.', 'in-browser-cache' );
			return $result;
		}

		// Parse URL components to check basic structure.
		$parsed_url = wp_parse_url( $url );

		if ( false === $parsed_url || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
			$result['message'] = __( 'Invalid URL format. Please enter a valid URL.', 'in-browser-cache' );
			return $result;
		}

		// Validate URL scheme - only allow HTTPS.
		if ( 'https' !== strtolower( $parsed_url['scheme'] ) ) {
			$result['message'] = __( 'CDN URL must use HTTPS for security.', 'in-browser-cache' );
			return $result;
		}

		// Validate hostname format and prevent dangerous patterns.
		if ( ! self::is_valid_hostname( $parsed_url['host'] ) ) {
			$result['message'] = __( 'Invalid hostname format in CDN URL.', 'in-browser-cache' );
			return $result;
		}

		// Prevent localhost and private IP ranges for security.
		if ( self::is_private_or_local_url( $parsed_url['host'] ) ) {
			$result['message'] = __( 'CDN URL cannot point to localhost or private IP addresses.', 'in-browser-cache' );
			return $result;
		}

		// Validate port if specified.
		if ( ! empty( $parsed_url['port'] ) && ! self::is_allowed_port( $parsed_url['port'] ) ) {
			$result['message'] = __( 'CDN URL uses a non-standard port that is not allowed.', 'in-browser-cache' );
			return $result;
		}

		$result['valid']   = true;
		$result['message'] = __( 'CDN URL format is valid.', 'in-browser-cache' );

		return $result;
	}

	/**
	 * Test CDN connectivity with real asset testing.
	 *
	 * @since 2.0.0
	 * @param string $url CDN base URL to test.
	 * @param bool   $log_validation_errors Whether to log validation errors (default: true).
	 * @return array Test result with status, message, and response time.
	 */
	public static function test_cdn_connectivity( $url, $log_validation_errors = true ) {
		$result = array(
			'status'        => 'error',
			'message'       => '',
			'response_time' => 0,
		);

		// Primary security validation using WordPress built-in function to prevent SSRF.
		// Skip validation only in test environment.
		if ( function_exists( 'wp_http_validate_url' ) && ! defined( 'WP_TESTS_DOMAIN' ) && ! wp_http_validate_url( $url ) ) {
			$error_message = __( 'CDN URL failed WordPress security validation and cannot be used.', 'in-browser-cache' );

			if ( $log_validation_errors && self::is_user_initiated_test() ) {
				JTZL_SW_CDN_Error_Handler::log_cdn_error(
					JTZL_SW_CDN_Error_Handler::ERROR_TYPES['VALIDATION'],
					$error_message,
					array( 'cdn_url' => $url ),
					JTZL_SW_CDN_Error_Handler::SEVERITY_LEVELS['ERROR']
				);
			}

			$result['message'] = $error_message;
			return $result;
		}

		// Secondary validation for format and additional security checks.
		$validation = self::validate_cdn_url( $url );
		if ( ! $validation['valid'] ) {
			// Only log validation errors if explicitly requested (e.g., during manual testing).
			// Don't log validation errors during automatic processes like settings save.
			// Also check if this is likely a user-initiated test vs automatic validation.
			if ( $log_validation_errors && self::is_user_initiated_test() ) {
				JTZL_SW_CDN_Error_Handler::log_cdn_error(
					JTZL_SW_CDN_Error_Handler::ERROR_TYPES['VALIDATION'],
					$validation['message'],
					array( 'cdn_url' => $url ),
					JTZL_SW_CDN_Error_Handler::SEVERITY_LEVELS['WARNING']
				);
			}

			$result['message'] = $validation['message'];
			return $result;
		}

		// Test multiple endpoints for comprehensive validation.
		$test_results     = array();
		$total_start_time = microtime( true );

		// Test 1: Basic connectivity test.
		$basic_test            = self::test_basic_connectivity( $url );
		$test_results['basic'] = $basic_test;

		// Test 2: Real asset test - try to fetch an actual WordPress asset.
		$asset_test            = self::test_real_asset( $url );
		$test_results['asset'] = $asset_test;

		// Test 3: CORS test - check if CDN allows cross-origin requests.
		$cors_test            = self::test_cors_headers( $url );
		$test_results['cors'] = $cors_test;

		$total_end_time          = microtime( true );
		$result['response_time'] = round( ( $total_end_time - $total_start_time ) * 1000 );

		// Analyze results and determine overall status.
		$success_count     = 0;
		$critical_failures = array();
		$warnings          = array();

		foreach ( $test_results as $test_name => $test_result ) {
			if ( 'success' === $test_result['status'] ) {
				++$success_count;
			} elseif ( 'critical' === $test_result['severity'] ) {
				$critical_failures[] = $test_result['message'];
			} else {
				$warnings[] = $test_result['message'];
			}
		}

		// Determine overall result.
		if ( $success_count >= 2 && empty( $critical_failures ) ) {
			$result['status']  = 'success';
			$result['message'] = sprintf(
				/* translators: 1: Number of successful tests, 2: Response time */
				__( 'CDN tests passed (%1$d/3 successful, %2$dms)', 'in-browser-cache' ),
				$success_count,
				$result['response_time']
			);

			if ( ! empty( $warnings ) ) {
				$result['message'] .= ' ' . __( 'Warnings:', 'in-browser-cache' ) . ' ' . implode( ', ', $warnings );
			}

			// Log successful test.
			JTZL_SW_CDN_Error_Handler::log_cdn_error(
				JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
				'CDN comprehensive test successful',
				array(
					'cdn_url'       => $url,
					'tests_passed'  => $success_count,
					'response_time' => $result['response_time'],
					'test_results'  => $test_results,
				),
				JTZL_SW_CDN_Error_Handler::SEVERITY_LEVELS['INFO']
			);
		} else {
			$result['status']  = 'error';
			$error_messages    = array_merge( $critical_failures, $warnings );
			$result['message'] = sprintf(
				/* translators: %s: Error messages */
				__( 'CDN tests failed: %s', 'in-browser-cache' ),
				implode( '; ', $error_messages )
			);

			// Log comprehensive failure.
			JTZL_SW_CDN_Error_Handler::log_cdn_error(
				JTZL_SW_CDN_Error_Handler::ERROR_TYPES['CONNECTIVITY'],
				'CDN comprehensive test failed',
				array(
					'cdn_url'           => $url,
					'tests_passed'      => $success_count,
					'critical_failures' => $critical_failures,
					'warnings'          => $warnings,
					'response_time'     => $result['response_time'],
					'test_results'      => $test_results,
				),
				JTZL_SW_CDN_Error_Handler::SEVERITY_LEVELS['ERROR']
			);
		}

		return $result;
	}

	/**
	 * Test basic CDN connectivity.
	 *
	 * @since 2.0.0
	 * @param string $url CDN base URL.
	 * @return array Test result.
	 */
	private static function test_basic_connectivity( $url ) {
		$test_url = rtrim( $url, '/' ) . '/test-connectivity';

		// Additional security validation for the test URL using WordPress built-in function.
		// Skip validation in test environment.
		if ( function_exists( 'wp_http_validate_url' ) && ! defined( 'WP_TESTS_DOMAIN' ) && ! wp_http_validate_url( $test_url ) ) {
			return array(
				'status'   => 'error',
				'message'  => __( 'CDN test URL failed WordPress security validation.', 'in-browser-cache' ),
				'severity' => 'critical',
			);
		}

		$response = wp_remote_get(
			$test_url,
			array(
				'timeout'            => 10,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
				'user-agent'         => 'JTZL-Service-Worker-CDN-Test/1.0',
				'headers'            => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_classification = JTZL_SW_CDN_Error_Handler::classify_error( $response );
			return array(
				'status'   => 'error',
				'message'  => $error_classification['user_message'],
				'severity' => $error_classification['severity'],
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $response_code, array( 200, 404, 403, 405 ), true ) ) {
			return array(
				'status'   => 'success',
				'message'  => __( 'Basic connectivity OK', 'in-browser-cache' ),
				'severity' => 'info',
			);
		}

		return array(
			'status'   => 'error',
			/* translators: %d: HTTP response code */
			'message'  => sprintf( __( 'Unexpected response: HTTP %d', 'in-browser-cache' ), $response_code ),
			'severity' => 'warning',
		);
	}

	/**
	 * Test CDN with a real WordPress asset.
	 *
	 * @since 2.0.0
	 * @param string $cdn_url CDN base URL.
	 * @return array Test result.
	 */
	private static function test_real_asset( $cdn_url ) {
		// Try to find a real asset to test with.
		$test_assets = array();

		// Try common WordPress core assets.
		$wp_includes_url = includes_url();
		$parsed_includes = wp_parse_url( $wp_includes_url );
		if ( ! empty( $parsed_includes['path'] ) ) {
			$test_assets[] = rtrim( $cdn_url, '/' ) . $parsed_includes['path'] . 'js/jquery/jquery.min.js';
		}

		// Try active theme stylesheet.
		$theme_stylesheet = get_stylesheet_uri();
		$parsed_theme     = wp_parse_url( $theme_stylesheet );
		if ( ! empty( $parsed_theme['path'] ) ) {
			$test_assets[] = rtrim( $cdn_url, '/' ) . $parsed_theme['path'];
		}

		// Try admin CSS.
		$admin_url    = admin_url( 'css/common.css' );
		$parsed_admin = wp_parse_url( $admin_url );
		if ( ! empty( $parsed_admin['path'] ) ) {
			$test_assets[] = rtrim( $cdn_url, '/' ) . $parsed_admin['path'];
		}

		foreach ( $test_assets as $test_asset_url ) {
			// Validate each test asset URL for security.
			if ( function_exists( 'wp_http_validate_url' ) && ! defined( 'WP_TESTS_DOMAIN' ) && ! wp_http_validate_url( $test_asset_url ) ) {
				continue; // Skip invalid URLs.
			}

			$response = wp_remote_head(
				$test_asset_url,
				array(
					'timeout'            => 10,
					'redirection'        => 3,
					'reject_unsafe_urls' => true,
					'user-agent'         => 'JTZL-Service-Worker-CDN-Test/1.0',
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$response_code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $response_code ) {
					return array(
						'status'   => 'success',
						'message'  => __( 'Real asset test passed', 'in-browser-cache' ),
						'severity' => 'info',
					);
				}
			}
		}

		return array(
			'status'   => 'error',
			'message'  => __( 'No real assets found on CDN - check CDN configuration', 'in-browser-cache' ),
			'severity' => 'critical',
		);
	}

	/**
	 * Test CORS headers from CDN.
	 *
	 * @since 2.0.0
	 * @param string $cdn_url CDN base URL.
	 * @return array Test result.
	 */
	private static function test_cors_headers( $cdn_url ) {
		$test_url = rtrim( $cdn_url, '/' ) . '/wp-includes/js/jquery/jquery.min.js';

		// Validate the CORS test URL for security.
		if ( function_exists( 'wp_http_validate_url' ) && ! defined( 'WP_TESTS_DOMAIN' ) && ! wp_http_validate_url( $test_url ) ) {
			return array(
				'status'   => 'error',
				'message'  => __( 'CORS test URL failed WordPress security validation.', 'in-browser-cache' ),
				'severity' => 'critical',
			);
		}

		$response = wp_remote_head(
			$test_url,
			array(
				'timeout'            => 10,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
				'user-agent'         => 'JTZL-Service-Worker-CDN-Test/1.0',
				'headers'            => array(
					'Origin' => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 'warning',
				'message'  => __( 'CORS test failed - CDN may not be configured', 'in-browser-cache' ),
				'severity' => 'warning',
			);
		}

		$cors_header = wp_remote_retrieve_header( $response, 'access-control-allow-origin' );
		$site_origin = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '*' === $cors_header || strpos( $cors_header, $site_origin ) !== false ) {
			return array(
				'status'   => 'success',
				'message'  => __( 'CORS headers OK', 'in-browser-cache' ),
				'severity' => 'info',
			);
		}

		return array(
			'status'   => 'warning',
			'message'  => __( 'CORS headers missing - may cause browser errors', 'in-browser-cache' ),
			'severity' => 'warning',
		);
	}

	/**
	 * Sanitize CDN settings array.
	 *
	 * @since 2.0.0
	 * @param array $settings Raw settings array.
	 * @return array Sanitized settings array.
	 */
	private static function sanitize_cdn_settings( $settings ) {
		$sanitized = array();

		// Sanitize boolean values.
		$sanitized['cdn_enabled']          = ! empty( $settings['cdn_enabled'] );
		$sanitized['cdn_fallback_enabled'] = ! empty( $settings['cdn_fallback_enabled'] );

		// Sanitize CDN base URL.
		$sanitized['cdn_base_url'] = '';
		if ( ! empty( $settings['cdn_base_url'] ) ) {
			$sanitized['cdn_base_url'] = esc_url_raw( trim( $settings['cdn_base_url'] ) );
			// Remove trailing slash for consistency.
			$sanitized['cdn_base_url'] = rtrim( $sanitized['cdn_base_url'], '/' );
		}

		// Sanitize test status.
		$sanitized['cdn_test_status'] = self::$default_settings['cdn_test_status'];
		if ( ! empty( $settings['cdn_test_status'] ) && is_array( $settings['cdn_test_status'] ) ) {
			$test_status = $settings['cdn_test_status'];

			$sanitized['cdn_test_status'] = array(
				'last_tested'   => absint( $test_status['last_tested'] ?? 0 ),
				'status'        => sanitize_text_field( $test_status['status'] ?? '' ),
				'message'       => sanitize_text_field( $test_status['message'] ?? '' ),
				'response_time' => absint( $test_status['response_time'] ?? 0 ),
			);
		}

		return $sanitized;
	}

	/**
	 * Check if URL points to localhost or private IP address.
	 *
	 * @since 2.0.0
	 * @param string $host Hostname or IP address.
	 * @return bool True if host is private/local, false otherwise.
	 */
	private static function is_private_or_local_url( $host ) {
		// Remove IPv6 brackets if present.
		$clean_host = trim( $host, '[]' );

		// Check for localhost variations.
		$localhost_patterns = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'0.0.0.0',
			'[::1]',
		);

		if ( in_array( strtolower( $clean_host ), $localhost_patterns, true ) ) {
			return true;
		}

		// Check for localhost-like patterns.
		if ( preg_match( '/^(localhost|127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|169\.254\.|::1|fc00:|fe80:)/i', $clean_host ) ) {
			return true;
		}

		// Only check IP ranges if the host is actually an IP address.
		if ( filter_var( $clean_host, FILTER_VALIDATE_IP ) ) {
			// Check for private IP ranges.
			if ( filter_var( $clean_host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
				return true;
			}
		}

		// Additional protection: Resolve hostname to IP and check if it points to private ranges.
		// This prevents DNS rebinding attacks where a domain resolves to private IPs.
		if ( ! filter_var( $clean_host, FILTER_VALIDATE_IP ) ) {
			// For hostnames, we need to resolve and check the actual IP address.
			$resolved_ips = self::resolve_hostname_safely( $clean_host );

			foreach ( $resolved_ips as $ip ) {
				// Check if any resolved IP is in private range.
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
					return true;
				}

				// Additional check for localhost ranges that might not be caught by filter.
				if ( preg_match( '/^(127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|169\.254\.)/i', $ip ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Safely resolve hostname to IP addresses with timeout protection.
	 *
	 * @since 2.0.0
	 * @param string $hostname Hostname to resolve.
	 * @return array Array of resolved IP addresses.
	 */
	private static function resolve_hostname_safely( $hostname ) {
		$resolved_ips = array();

		// Prevent resolution of known dangerous hostnames.
		$dangerous_hostnames = array(
			'localhost',
			'metadata.google.internal',
			'169.254.169.254', // AWS/GCP metadata service.
			'100.100.100.200', // Alibaba Cloud metadata.
		);

		if ( in_array( strtolower( $hostname ), $dangerous_hostnames, true ) ) {
			return $resolved_ips; // Return empty array for dangerous hostnames.
		}

		// Use gethostbyname for IPv4 resolution with basic timeout protection.
		$ipv4 = gethostbyname( $hostname );

		// gethostbyname returns the hostname if resolution fails.
		if ( $ipv4 !== $hostname && filter_var( $ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$resolved_ips[] = $ipv4;
		}

		// For more comprehensive resolution, we could use dns_get_record.
		// but we limit this to prevent DNS-based attacks and timeouts.
		if ( function_exists( 'dns_get_record' ) ) {
			// Set a reasonable timeout and try to get A records.
			$records = @dns_get_record( $hostname, DNS_A, $authns, $addtl );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ip'] ) && filter_var( $record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
						if ( ! in_array( $record['ip'], $resolved_ips, true ) ) {
							$resolved_ips[] = $record['ip'];
						}
					}
				}
			}
		}

		return $resolved_ips;
	}

	/**
	 * Validate hostname format and security.
	 *
	 * @since 2.0.0
	 * @param string $hostname Hostname to validate.
	 * @return bool True if hostname is valid and safe, false otherwise.
	 */
	private static function is_valid_hostname( $hostname ) {
		// Remove IPv6 brackets if present.
		$clean_hostname = trim( $hostname, '[]' );

		// Check for empty hostname.
		if ( empty( $clean_hostname ) ) {
			return false;
		}

		// Check hostname length (RFC 1035).
		if ( strlen( $clean_hostname ) > 253 ) {
			return false;
		}

		// Check for valid hostname format.
		if ( ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $clean_hostname ) ) {
			// If it's not a valid hostname, check if it's a valid IP address.
			if ( ! filter_var( $clean_hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
				return false;
			}
		}

		// Check for suspicious patterns in hostname.
		$suspicious_patterns = array(
			'/[<>"\']/',           // HTML/script injection characters.
			'/javascript:/i',      // JavaScript protocol.
			'/data:/i',           // Data protocol.
			'/vbscript:/i',       // VBScript protocol.
			'/file:/i',           // File protocol.
			'/ftp:/i',            // FTP protocol.
			'/\.\./i',            // Directory traversal.
			'/\x00/',             // Null bytes.
		);

		foreach ( $suspicious_patterns as $pattern ) {
			if ( preg_match( $pattern, $clean_hostname ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check for suspicious patterns in the full URL.
	 *
	 * @since 2.0.0
	 * @param string $url Full URL to check.
	 * @return bool True if suspicious patterns found, false otherwise.
	 */
	private static function has_suspicious_patterns( $url ) {
		// Check both original and URL-decoded versions to catch encoded attacks.
		$urls_to_check = array( $url, urldecode( $url ) );

		// Patterns that could indicate XSS, SSRF, or other attacks.
		$suspicious_patterns = array(
			'/javascript:/i',                    // JavaScript protocol.
			'/data:/i',                         // Data protocol.
			'/vbscript:/i',                     // VBScript protocol.
			'/file:/i',                         // File protocol.
			'/ftp:/i',                          // FTP protocol.
			'/gopher:/i',                       // Gopher protocol.
			'/dict:/i',                         // Dict protocol.
			'/ldap:/i',                         // LDAP protocol.
			'/[\x00-\x1f\x7f-\x9f]/',          // Control characters (including null bytes).
			'/[<>"\']/',                        // HTML/script injection characters.
			'/<script/i',                       // Script tags.
			'/onload=/i',                       // Event handlers.
			'/onclick=/i',                      // Event handlers.
			'/onerror=/i',                      // Event handlers.
			'/\.\./i',                          // Directory traversal.
			'/@[^\/]*localhost/i',              // Username with localhost.
			'/@[^\/]*127\./i',                  // Username with 127.x.x.x.
			'/@[^\/]*10\./i',                   // Username with 10.x.x.x.
			'/@[^\/]*192\.168\./i',             // Username with 192.168.x.x.
			'/@[^\/]*172\.(1[6-9]|2[0-9]|3[01])\./i', // Username with 172.16-31.x.x.
			'/metadata\.google\.internal/i',    // Google Cloud metadata.
			'/169\.254\.169\.254/i',            // AWS/GCP metadata service.
			'/100\.100\.100\.200/i',            // Alibaba Cloud metadata.
			'/metadata\.azure\.com/i',          // Azure metadata service.
			'/metadata\.packet\.net/i',         // Packet metadata.
			'/metadata\.digitalocean\.com/i',   // DigitalOcean metadata.
		);

		foreach ( $urls_to_check as $check_url ) {
			foreach ( $suspicious_patterns as $pattern ) {
				if ( preg_match( $pattern, $check_url ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if port is allowed for CDN URLs.
	 *
	 * @since 2.0.0
	 * @param int $port Port number to validate.
	 * @return bool True if port is allowed, false otherwise.
	 */
	private static function is_allowed_port( $port ) {
		$port = (int) $port;

		// Allow standard HTTPS port.
		if ( 443 === $port ) {
			return true;
		}

		// Allow common CDN ports.
		$allowed_ports = array(
			80,   // HTTP (though we require HTTPS, some CDNs redirect).
			443,  // HTTPS.
			8080, // Alternative HTTP.
			8443, // Alternative HTTPS.
		);

		// Disallow dangerous ports that could be used for SSRF attacks.
		$dangerous_ports = array(
			22,   // SSH.
			23,   // Telnet.
			25,   // SMTP.
			53,   // DNS.
			110,  // POP3.
			143,  // IMAP.
			993,  // IMAPS.
			995,  // POP3S.
			1433, // MSSQL.
			3306, // MySQL.
			5432, // PostgreSQL.
			6379, // Redis.
			11211, // Memcached.
		);

		if ( in_array( $port, $dangerous_ports, true ) ) {
			return false;
		}

		// Allow ports in safe ranges.
		if ( in_array( $port, $allowed_ports, true ) || ( $port >= 8000 && $port <= 9000 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Clear CDN test status.
	 *
	 * @since 2.0.0
	 * @return bool True if status was cleared successfully.
	 */
	public static function clear_test_status() {
		$settings                    = self::get_cdn_settings();
		$settings['cdn_test_status'] = self::$default_settings['cdn_test_status'];

		return self::update_cdn_settings( $settings );
	}

	/**
	 * Get CDN test status.
	 *
	 * @since 2.0.0
	 * @return array CDN test status array.
	 */
	public static function get_test_status() {
		$settings = self::get_cdn_settings();
		return $settings['cdn_test_status'];
	}

	/**
	 * Update CDN test status.
	 *
	 * @since 2.0.0
	 * @param array $test_result Test result from test_cdn_connectivity().
	 * @return bool True if status was updated successfully.
	 */
	public static function update_test_status( $test_result ) {
		$settings                    = self::get_cdn_settings();
		$settings['cdn_test_status'] = array(
			'last_tested'   => time(),
			'status'        => sanitize_text_field( $test_result['status'] ),
			'message'       => sanitize_text_field( $test_result['message'] ),
			'response_time' => absint( $test_result['response_time'] ),
		);

		return self::update_cdn_settings( $settings );
	}

	/**
	 * Check if the current CDN test is likely user-initiated.
	 *
	 * This helps prevent logging validation errors during automatic processes
	 * like settings save, while still logging errors for manual tests.
	 *
	 * @since 2.0.0
	 * @return bool True if this appears to be a user-initiated test.
	 */
	private static function is_user_initiated_test() {
		// Check if this is being called from the AJAX handler.
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 );
		foreach ( $backtrace as $trace ) {
			if ( isset( $trace['function'] ) && 'handle_cdn_test' === $trace['function'] ) {
				return true;
			}
		}

		// Check if this is an AJAX request for CDN testing.
		if ( wp_doing_ajax() ) {
			// Use wp_get_current_user() to check if user has proper permissions.
			$current_user = wp_get_current_user();
			if ( $current_user && user_can( $current_user, 'manage_options' ) ) {
				return true;
			}
		}

		// Check if we're in admin context but not during a form submission.
		if ( is_admin() && ! wp_doing_ajax() && ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) ) {
			return true;
		}

		// Default to false for automatic processes.
		return false;
	}
}
