<?php
/**
 * Database schema for the 404 log.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's custom table(s): definition, creation, and versioned
 * migration.
 *
 * The schema version is stored in an autoloaded option so the cheap "is the
 * schema current?" check costs no extra query. {@see self::migrate()} runs on
 * activation; {@see self::maybe_migrate()} runs on admin requests to cover
 * plugin *updates*, which do not fire the activation hook. `dbDelta()` is
 * idempotent, so running it again on an up-to-date schema is harmless.
 *
 * Nothing here ever runs on a front-end request — capture only writes rows.
 */
final class Schema {

	/**
	 * Current schema version. Bump on any table change.
	 *
	 * v1: 404 log table (S4). v2: + manual redirects table (S5).
	 *
	 * @var string
	 */
	public const DB_VERSION = '2';

	/**
	 * Option name holding the installed schema version (autoloaded).
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'fv_erh_db_version';

	/**
	 * Unprefixed name of the 404 log table.
	 *
	 * @var string
	 */
	private const LOG_TABLE_BASE = 'fv_erh_404_log';

	/**
	 * Unprefixed name of the manual redirects table.
	 *
	 * @var string
	 */
	private const REDIRECTS_TABLE_BASE = 'fv_erh_redirects';

	/**
	 * Fully-prefixed name of the 404 log table.
	 *
	 * @return string
	 */
	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::LOG_TABLE_BASE;
	}

	/**
	 * Fully-prefixed name of the manual redirects table.
	 *
	 * @return string
	 */
	public static function redirects_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::REDIRECTS_TABLE_BASE;
	}

	/**
	 * Run the migration unless the installed schema is already current.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( self::DB_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Create or update the plugin tables and record the schema version.
	 *
	 * @return void
	 */
	public static function migrate(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( self::log_table_sql( self::log_table(), $charset_collate ) );
		dbDelta( self::redirects_table_sql( self::redirects_table(), $charset_collate ) );

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * The CREATE TABLE statement for the 404 log, in dbDelta-compatible form.
	 *
	 * `url_hash` is the md5 of the normalized path and carries the UNIQUE key —
	 * the path itself can exceed every indexable length. `last_seen` is indexed
	 * for retention pruning, `classification` for the dashboard filters (S5).
	 *
	 * @param string $table           Fully-prefixed table name.
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	public static function log_table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url_path text NOT NULL,
			referrer text NOT NULL,
			classification varchar(20) NOT NULL DEFAULT 'wp-page',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'new',
			hit_count bigint(20) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen),
			KEY classification (classification)
		) {$charset_collate};";
	}

	/**
	 * The CREATE TABLE statement for manual redirects, in dbDelta-compatible form.
	 *
	 * `source_hash` is the md5 of the normalized source pattern and carries the
	 * UNIQUE key (same reasoning as `url_hash` on the log table: the pattern
	 * itself can exceed every indexable length). `is_wildcard` is derived from
	 * the source at save time so the matcher can split exact/wildcard rules
	 * without re-parsing every pattern on every 404.
	 *
	 * @param string $table           Fully-prefixed table name.
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	public static function redirects_table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash char(32) NOT NULL,
			source text NOT NULL,
			destination text NOT NULL,
			is_wildcard tinyint(1) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY active (active)
		) {$charset_collate};";
	}
}
