<?php
/**
 * Unit tests for the persisted connection state.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Connection;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Connection\ConnectionState;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers connect/disconnect persistence against an in-memory option store.
 */
final class ConnectionStateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WordPress options table.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_fresh_state_is_disconnected(): void {
		$state = new ConnectionState();

		$this->assertFalse( $state->is_connected() );
		$this->assertSame( 0, $state->store_id() );
		$this->assertSame( 0, $state->verified_at() );
	}

	public function test_mark_connected_records_store_and_timestamp(): void {
		$state = new ConnectionState();

		$state->mark_connected( 130416012, 1700000000 );

		$this->assertTrue( $state->is_connected() );
		$this->assertSame( 130416012, $state->store_id() );
		$this->assertSame( 1700000000, $state->verified_at() );
	}

	public function test_mark_disconnected_keeps_store_id_but_flips_flag(): void {
		$state = new ConnectionState();
		$state->mark_connected( 130416012, 1700000000 );

		$state->mark_disconnected();

		$this->assertFalse( $state->is_connected() );
		$this->assertSame( 130416012, $state->store_id() );
	}

	public function test_delete_clears_all_state(): void {
		$state = new ConnectionState();
		$state->mark_connected( 130416012, 1700000000 );

		$state->delete();

		$this->assertFalse( $state->is_connected() );
		$this->assertSame( 0, $state->store_id() );
	}
}
