<?php
/**
 * JTZL SW Admin Class
 *
 * Handles the admin interface for the In-Browser Cache plugin.
 *
 * @package JTZL_Service_Worker
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_Admin
 *
 * Handles the admin interface for the In-Browser Cache plugin.
 *
 * @since 0.1.0
 */
class JTZL_SW_Admin {

	/**
	 * Constructor.
	 *
	 * Initializes the class by adding necessary hooks for admin menu, settings registration,
	 * script enqueuing, and handling admin actions.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'wp_ajax_jtzl_clear_all_user_caches', array( $this, 'handle_clear_all_caches' ) );
		add_action( 'wp_ajax_test_cdn_connectivity', array( $this, 'handle_cdn_test' ) );
		add_action( 'admin_notices', array( $this, 'show_build_missing_notice' ) );

		// Initialize URL rewriting hooks.
		add_action( 'init', array( $this, 'init_url_rewriting' ) );
	}

	/**
	 * Adds the admin menu and submenus for the plugin.
	 *
	 * This method creates the main menu item and submenus for the plugin settings and dashboard.
	 *
	 * @since 0.1.0
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'In-Browser Cache', 'in-browser-cache' ),
			__( 'In-Browser Cache', 'in-browser-cache' ),
			'manage_options',
			'jtzl_sw',
			array( $this, 'render_settings_page' ),
			'dashicons-performance'
		);

		add_submenu_page(
			'jtzl_sw',
			__( 'Dashboard', 'in-browser-cache' ),
			__( 'Dashboard', 'in-browser-cache' ),
			'manage_options',
			'jtzl_sw_dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'jtzl_sw',
			__( 'Settings', 'in-browser-cache' ),
			__( 'Settings', 'in-browser-cache' ),
			'manage_options',
			'jtzl_sw',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the plugin settings.
	 *
	 * This method registers the settings for the plugin, including sanitization callbacks.
	 *
	 * @since 0.1.0
	 */
	public function register_settings() {
		register_setting(
			'jtzl_sw_options',
			'jtzl_sw_options',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_options' ) )
		);

		register_setting(
			'jtzl_sw_options',
			'jtzl_sw_cdn_settings',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_cdn_options' ) )
		);

		register_setting(
			'jtzl_sw_options',
			'jtzl_sw_log_settings',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_log_options' ) )
		);
	}

	/**
	 * Sanitize plugin options before saving.
	 *
	 * @since 0.1.0
	 * @param array $options The options to sanitize.
	 * @return array Sanitized options.
	 */
	public static function sanitize_options( $options ) {
		$sanitized = array();

		$sanitized['enabled']                  = isset( $options['enabled'] ) ? 1 : 0;
		$sanitized['max_cache_size']           = isset( $options['max_cache_size'] ) ? absint( $options['max_cache_size'] ) : 200;
		$sanitized['max_cache_lifetime']       = isset( $options['max_cache_lifetime'] ) ? absint( $options['max_cache_lifetime'] ) : 30;
		$sanitized['disable_for_logged_users'] = isset( $options['disable_for_logged_users'] ) ? 1 : 0;

		// Performance settings with reasonable bounds.
		$sanitized['cache_version_check_interval'] = isset( $options['cache_version_check_interval'] ) ? max( 1, min( 60, absint( $options['cache_version_check_interval'] ) ) ) : 3;
		$sanitized['metrics_sync_interval']        = isset( $options['metrics_sync_interval'] ) ? max( 1, min( 60, absint( $options['metrics_sync_interval'] ) ) ) : 5;
		$sanitized['health_check_interval']        = isset( $options['health_check_interval'] ) ? max( 5, min( 120, absint( $options['health_check_interval'] ) ) ) : 10;

		return $sanitized;
	}

	/**
	 * Sanitize CDN options before saving.
	 *
	 * @since 2.0.0
	 * @param array $options The CDN options to sanitize.
	 * @return array Sanitized CDN options.
	 */
	public static function sanitize_cdn_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$sanitized = array();

		// Sanitize CDN enabled flag.
		$sanitized['cdn_enabled'] = ! empty( $options['cdn_enabled'] );

		// Sanitize CDN base URL.
		$sanitized['cdn_base_url'] = '';
		if ( ! empty( $options['cdn_base_url'] ) ) {
			$sanitized['cdn_base_url'] = esc_url_raw( trim( $options['cdn_base_url'] ) );
			// Remove trailing slash for consistency.
			$sanitized['cdn_base_url'] = rtrim( $sanitized['cdn_base_url'], '/' );

			// Validate the URL format.
			$validation = JTZL_SW_CDN_Config::validate_cdn_url( $sanitized['cdn_base_url'] );
			if ( ! $validation['valid'] ) {
				$sanitized['cdn_base_url'] = '';
			}
		}

		// If CDN is enabled but URL is empty or invalid, disable CDN.
		if ( $sanitized['cdn_enabled'] && empty( $sanitized['cdn_base_url'] ) ) {
			$sanitized['cdn_enabled'] = false;
		}

		// Preserve existing test status and other settings.
		$current_settings = JTZL_SW_CDN_Config::get_cdn_settings();
		$sanitized        = wp_parse_args( $sanitized, $current_settings );

