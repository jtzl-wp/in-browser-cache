<?php
/**
 * JTZL SW Database Class
 *
 * This class handles the creation and management of the database table used for caching metrics.
 * It includes methods to create the table, check if it exists, and ensure it is created if not.
 *
 * @package   ServiceWorker
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_DB
 *
 * Handles the database operations for the ServiceWorker plugin.
 *
 * @since 0.1.0
 */
class JTZL_SW_DB {

	/**
	 * Plugin version to database migration mapping.
	 *
	 * Maps plugin versions to their required database schema changes.
	 * This ensures migrations run based on actual plugin updates.
	 *
	 * Note: Pre-1.0.0 migrations (0.1.0, 0.5.0, 0.6.0) have been removed
	 * as those versions were never publicly released. Fresh installations
	 * automatically get the complete database schema via create_initial_tables().
	 *
	 * @since 1.5.0
	 */
	const VERSION_MIGRATIONS = array(
		'1.5.0'      => array( 'create_cdn_error_log_table' ),
		'2.0.0-rc.5' => array( 'add_cdn_metrics_columns' ),
	);

	/**
	 * Run database migrations based on plugin version changes.
	 *
	 * This method compares the last known plugin version with the current version
	 * and runs any necessary migrations to bring the database schema up to date.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True if migrations completed successfully, false otherwise.
	 */
	public static function maybe_migrate() {
		$current_plugin_version = JTZL_SW_VERSION;
		$last_migrated_version  = get_option( 'jtzl_sw_plugin_version', '0.0.0' );

		// Skip if already up to date.
		if ( version_compare( $last_migrated_version, $current_plugin_version, '>=' ) ) {
			return true;
		}

		// For fresh installations (pre-1.0.0), create all tables and mark as current version.
		if ( version_compare( $last_migrated_version, '1.0.0', '<' ) ) {
			$result = self::create_initial_tables();
			if ( ! $result ) {
				error_log( 'JTZL SW: Failed to create initial tables during fresh installation' );
				return false;
			}

			// Mark as current version after successful table creation.
			self::update_plugin_version( $current_plugin_version );
			return true;
		}

		// Get migrations needed between versions for existing installations.
		$needed_migrations = self::get_migrations_between_versions( $last_migrated_version, $current_plugin_version );

		if ( empty( $needed_migrations ) ) {
			// Update version even if no migrations needed.
			self::update_plugin_version( $current_plugin_version );
			return true;
		}

		// Run migrations in chronological order.
		foreach ( $needed_migrations as $version => $migrations ) {
			foreach ( $migrations as $migration ) {
				$migration_method = 'migrate_' . $migration;
				if ( method_exists( __CLASS__, $migration_method ) ) {
					$result = call_user_func( array( __CLASS__, $migration_method ) );
					if ( ! $result ) {
						// Log the failed migration for debugging.
						error_log( sprintf( 'JTZL SW: Migration failed: %s (method: %s) for version %s', $migration, $migration_method, $version ) );
						return false;
					}
					// Log successful migration for debugging.
					error_log( sprintf( 'JTZL SW: Migration successful: %s (method: %s) for version %s', $migration, $migration_method, $version ) );
				} else {
					// List available methods for debugging.
					$available_methods = array_filter(
						get_class_methods( __CLASS__ ),
						function ( $method ) {
							return strpos( $method, 'migrate_' ) === 0;
						}
					);
					error_log(
						sprintf(
							'JTZL SW: Migration method not found: %s for version %s. Available migration methods: %s',
							$migration_method,
							$version,
							implode( ', ', $available_methods )
						)
					);
					return false;
				}
			}
		}

		// Update to current version after successful migrations.
		self::update_plugin_version( $current_plugin_version );

		return true;
	}

