<?php
/**
 * Unit tests for the Ecwid storefront catalog client.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Api\EcwidCatalogClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the EXISTS / NOT_FOUND / UNKNOWN tri-state mapping and URL building.
 */
final class EcwidCatalogClientTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const STORE_ID = 130416012;
	private const TOKEN    = 'public_AbC123';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function client(): EcwidCatalogClient {
		return new EcwidCatalogClient( self::STORE_ID, self::TOKEN );
	}

	public function test_product_exists_on_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 200 ) );

		$this->assertSame( EcwidCatalogClient::EXISTS, $this->client()->product_exists( 42 ) );
	}

	public function test_product_not_found_on_404(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 404 ) );

		$this->assertSame( EcwidCatalogClient::NOT_FOUND, $this->client()->product_exists( 42 ) );
	}

	public function test_category_exists_on_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 200 ) );

		$this->assertSame( EcwidCatalogClient::EXISTS, $this->client()->category_exists( 7 ) );
	}

	public function test_category_not_found_on_404(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 404 ) );

		$this->assertSame( EcwidCatalogClient::NOT_FOUND, $this->client()->category_exists( 7 ) );
	}

	public function test_unknown_on_wp_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'boom' );

		$this->assertSame( EcwidCatalogClient::UNKNOWN, $this->client()->product_exists( 42 ) );
	}

	public function test_unknown_on_unexpected_status(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 500 ) );

		$this->assertSame( EcwidCatalogClient::UNKNOWN, $this->client()->category_exists( 7 ) );
	}

	public function test_request_targets_public_api_with_bearer_header_not_query_token(): void {
		$captured = array();
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $args;
					return array( 'code' => 200 );
				}
			);

		$this->client()->product_exists( 42 );

		// Ecwid (2025-03) accepts the token only in the Authorization header.
		$expected = 'https://app.ecwid.com/api/v3/' . self::STORE_ID
			. '/products/42?responseFields=id';
		$this->assertSame( $expected, $captured['url'] );
		$this->assertStringNotContainsString( 'token=', $captured['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $captured['args']['headers']['Authorization'] );
	}

	public function test_empty_token_returns_unknown_without_http(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$client = new EcwidCatalogClient( self::STORE_ID, '' );

		$this->assertSame( EcwidCatalogClient::UNKNOWN, $client->product_exists( 42 ) );
	}

	public function test_products_exist_maps_present_and_absent_ids(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => json_encode( array( 'items' => array( array( 'id' => 42 ) ) ) ),
			)
		);

		$this->assertSame(
			array(
				42 => EcwidCatalogClient::EXISTS,
				99 => EcwidCatalogClient::NOT_FOUND,
			),
			$this->client()->products_exist( array( 42, 99 ) )
		);
	}

	public function test_products_exist_is_one_request_with_id_list_and_bearer(): void {
		$captured = array();
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $args;
					return array(
						'code' => 200,
						'body' => json_encode( array( 'items' => array() ) ),
					);
				}
			);

		$this->client()->products_exist( array( 42, 99, 7 ) );

		$expected = 'https://app.ecwid.com/api/v3/' . self::STORE_ID
			. '/products?productId=42,99,7&responseFields=items(id)';
		$this->assertSame( $expected, $captured['url'] );
		$this->assertStringNotContainsString( 'token=', $captured['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $captured['args']['headers']['Authorization'] );
	}

	public function test_products_exist_chunks_over_the_page_limit(): void {
		// Ecwid serves at most 100 items per page, so 101 ids must split into
		// two requests or the truncated tail would misread as absent.
		$ids   = range( 1, 101 );
		$calls = 0;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url ) use ( &$calls ) {
				++$calls;
				preg_match( '~productId=([0-9,]+)~', $url, $m );
				$items = array();
				foreach ( explode( ',', $m[1] ) as $id ) {
					$items[] = array( 'id' => (int) $id );
				}

				return array(
					'code' => 200,
					'body' => json_encode( array( 'items' => $items ) ),
				);
			}
		);

		$statuses = $this->client()->products_exist( $ids );

		$this->assertSame( 2, $calls );
		$this->assertCount( 101, $statuses );
		$this->assertSame( EcwidCatalogClient::EXISTS, $statuses[1] );
		$this->assertSame( EcwidCatalogClient::EXISTS, $statuses[101] );
	}

	public function test_products_exist_null_on_wp_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'boom' );

		$this->assertNull( $this->client()->products_exist( array( 42 ) ) );
	}

	public function test_products_exist_null_on_unexpected_status(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 500 ) );

		$this->assertNull( $this->client()->products_exist( array( 42 ) ) );
	}

	public function test_products_exist_null_on_malformed_body(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => 'not json',
			)
		);

		$this->assertNull( $this->client()->products_exist( array( 42 ) ) );
	}

	public function test_products_exist_null_on_missing_items_key(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => json_encode( array( 'total' => 0 ) ),
			)
		);

		$this->assertNull( $this->client()->products_exist( array( 42 ) ) );
	}

	public function test_products_exist_empty_input_returns_empty_without_http(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( array(), $this->client()->products_exist( array() ) );
	}

	public function test_products_exist_null_on_empty_token_without_http(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$client = new EcwidCatalogClient( self::STORE_ID, '' );

		$this->assertNull( $client->products_exist( array( 42 ) ) );
	}

	public function test_verify_credentials_ok_on_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 200 ) );

		$this->assertSame( EcwidCatalogClient::AUTH_OK, $this->client()->verify_credentials() );
	}

	public function test_verify_credentials_failed_on_401(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 401 ) );

		$this->assertSame( EcwidCatalogClient::AUTH_FAILED, $this->client()->verify_credentials() );
	}

	public function test_verify_credentials_failed_on_403(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 403 ) );

		$this->assertSame( EcwidCatalogClient::AUTH_FAILED, $this->client()->verify_credentials() );
	}

	public function test_verify_credentials_unknown_on_500(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'code' => 500 ) );

		$this->assertSame( EcwidCatalogClient::AUTH_UNKNOWN, $this->client()->verify_credentials() );
	}

	public function test_verify_credentials_unknown_on_wp_error(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( 'boom' );

		$this->assertSame( EcwidCatalogClient::AUTH_UNKNOWN, $this->client()->verify_credentials() );
	}

	public function test_verify_credentials_failed_on_empty_token_without_http(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$client = new EcwidCatalogClient( self::STORE_ID, '' );

		$this->assertSame( EcwidCatalogClient::AUTH_FAILED, $client->verify_credentials() );
	}

	public function test_verify_credentials_targets_products_list_with_bearer(): void {
		$captured = array();
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $args;
					return array( 'code' => 200 );
				}
			);

		$this->client()->verify_credentials();

		$expected = 'https://app.ecwid.com/api/v3/' . self::STORE_ID
			. '/products?limit=1&responseFields=count';
		$this->assertSame( $expected, $captured['url'] );
		$this->assertStringNotContainsString( 'token=', $captured['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $captured['args']['headers']['Authorization'] );
	}
}
