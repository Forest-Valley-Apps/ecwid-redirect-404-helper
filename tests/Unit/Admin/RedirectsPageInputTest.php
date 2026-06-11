<?php
/**
 * Unit tests for the redirects admin form's input decoding.
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
 * Pins that handle_add() percent-decodes the submitted source/destination the
 * same way RequestPath decodes request paths, so a rule pasted as
 * `/caf%C3%A9-p123` is stored as `/café-p123` and actually matches the
 * (decoded) 404s it was created for.
 */
final class RedirectsPageInputTest extends WpdbTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php' );

		// redirect_back() ends in `exit`; making the redirect throw keeps the
		// test runner alive and marks the handler's happy-path completion.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new RuntimeException( 'redirect_back' );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_POST['fv_source'], $_POST['fv_destination'] );
		parent::tearDown();
	}

	public function test_handle_add_percent_decodes_source_and_destination(): void {
		$_POST['fv_source']      = '/Caf%C3%A9-p123';
		$_POST['fv_destination'] = '/caf%C3%A9-new';

		// find_by_source(): no existing rule for this source.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		// The save-time loop check reads the active set: nothing to cycle with.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		// mark_redirected() flags the matching 404-log row.
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();

		$inserted = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				static function ( $table, $data ) use ( &$inserted ) {
					$inserted = $data;

					return 1;
				}
			);

		try {
			( new RedirectsPage() )->handle_add();
			$this->fail( 'handle_add() must finish by redirecting back' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect_back', $e->getMessage() );
		}

		$this->assertNotNull( $inserted );
		// Sources are decoded, then stored normalized (lowercased).
		$this->assertSame( '/café-p123', $inserted['source'] );
		// Destinations are stored as entered, decoded the same way.
		$this->assertSame( '/café-new', $inserted['destination'] );
	}

	public function test_handle_add_rejects_source_decoding_to_control_characters(): void {
		$_POST['fv_source']      = '/bad%00path';
		$_POST['fv_destination'] = '/fine';

		// The decode rejects the source outright, so the store must treat it
		// as invalid and never write anything.
		$this->wpdb->shouldReceive( 'insert' )->never();
		$this->wpdb->shouldReceive( 'update' )->never();

		try {
			( new RedirectsPage() )->handle_add();
			$this->fail( 'handle_add() must finish by redirecting back' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect_back', $e->getMessage() );
		}
	}
}
