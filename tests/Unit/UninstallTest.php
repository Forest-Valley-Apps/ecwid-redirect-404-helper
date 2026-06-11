<?php
/**
 * Smoke tests for the uninstall teardown (uninstall.php).
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Executes the real uninstall.php against the mocked WordPress boundary and
 * pins the complete teardown: both tables dropped, all four options deleted,
 * the transient cleanup catching both the value and the `_transient_timeout_`
 * rows, the cron hooks cleared — and on multisite, all of it once per site
 * under that site's own table prefix.
 */
final class UninstallTest extends WpdbTestCase {

	/**
	 * Direct (non-prepared) queries captured from the teardown.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->queries       = array();
		$this->wpdb->options = 'wp_options';
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) {
					$this->queries[] = (string) $sql;

					return 1;
				}
			);
	}

	/**
	 * Execute the uninstall routine exactly once for this test.
	 *
	 * uninstall.php guards on WP_UNINSTALL_PLUGIN and calls fv_erh_uninstall()
	 * at file scope, so the first require executes the teardown. Its functions
	 * cannot be declared twice in one process, so once they exist later tests
	 * invoke the entry point directly — either way the teardown runs once per
	 * call, against whatever stubs the test installed.
	 *
	 * @return void
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ecwid-redirect-404-helper/ecwid-redirect-404-helper.php' );
		}

		if ( \function_exists( 'fv_erh_uninstall' ) ) {
			\fv_erh_uninstall();

			return;
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Assert one site's full teardown is present in the captured SQL.
	 *
	 * @param string $prefix  The site's table prefix (e.g. 'wp_').
	 * @param string $options The site's options table name.
	 * @return void
	 */
	private function assert_site_teardown_sql( string $prefix, string $options ): void {
		$all_sql = implode( "\n", $this->queries );

		$this->assertStringContainsString( "DROP TABLE IF EXISTS `{$prefix}fv_erh_404_log`", $all_sql );
		$this->assertStringContainsString( "DROP TABLE IF EXISTS `{$prefix}fv_erh_redirects`", $all_sql );

		// The transient sweep covers every fv_erh_ transient — value rows AND
		// their `_transient_timeout_` counterparts — in this site's options table.
		$this->assertStringContainsString( "DELETE FROM `{$options}`", $all_sql );
		$this->assertStringContainsString( "LIKE '\_transient\_fv\_erh\_%'", $all_sql );
		$this->assertStringContainsString( "LIKE '\_transient\_timeout\_fv\_erh\_%'", $all_sql );
	}

	public function test_single_site_uninstall_removes_every_persistent_artifact(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$deleted_options = array();
		Functions\expect( 'delete_option' )
			->times( 4 )
			->andReturnUsing(
				static function ( $option ) use ( &$deleted_options ) {
					$deleted_options[] = $option;

					return true;
				}
			);

		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'fv_erh_hourly_tasks' );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'fv_erh_collision_scan' );

		$this->run_uninstall();

		$this->assert_site_teardown_sql( 'wp_', 'wp_options' );

		// Every option the plugin ever writes, nothing else.
		$this->assertSame(
			array(
				'fv_erh_connection',
				'fv_erh_cta_dismissed',
				'fv_erh_collision_dismissed',
				'fv_erh_db_version',
			),
			$deleted_options
		);
	}

	public function test_multisite_uninstall_loops_every_site_with_its_own_prefix(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		// 'number' => 0 lifts get_sites()'s default 100-site cap.
		Functions\expect( 'get_sites' )
			->once()
			->with(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
			->andReturn( array( 1, 2 ) );

		$wpdb = $this->wpdb;
		Functions\expect( 'switch_to_blog' )
			->twice()
			->andReturnUsing(
				static function ( $site_id ) use ( $wpdb ) {
					// Mirror WordPress: switching sites repoints the prefix
					// (the main site keeps the base prefix).
					$wpdb->prefix  = 1 === (int) $site_id ? 'wp_' : 'wp_' . (int) $site_id . '_';
					$wpdb->options = $wpdb->prefix . 'options';

					return true;
				}
			);
		Functions\expect( 'restore_current_blog' )->twice()->andReturn( true );

		// The per-site teardown runs in full on each site.
		Functions\expect( 'delete_option' )->times( 8 )->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->twice()
			->with( 'fv_erh_hourly_tasks' );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->twice()
			->with( 'fv_erh_collision_scan' );

		$this->run_uninstall();

		$this->assert_site_teardown_sql( 'wp_', 'wp_options' );
		$this->assert_site_teardown_sql( 'wp_2_', 'wp_2_options' );
	}
}
