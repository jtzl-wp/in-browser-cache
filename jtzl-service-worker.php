<?php
/**
 * Plugin Name:       In-Browser Cache
 * Description:       An advanced caching plugin using the power of Service Workers.
 * Version:           2.0.3
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            JT G.
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       in-browser-cache
 * Domain Path:       /languages
 *
 * @package JTZL_Service_Worker
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

define( 'JTZL_SW_VERSION', '2.0.3' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-registrar.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-admin.php';
require_once plugin_dir_path( __FILE__ ) .
	'includes/class-jtzl-sw-metrics-endpoint.php';
require_once plugin_dir_path( __FILE__ ) .
	'includes/class-jtzl-sw-file-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-activator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-db.php';
require_once plugin_dir_path( __FILE__ ) .
	'includes/class-jtzl-sw-cdn-config.php';
require_once plugin_dir_path( __FILE__ ) .
	'includes/class-jtzl-sw-url-rewriter.php';
require_once plugin_dir_path( __FILE__ ) .
	'includes/class-jtzl-sw-cdn-error-handler.php';

register_activation_hook( __FILE__, array( 'JTZL_SW_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JTZL_SW_Activator', 'deactivate' ) );

// Flush rewrite rules after activation (deferred for reliability).
// This runs on the next page load after plugin activation.
// Using 'init' hook instead of 'admin_init' for better reliability (including WP-CLI).
add_action(
	'init',
	function () {
		if ( get_transient( 'jtzl_sw_flush_rewrite_rules' ) && current_user_can( 'activate_plugins' ) ) {
			// Register the rewrite rule before flushing to ensure it's applied.
			$file_handler = new JTZL_SW_File_Handler();
			$file_handler->add_rewrite_rule();

			delete_transient( 'jtzl_sw_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}
);

// Ensure tables exist on plugin initialization.
add_action(
	'plugins_loaded',
	function () {
		JTZL_SW_DB::ensure_table_exists();
	}
);

new JTZL_SW_Registrar();
new JTZL_SW_Admin();
new JTZL_SW_Metrics_Endpoint();
new JTZL_SW_File_Handler();

// Add cleanup hook for CDN error logs.
add_action(
	'jtzl_sw_cleanup_error_logs',
	array(
		'JTZL_SW_CDN_Error_Handler',
		'cleanup_old_logs',
	)
);