	/**
	 * Get migrations needed between two plugin versions.
	 *
	 * @since 1.5.0
	 *
	 * @param string $from_version Starting version.
	 * @param string $to_version   Target version.
	 * @return array Migrations needed, keyed by version.
	 */
	private static function get_migrations_between_versions( $from_version, $to_version ) {
		$needed_migrations = array();

		foreach ( self::VERSION_MIGRATIONS as $version => $migrations ) {
			// Include migration if it's newer than from_version and <= to_version.
			if ( version_compare( $version, $from_version, '>' ) &&
				version_compare( $version, $to_version, '<=' ) ) {
				$needed_migrations[ $version ] = $migrations;
			}
		}

		// Sort by version to ensure proper order.
		uksort( $needed_migrations, 'version_compare' );

		return $needed_migrations;
	}

	/**
	 * Update the stored plugin version after successful migration.
	 *
	 * @since 1.5.0
	 *
	 * @param string $version Plugin version.
	 * @return void
	 */
	private static function update_plugin_version( $version ) {
		update_option( 'jtzl_sw_plugin_version', $version, false );

		// Also update legacy DB version for backwards compatibility.
		// Set to 4 to maintain compatibility with existing installations that had the full migration history.
		update_option( 'jtzl_sw_db_version', 4 );
	}







	/**
	 * Create the database table for caching metrics.
	 *
	 * This method creates a table to store cache metrics such as hits, misses, and bytes saved.
	 * It uses the dbDelta function to ensure the table is created with the correct structure.
	 *
	 * @since 0.1.0
	 *
	 * @return bool The result of the dbDelta operation.
	 */
	public static function create_table() {
		// Run migrations instead of direct table creation.
		return self::maybe_migrate();
	}

	/**
	 * Create initial database tables (version 1 schema).
	 *
	 * @since 0.5.0
	 *
	 * @return bool True if tables created successfully, false otherwise.
	 */
	public static function create_initial_tables() {
		global $wpdb;
		$metrics_table   = esc_sql( $wpdb->prefix . 'jtzl_sw_cache_metrics' );
		$assets_table    = esc_sql( $wpdb->prefix . 'jtzl_sw_cached_assets' );
		$charset_collate = $wpdb->get_charset_collate();

		$sql_metrics = "CREATE TABLE $metrics_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_date date NOT NULL,
			hits int(11) NOT NULL DEFAULT 0,
			misses int(11) NOT NULL DEFAULT 0,
			bytes_saved bigint(20) NOT NULL DEFAULT 0,
			cdn_hits int(11) NOT NULL DEFAULT 0,
			cdn_misses int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY metric_date (metric_date)
		) $charset_collate;";

		$sql_assets = "CREATE TABLE $assets_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			asset_url varchar(2048) NOT NULL,
			asset_type varchar(20) NOT NULL,
			asset_size int(11) NOT NULL,
			hit_count int(11) NOT NULL DEFAULT 0,
			last_accessed datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY asset_url_key (asset_url(191)),
			KEY hit_count_idx (hit_count)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result_metrics = dbDelta( $sql_metrics );
		$result_assets  = dbDelta( $sql_assets );

