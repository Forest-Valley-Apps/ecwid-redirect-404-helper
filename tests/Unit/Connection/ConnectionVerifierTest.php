<?php
/**
 * Unit tests for the connection verifier.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Connection;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Api\EcwidCatalogClient;
use FV\WPEcwidRedirectHelper\Connection\ConnectionVerifier;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the combined backend-ping + catalog-auth verdict by routing the
 * mocked HTTP layer on URL.
 */
final class ConnectionVerifierTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const STORE_ID = 130416012;

	/**
	 * Response returned for the backend rules request.
	 *
	 * @var array<string,mixed>
	 */
	private array $rules_response;

	/**
	 * Response returned for the catalog ping request.
	 *
	 * @var array<string,mixed>
	 */
	private array $catalog_response;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// A valid (empty) ruleset and a passing catalog ping by default.
		$this->rules_response   = array(
			'code' => 200,
			'body' => json_encode(
				array(
					'v'        => 2,
					'exact'    => array(),
					'wildcard' => array(),
				)
			),
		);
		$this->catalog_response = array( 'code' => 200 );

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
		// A successful backend ping primes the rules cache as a side effect.
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				return false !== strpos( $url, '/api/storefront/rules/' )
					? $this->rules_response
					: $this->catalog_response;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function verifier(): ConnectionVerifier {
		return new ConnectionVerifier(
			new BackendClient( self::STORE_ID, BackendClient::STAGING_BASE_URL ),
			new EcwidCatalogClient( self::STORE_ID, 'public_token' )
		);
	}

	public function test_ok_when_store_resolves_and_token_authenticates(): void {
		$this->assertSame( ConnectionVerifier::OK, $this->verifier()->verify() );
	}

	public function test_backend_unreachable_when_backend_does_not_answer(): void {
		$this->rules_response = array(
			'code' => 404,
			'body' => 'not found',
		);

		$this->assertSame( ConnectionVerifier::BACKEND_UNREACHABLE, $this->verifier()->verify() );
	}

	public function test_auth_failed_when_catalog_rejects_token(): void {
		$this->catalog_response = array( 'code' => 401 );

		$this->assertSame( ConnectionVerifier::AUTH_FAILED, $this->verifier()->verify() );
	}

	public function test_catalog_unreachable_on_unexpected_catalog_status(): void {
		$this->catalog_response = array( 'code' => 500 );

		$this->assertSame( ConnectionVerifier::CATALOG_UNREACHABLE, $this->verifier()->verify() );
	}
}
