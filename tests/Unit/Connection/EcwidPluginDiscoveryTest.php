<?php
/**
 * Unit tests for the Ecwid plugin discovery class.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Connection;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Connection\EcwidPluginDiscovery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the discovery status matrix and option reading.
 */
final class EcwidPluginDiscoveryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_plugin_missing_when_plugin_inactive(): void {
		$discovery = new EcwidPluginDiscovery( false, 130416012, 'token' );

		$this->assertSame( EcwidPluginDiscovery::STATUS_PLUGIN_MISSING, $discovery->status() );
		$this->assertFalse( $discovery->is_ready() );
	}

	public function test_status_not_configured_when_no_store_id(): void {
		$discovery = new EcwidPluginDiscovery( true, 0, '' );

		$this->assertSame( EcwidPluginDiscovery::STATUS_NOT_CONFIGURED, $discovery->status() );
		$this->assertFalse( $discovery->has_store_id() );
		$this->assertFalse( $discovery->is_ready() );
	}

	public function test_status_no_token_when_store_id_but_no_token(): void {
		$discovery = new EcwidPluginDiscovery( true, 130416012, '' );

		$this->assertSame( EcwidPluginDiscovery::STATUS_NO_TOKEN, $discovery->status() );
		$this->assertTrue( $discovery->has_store_id() );
		$this->assertFalse( $discovery->has_public_token() );
		$this->assertFalse( $discovery->is_ready() );
	}

	public function test_status_ready_with_store_id_and_token(): void {
		$discovery = new EcwidPluginDiscovery( true, 130416012, 'public_token' );

		$this->assertSame( EcwidPluginDiscovery::STATUS_READY, $discovery->status() );
		$this->assertTrue( $discovery->is_ready() );
		$this->assertSame( 130416012, $discovery->store_id() );
		$this->assertSame( 'public_token', $discovery->public_token() );
	}

	public function test_discover_reads_the_ecwid_plugin_options(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				$values = array(
					EcwidPluginDiscovery::STORE_ID_OPTION     => '130416012',
					EcwidPluginDiscovery::PUBLIC_TOKEN_OPTION => 'public_AbC',
				);

				return $values[ $name ] ?? $default;
			}
		);

		$discovery = EcwidPluginDiscovery::discover();

		$this->assertSame( 130416012, $discovery->store_id() );
		$this->assertSame( 'public_AbC', $discovery->public_token() );
	}

	public function test_discover_defaults_to_zero_and_empty_when_unconfigured(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return $default;
			}
		);

		$discovery = EcwidPluginDiscovery::discover();

		$this->assertSame( 0, $discovery->store_id() );
		$this->assertSame( '', $discovery->public_token() );
	}
}
