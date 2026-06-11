<?php
/**
 * Smoke tests for the install lifecycle: fresh activation and the
 * version-gated update migration.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Cron\Tasks;
use FV\WPEcwidRedirectHelper\Log\Schema;
use FV\WPEcwidRedirectHelper\Plugin;
use Mockery;

/**
 * Pins what a fresh activation and a plugin *update* (no activation hook)
 * leave behind: both tables in their schema-v3 shape, the recorded
 * `fv_erh_db_version`, and the scheduled hourly cron event.
 *
 * What a unit test cannot prove is dbDelta's row-preserving in-place
 * ALTER of a live, seeded v1 table — that diff happens inside WordPress
 * against a real database. The contract pinned here is the one this
 * plugin owns: the migration always feeds dbDelta the full v3 CREATE
 * TABLE statements (from which dbDelta derives the missing columns/keys
 * without touching existing rows) and records the new version.
 */
final class InstallLifecycleTest extends WpdbTestCase {

	/**
	 * Capture every CREATE TABLE statement handed to dbDelta.
	 *
	 * @param array<int,string> $created Capture target.
	 * @return void
	 */
	private function capture_dbdelta( array &$created ): void {
		Functions\expect( 'dbDelta' )
			->twice()
			->andReturnUsing(
				static function ( $sql ) use ( &$created ) {
					$created[] = (string) $sql;

					return array();
				}
			);
	}

	/**
	 * Assert the captured statements describe both tables in v3 shape.
	 *
	 * @param array<int,string> $created Captured dbDelta statements.
	 * @return void
	 */
	private function assert_v3_schema( array $created ): void {
		$all_sql = implode( "\n", $created );

		// Both tables, fully prefixed.
		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_404_log', $all_sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_fv_erh_redirects', $all_sql );

		// The v3 verdict columns and keys (added in S6) are part of the shape.
		$this->assertStringContainsString( "verdict varchar(20) NOT NULL DEFAULT ''", $all_sql );
		$this->assertStringContainsString( 'verdict_checked_at datetime NULL DEFAULT NULL', $all_sql );
		$this->assertStringContainsString( 'KEY verdict (verdict)', $all_sql );
		$this->assertStringContainsString( 'KEY entity (classification,entity_id)', $all_sql );
	}

	public function test_current_schema_version_is_pinned_at_3(): void {
		// uninstall.php and the migration tests below all assume the v3 option
		// value; a bump must consciously revisit them.
		$this->assertSame( '3', Schema::DB_VERSION );
		$this->assertSame( 'fv_erh_db_version', Schema::VERSION_OPTION );
	}

	public function test_fresh_activation_creates_v3_tables_records_version_and_schedules_cron(): void {
		$created = array();
		$this->capture_dbdelta( $created );

		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Functions\expect( 'wp_next_scheduled' )->once()->with( Tasks::HOOK )->andReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( Mockery::type( 'int' ), 'hourly', Tasks::HOOK );

		Plugin::activate();

		$this->assert_v3_schema( $created );
	}

	public function test_stale_v1_install_is_migrated_to_v3_on_update(): void {
		// A plugin *update* does not fire the activation hook; the admin-side
		// maybe_migrate() sees the stale recorded version and must run the
		// same full migration a fresh activation gets.
		Functions\when( 'get_option' )->justReturn( '1' );

		$created = array();
		$this->capture_dbdelta( $created );

		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Schema::maybe_migrate();

		$this->assert_v3_schema( $created );
	}
}
