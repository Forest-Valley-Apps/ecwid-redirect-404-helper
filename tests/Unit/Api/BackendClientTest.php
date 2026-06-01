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
