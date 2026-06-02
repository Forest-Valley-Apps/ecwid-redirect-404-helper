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
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Log\Schema;
use FV\WPEcwidRedirectHelper\Plugin;
use Mockery;
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

	public function test_register_wires_admin_settings_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		// Schema is current — the admin-side update check must not migrate.
		Functions\when( 'get_option' )->justReturn( Schema::DB_VERSION );

		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_post_fv_erh_connect' )->once();
		Actions\expectAdded( 'admin_post_fv_erh_refresh' )->once();
		Actions\expectAdded( 'admin_post_fv_erh_disconnect' )->once();
		Actions\expectAdded( 'template_redirect' )->never();

		Plugin::instance()->register();
	}

	public function test_register_wires_404_capture_on_front_end(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'admin_menu' )->never();
		Actions\expectAdded( 'admin_post_fv_erh_connect' )->never();
		Actions\expectAdded( 'admin_post_fv_erh_refresh' )->never();
		Actions\expectAdded( 'admin_post_fv_erh_disconnect' )->never();
		Actions\expectAdded( 'template_redirect' )->once();

		Plugin::instance()->register();
	}

	public function test_activate_migrates_the_schema(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )
			->once()
			->with( Schema::VERSION_OPTION, Schema::DB_VERSION, true );

		Plugin::activate();

		unset( $GLOBALS['wpdb'] );
	}
}
