<?php
/**
 * Uninstall script for In-Browser Cache
 *
 * This file is executed when the plugin is deleted (not just deactivated).
 * It performs complete cleanup of all plugin data.
 *
 * @package JTZL_Service_Worker
 * @since 0.2.4
 */

// Exit if accessed directly or not in uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Include the database class for cleanup.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-db.php';

/**
 * Complete cleanup of plugin data
 */
function jtzl_sw_uninstall_cleanup() {
	global $wpdb;

	// Remove plugin options.
	delete_option( 'jtzl_sw_options' );
	delete_option( 'jtzl_sw_version' );

	// Remove any transients.
	delete_transient( 'jtzl_sw_deactivated' );
	delete_transient( 'jtzl_sw_reregister_sw' );

	// Drop custom database tables.
	$metrics_table = $wpdb->prefix . 'jtzl_sw_cache_metrics';
	$assets_table  = $wpdb->prefix . 'jtzl_sw_cached_assets';

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $metrics_table ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $assets_table ) );

	// Clean up any remaining cache-related data.
	$wpdb->query(
		"DELETE FROM {$wpdb->options} 
		 WHERE option_name LIKE 'jtzl_sw_%' 
		 OR option_name LIKE '_transient_jtzl_sw_%' 
		 OR option_name LIKE '_transient_timeout_jtzl_sw_%'"
	);

	// Note: We cannot unregister service workers from PHP
	// The service worker's auto-deregister mechanism will handle cleanup
	// when it detects the plugin is no longer available.
}

// Perform the cleanup.
jtzl_sw_uninstall_cleanup();
