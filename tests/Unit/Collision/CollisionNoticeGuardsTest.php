<?php
/**
 * Guard-contract tests for the collision notice's dismiss handler.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Collision;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Collision\CollisionNotice;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the capability + nonce guard contract of the dismiss action: a
 * non-capable user dies before the nonce check, and the nonce is verified
 * before the scanner's cache is even read — let alone the dismissal
 * persisted. The stubbed wp_die()/check_admin_referer() throw instead of
 * dying, so a stopped request never reaches the handler's exit; the guard
 * ordering itself is the contract under test.
 */
final class CollisionNoticeGuardsTest extends TestCase {

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
	 * Run the dismiss handler expecting it to be stopped by a thrown guard,
	 * with the scanner (transients/options) never touched.
	 *
	 * @param string $marker Expected exception message ('died' or 'nonce-checked').
	 * @return void
	 */
	private function assert_dismiss_stopped_by( string $marker ): void {
		// The scanner reads its cache via get_transient and persists a
		// dismissal via update_option; neither may happen before the guard.
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'update_option' )->never();

		try {
			( new CollisionNotice() )->handle_dismiss();
			$this->fail( 'The guard must stop the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $marker, $e->getMessage() );
		}
	}

	public function test_dismiss_dies_for_non_capable_user_before_nonce(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( false );
		Functions\expect( 'wp_die' )
			->once()
			->andReturnUsing(
				static function (): void {
					throw new RuntimeException( 'died' );
				}
			);
		Functions\expect( 'check_admin_referer' )->never();

		$this->assert_dismiss_stopped_by( 'died' );
	}

	public function test_dismiss_verifies_its_own_nonce_before_touching_the_scanner(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( CollisionNotice::ACTION_DISMISS )
			->andReturnUsing(
				static function (): void {
					throw new RuntimeException( 'nonce-checked' );
				}
			);

		$this->assert_dismiss_stopped_by( 'nonce-checked' );
	}
}
