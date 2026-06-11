<?php
/**
 * Guard-contract tests for the 404 log page's mutation handlers.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Admin\LogPage;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use RuntimeException;

/**
 * Pins the capability + nonce guard contract of every LogPage mutation:
 * a non-capable user dies before the nonce check and before any SQL, and
 * each action verifies exactly its own nonce action name. The stubbed
 * wp_die()/check_admin_referer() throw instead of dying so a stopped
 * request never reaches the handler's exit — which also means a passing
 * guard is deliberately NOT followed through to the mutation here; the
 * guard ordering is the whole contract under test.
 */
final class LogPageGuardsTest extends WpdbTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_GET     = array();
		$_POST    = array();
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
	 * Run a callable that must be stopped by a thrown guard.
	 *
	 * @param callable $handler The handler invocation.
	 * @param string   $marker  Expected exception message ('died' or 'nonce-checked').
	 * @return void
	 */
	private function assert_stopped_by( callable $handler, string $marker ): void {
		try {
			$handler();
			$this->fail( 'The guard must stop the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $marker, $e->getMessage() );
		}

		$this->assertSame( array(), $this->prepared, 'no SQL may run before the guard passes' );
	}

	public function test_handle_actions_ignores_unknown_actions(): void {
		$_REQUEST['action'] = 'made_up_action';

		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'check_admin_referer' )->never();

		( new LogPage() )->handle_actions();

		$this->assertSame( array(), $this->prepared, 'routing alone must not touch the database' );
	}

	public function test_non_capable_user_dies_before_nonce_and_sql(): void {
		$_REQUEST['action'] = 'delete';
		$_REQUEST['id']     = '5';

		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'died'
		);
	}

	public function test_single_delete_verifies_the_per_row_nonce(): void {
		$_REQUEST['action'] = 'delete';
		$_REQUEST['id']     = '5';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_log_delete_5' );

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'nonce-checked'
		);
	}

	public function test_bulk_delete_verifies_the_list_table_bulk_nonce(): void {
		$_REQUEST['action'] = 'delete';
		$_REQUEST['ids']    = array( '5', '6' );

		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'bulk-fv-erh-404s' );

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'nonce-checked'
		);
	}

	public function test_check_catalog_verifies_its_own_nonce(): void {
		$_REQUEST['action'] = 'check_catalog';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_check_catalog' );

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'nonce-checked'
		);
	}

	public function test_rescan_collisions_verifies_its_own_nonce(): void {
		$_REQUEST['action'] = 'rescan_collisions';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'fv_erh_rescan_collisions' );

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'nonce-checked'
		);
	}

	public function test_export_dies_for_non_capable_user_before_nonce_and_sql(): void {
		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_export();
			},
			'died'
		);
	}

	public function test_export_verifies_its_own_nonce_before_streaming(): void {
		$this->expect_capability_check( true );
		$this->expect_nonce_check( LogPage::ACTION_EXPORT );

		$page = new LogPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_export();
			},
			'nonce-checked'
		);
	}
}
