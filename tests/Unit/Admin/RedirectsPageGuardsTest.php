<?php
/**
 * Guard-contract tests for the redirects page's mutation handlers.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Admin\RedirectsPage;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use RuntimeException;

/**
 * Pins the capability + nonce guard contract of every RedirectsPage
 * mutation: a non-capable user dies before the nonce check and before any
 * SQL, and each action verifies exactly its own nonce action name — the
 * per-row toggle/delete nonces cover the row id. The stubbed
 * wp_die()/check_admin_referer() throw instead of dying, so a stopped
 * request never reaches the handler's exit; the guard ordering itself is
 * the contract under test, not the mutation behind it.
 */
final class RedirectsPageGuardsTest extends WpdbTestCase {

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

	public function test_add_dies_for_non_capable_user_before_nonce_and_sql(): void {
		$_POST['fv_source']      = '/old';
		$_POST['fv_destination'] = '/new';

		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_add();
			},
			'died'
		);
	}

	public function test_add_verifies_its_own_nonce_before_reading_input(): void {
		$_POST['fv_source']      = '/old';
		$_POST['fv_destination'] = '/new';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( RedirectsPage::ACTION_ADD );

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_add();
			},
			'nonce-checked'
		);
	}

	public function test_toggle_dies_for_non_capable_user_before_nonce_and_sql(): void {
		$_GET['id']    = '7';
		$_GET['state'] = '1';

		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_toggle();
			},
			'died'
		);
	}

	public function test_toggle_verifies_the_per_row_nonce_covering_the_id(): void {
		$_GET['id']    = '7';
		$_GET['state'] = '1';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( RedirectsPage::ACTION_TOGGLE . '_7' );

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_toggle();
			},
			'nonce-checked'
		);
	}

	public function test_delete_dies_for_non_capable_user_before_nonce_and_sql(): void {
		$_GET['id'] = '7';

		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_delete();
			},
			'died'
		);
	}

	public function test_delete_verifies_the_per_row_nonce_covering_the_id(): void {
		$_GET['id'] = '7';

		$this->expect_capability_check( true );
		$this->expect_nonce_check( RedirectsPage::ACTION_DELETE . '_7' );

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_delete();
			},
			'nonce-checked'
		);
	}

	public function test_bulk_delete_routing_ignores_other_actions(): void {
		$_REQUEST['action'] = 'made_up_action';
		$_REQUEST['ids']    = array( '3' );

		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'check_admin_referer' )->never();

		( new RedirectsPage() )->handle_actions();

		$this->assertSame( array(), $this->prepared, 'routing alone must not touch the database' );
	}

	public function test_bulk_delete_dies_for_non_capable_user_before_nonce_and_sql(): void {
		$_REQUEST['action'] = 'delete';
		$_REQUEST['ids']    = array( '3', '4' );

		$this->expect_capability_check( false );
		$this->expect_wp_die();
		Functions\expect( 'check_admin_referer' )->never();

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'died'
		);
	}

	public function test_bulk_delete_verifies_the_list_table_bulk_nonce(): void {
		$_REQUEST['action'] = 'delete';
		$_REQUEST['ids']    = array( '3', '4' );

		$this->expect_capability_check( true );
		$this->expect_nonce_check( 'bulk-fv-erh-redirects' );

		$page = new RedirectsPage();
		$this->assert_stopped_by(
			static function () use ( $page ): void {
				$page->handle_actions();
			},
			'nonce-checked'
		);
	}
}
