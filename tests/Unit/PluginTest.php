<?php
/**
 * Unit tests for the Plugin bootstrap class.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use FV\WPEcwidRedirectHelper\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Proves the test harness runs and the scaffold wires up correctly.
 */
final class PluginTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_instance_returns_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_version_constant_matches_header(): void {
		$this->assertSame( '0.1.0', Plugin::VERSION );
	}

	public function test_register_adds_init_hook(): void {
		Actions\expectAdded( 'init' )->once();

		Plugin::instance()->register();
	}
}
