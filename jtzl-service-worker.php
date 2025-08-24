<?php
/**
 * Plugin Name:       In-Browser Cache
 * Description:       Improve website performance with client-side caching using Service Workers for advanced in-browser cache management.
 * Version:           1.0.0
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
	exit;
}

define( 'JTZL_SW_VERSION', '1.0.0' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-registrar.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-metrics-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-file-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-activator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jtzl-sw-db.php';

register_activation_hook( __FILE__, array( 'JTZL_SW_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JTZL_SW_Activator', 'deactivate' ) );

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