		return $sanitized;
	}

	/**
	 * Sanitize log options before saving.
	 *
	 * @since 2.0.0
	 * @param array $options The log options to sanitize.
	 * @return array Sanitized log options.
	 */
	public static function sanitize_log_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$sanitized = array();

		// Sanitize retention period (1-365 days).
		$sanitized['retention_days'] = isset( $options['retention_days'] ) ?
			max( 1, min( 365, absint( $options['retention_days'] ) ) ) : 30;

		return $sanitized;
	}

	/**
	 * Renders the settings page for the plugin.
	 *
	 * This method outputs the HTML for the plugin settings page, including form fields and buttons.
	 *
	 * @since 0.1.0
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'In-Browser Cache Settings', 'in-browser-cache' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'jtzl_sw_options' );
				do_settings_sections( 'jtzl_sw' );
				$options = get_option( 'jtzl_sw_options' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable In-Browser Caching', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jtzl_sw_options[enabled]" value="1" <?php checked( isset( $options['enabled'] ) ? $options['enabled'] : 0, 1 ); ?> />
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Disable for Logged-in Users (GDPR)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jtzl_sw_options[disable_for_logged_users]" value="1" <?php checked( isset( $options['disable_for_logged_users'] ) ? $options['disable_for_logged_users'] : 1, 1 ); ?> />
								<span class="description"><?php esc_html_e( 'Disable caching for logged-in users to ensure GDPR compliance. When enabled, the service worker will be unregistered and all caches cleared for authenticated users.', 'in-browser-cache' ); ?></span>
							</label>
						</td>
					</tr>

				</table>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Maximum Cache Size (MB)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="number" name="jtzl_sw_options[max_cache_size]" value="<?php echo isset( $options['max_cache_size'] ) ? esc_attr( $options['max_cache_size'] ) : 200; ?>" min="1" />
								<p class="description"><?php esc_html_e( 'Maximum amount of storage space (in megabytes) that the in-browser cache can use. When this limit is reached, older cached items will be removed.', 'in-browser-cache' ); ?></p>
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Cache Lifetime (Days)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="number" name="jtzl_sw_options[max_cache_lifetime]" value="<?php echo isset( $options['max_cache_lifetime'] ) ? esc_attr( $options['max_cache_lifetime'] ) : 30; ?>" min="1" />
								<p class="description"><?php esc_html_e( 'How long cached items should be stored before being considered stale and refreshed. Longer lifetimes reduce server requests but may serve outdated content.', 'in-browser-cache' ); ?></p>
							</label>
						</td>
					</tr>
				</table>

			<h3><?php esc_html_e( 'Performance Settings', 'in-browser-cache' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Configure how often Service Workers check for updates. Lower values provide faster response but increase server load.', 'in-browser-cache' ); ?></p>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Cache Version Check Interval (Minutes)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="number" name="jtzl_sw_options[cache_version_check_interval]" value="<?php echo isset( $options['cache_version_check_interval'] ) ? esc_attr( $options['cache_version_check_interval'] ) : 3; ?>" min="1" max="60" />
								<p class="description"><?php esc_html_e( 'How often Service Workers check if caches should be cleared (1-60 minutes). Lower values clear caches faster but increase server requests.', 'in-browser-cache' ); ?></p>
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Metrics Sync Interval (Minutes)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="number" name="jtzl_sw_options[metrics_sync_interval]" value="<?php echo isset( $options['metrics_sync_interval'] ) ? esc_attr( $options['metrics_sync_interval'] ) : 5; ?>" min="1" max="60" />
								<p class="description"><?php esc_html_e( 'How often Service Workers send usage metrics to the server (1-60 minutes). Higher values reduce server load.', 'in-browser-cache' ); ?></p>
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Health Check Interval (Minutes)', 'in-browser-cache' ); ?></th>
						<td>
							<label>
								<input type="number" name="jtzl_sw_options[health_check_interval]" value="<?php echo isset( $options['health_check_interval'] ) ? esc_attr( $options['health_check_interval'] ) : 10; ?>" min="5" max="120" />
								<p class="description"><?php esc_html_e( 'How often Service Workers check if the plugin is still active (5-120 minutes). Used for automatic cleanup when plugin is deactivated.', 'in-browser-cache' ); ?></p>
							</label>
						</td>
					</tr>
				</table>

				<?php $this->render_cdn_settings_section(); ?>

				<?php $this->render_log_settings_section(); ?>

				<?php submit_button(); ?>
			</form>

		</div>

		<div class="wrap">
			<h2><?php esc_html_e( 'Cache Management', 'in-browser-cache' ); ?></h2>
			<div class="jtzl-sw-warning" style="border-left: 4px solid #ffb900; background: #fff8e1; padding: 12px; margin: 10px 0;">
				<p><strong><?php esc_html_e( 'Clear All User Caches:', 'in-browser-cache' ); ?></strong> <?php esc_html_e( 'This will clear the in-browser caches for all website visitors, not just your browser. Use this when you\'ve updated critical assets and want to ensure all users get the latest version.', 'in-browser-cache' ); ?></p>
			</div>
			<form method="post" style="margin-top: 10px;">
				<?php wp_nonce_field( 'clear_all_caches' ); ?>
				<input type="hidden" name="clear_all_caches" value="1">
				<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Clear All User Caches', 'in-browser-cache' ); ?>">
				<p class="description"><?php esc_html_e( 'This action will increment the cache version, causing all users\' Service Workers to automatically clear their caches within a few minutes.', 'in-browser-cache' ); ?></p>
			</form>

		</div>

		<div class="wrap">
			<h2><?php esc_html_e( 'Error Log Management', 'in-browser-cache' ); ?></h2>
			


			<?php
			// Show current error log count.
			$recent_errors = JTZL_SW_CDN_Error_Handler::get_recent_errors( 1000 ); // Get all recent errors.
			$error_count   = count( $recent_errors );
			?>
			
			<div class="jtzl-sw-info" style="border-left: 4px solid #007cba; background: #e5f5fa; padding: 12px; margin: 10px 0;">
				<p><strong><?php esc_html_e( 'Current Status:', 'in-browser-cache' ); ?></strong> 
				<?php
				if ( $error_count > 0 ) {
					printf(
						/* translators: %d: number of error log entries */
						esc_html__( 'There are currently %d CDN error log entries stored.', 'in-browser-cache' ),
						esc_html( $error_count )
					);
				} else {
					esc_html_e( 'No CDN error logs are currently stored.', 'in-browser-cache' );
				}
				?>
				</p>
			</div>

			<div class="jtzl-sw-warning" style="border-left: 4px solid #ffb900; background: #fff8e1; padding: 12px; margin: 10px 0;">
				<p><strong><?php esc_html_e( 'Delete Error Logs:', 'in-browser-cache' ); ?></strong> <?php esc_html_e( 'This will permanently delete all stored CDN error logs. This action cannot be undone.', 'in-browser-cache' ); ?></p>
			</div>
			<form method="post" style="margin-top: 10px;">
				<?php wp_nonce_field( 'cleanup_error_logs' ); ?>
				<input type="hidden" name="cleanup_error_logs" value="1">
				<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Delete All Error Logs Now', 'in-browser-cache' ); ?>" <?php disabled( $error_count, 0 ); ?>>
				<p class="description">
					<?php
					if ( $error_count > 0 ) {
						esc_html_e( 'Immediately delete all stored CDN error logs. This action cannot be undone.', 'in-browser-cache' );
					} else {
						esc_html_e( 'No error logs to delete. The button will be enabled when error logs are present.', 'in-browser-cache' );
					}
					?>
				</p>
			</form>

		</div>
		<?php
	}

	/**
	 * Renders the CDN settings section.
	 *
	 * @since 2.0.0
	 */
	private function render_cdn_settings_section() {
		$cdn_settings  = JTZL_SW_CDN_Config::get_cdn_settings();
		$test_status   = $cdn_settings['cdn_test_status'];
		$recent_errors = JTZL_SW_CDN_Error_Handler::get_recent_errors( 5 );
		$error_stats   = JTZL_SW_CDN_Error_Handler::get_error_statistics( 7 );
		?>
		<h3><?php esc_html_e( 'CDN Configuration', 'in-browser-cache' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Configure a Content Delivery Network (CDN) to serve your static assets faster. When enabled, asset URLs will be automatically rewritten to use your CDN with intelligent fallback to local files.', 'in-browser-cache' ); ?></p>
		
		<?php if ( $error_stats['total_errors'] > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'CDN Issues Detected', 'in-browser-cache' ); ?></strong><br>
					<?php
					printf(
						/* translators: %d: Number of errors in the last 7 days */
						esc_html__( '%d CDN errors occurred in the last 7 days. Check the troubleshooting section below for guidance.', 'in-browser-cache' ),
						esc_html( $error_stats['total_errors'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php esc_html_e( 'Enable Active CDN Support', 'in-browser-cache' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="jtzl_sw_cdn_settings[cdn_enabled]" value="1" <?php checked( $cdn_settings['cdn_enabled'], true ); ?> id="cdn-enabled-checkbox" />
						<?php esc_html_e( 'Automatically rewrite asset URLs to use CDN', 'in-browser-cache' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, static assets (images, CSS, JS) will be served from your CDN with automatic fallback to origin server.', 'in-browser-cache' ); ?></p>
				</td>
			</tr>
			<tr valign="top" class="cdn-setting-row">
				<th scope="row"><?php esc_html_e( 'CDN Base URL', 'in-browser-cache' ); ?></th>
				<td>
					<input type="url" name="jtzl_sw_cdn_settings[cdn_base_url]" value="<?php echo esc_attr( $cdn_settings['cdn_base_url'] ); ?>" class="regular-text" id="cdn-base-url" placeholder="https://cdn.example.com" />
					<button type="button" class="button button-secondary" id="test-cdn-btn" <?php disabled( empty( $cdn_settings['cdn_base_url'] ) ); ?>>
						<?php esc_html_e( 'Test Connection', 'in-browser-cache' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Enter your CDN base URL (must use HTTPS). Example: https://cdn.example.com', 'in-browser-cache' ); ?></p>
					
					<div id="cdn-test-result" class="cdn-test-result" <?php echo empty( $test_status['message'] ) ? 'style="display: none;"' : ''; ?>>
						<?php if ( ! empty( $test_status['message'] ) ) : ?>
							<div class="cdn-test-status cdn-test-<?php echo esc_attr( $test_status['status'] ); ?>">
								<span class="cdn-test-message"><?php echo esc_html( $test_status['message'] ); ?></span>
								<?php if ( $test_status['last_tested'] > 0 ) : ?>
									<?php
									/* translators: %s: Formatted date and time when CDN was last tested */
									echo '<span class="cdn-test-time">' . esc_html( sprintf( __( 'Tested: %s', 'in-browser-cache' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $test_status['last_tested'] ) ) ) . '</span>';
									?>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $recent_errors ) || $error_stats['total_errors'] > 0 ) : ?>
			<div class="jtzl-sw-cdn-troubleshooting" style="margin-top: 30px;">
				<h4><?php esc_html_e( 'CDN Troubleshooting', 'in-browser-cache' ); ?></h4>
				
				<?php if ( ! empty( $recent_errors ) ) : ?>
					<div class="jtzl-sw-recent-errors">
						<h5><?php esc_html_e( 'Recent CDN Errors', 'in-browser-cache' ); ?></h5>
						<div class="jtzl-sw-error-list">
							<?php foreach ( $recent_errors as $error ) : ?>
								<?php
								$error_info = JTZL_SW_CDN_Error_Handler::get_user_friendly_message( $error['error_type'] );
								// Validate severity against whitelist to prevent CSS class injection.
								$allowed_severities = array( 'critical', 'error', 'warning', 'info' );
								$safe_severity      = in_array( $error['severity'], $allowed_severities, true ) ? $error['severity'] : 'error';
								?>
								<div class="jtzl-sw-error-item jtzl-sw-error-<?php echo esc_attr( $safe_severity ); ?>">
									<div class="jtzl-sw-error-header">
										<strong><?php echo esc_html( $error_info['title'] ); ?></strong>
										<span class="jtzl-sw-error-time"><?php echo esc_html( $error['time_ago'] . ' ago' ); ?></span>
									</div>
									<p class="jtzl-sw-error-description"><?php echo esc_html( $error_info['description'] ); ?></p>
									<div class="jtzl-sw-error-actions">
										<strong><?php esc_html_e( 'Recommended Actions:', 'in-browser-cache' ); ?></strong>
										<ul>
											<?php foreach ( $error_info['actions'] as $action ) : ?>
												<li><?php echo esc_html( $action ); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $error_stats['total_errors'] > 0 ) : ?>
					<div class="jtzl-sw-error-statistics">
						<h5><?php esc_html_e( 'Error Statistics (Last 7 Days)', 'in-browser-cache' ); ?></h5>
						<table class="widefat">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Error Type', 'in-browser-cache' ); ?></th>
									<th><?php esc_html_e( 'Count', 'in-browser-cache' ); ?></th>
									<th><?php esc_html_e( 'Severity', 'in-browser-cache' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $error_stats['by_type'] as $type => $count ) : ?>
									<tr>
										<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $type ) ) ); ?></td>
										<td><?php echo esc_html( $count ); ?></td>
										<td>
											<?php
											// Validate error type and map to safe severity class.
											$allowed_error_types = array(
												'ssl_error',
												'dns_error',
												'connectivity',
												'timeout',
												'http_error',
												'cors_error',
												'rate_limit',
												'configuration',
												'validation',
												'unknown',
											);
											$safe_type           = in_array( $type, $allowed_error_types, true ) ? $type : 'unknown';

											$severity_class = 'warning';
											if ( in_array( $safe_type, array( 'ssl_error', 'dns_error' ), true ) ) {
												$severity_class = 'critical';
											} elseif ( in_array( $safe_type, array( 'connectivity', 'timeout' ), true ) ) {
												$severity_class = 'error';
											}

											// Double-check severity class is in allowed list.
											$allowed_severities  = array( 'critical', 'error', 'warning' );
											$safe_severity_class = in_array( $severity_class, $allowed_severities, true ) ? $severity_class : 'warning';
											?>
											<span class="jtzl-sw-severity jtzl-sw-severity-<?php echo esc_attr( $safe_severity_class ); ?>">
												<?php echo esc_html( ucfirst( $safe_severity_class ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<div class="jtzl-sw-general-troubleshooting">
					<h5><?php esc_html_e( 'General Troubleshooting Steps', 'in-browser-cache' ); ?></h5>
					<div class="jtzl-sw-troubleshooting-steps">
						<div class="jtzl-sw-step">
							<h6><?php esc_html_e( '1. Verify CDN Configuration', 'in-browser-cache' ); ?></h6>
							<ul>
								<li><?php esc_html_e( 'Ensure your CDN URL is correct and accessible', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Check that your CDN supports HTTPS', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Verify your CDN is properly configured to serve your domain\'s assets', 'in-browser-cache' ); ?></li>
							</ul>
						</div>
						<div class="jtzl-sw-step">
							<h6><?php esc_html_e( '2. Check Network Connectivity', 'in-browser-cache' ); ?></h6>
							<ul>
								<li><?php esc_html_e( 'Test your CDN URL in a web browser', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Check if your server can reach the CDN (firewall/network restrictions)', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Verify DNS resolution for your CDN domain', 'in-browser-cache' ); ?></li>
							</ul>
						</div>
						<div class="jtzl-sw-step">
							<h6><?php esc_html_e( '3. Review CDN Provider Settings', 'in-browser-cache' ); ?></h6>
							<ul>
								<li><?php esc_html_e( 'Check your CDN provider\'s dashboard for service status', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Verify rate limits and usage quotas', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Ensure CORS headers are properly configured if needed', 'in-browser-cache' ); ?></li>
							</ul>
						</div>
						<div class="jtzl-sw-step">
							<h6><?php esc_html_e( '4. Monitor and Test', 'in-browser-cache' ); ?></h6>
							<ul>
								<li><?php esc_html_e( 'Use the "Test CDN Connection" button above to verify connectivity', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Check your website\'s browser console for CDN-related errors', 'in-browser-cache' ); ?></li>
								<li><?php esc_html_e( 'Monitor the error log regularly for patterns', 'in-browser-cache' ); ?></li>
							</ul>
						</div>
					</div>
				</div>
			</div>

			<style>
			.jtzl-sw-error-item {
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 15px;
				margin: 10px 0;
			}
			.jtzl-sw-error-critical {
				border-color: #dc3545;
				background-color: #f8d7da;
			}
			.jtzl-sw-error-error {
				border-color: #fd7e14;
				background-color: #fff3cd;
			}
			.jtzl-sw-error-warning {
				border-color: #ffc107;
				background-color: #fff3cd;
			}
			.jtzl-sw-error-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 8px;
			}
			.jtzl-sw-error-time {
				font-size: 0.9em;
				color: #666;
			}
			.jtzl-sw-error-description {
				margin: 8px 0;
			}
			.jtzl-sw-error-actions ul {
				margin: 5px 0 0 20px;
			}
			.jtzl-sw-troubleshooting-steps {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
				gap: 20px;
				margin-top: 15px;
			}
			.jtzl-sw-step {
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 15px;
			}
			.jtzl-sw-step h6 {
				margin: 0 0 10px 0;
				color: #333;
			}
			.jtzl-sw-step ul {
				margin: 0 0 0 20px;
			}
			.jtzl-sw-severity {
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 0.85em;
				font-weight: bold;
			}
			.jtzl-sw-severity-critical {
				background-color: #dc3545;
				color: white;
			}
			.jtzl-sw-severity-error {
				background-color: #fd7e14;
				color: white;
			}
			.jtzl-sw-severity-warning {
				background-color: #ffc107;
				color: #212529;
			}
			</style>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the privacy settings section.
	 *
	 * @since 2.0.0
	 */
	private function render_log_settings_section() {
		$log_settings = get_option(
			'jtzl_sw_log_settings',
			array(
				'retention_days' => 30,
			)
		);
		?>
		<h3><?php esc_html_e( 'Error Log Management', 'in-browser-cache' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Configure CDN error logging settings. This plugin follows privacy-by-design principles and does not collect any personal information.', 'in-browser-cache' ); ?></p>
		
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Privacy-by-Design:', 'in-browser-cache' ); ?></strong>
				<?php esc_html_e( 'CDN error logs contain only technical information (error types, timestamps, CDN URLs). No IP addresses, user agents, or other personal data is collected.', 'in-browser-cache' ); ?>
			</p>
		</div>

		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php esc_html_e( 'Log Retention Period', 'in-browser-cache' ); ?></th>
				<td>
					<input type="number" name="jtzl_sw_log_settings[retention_days]" value="<?php echo esc_attr( $log_settings['retention_days'] ); ?>" min="1" max="365" class="small-text" />
					<span><?php esc_html_e( 'days', 'in-browser-cache' ); ?></span>
					<p class="description"><?php esc_html_e( 'How long to keep error logs before automatic deletion (1-365 days). Logs are automatically cleaned up daily.', 'in-browser-cache' ); ?></p>
				</td>
			</tr>
		</table>

		<div class="jtzl-sw-data-management" style="margin-top: 20px;">
			<h4><?php esc_html_e( 'Log Management', 'in-browser-cache' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Error log cleanup can be performed after saving settings using the separate form below.', 'in-browser-cache' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the dashboard page for the plugin.
	 *
	 * This method outputs the HTML for the plugin dashboard, including metrics summary and charts.
	 *
	 * @since 0.1.0
	 */
	public function render_dashboard_page() {
		// Ensure database tables exist before rendering dashboard.
		JTZL_SW_DB::ensure_table_exists();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'In-Browser Cache Dashboard', 'in-browser-cache' ); ?></h1>
			<h2><?php esc_html_e( 'Origin Server Stats', 'in-browser-cache' ); ?></h2>
			<div id="dashboard-summary">
				<div class="summary-box">
					<h2><?php esc_html_e( 'Origin Hits', 'in-browser-cache' ); ?></h2>
					<p id="total-hits">0</p>
				</div>
				<div class="summary-box">
					<h2><?php esc_html_e( 'Origin Misses', 'in-browser-cache' ); ?></h2>
					<p id="total-misses">0</p>
				</div>
				<div class="summary-box">
					<h2><?php esc_html_e( 'Origin Bandwidth Saved', 'in-browser-cache' ); ?></h2>
					<p id="bytes-saved">0 B</p>
				</div>
			</div>
			<h2><?php esc_html_e( 'CDN Stats', 'in-browser-cache' ); ?></h2>
			<div id="cdn-dashboard-summary">
				<div class="summary-box">
					<h2><?php esc_html_e( 'CDN Hits', 'in-browser-cache' ); ?></h2>
					<p id="cdn-total-hits">0</p>
				</div>
				<div class="summary-box">
					<h2><?php esc_html_e( 'CDN Misses', 'in-browser-cache' ); ?></h2>
					<p id="cdn-total-misses">0</p>
				</div>
			</div>
			<div class="chart-container">
				<canvas id="hits-misses-chart"></canvas>
			</div>

			<h2><?php esc_html_e( 'Most Cached Resources', 'in-browser-cache' ); ?></h2>
			<div id="top-assets-container">
				<table id="top-assets-table" class="wp-list-table widefat fixed striped" style="table-layout: fixed; width: 100%;">
					<thead>
						<tr>
							<th scope="col" style="width: 45%;"><?php esc_html_e( 'Asset URL', 'in-browser-cache' ); ?></th>
							<th scope="col" style="width: 10%;"><?php esc_html_e( 'Type', 'in-browser-cache' ); ?></th>
							<th scope="col" style="width: 10%;"><?php esc_html_e( 'Size', 'in-browser-cache' ); ?></th>
							<th scope="col" style="width: 15%;"><?php esc_html_e( 'Hit Count', 'in-browser-cache' ); ?></th>
							<th scope="col" style="width: 20%;"><?php esc_html_e( 'Last Accessed', 'in-browser-cache' ); ?></th>
						</tr>
					</thead>
					<tbody id="top-assets-tbody">
						<tr>
							<td colspan="5" style="text-align: center; padding: 20px;">
								<?php esc_html_e( 'Loading frequency data...', 'in-browser-cache' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<button type="button" id="refresh-data-btn" class="button button-primary"><?php esc_html_e( 'Refresh Data', 'in-browser-cache' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Get the URL for a script file, only using built versions.
	 *
	 * @since 0.1.0
	 *
	 * @param string $script_name The name of the script file (e.g., 'admin.js').
	 * @return string|false The URL to the built script file, or false if missing.
	 */
	private function get_script_url( $script_name ) {
		$build_path = plugin_dir_path( __FILE__ ) . '../build/' . $script_name;
		$build_url  = plugin_dir_url( __FILE__ ) . '../build/' . $script_name;

		// Only use built assets to avoid ESM import issues.
		if ( file_exists( $build_path ) ) {
			return $build_url;
		}

		// Set transient to show admin notice about missing build.
		set_transient( 'jtzl_sw_build_missing_notice', true, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Enqueues admin scripts and styles for the plugin.
	 *
	 * This method loads the necessary JavaScript files for the settings and dashboard pages.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Debug: Log the current hook to help troubleshoot.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'JTZL Admin Hook: ' . $hook );
		}

		// Enqueue admin script for settings page.
		if ( 'toplevel_page_jtzl_sw' === $hook ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'JTZL Admin: Enqueuing admin script' );
			}
			$admin_src = $this->get_script_url( 'admin.js' );

			// Only enqueue if build file exists.
			if ( false !== $admin_src ) {
				wp_enqueue_script(
					'jtzl-sw-admin',
					$admin_src,
					array( 'jquery' ),
					JTZL_SW_VERSION,
					true
				);

				wp_localize_script(
					'jtzl-sw-admin',
					'jtzl_sw_admin',
					array(
						'ajax_url'       => admin_url( 'admin-ajax.php' ),
						'nonce'          => wp_create_nonce( 'clear_all_caches' ),
						'cdn_test_nonce' => wp_create_nonce( 'test_cdn_connectivity' ),
						'strings'        => array(
							'testing_cdn'      => __( 'Testing...', 'in-browser-cache' ),
							'test_connection'  => __( 'Test Connection', 'in-browser-cache' ),
							'cdn_test_success' => __( 'CDN connectivity test successful', 'in-browser-cache' ),
							'cdn_test_failed'  => __( 'CDN connectivity test failed', 'in-browser-cache' ),
							'invalid_url'      => __( 'Please enter a valid CDN URL', 'in-browser-cache' ),
							'network_error'    => __( 'Network error occurred during testing', 'in-browser-cache' ),
						),
					)
				);
			}

			// Register and enqueue admin stylesheet for CDN settings CSS.
			wp_register_style(
				'jtzl-sw-admin-settings',
				false, // No external file, inline only.
				array(),
				JTZL_SW_VERSION
			);
			wp_enqueue_style( 'jtzl-sw-admin-settings' );

			// Add CDN settings CSS.
			wp_add_inline_style(
				'jtzl-sw-admin-settings',
				'
				.cdn-test-result {
					margin-top: 10px;
				}
				.cdn-test-status {
					padding: 8px 12px;
					border-radius: 4px;
					font-size: 13px;
				}
				.cdn-test-success {
					background-color: #d1e7dd;
					border: 1px solid #badbcc;
					color: #0f5132;
				}
				.cdn-test-error {
					background-color: #f8d7da;
					border: 1px solid #f5c2c7;
					color: #842029;
				}
				.cdn-test-loading {
					background-color: #cff4fc;
					border: 1px solid #b6effb;
					color: #055160;
				}
				.cdn-test-message {
					font-weight: 500;
				}
				.cdn-test-time {
					font-size: 12px;
					opacity: 0.8;
				}
				.cdn-url-feedback {
					font-weight: 500;
				}
				#cdn-base-url {
					width: 300px;
				}
				#test-cdn-btn {
					margin-left: 10px;
				}
				.cdn-setting-row {
					transition: opacity 0.3s ease;
				}
			'
			);
		}

		// Enqueue scripts for dashboard page.
		if ( 'in-browser-cache_page_jtzl_sw_dashboard' === $hook ) {
			$dashboard_src = $this->get_script_url( 'dashboard.js' );

			// Only enqueue if build file exists (get_script_url returns false if missing).
			if ( false !== $dashboard_src ) {
				wp_enqueue_script(
					'jtzl-sw-dashboard',
					$dashboard_src,
					array( 'jquery' ),
					JTZL_SW_VERSION,
					true
				);

				wp_localize_script(
					'jtzl-sw-dashboard',
					'jtzl_sw',
					array(
						'api_nonce' => wp_create_nonce( 'wp_rest' ),
						'api_url'   => rest_url( 'jtzl-sw/v1/' ),
					)
				);
			}

			// Register and enqueue admin stylesheet for dashboard CSS.
			wp_register_style(
				'jtzl-sw-admin-dashboard',
				false, // No external file, inline only.
				array(),
				JTZL_SW_VERSION
			);
			wp_enqueue_style( 'jtzl-sw-admin-dashboard' );

			// Add inline CSS for dashboard styling.
			wp_add_inline_style(
				'jtzl-sw-admin-dashboard',
				'
				#top-assets-container {
					margin: 20px 0;
				}
				#top-assets-table {
					margin-top: 10px !important;
					table-layout: fixed !important;
					width: 100% !important;
				}
				#top-assets-table th {
					font-weight: 600 !important;
				}
				#top-assets-table td {
					vertical-align: middle !important;
				}
				#top-assets-table th:first-child,
				#top-assets-table td:first-child {
					width: 45% !important;
					max-width: none !important;
					white-space: normal !important;
				}
				#top-assets-table th:nth-child(2),
				#top-assets-table td:nth-child(2) {
					width: 10% !important;
				}
				#top-assets-table th:nth-child(3),
				#top-assets-table td:nth-child(3) {
					width: 10% !important;
				}
				#top-assets-table th:nth-child(4),
				#top-assets-table td:nth-child(4) {
					width: 15% !important;
				}
				#top-assets-table th:nth-child(5),
				#top-assets-table td:nth-child(5) {
					width: 20% !important;
				}
				#top-assets-table .asset-url-cell {
					word-wrap: break-word !important;
					word-break: break-all !important;
					overflow-wrap: break-word !important;
					hyphens: auto !important;
					line-height: 1.4 !important;
					padding: 8px 12px !important;
					white-space: normal !important;
					max-width: none !important;
					text-overflow: clip !important;
					overflow: visible !important;
				}
				/* Override WordPress table styles */
				.wp-list-table.widefat.fixed.striped th:first-child,
				.wp-list-table.widefat.fixed.striped td:first-child {
					width: 45% !important;
					max-width: none !important;
				}
				.wp-list-table.widefat.fixed.striped {
					table-layout: fixed !important;
				}
				.chart-container {
					margin: 20px 0;
					max-width: 800px;
				}
				#dashboard-summary, #cdn-dashboard-summary {
					display: flex;
					gap: 20px;
					margin: 20px 0;
				}
				.summary-box {
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 4px;
					padding: 20px;
					flex: 1;
					text-align: center;
				}
				.summary-box h2 {
					margin: 0 0 10px 0;
					font-size: 14px;
					color: #646970;
					text-transform: uppercase;
				}
				.summary-box p {
					margin: 0;
					font-size: 24px;
					font-weight: 600;
					color: #1d2327;
				}
			'
			);
		}
	}

	/**
	 * Handles admin actions such as clearing all user caches.
	 *
	 * This method checks for specific POST requests and performs actions like clearing caches.
	 *
	 * @since 0.1.0
	 */
	public function handle_admin_actions() {
		if ( isset( $_POST['clear_all_caches'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'clear_all_caches' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions' );
			}

			// Ensure database tables exist before incrementing cache version.
			JTZL_SW_DB::ensure_table_exists();
			$new_version = JTZL_SW_DB::increment_cache_version();

			add_action(
				'admin_notices',
				function () use ( $new_version ) {
					echo '<div class="notice notice-success is-dismissible">';
					echo '<p>' . sprintf(
						/* translators: %d: cache version number */
						esc_html__( 'Cache version incremented to %d. All user caches will be cleared automatically within a few minutes.', 'in-browser-cache' ),
						esc_html( $new_version )
					) . '</p>';
					echo '</div>';
				}
			);
		}

		// Handle cleanup error logs action.
		if ( isset( $_POST['cleanup_error_logs'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cleanup_error_logs' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions' );
			}

			$deleted_count = JTZL_SW_CDN_Error_Handler::cleanup_old_logs( 0 ); // Delete all logs.

			add_action(
				'admin_notices',
				function () use ( $deleted_count ) {
					echo '<div class="notice notice-success is-dismissible">';
					echo '<p>' . sprintf(
						/* translators: %d: number of deleted log entries */
						esc_html__( 'Successfully deleted %d CDN error log entries.', 'in-browser-cache' ),
						esc_html( $deleted_count )
					) . '</p>';
					echo '</div>';
				}
			);
		}
	}

	/**
	 * Handle AJAX request to clear all user caches.
	 *
	 * @since 0.1.0
	 */
	public function handle_clear_all_caches() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		check_ajax_referer( 'clear_all_caches' );

		// Ensure database tables exist before incrementing cache version.
		JTZL_SW_DB::ensure_table_exists();
		$new_version = JTZL_SW_DB::increment_cache_version();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: cache version number */
					__( 'Cache version incremented to %d. All user caches will be cleared automatically.', 'in-browser-cache' ),
					$new_version
				),
				'version' => $new_version,
			)
		);
	}



	/**
	 * Handle AJAX request to test CDN connectivity.
	 *
	 * @since 2.0.0
	 */
	public function handle_cdn_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		check_ajax_referer( 'test_cdn_connectivity' );

		$cdn_url = isset( $_POST['cdn_url'] ) ? sanitize_text_field( wp_unslash( $_POST['cdn_url'] ) ) : '';

		if ( empty( $cdn_url ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'CDN URL is required for testing.', 'in-browser-cache' ),
				)
			);
		}

		// Test CDN connectivity.
		$test_result = JTZL_SW_CDN_Config::test_cdn_connectivity( $cdn_url );

		// Update test status in settings.
		JTZL_SW_CDN_Config::update_test_status( $test_result );

		if ( 'success' === $test_result['status'] ) {
			wp_send_json_success(
				array(
					'message'       => esc_html( $test_result['message'] ),
					'response_time' => absint( $test_result['response_time'] ),
					'status'        => sanitize_text_field( $test_result['status'] ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message'       => esc_html( $test_result['message'] ),
					'response_time' => absint( $test_result['response_time'] ),
					'status'        => sanitize_text_field( $test_result['status'] ),
				)
			);
		}
	}

	/**
	 * Shows an admin notice if build artifacts are missing.
	 *
	 * This notice appears when build artifacts are missing, which would cause
	 * JavaScript functionality to fail loading.
	 *
	 * @since 0.2.5
	 */
	public function show_build_missing_notice() {
		if ( ! get_transient( 'jtzl_sw_build_missing_notice' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if any required build files are missing.
		$required_files = array(
			'service-worker.js',
			'admin.js',
			'dashboard.js',
		);

		$missing_files = array();
		foreach ( $required_files as $file ) {
			$build_path = plugin_dir_path( __DIR__ ) . 'build/' . $file;
			if ( ! file_exists( $build_path ) ) {
				$missing_files[] = $file;
			}
		}

		if ( empty( $missing_files ) ) {
			// All build files exist now, clear the notice.
			delete_transient( 'jtzl_sw_build_missing_notice' );
			return;
		}

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e( 'In-Browser Cache Plugin:', 'in-browser-cache' ); ?></strong>
				<?php esc_html_e( 'Build artifacts are missing. The plugin will not function properly until you run:', 'in-browser-cache' ); ?>
				<code>npm run build</code>
			</p>
			<p>
				<?php esc_html_e( 'Missing files use ES modules that must be bundled for browser compatibility.', 'in-browser-cache' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Initialize URL rewriting hooks if CDN is enabled.
	 *
	 * @since 2.0.0
	 */
	public function init_url_rewriting() {
		// Only initialize URL rewriting if CDN is enabled.
		if ( ! JTZL_SW_CDN_Config::is_cdn_enabled() ) {
			return;
		}

		// Add filters for asset URL rewriting.
		add_filter( 'script_loader_src', array( $this, 'rewrite_script_url' ), 10, 2 );
		add_filter( 'style_loader_src', array( $this, 'rewrite_style_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_asset_url' ), 10, 1 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_srcset_urls' ), 10, 1 );
	}

	/**
	 * Rewrite script URLs to use CDN.
	 *
	 * @since 2.0.0
	 * @param string $src    Script URL.
	 * @param string $handle Script handle (optional).
	 * @return string Rewritten URL or original URL if not rewritable.
	 */
	public function rewrite_script_url( $src, $handle = '' ) {
		return $this->rewrite_asset_url( $src );
	}

	/**
	 * Rewrite style URLs to use CDN.
	 *
	 * @since 2.0.0
	 * @param string $src    Style URL.
	 * @param string $handle Style handle (optional).
	 * @return string Rewritten URL or original URL if not rewritable.
	 */
	public function rewrite_style_url( $src, $handle = '' ) {
		return $this->rewrite_asset_url( $src );
	}

	/**
	 * Rewrite asset URLs to use CDN.
	 *
	 * @since 2.0.0
	 * @param string $url Original asset URL.
	 * @return string Rewritten URL or original URL if not rewritable.
	 */
	public function rewrite_asset_url( $url ) {
		// Get CDN base URL.
		$cdn_base_url = JTZL_SW_CDN_Config::get_cdn_base_url();

		if ( empty( $cdn_base_url ) ) {
			return $url;
		}

		// Create URL rewriter instance.
		$rewriter = new JTZL_SW_URL_Rewriter( $cdn_base_url );

		return $rewriter->rewrite_asset_url( $url );
	}

	/**
	 * Rewrite URLs in responsive image srcset attributes.
	 *
	 * @since 2.0.0
	 * @param array $sources Array of image sources for srcset.
	 * @return array Modified sources array with rewritten URLs.
	 */
	public function rewrite_srcset_urls( $sources ) {
		// Get CDN base URL.
		$cdn_base_url = JTZL_SW_CDN_Config::get_cdn_base_url();

		if ( empty( $cdn_base_url ) || ! is_array( $sources ) ) {
			return $sources;
		}

		// Create URL rewriter instance.
		$rewriter = new JTZL_SW_URL_Rewriter( $cdn_base_url );

		// Rewrite each source URL in the srcset.
		foreach ( $sources as $width => $source ) {
			if ( isset( $source['url'] ) ) {
				$sources[ $width ]['url'] = $rewriter->rewrite_asset_url( $source['url'] );
			}
		}

		return $sources;
	}
}
