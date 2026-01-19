<?php
/**
 * JTZL SW Activator Class
 *
 * This class handles the activation tasks for the ServiceWorker plugin.
 *
 * @package   ServiceWorker
 * @author    JT G.
 * @license   GPL-2.0+
 * @link      https://example.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_Activator
 *
 * Handles the activation tasks for the ServiceWorker plugin.
 *
 * @since 0.1.0
 */
class JTZL_SW_Activator {

	/**
	 * Activate the plugin.
	 *
	 * This method is called when the plugin is activated. It creates the necessary
	 * database tables and flushes rewrite rules to ensure the plugin functions correctly.
	 *
	 * @since 0.1.0
	 */
	public static function activate() {
		// Check if build artifacts exist.
		$build_path = plugin_dir_path( __DIR__ ) . 'build/service-worker.js';
		if ( ! file_exists( $build_path ) ) {
			// Store a notice for admin users about missing build artifacts.
			set_transient( 'jtzl_sw_build_missing_notice', true, DAY_IN_SECONDS );
		}

		// Create the custom table for metrics and run any pending migrations.
		JTZL_SW_DB::ensure_table_exists();

		// Clear any deactivation flags from previous deactivation.
		delete_transient( 'jtzl_sw_deactivated' );
		delete_option( 'jtzl_sw_deactivated_at' );

		// Schedule automatic cleanup of CDN error logs.
		JTZL_SW_CDN_Error_Handler::schedule_cleanup();

		// Set a flag to flush rewrite rules on the next page load.
		// This is more reliable than flushing during activation because WordPress
		// regenerates rewrite rules by triggering init hooks, which may not have
		// fully run during the activation context.
		// No expiration - ensures rules are always flushed regardless of when admin visits.
		set_transient( 'jtzl_sw_flush_rewrite_rules', true );
	}

	/**
	 * Deactivate the plugin.
	 *
	 * This method is called when the plugin is deactivated. Since we can't run
	 * JavaScript to unregister the service worker after deactivation, we implement
	 * a multi-layered approach for the service worker to auto-deregister itself.
	 *
	 * @since 0.2.4
	 */
	public static function deactivate() {
		// Set a transient flag that indicates the plugin has been deactivated
		// This will be checked by the service worker's auto-deregister mechanism.
		set_transient( 'jtzl_sw_deactivated', true, WEEK_IN_SECONDS );

		// Store deactivation timestamp for health check endpoint.
		update_option( 'jtzl_sw_deactivated_at', time() );

		// Flush rewrite rules to remove our custom service worker endpoint
		// This will cause 404 errors when the service worker tries to fetch itself.
		flush_rewrite_rules();

		// Clear any cached service worker files from object cache.
		wp_cache_delete( 'jtzl_sw_service_worker_content' );

		// Unschedule automatic cleanup of CDN error logs.
		JTZL_SW_CDN_Error_Handler::unschedule_cleanup();

		// Note: We cannot unregister the service worker here because:
		// 1. We're in PHP context, not JavaScript
		// 2. After deactivation, no plugin code can run
		//
		// The service worker will auto-deregister itself using multiple detection methods:
		// 1. Health check failures (service worker file returns 404)
		// 2. REST API endpoint failures (metrics/nonce endpoints return 404)
		// 3. Deactivation flag check via health check endpoint
		// 4. Failed communication attempts over time.
	}
}
