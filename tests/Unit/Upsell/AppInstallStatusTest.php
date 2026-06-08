<?php
/**
 * Unit tests for the render-safe app-install resolver.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Upsell;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Upsell\AppInstallStatus;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the cache-only precedence: authoritative app-status wins; the
 * deleted-endpoint tracked flag is only a fallback for missing data; neither
 * cached yields null. No network is touched.
 */
final class AppInstallStatusTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const STORE       = 130416012;
	private const STATUS_KEY  = 'fv_erh_app_status_130416012';
	private const DELETED_KEY = 'fv_erh_deleted_130416012';

	/**
	 * In-memory stand-in for the WordPress transient store.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->transients[ $key ] ?? false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function status(): AppInstallStatus {
		return new AppInstallStatus( new BackendClient( self::STORE ) );
	}

	public function test_authoritative_true_wins(): void {
		$this->transients[ self::STATUS_KEY ]  = array( 'installed' => true );
		$this->transients[ self::DELETED_KEY ] = array( 'tracked' => false );

		$this->assertTrue( $this->status()->is_installed() );
	}

	public function test_authoritative_false_overrides_tracked_proxy(): void {
		// A since-uninstalled store: app-status false must win over tracked true.
		$this->transients[ self::STATUS_KEY ]  = array( 'installed' => false );
		$this->transients[ self::DELETED_KEY ] = array( 'tracked' => true );

		$this->assertFalse( $this->status()->is_installed() );
	}

	public function test_falls_back_to_tracked_proxy_when_authoritative_absent(): void {
		$this->transients[ self::DELETED_KEY ] = array( 'tracked' => true );

		$this->assertTrue( $this->status()->is_installed() );
	}

	public function test_null_when_no_cached_signal(): void {
		$this->assertNull( $this->status()->is_installed() );
	}
}
