<?php
/**
 * Guard-contract tests for the settings page's connection actions.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Admin\SettingsPage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the capability + nonce guard contract of the Connect / Refresh /
 * Disconnect actions: a non-capable user dies before the nonce check and
 * before any option is read or written, and each action verifies exactly
 * its own nonce action name. The stubbed wp_die()/check_admin_referer()
 * throw instead of dying, so a stopped request never reaches the
 * handler's exit; the guard ordering itself is the contract under test.
 */
final class SettingsPageGuardsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Expect the capability check, answering as given.
	 *
	 * @param bool $capable Whether the current user has manage_options.
	 * @return void
	 */
	private function expect_capability_check( bool $capable ): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( $capable );
	}

	/**
	 * Make wp_die() throw, like the real one stops the request.
	 *
	 * @return void
	 */
	private function expect_wp_die(): void {
		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				static function (): void {
					throw new RuntimeException( 'died' );
				}
			);
	}

	/**
	 * Expect exactly one nonce check for the given action, then stop the
	 * request (a failed check_admin_referer() dies for real).
	 *
	 * @param string $action The expected nonce action name.
	 * @return void
	 */
	private function expect_nonce_check( string $action ): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( $action )
			->andReturnUsing(
				static function (): void {
					throw new RuntimeException( 'nonce-checked' );
				}
			);
	}

	/**
	 * Run a callable that must be stopped by a thrown guard, with no
	 * option read or written along the way.
	 *
	 * @param callable $handler The handler invocation.
	 * @param string   $marker  Expected exception message ('died' or 'nonce-checked').
	 * @return void
	 */
	private function assert_stopped_by( callable $handler, string $marker ): void {
		// The state/discovery/backend collaborators all live behind these.
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();

		try {
			$handler();
			$this->fail( 'The guard must stop the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $marker, $e->getMessage() );
		}
	}

	public function test_connect_dies_for_non_capable_user_before_nonce(): void {
		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_connect();
			},
			'died'
		);
	}

	public function test_connect_verifies_its_own_nonce(): void {
		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_connect' );

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_connect();
			},
			'nonce-checked'
		);
	}

	public function test_refresh_dies_for_non_capable_user_before_nonce(): void {
		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_refresh();
			},
			'died'
		);
	}

	public function test_refresh_verifies_its_own_nonce(): void {
		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_refresh' );

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_refresh();
			},
			'nonce-checked'
		);
	}

	public function test_disconnect_dies_for_non_capable_user_before_nonce(): void {
		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_disconnect();
			},
			'died'
		);
	}

	public function test_disconnect_verifies_its_own_nonce(): void {
		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_disconnect' );

		$page = new SettingsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_disconnect();
			},
			'nonce-checked'
		);
	}
}
