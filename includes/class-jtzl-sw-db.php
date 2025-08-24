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
	 * Current database schema version.
	 *
	 * @since 0.5.0
	 */
	const DB_VERSION = 2;
	/**
	 * Run database migrations if needed.
	 *
	 * This method checks the current database version and runs any necessary migrations
	 * to bring the schema up to the current version.
	 *
	 * @since 0.5.0
	 *
	 * @return bool True if migrations completed successfully, false otherwise.
	 */
	public static function maybe_migrate() {
		$current_db_version = get_option( 'jtzl_sw_db_version', 0 );

		if ( $current_db_version < self::DB_VERSION ) {
			// Run migrations in sequence.
			for ( $version = $current_db_version + 1; $version <= self::DB_VERSION; $version++ ) {
				$migration_method = 'migrate_to_version_' . $version;
				if ( method_exists( __CLASS__, $migration_method ) ) {
					$result = call_user_func( array( __CLASS__, $migration_method ) );
					if ( ! $result ) {
						return false;
					}
				}
			}

			// Update database version.
			update_option( 'jtzl_sw_db_version', self::DB_VERSION );
		}

		return true;
	}

	/**
	 * Migration to version 1 - Create initial tables.
	 *
	 * @since 0.5.0
	 *
	 * @return bool True if migration successful, false otherwise.
	 */
	private static function migrate_to_version_1() {
		return self::create_initial_tables();
	}

	/**
	 * Migration to version 2 - Add hit_count column and constraints.
	 *
	 * @since 0.5.0
	 *
	 * @return bool True if migration successful, false otherwise.
	 */
	private static function migrate_to_version_2() {
		global $wpdb;
		$assets_table = $wpdb->prefix . 'jtzl_sw_cached_assets';

		// Check if hit_count column already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema inspection during migration is safe and uncached
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW COLUMNS FROM `{$assets_table}` LIKE %s",
				'hit_count'
			)
		);

		if ( empty( $column_exists ) ) {
			// Add hit_count column with default value 0.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( "ALTER TABLE `{$assets_table}` ADD COLUMN `hit_count` int(11) NOT NULL DEFAULT 0" );
			if ( false === $result ) {
				return false;
			}
		}

		// Add index on hit_count column for efficient sorting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema inspection
		$index_exists = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW INDEX FROM `{$assets_table}` WHERE Key_name = %s",
				'hit_count_idx'
			)
		);

		if ( empty( $index_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( "ALTER TABLE `{$assets_table}` ADD INDEX `hit_count_idx` (`hit_count`)" );
			if ( false === $result ) {
				return false;
			}
		}

		// Ensure unique constraint on asset_url exists (it should from initial creation).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema inspection
		$unique_exists = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW INDEX FROM `{$assets_table}` WHERE Key_name = %s AND Non_unique = 0",
				'asset_url_key'
			)
		);

		if ( empty( $unique_exists ) ) {
			// Drop existing non-unique index if it exists.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE `{$assets_table}` DROP INDEX IF EXISTS `asset_url_key`" );

			// Add unique constraint.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( "ALTER TABLE `{$assets_table}` ADD UNIQUE KEY `asset_url_key` (`asset_url`(191))" );
			if ( false === $result ) {
				return false;
			}
		}

		return true;
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
	private static function create_initial_tables() {
		global $wpdb;
		$metrics_table   = $wpdb->prefix . 'jtzl_sw_cache_metrics';
		$assets_table    = $wpdb->prefix . 'jtzl_sw_cached_assets';
		$charset_collate = $wpdb->get_charset_collate();

		$sql_metrics = "CREATE TABLE $metrics_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_date date NOT NULL,
			hits int(11) NOT NULL DEFAULT 0,
			misses int(11) NOT NULL DEFAULT 0,
			bytes_saved bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY metric_date (metric_date)
		) $charset_collate;";

		$sql_assets = "CREATE TABLE $assets_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			asset_url varchar(2048) NOT NULL,
			asset_type varchar(20) NOT NULL,
			asset_size int(11) NOT NULL,
			last_accessed datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY asset_url_key (asset_url(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result_metrics = dbDelta( $sql_metrics );
		$result_assets  = dbDelta( $sql_assets );

		// Verify tables were created.
		$metrics_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $metrics_table ) );
		$assets_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $assets_table ) );

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
	 * @return bool True if both tables exist, false otherwise.
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

		$assets_table = $wpdb->prefix . 'jtzl_sw_cached_assets';

		// Sanitize inputs.
		$asset_url     = esc_url_raw( $asset_url );
		$asset_type    = sanitize_text_field( $asset_type );
		$asset_size    = absint( $asset_size );
		$last_accessed = sanitize_text_field( $last_accessed );
		$hit_count     = absint( $hit_count );

		if ( empty( $asset_url ) ) {
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

		$assets_table = $wpdb->prefix . 'jtzl_sw_cached_assets';
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
	 * Get database schema version for debugging.
	 *
	 * @since 0.5.0
	 *
	 * @return int Current database schema version.
	 */
	public static function get_db_version() {
		return get_option( 'jtzl_sw_db_version', 0 );
	}
}
