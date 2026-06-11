<?php
/**
 * Unit tests for the hourly cron tasks.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Connection\ConnectionState;
use FV\WPEcwidRedirectHelper\Cron\Tasks;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;

/**
 * Covers the scheduling guards, the connection gating of the hourly run, and
 * the connected happy path (each of the three jobs runs exactly once).
 */
final class TasksTest extends WpdbTestCase {

	private const STORE_ID = 130416012;
	private const TOKEN    = 'public_AbC123';

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
		// Not connected: VerdictChecker::for_current_connection() must bail
		// before touching any HTTP, the app-status warm is gated off by the
		// same connection flag, and a warm collision cache skips the scan.
		// Returning each option's own default leaves the connection
		// unconfigured.
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

	public function test_run_not_connected_skips_app_status_even_with_store_id(): void {
		// The R2.1 privacy gate: a discoverable Ecwid store id alone must NOT
		// warm the app-status flag — only the merchant's explicit Connect does
		// (the readme promises the backend is contacted only while connected).
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ecwid_store_id' === $key ) {
					return (string) self::STORE_ID;
				}
				if ( 'ecwid_public_token' === $key ) {
					return self::TOKEN;
				}

				// Includes ConnectionState::OPTION: unset, so not connected.
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( array() );

		Functions\expect( 'wp_remote_get' )->never();

		( new Tasks() )->run();

		$this->assertTrue( true );
	}

	public function test_run_connected_with_cold_caches_does_each_job_exactly_once(): void {
		// The happy path: connected, store credentials discoverable, every
		// cache cold, one product 404 awaiting a verdict. The run must perform
		// the collision scan, the app-status warm, and the verdict batch —
		// each exactly once.
		Functions\when( 'get_ecwid_store_id' )->justReturn( self::STORE_ID );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( ConnectionState::OPTION === $key ) {
					return array(
						'connected'   => true,
						'store_id'    => self::STORE_ID,
						'verified_at' => 1700000000,
					);
				}
				if ( 'ecwid_store_id' === $key ) {
					return (string) self::STORE_ID;
				}
				if ( 'ecwid_public_token' === $key ) {
					return self::TOKEN;
				}

				return $default;
			}
		);

		// Every transient is cold; writes are accepted.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['code'] ?? 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'page' => 'page',
			)
		);

		// Route the two repository reads by their SQL: the collision scan's
		// REGEXP query returns no collisions; the verdict query returns one
		// product entity awaiting a verdict.
		$this->wpdb->posts = 'wp_posts';
		$collision_scans   = 0;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$collision_scans ) {
				$index = (int) substr( (string) $query, strlen( 'PREPARED_' ) ) - 1;
				$sql   = $this->prepared[ $index ]['sql'];

				if ( false !== strpos( $sql, 'REGEXP' ) ) {
					++$collision_scans;

					return array();
				}

				return array(
					array(
						'classification' => 'product',
						'entity_id'      => 42,
					),
				);
			}
		);
		// The verdict run's remaining count.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		// Exactly one verdict write: the product is live in the catalog.
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->withArgs(
				static function ( $table, $data ) {
					return 'wp_fv_erh_404_log' === $table && 'in-catalog' === $data['verdict'];
				}
			)
			->andReturn( 1 );

		// Route the HTTP layer by URL, counting each endpoint's hits. The
		// product answers EXISTS, so the deletion history is never consulted.
		$catalog_batch_calls = 0;
		$app_status_calls    = 0;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url ) use ( &$catalog_batch_calls, &$app_status_calls ) {
				if ( false !== strpos( $url, '/products?productId=42' ) ) {
					++$catalog_batch_calls;

					return array(
						'code' => 200,
						'body' => json_encode( array( 'items' => array( array( 'id' => 42 ) ) ) ),
					);
				}

				if ( false !== strpos( $url, '/api/storefront/app-status/' . self::STORE_ID ) ) {
					++$app_status_calls;

					return array(
						'code' => 200,
						'body' => json_encode(
							array(
								'v'         => 1,
								'installed' => true,
							)
						),
					);
				}

				self::fail( 'Unexpected HTTP GET: ' . $url );
			}
		);

		( new Tasks() )->run();

		$this->assertSame( 1, $collision_scans );
		$this->assertSame( 1, $catalog_batch_calls );
		$this->assertSame( 1, $app_status_calls );
	}
}
