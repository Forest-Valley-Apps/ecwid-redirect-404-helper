<?php
/**
 * Unit tests for the hosted-backend HTTP client.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Api\BackendClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers rules parsing/caching and the fire-and-forget report calls.
 */
final class BackendClientTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const STORE_ID = 130416012;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Helpers used by the client, with simple deterministic behaviour.
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
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		// Default: nothing is a WP_Error unless a test says so.
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function client(): BackendClient {
		return new BackendClient( self::STORE_ID, BackendClient::STAGING_BASE_URL );
	}

	private function rules_response( string $json ): array {
		return array(
			'code' => 200,
			'body' => $json,
		);
	}

	public function test_get_rules_parses_and_caches_full_ruleset(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$json = wp_json_encode_stub(
			array(
				'v'        => 2,
				'storeUrl' => 'https://store.example',
				'baseUrl'  => 'https://redirect-manager-dev.up.railway.app',
				'exact'    => array(
					array(
						'source'      => '/old',
						'destination' => '/new',
						'active'      => true,
					),
				),
				'wildcard' => array(
					array(
						'source'      => '/blog/*',
						'destination' => '/news/*',
						'active'      => true,
					),
				),
			)
		);

		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://redirect-manager-dev.up.railway.app/api/storefront/rules/' . self::STORE_ID,
				\Mockery::type( 'array' )
			)
			->andReturn( $this->rules_response( $json ) );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'fv_erh_rules_' . self::STORE_ID, \Mockery::type( 'array' ), 900 );

		$rules = $this->client()->get_rules();

		$this->assertSame( 2, $rules['v'] );
		$this->assertCount( 1, $rules['exact'] );
		$this->assertCount( 1, $rules['wildcard'] );
		$this->assertSame( '/old', $rules['exact'][0]['source'] );
		$this->assertSame( 'https://store.example', $rules['storeUrl'] );
		$this->assertSame( 'https://redirect-manager-dev.up.railway.app', $rules['baseUrl'] );
	}

	public function test_get_rules_returns_cached_value_without_http(): void {
		$cached = array(
			'v'        => 2,
			'exact'    => array(),
			'wildcard' => array(),
		);
		Functions\when( 'get_transient' )->justReturn( $cached );

		// If the cache is honoured, no HTTP call must happen.
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( $cached, $this->client()->get_rules() );
	}

	public function test_force_refresh_bypasses_cache_and_refetches(): void {
		// get_transient must not even be consulted on a forced refresh.
		Functions\expect( 'get_transient' )->never();
		Functions\when( 'set_transient' )->justReturn( true );

		$json = wp_json_encode_stub(
			array(
				'v'        => 2,
				'exact'    => array(),
				'wildcard' => array(),
			)
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->rules_response( $json ) );

		$rules = $this->client()->refresh_rules();

		$this->assertSame( array(), $rules['exact'] );
	}

	public function test_get_rules_returns_empty_and_does_not_cache_on_wp_error(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'an-error' );

		// A failed fetch must not be cached, so the next call can retry.
		Functions\expect( 'set_transient' )->never();

		$rules = $this->client()->get_rules();

		$this->assertSame( 2, $rules['v'] );
		$this->assertSame( array(), $rules['exact'] );
		$this->assertSame( array(), $rules['wildcard'] );
	}

	public function test_get_rules_returns_empty_on_non_200(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 503,
				'body' => 'service unavailable',
			)
		);
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( array(), $this->client()->get_rules()['exact'] );
	}

	public function test_get_rules_returns_empty_on_malformed_json(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( $this->rules_response( '{not-json' ) );
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( array(), $this->client()->get_rules()['wildcard'] );
	}

	public function test_get_rules_treats_overflow_marker_as_empty(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$json = wp_json_encode_stub(
			array(
				'v'        => 2,
				'overflow' => true,
				'hash'     => 'abc123',
				'endpoint' => 'https://redirect-manager-dev.up.railway.app/api/storefront/rules/' . self::STORE_ID,
			)
		);
		Functions\when( 'wp_remote_get' )->justReturn( $this->rules_response( $json ) );

		$rules = $this->client()->get_rules();

		$this->assertSame( array(), $rules['exact'] );
		$this->assertSame( array(), $rules['wildcard'] );
	}

	public function test_clear_rules_cache_deletes_the_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'fv_erh_rules_' . self::STORE_ID );

		$this->client()->clear_rules_cache();
	}

	public function test_ping_true_on_valid_ruleset_primes_cache_without_reading_it(): void {
		$json = wp_json_encode_stub(
			array(
				'v'        => 2,
				'exact'    => array(),
				'wildcard' => array(),
			)
		);
		Functions\when( 'wp_remote_get' )->justReturn( $this->rules_response( $json ) );

		// A ping never reads the cache (a cached copy must not mask a dead
		// backend) but primes it on success so no second fetch is needed.
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )
			->once()
			->with( 'fv_erh_rules_' . self::STORE_ID, \Mockery::type( 'array' ), 900 );

		$this->assertTrue( $this->client()->ping() );
	}

	public function test_ping_false_on_non_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 404,
				'body' => 'not found',
			)
		);

		// A failed ping must not cache anything.
		Functions\expect( 'set_transient' )->never();

		$this->assertFalse( $this->client()->ping() );
	}

	public function test_ping_false_on_wp_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'an-error' );

		$this->assertFalse( $this->client()->ping() );
	}

	public function test_ping_false_on_malformed_json(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->rules_response( '{not-json' ) );

		$this->assertFalse( $this->client()->ping() );
	}

	public function test_get_deleted_entities_parses_and_caches_tracked_store(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$json = wp_json_encode_stub(
			array(
				'v'          => 1,
				'products'   => array( 111, '222', 0, -5, 'junk' ),
				'categories' => array( 9 ),
			)
		);

		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://redirect-manager-dev.up.railway.app/api/storefront/deleted/' . self::STORE_ID,
				\Mockery::type( 'array' )
			)
			->andReturn( $this->rules_response( $json ) );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'fv_erh_deleted_' . self::STORE_ID, \Mockery::type( 'array' ), 3600 );

		$result = $this->client()->get_deleted_entities();

		$this->assertTrue( $result['tracked'] );
		// Non-numeric / non-positive entries are dropped, numeric strings kept.
		$this->assertSame( array( 111, 222 ), $result['products'] );
		$this->assertSame( array( 9 ), $result['categories'] );
	}

	public function test_get_deleted_entities_returns_cached_value_without_http(): void {
		$cached = array(
			'tracked'    => true,
			'products'   => array( 1 ),
			'categories' => array(),
		);
		Functions\when( 'get_transient' )->justReturn( $cached );

		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( $cached, $this->client()->get_deleted_entities() );
	}

	public function test_get_deleted_entities_force_refresh_bypasses_cache(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\when( 'set_transient' )->justReturn( true );

		$json = wp_json_encode_stub(
			array(
				'v'          => 1,
				'products'   => array(),
				'categories' => array(),
			)
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->rules_response( $json ) );

		$result = $this->client()->get_deleted_entities( true );

		$this->assertTrue( $result['tracked'] );
		$this->assertSame( array(), $result['products'] );
	}

	public function test_get_deleted_entities_marked_404_means_untracked_store_and_is_cached(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 404,
				'body' => wp_json_encode_stub( array( 'error' => 'store-not-tracked' ) ),
			)
		);

		// "Never installed the app" is a definite answer worth caching too.
		Functions\expect( 'set_transient' )
			->once()
			->with( 'fv_erh_deleted_' . self::STORE_ID, \Mockery::type( 'array' ), 3600 );

		$result = $this->client()->get_deleted_entities();

		$this->assertFalse( $result['tracked'] );
		$this->assertSame( array(), $result['products'] );
		$this->assertSame( array(), $result['categories'] );
	}

	public function test_get_deleted_entities_unmarked_404_stays_indeterminate(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		// A routing 404 (endpoint not deployed, proxy) carries no marker body —
		// it must NOT be read as "store not tracked".
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 404,
				'body' => wp_json_encode_stub(
					array(
						'message'    => 'Route GET:/api/storefront/deleted/130416012 not found',
						'error'      => 'Not Found',
						'statusCode' => 404,
					)
				),
			)
		);
		Functions\expect( 'set_transient' )->never();

		$this->assertNull( $this->client()->get_deleted_entities() );
	}

	public function test_get_deleted_entities_null_and_uncached_on_wp_error(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'an-error' );

		Functions\expect( 'set_transient' )->never();

		$this->assertNull( $this->client()->get_deleted_entities() );
	}

	public function test_get_deleted_entities_null_on_unexpected_status(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 503,
				'body' => 'service unavailable',
			)
		);
		Functions\expect( 'set_transient' )->never();

		$this->assertNull( $this->client()->get_deleted_entities() );
	}

	public function test_get_deleted_entities_null_on_malformed_json(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( $this->rules_response( '{not-json' ) );
		Functions\expect( 'set_transient' )->never();

		$this->assertNull( $this->client()->get_deleted_entities() );
	}

	public function test_get_deleted_entities_null_on_missing_id_lists(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->rules_response( wp_json_encode_stub( array( 'v' => 1 ) ) )
		);
		Functions\expect( 'set_transient' )->never();

		$this->assertNull( $this->client()->get_deleted_entities() );
	}

	public function test_clear_deleted_cache_deletes_the_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'fv_erh_deleted_' . self::STORE_ID );

		$this->client()->clear_deleted_cache();
	}

	public function test_report_404_posts_non_blocking_with_referrer(): void {
		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $args;
					return array();
				}
			);

		$this->client()->report_404( '/missing-page', 'https://ref.example' );

		$this->assertSame(
			'https://redirect-manager-dev.up.railway.app/api/storefront/404',
			$captured['url']
		);
		$this->assertFalse( $captured['args']['blocking'], 'reports must be fire-and-forget' );
		$this->assertSame( 1, $captured['args']['timeout'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( self::STORE_ID, $body['storeId'] );
		$this->assertSame( '/missing-page', $body['urlPath'] );
		$this->assertSame( 'https://ref.example', $body['referrer'] );
	}

	public function test_report_404_omits_empty_referrer(): void {
		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['args'] = $args;
					return array();
				}
			);

		$this->client()->report_404( '/missing-page' );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertArrayNotHasKey( 'referrer', $body );
	}

	public function test_report_404_skips_empty_url_path(): void {
		// An empty path would fail the backend's min(1) validation and, being
		// fire-and-forget, be silently dropped — so don't send at all.
		Functions\expect( 'wp_remote_post' )->never();

		$this->client()->report_404( '' );
	}

	public function test_report_hit_skips_empty_source_path(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$this->client()->report_hit( '' );
	}

	public function test_report_404_clamps_overlong_fields_to_backend_max(): void {
		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['args'] = $args;
					return array();
				}
			);

		$long_path     = '/' . str_repeat( 'a', 3000 );
		$long_referrer = 'https://ref.example/' . str_repeat( 'b', 3000 );
		$this->client()->report_404( $long_path, $long_referrer );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 2048, mb_strlen( $body['urlPath'] ) );
		$this->assertSame( 2048, mb_strlen( $body['referrer'] ) );
	}

	public function test_report_hit_posts_non_blocking_body(): void {
		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $args;
					return array();
				}
			);

		$this->client()->report_hit( '/promo' );

		$this->assertSame(
			'https://redirect-manager-dev.up.railway.app/api/storefront/hit',
			$captured['url']
		);
		$this->assertFalse( $captured['args']['blocking'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( self::STORE_ID, $body['storeId'] );
		$this->assertSame( '/promo', $body['sourcePath'] );
	}
}

/**
 * Local JSON encoder for building fixtures (the client uses wp_json_encode,
 * which Brain Monkey aliases to json_encode inside the tests).
 *
 * @param array<string,mixed> $data Data to encode.
 * @return string
 */
function wp_json_encode_stub( array $data ): string {
	return json_encode( $data );
}
