<?php
/**
 * JTZL SW Admin Class
 *
 * Handles the admin interface for the In-Browser Cache plugin.
 *
 * @package In-Browser Cache
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
				<?php submit_button(); ?>
			</form>

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
			<div id="dashboard-summary">
				<div class="summary-box">
					<h2><?php esc_html_e( 'Total Hits', 'in-browser-cache' ); ?></h2>
					<p id="total-hits">0</p>
				</div>
				<div class="summary-box">
					<h2><?php esc_html_e( 'Total Misses', 'in-browser-cache' ); ?></h2>
					<p id="total-misses">0</p>
				</div>
				<div class="summary-box">
					<h2><?php esc_html_e( 'Bandwidth Saved', 'in-browser-cache' ); ?></h2>
					<p id="bytes-saved">0 B</p>
				</div>
			</div>
			<div class="chart-container">
				<canvas id="hits-misses-chart"></canvas>
			</div>

			<h2><?php esc_html_e( 'Most Cached Resources', 'in-browser-cache' ); ?></h2>
			<div id="top-assets-container">
				<table id="top-assets-table" class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Asset URL', 'in-browser-cache' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'in-browser-cache' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'in-browser-cache' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hit Count', 'in-browser-cache' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last Accessed', 'in-browser-cache' ); ?></th>
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
	 * Get the URL for a script file, preferring the built version if it exists.
	 *
	 * @since 0.1.0
	 *
	 * @param string $script_name The name of the script file (e.g., 'admin.js').
	 * @return string The URL to the script file.
	 */
	private function get_script_url( $script_name ) {
		$build_path = plugin_dir_path( __FILE__ ) . '../build/' . $script_name;
		$build_url  = plugin_dir_url( __FILE__ ) . '../build/' . $script_name;
		$src_url    = plugin_dir_url( __FILE__ ) . '../src/' . $script_name;

		return file_exists( $build_path ) ? $build_url : $src_url;
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
		// Enqueue admin script for settings page.
		if ( 'toplevel_page_jtzl_sw' === $hook ) {
			$admin_src = $this->get_script_url( 'admin.js' );
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
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'clear_all_caches' ),
				)
			);
		}

		// Enqueue scripts for dashboard page.
		if ( 'in-browser-cache_page_jtzl_sw_dashboard' === $hook ) {
			$chart_src = plugin_dir_url( __FILE__ ) . '../assets/js/vendor/chart.umd.js';
			wp_enqueue_script(
				'chart-js',
				$chart_src,
				array(),
				'4.4.1',
				true
			);

			$dashboard_src = $this->get_script_url( 'dashboard.js' );
			wp_enqueue_script(
				'jtzl-sw-dashboard',
				$dashboard_src,
				array( 'jquery', 'chart-js' ),
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

			// Add inline CSS for dashboard styling.
			wp_add_inline_style(
				'wp-admin',
				'
				#top-assets-container {
					margin: 20px 0;
				}
				#top-assets-table {
					margin-top: 10px;
				}
				#top-assets-table th {
					font-weight: 600;
				}
				#top-assets-table td {
					vertical-align: middle;
				}
				#top-assets-table td[title] {
					cursor: help;
				}
				.chart-container {
					margin: 20px 0;
					max-width: 800px;
				}
				#dashboard-summary {
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
}
