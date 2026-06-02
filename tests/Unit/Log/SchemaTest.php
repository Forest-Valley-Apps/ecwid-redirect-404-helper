<?php
/**
 * Unit tests for the database schema migrations.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Log;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Log\Schema;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;

/**
 * Covers the dbDelta migration and the version-gated re-run guard.
 */
final class SchemaTest extends WpdbTestCase {

	public function test_log_table_is_prefixed(): void {
		$this->assertSame( 'wp_fv_erh_404_log', Schema::log_table() );
	}

	public function test_redirects_table_is_prefixed(): void {
		$this->assertSame( 'wp_fv_erh_redirects', Schema::redirects_table() );
	}

	public function test_redirects_table_sql_defines_required_columns_and_keys(): void {
		$sql = Schema::redirects_table_sql( 'wp_fv_erh_redirects', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_redirects', $sql );
		foreach ( array( 'source_hash', 'source', 'destination', 'is_wildcard', 'active', 'hit_count', 'last_hit', 'created_at' ) as $column ) {
			$this->assertStringContainsString( $column, $sql );
		}
		$this->assertStringContainsString( 'UNIQUE KEY source_hash (source_hash)', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
	}

	public function test_log_table_sql_defines_required_columns_and_keys(): void {
		$sql = Schema::log_table_sql( 'wp_fv_erh_404_log', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_404_log', $sql );
		foreach ( array( 'url_hash', 'url_path', 'referrer', 'classification', 'entity_id', 'status', 'verdict', 'verdict_checked_at', 'hit_count', 'first_seen', 'last_seen' ) as $column ) {
			$this->assertStringContainsString( $column, $sql );
		}
		$this->assertStringContainsString( 'UNIQUE KEY url_hash (url_hash)', $sql );
		$this->assertStringContainsString( 'KEY last_seen (last_seen)', $sql );
		$this->assertStringContainsString( 'KEY verdict (verdict)', $sql );
		$this->assertStringContainsString( 'KEY entity (classification,entity_id)', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
	}

	public function test_migrate_runs_dbdelta_for_both_tables_and_records_version(): void {
		$created = array();

		Functions\expect( 'dbDelta' )
			->twice()
			->andReturnUsing(
				static function ( $sql ) use ( &$created ) {
					$created[] = $sql;

					return array();
				}
			);

		// The version option is autoloaded on purpose: the maybe_migrate() check
		// must not cost an extra query on admin requests.
		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Schema::migrate();

		$all_sql = implode( "\n", $created );
		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_404_log', $all_sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_redirects', $all_sql );
	}

	public function test_maybe_migrate_skips_when_schema_is_current(): void {
		Functions\when( 'get_option' )->justReturn( Schema::DB_VERSION );

		Functions\expect( 'dbDelta' )->never();
		Functions\expect( 'update_option' )->never();

		Schema::maybe_migrate();
	}

	public function test_maybe_migrate_migrates_when_version_is_stale(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		Functions\expect( 'dbDelta' )->twice();
		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Schema::maybe_migrate();
	}
}
