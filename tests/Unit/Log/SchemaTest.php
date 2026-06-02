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
use Mockery;

/**
 * Covers the dbDelta migration and the version-gated re-run guard.
 */
final class SchemaTest extends WpdbTestCase {

	public function test_log_table_is_prefixed(): void {
		$this->assertSame( 'wp_fv_erh_404_log', Schema::log_table() );
	}

	public function test_log_table_sql_defines_required_columns_and_keys(): void {
		$sql = Schema::log_table_sql( 'wp_fv_erh_404_log', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_404_log', $sql );
		foreach ( array( 'url_hash', 'url_path', 'referrer', 'classification', 'entity_id', 'status', 'hit_count', 'first_seen', 'last_seen' ) as $column ) {
			$this->assertStringContainsString( $column, $sql );
		}
		$this->assertStringContainsString( 'UNIQUE KEY url_hash (url_hash)', $sql );
		$this->assertStringContainsString( 'KEY last_seen (last_seen)', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
	}

	public function test_migrate_runs_dbdelta_and_records_version(): void {
		Functions\expect( 'dbDelta' )
			->once()
			->with(
				Mockery::on(
					static function ( $sql ): bool {
						return false !== strpos( $sql, 'CREATE TABLE wp_fv_erh_404_log' );
					}
				)
			);

		// The version option is autoloaded on purpose: the maybe_migrate() check
		// must not cost an extra query on admin requests.
		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Schema::migrate();
	}

	public function test_maybe_migrate_skips_when_schema_is_current(): void {
		Functions\when( 'get_option' )->justReturn( Schema::DB_VERSION );

		Functions\expect( 'dbDelta' )->never();
		Functions\expect( 'update_option' )->never();

		Schema::maybe_migrate();
	}

	public function test_maybe_migrate_migrates_when_version_is_stale(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Schema::maybe_migrate();
	}
}