		// Verify tables were created.
		$metrics_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'jtzl_sw_cache_metrics' ) );
		$assets_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'jtzl_sw_cached_assets' ) );

		// Verify tables were created but don't log for production.

		// Ensure the cache version option exists.
		self::initialize_cache_version();

		// Only return true if BOTH tables exist after attempting creation.
		return ( $metrics_exists && $assets_exists );
	}

	/**
	 * Check if the metrics and assets tables exist.
	 *
	 * This method checks if the database tables for caching metrics and cached assets exist.
	 * Cache version is stored in wp_options, so no separate table is needed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if all required tables exist, false otherwise.
	 */
	public static function table_exists() {
		global $wpdb;
		$metrics_table  = $wpdb->prefix . 'jtzl_sw_cache_metrics';
		$assets_table   = $wpdb->prefix . 'jtzl_sw_cached_assets';
		$metrics_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $metrics_table ) ) === $metrics_table;
		$assets_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $assets_table ) ) === $assets_table;
		return ( $metrics_exists && $assets_exists );
	}

	/**
	 * Ensure the metrics and assets tables exist and are up to date.
	 *
	 * This method checks if the required tables exist and runs any necessary migrations.
	 * Cache version is managed via wp_options, so no separate table is needed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if all tables exist and are up to date, false on failure.
	 */
	public static function ensure_table_exists() {
		// Run migrations which will create tables if needed and update schema.
		$migration_result = self::maybe_migrate();

		// Ensure cache version is initialized.
		self::initialize_cache_version();

		return $migration_result;
	}

	/**
	 * Initialize the cache version option if it doesn't exist.
	 *
	 * @since 0.2.0
	 */
	public static function initialize_cache_version() {
		if ( false === get_option( 'jtzl_sw_cache_version', false ) ) {
			update_option( 'jtzl_sw_cache_version', 1, false );
		}
	}

	/**
	 * Get the current cache version.
	 *
	 * @since 0.1.0
	 *
	 * @return int The current cache version.
	 */
	public static function get_cache_version() {
		// Ensure cache version option is initialized.
		self::initialize_cache_version();
		$version = get_option( 'jtzl_sw_cache_version', 1 );
		return (int) $version;
	}

	/**
	 * Increment the cache version to trigger cache clearing across all users.
	 *
	 * @since 0.1.0
	 *
	 * @return int The new cache version.
	 */
	public static function increment_cache_version() {
		// Ensure database tables exist before incrementing version.
		self::ensure_table_exists();

		$current_version = self::get_cache_version();
		$new_version     = $current_version + 1;

		update_option( 'jtzl_sw_cache_version', $new_version, false );

		return $new_version;
	}

	/**
	 * Upsert asset data with hit count tracking.
	 *
	 * This method inserts a new asset or updates an existing one, incrementing
	 * the hit count and updating the last accessed timestamp.
	 *
	 * @since 0.5.0
	 *
	 * @param string $asset_url    The URL of the cached asset.
	 * @param string $asset_type   The type of the asset (js, css, image, font).
	 * @param int    $asset_size   The size of the asset in bytes.
	 * @param string $last_accessed The last accessed timestamp (Y-m-d H:i:s format).
	 * @param int    $hit_count    The number of hits to add (default: 1).
	 * @return bool True if operation successful, false otherwise.
	 */
	public static function upsert_asset_hit_count( $asset_url, $asset_type, $asset_size, $last_accessed, $hit_count = 1 ) {
		global $wpdb;

		// Ensure tables exist and are up to date.
		self::ensure_table_exists();

		$assets_table = esc_sql( $wpdb->prefix . 'jtzl_sw_cached_assets' );

		// Sanitize inputs.
		$asset_url     = esc_url_raw( $asset_url );
		$asset_type    = sanitize_text_field( $asset_type );
		$asset_size    = absint( $asset_size );
		$last_accessed = sanitize_text_field( $last_accessed );
		$hit_count     = absint( $hit_count );

		// Business logic validation.
		if ( empty( $asset_url ) ) {
			return false;
		}

		// Validate asset size (max 100MB for web assets).
		if ( $asset_size > 104857600 ) { // 100MB in bytes.
			return false;
		}

		// Validate hit count (max 10,000 hits per single operation).
		if ( $hit_count > 10000 ) {
			return false;
		}

		// Validate asset type against allowed types.
		$allowed_types = array( 'js', 'css', 'image', 'font', 'html', 'json', 'xml', 'other' );
		if ( ! in_array( $asset_type, $allowed_types, true ) ) {
			$asset_type = 'other';
		}

		// Validate timestamp format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last_accessed ) ) {
			return false;
		}

		// Use INSERT ... ON DUPLICATE KEY UPDATE to handle upsert with hit count increment.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- required for custom table upsert
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO `{$assets_table}` (asset_url, asset_type, asset_size, hit_count, last_accessed)
			VALUES (%s, %s, %d, %d, %s)
			ON DUPLICATE KEY UPDATE 
				asset_type = VALUES(asset_type),
				asset_size = CASE 
					WHEN VALUES(asset_size) > 0 THEN VALUES(asset_size)
					ELSE asset_size 
				END,
				hit_count = hit_count + %d,
				last_accessed = VALUES(last_accessed)",
				$asset_url,
				$asset_type,
				$asset_size,
				$hit_count,
				$last_accessed,
				$hit_count
			)
		);

		if ( false === $result ) {
			return false;
		}

		// Invalidate related caches.
		wp_cache_delete( 'top_assets_10', 'jtzl_sw' );

		return true;
	}

	/**
	 * Get top assets by hit count frequency.
	 *
	 * This method retrieves the most frequently cached assets based on hit count,
	 * sorted in descending order by frequency.
	 *
	 * @since 0.5.0
	 *
	 * @param int $limit The maximum number of assets to retrieve (default: 10).
	 * @return array Array of asset objects with URL, type, size, hit_count, and last_accessed.
	 */
	public static function get_top_assets_by_frequency( $limit = 10 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		global $wpdb;

		// Ensure tables exist and are up to date.
		self::ensure_table_exists();

		$assets_table = esc_sql( $wpdb->prefix . 'jtzl_sw_cached_assets' );
		$limit        = absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 10;
		}

		// Try cache first.
		$cache_key = 'top_assets_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'jtzl_sw' );
		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table read with explicit object caching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT asset_url, asset_type, asset_size, hit_count, last_accessed 
			FROM `{$assets_table}` 
			WHERE hit_count > 0 
			ORDER BY hit_count DESC, last_accessed DESC 
			LIMIT %d",
				$limit
			)
		);

		if ( false === $results ) {
			return array();
		}

		// Cache results for a short period.
		wp_cache_set( $cache_key, $results, 'jtzl_sw', 60 );

		return $results;
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared



	/**
	 * Get CDN metrics for a date range.
	 *
	 * This method retrieves CDN metrics from the existing columns in the metrics table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date in Y-m-d format (optional).
	 * @param string $end_date   End date in Y-m-d format (optional).
	 * @return array Array of CDN metrics records.
	 */
	public static function get_cdn_metrics( $start_date = null, $end_date = null ) {
		global $wpdb;

		// Ensure tables exist and are up to date.
		self::ensure_table_exists();

		$metrics_table = esc_sql( $wpdb->prefix . 'jtzl_sw_cache_metrics' );

		$where_conditions = array();
		$prepare_values   = array();

		if ( ! empty( $start_date ) ) {
			$where_conditions[] = 'metric_date >= %s';
			$prepare_values[]   = sanitize_text_field( $start_date );
		}

		if ( ! empty( $end_date ) ) {
			$where_conditions[] = 'metric_date <= %s';
			$prepare_values[]   = sanitize_text_field( $end_date );
		}

		// Build the SQL query.
		$sql = "SELECT metric_date, cdn_hits, cdn_misses FROM `{$metrics_table}`";
		if ( ! empty( $where_conditions ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_conditions );
		}
		$sql .= ' ORDER BY metric_date DESC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table read
		if ( ! empty( $prepare_values ) ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is constructed safely above
					...$prepare_values
				)
			);
		} else {
			$results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input, table name is safe
		}

		return $results ? $results : array();
	}

	/**
	 * Get database schema version for debugging.
	 *
	 * @since 0.5.0
	 *
	 * @return int Current database schema version.
	 */
	public static function get_db_version() {
		return get_option( 'jtzl_sw_db_version', 0 );
	}

	/**
	 * Get comprehensive migration status for debugging.
	 *
	 * @since 1.5.0
	 *
	 * @return array Migration status information.
	 */
	public static function get_migration_status() {
		$current_plugin_version = JTZL_SW_VERSION;
		$last_migrated_version  = get_option( 'jtzl_sw_plugin_version', '0.0.0' );
		$legacy_db_version      = get_option( 'jtzl_sw_db_version', 0 );

		return array(
			'current_plugin_version' => $current_plugin_version,
			'last_migrated_version'  => $last_migrated_version,
			'legacy_db_version'      => $legacy_db_version,
			'migrations_needed'      => self::get_migrations_between_versions( $last_migrated_version, $current_plugin_version ),
			'available_migrations'   => self::VERSION_MIGRATIONS,
			'needs_migration'        => version_compare( $last_migrated_version, $current_plugin_version, '<' ),
		);
	}

	/**
	 * Migration: Create CDN error log table.
	 *
	 * Creates the CDN error logging table for tracking CDN failures and diagnostics.
	 * Introduced in plugin version 1.5.0.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True if migration successful, false otherwise.
	 */
	private static function migrate_create_cdn_error_log_table() {
		// Create the CDN error log table.
		JTZL_SW_CDN_Error_Handler::create_error_log_table();

		// Verify table was created successfully.
		global $wpdb;
		$table_name   = $wpdb->prefix . 'jtzl_sw_cdn_error_log';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return $table_exists === $table_name;
	}

	/**
	 * Migration: Add CDN metrics columns to existing metrics table.
	 *
	 * Adds cdn_hits and cdn_misses columns to the metrics table for existing installations.
	 * This migration is idempotent - it only adds columns if they don't already exist.
	 * Introduced in plugin version 2.0.0.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if migration successful, false otherwise.
	 */
	private static function migrate_add_cdn_metrics_columns() {
		global $wpdb;

		$metrics_table = esc_sql( $wpdb->prefix . 'jtzl_sw_cache_metrics' );

		// Check if cdn_hits column already exists using DESCRIBE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- schema migration check with safe table name
		$describe_result  = $wpdb->get_results( 'DESCRIBE `' . $metrics_table . '`' );
		$existing_columns = array();
		if ( $describe_result ) {
			foreach ( $describe_result as $column ) {
				$existing_columns[] = $column->Field;
			}
		}

		$cdn_hits_exists   = in_array( 'cdn_hits', $existing_columns, true );
		$cdn_misses_exists = in_array( 'cdn_misses', $existing_columns, true );

		$columns_to_add = array();

		if ( ! $cdn_hits_exists ) {
			$columns_to_add[] = 'ADD COLUMN cdn_hits int(11) NOT NULL DEFAULT 0';
		}

		if ( ! $cdn_misses_exists ) {
			$columns_to_add[] = 'ADD COLUMN cdn_misses int(11) NOT NULL DEFAULT 0';
		}

		// If both columns already exist, migration is complete.
		if ( empty( $columns_to_add ) ) {
			return true;
		}

		// Add the missing columns.
		$alter_sql = 'ALTER TABLE `' . $metrics_table . '` ' . implode( ', ', $columns_to_add );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- schema migration with safe table name
		$result = $wpdb->query( $alter_sql );

		if ( false === $result ) {
			error_log( sprintf( 'JTZL SW: Failed to add CDN columns to metrics table. SQL: %s, Error: %s', $alter_sql, $wpdb->last_error ) );
			return false;
		}

		// Verify columns were added successfully using DESCRIBE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- schema migration verification with safe table name
		$final_describe_result = $wpdb->get_results( 'DESCRIBE `' . $metrics_table . '`' );
		$final_columns         = array();
		if ( $final_describe_result ) {
			foreach ( $final_describe_result as $column ) {
				$final_columns[] = $column->Field;
			}
		}

		$cdn_hits_final   = in_array( 'cdn_hits', $final_columns, true );
		$cdn_misses_final = in_array( 'cdn_misses', $final_columns, true );

		$success = $cdn_hits_final && $cdn_misses_final;

		if ( $success ) {
			error_log( 'JTZL SW: Successfully added CDN metrics columns to existing metrics table' );
		} else {
			error_log( 'JTZL SW: Failed to verify CDN columns were added to metrics table' );
		}

		return $success;
	}
}
