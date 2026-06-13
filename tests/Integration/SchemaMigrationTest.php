<?php
/**
 * Integration test for the live, row-preserving schema migration.
 *
 * Unlike the Brain Monkey unit suite (which mocks dbDelta and can only prove
 * the plugin feeds it the full v3 CREATE TABLE statements), this test runs the
 * migration against a real WordPress + MySQL so it exercises dbDelta's actual
 * in-place ALTER: a seeded schema-v1 table must gain the v3 verdict columns and
 * keys *without losing its existing rows*. This is the contract a unit test
 * structurally cannot cover.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Integration;

use FV\WPEcwidRedirectHelper\Log\Schema;
use WP_UnitTestCase;

/**
 * Drives Schema::migrate() / maybe_migrate() against the test database.
 *
 * WP_UnitTestCase wraps each test in a transaction it rolls back, but DDL
 * (CREATE / ALTER / DROP) implicitly commits in MySQL and escapes that
 * rollback — so the plugin tables are dropped explicitly in set_up/tear_down
 * to keep every test hermetic.
 */
final class SchemaMigrationTest extends WP_UnitTestCase {

	/**
	 * Fully-prefixed 404 log table name.
	 *
	 * @var string
	 */
	private $log_table;

	/**
	 * Fully-prefixed manual redirects table name.
	 *
	 * @var string
	 */
	private $redirects_table;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->log_table       = $wpdb->prefix . 'fv_erh_404_log';
		$this->redirects_table = $wpdb->prefix . 'fv_erh_redirects';

		$this->drop_plugin_tables();
		delete_option( Schema::VERSION_OPTION );
	}

	public function tear_down(): void {
		$this->drop_plugin_tables();
		delete_option( Schema::VERSION_OPTION );

		parent::tear_down();
	}

	/**
	 * Drop both plugin tables if present (DDL escapes the test transaction).
	 *
	 * @return void
	 */
	private function drop_plugin_tables(): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$this->log_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->redirects_table}" );
	}

	/**
	 * Column names of a table.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return array<int,string>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		return $wpdb->get_col( "DESCRIBE {$table}", 0 );
	}

	/**
	 * Distinct index (key) names on a table.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return array<int,string>
	 */
	private function index_names( string $table ): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$keys = array_map(
			static function ( $row ) {
				return $row['Key_name'];
			},
			$rows
		);

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * Probes with DESCRIBE rather than SHOW TABLES: WP_UnitTestCase rewrites
	 * CREATE TABLE into CREATE TEMPORARY TABLE for transaction isolation, and
	 * temporary tables are invisible to SHOW TABLES — but DESCRIBE sees them.
	 * A missing table makes DESCRIBE error, so a suppressed empty result means
	 * "absent".
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		$columns    = $wpdb->get_results( "DESCRIBE {$table}" );
		$wpdb->suppress_errors( $suppressed );

		return ! empty( $columns );
	}

	/**
	 * Create a genuine schema-v1 log table: no verdict columns/keys, and no
	 * redirects table (that arrived in v2). Records the installed version as 1.
	 *
	 * @return void
	 */
	private function seed_v1_log_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE {$this->log_table} (
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
			) {$charset_collate};"
		);

		update_option( Schema::VERSION_OPTION, '1' );
	}

	public function test_seeded_v1_log_table_migrates_to_v3_in_place_preserving_rows(): void {
		global $wpdb;

		$this->seed_v1_log_table();

		$wpdb->insert(
			$this->log_table,
			array(
				'url_hash'       => md5( '/alpha' ),
				'url_path'       => '/alpha',
				'referrer'       => '',
				'classification' => 'product',
				'entity_id'      => 111,
				'status'         => 'new',
				'hit_count'      => 3,
				'first_seen'     => '2026-01-01 00:00:00',
				'last_seen'      => '2026-01-02 00:00:00',
			)
		);
		$wpdb->insert(
			$this->log_table,
			array(
				'url_hash'       => md5( '/beta' ),
				'url_path'       => '/beta',
				'referrer'       => 'https://ref.example',
				'classification' => 'wp-page',
				'entity_id'      => 0,
				'status'         => 'ignored',
				'hit_count'      => 9,
				'first_seen'     => '2026-02-01 00:00:00',
				'last_seen'      => '2026-02-02 00:00:00',
			)
		);

		// Preconditions: v1 shape — no verdict column, no redirects table, version 1.
		$this->assertNotContains( 'verdict', $this->columns( $this->log_table ) );
		$this->assertFalse( $this->table_exists( $this->redirects_table ) );
		$this->assertSame( '1', get_option( Schema::VERSION_OPTION ) );

		// Run the real migration against the live, seeded table.
		Schema::migrate();

		// New schema version recorded.
		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );

		// v3 verdict columns added in place.
		$cols = $this->columns( $this->log_table );
		$this->assertContains( 'verdict', $cols );
		$this->assertContains( 'verdict_checked_at', $cols );

		// v3 keys added in place.
		$keys = $this->index_names( $this->log_table );
		$this->assertContains( 'verdict', $keys );
		$this->assertContains( 'entity', $keys );

		// The manual redirects table (introduced in v2) now exists.
		$this->assertTrue( $this->table_exists( $this->redirects_table ) );

		// Existing rows survived, in order, with the new column defaulted to ''.
		$rows = $wpdb->get_results(
			"SELECT url_path, hit_count, status, verdict FROM {$this->log_table} ORDER BY id ASC",
			ARRAY_A
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( '/alpha', $rows[0]['url_path'] );
		$this->assertSame( '3', $rows[0]['hit_count'] );
		$this->assertSame( 'new', $rows[0]['status'] );
		$this->assertSame( '', $rows[0]['verdict'] );
		$this->assertSame( '/beta', $rows[1]['url_path'] );
		$this->assertSame( '9', $rows[1]['hit_count'] );
		$this->assertSame( 'ignored', $rows[1]['status'] );
		$this->assertSame( '', $rows[1]['verdict'] );
	}

	public function test_fresh_install_creates_both_tables_in_v3_shape(): void {
		// set_up dropped any tables and cleared the version option.
		$this->assertFalse( $this->table_exists( $this->log_table ) );
		$this->assertFalse( $this->table_exists( $this->redirects_table ) );

		Schema::migrate();

		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( $this->table_exists( $this->log_table ) );
		$this->assertTrue( $this->table_exists( $this->redirects_table ) );
		$this->assertContains( 'verdict', $this->columns( $this->log_table ) );
		$this->assertContains( 'is_wildcard', $this->columns( $this->redirects_table ) );
	}

	public function test_maybe_migrate_is_idempotent_once_current(): void {
		Schema::migrate();
		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );

		// Already current: a second pass must not error or change anything.
		Schema::maybe_migrate();

		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );
		$this->assertContains( 'verdict', $this->columns( $this->log_table ) );
	}
}
