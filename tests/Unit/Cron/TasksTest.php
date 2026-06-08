<?php
/**
 * Unit tests for the hourly cron tasks.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Cron;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Cron\Tasks;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the scheduling guards and the connection gating of the hourly run.
 */
final class TasksTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_scheduled_skips_when_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )->once()->with( Tasks::HOOK )->andReturn( time() + 60 );
		Functions\expect( 'wp_schedule_event' )->never();

		Tasks::ensure_scheduled();
	}

	public function test_ensure_scheduled_schedules_when_missing(): void {
		Functions\expect( 'wp_next_scheduled' )->once()->with( Tasks::HOOK )->andReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'hourly', Tasks::HOOK );

		Tasks::ensure_scheduled();
	}

	public function test_run_without_connection_and_warm_collision_cache_does_nothing(): void {
		// Not connected: VerdictChecker::for_current_connection() must bail before
		// touching any HTTP, the store-id discovery yields nothing so the
		// app-status warm is skipped, and a warm collision cache skips the scan.
		// Returning each option's own default leaves store_id 0 / token '' (so the
		// discovery's string cast stays scalar) and the connection unconfigured.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( array() );

		Functions\expect( 'wp_remote_get' )->never();

		( new Tasks() )->run();

		// Reaching this point without an HTTP call is the assertion; keep
		// PHPUnit from flagging the test as risky.
		$this->assertTrue( true );
	}
}
