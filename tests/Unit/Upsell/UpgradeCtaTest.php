<?php
/**
 * Unit tests for the paid-tier CTA dismissal state.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Upsell;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Upsell\UpgradeCta;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers per-CTA dismissal persistence against an in-memory option store.
 */
final class UpgradeCtaTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WordPress options table.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cta(): UpgradeCta {
		// A configured deep-link keeps the renderer self-contained; these tests
		// only exercise dismissal state, not rendering.
		return new UpgradeCta( new DeepLink( 1, 'seo-redirect-manager', null, 'https://market.example' ) );
	}

	public function test_fresh_cta_is_not_dismissed(): void {
		$this->assertFalse( $this->cta()->is_dismissed( 'log-deleted-redirects' ) );
	}

	public function test_dismissal_persists_for_that_key_only(): void {
		// Simulate the option a dismissal would write.
		$this->options['fv_erh_cta_dismissed'] = array( 'log-deleted-redirects' );

		$cta = $this->cta();

		$this->assertTrue( $cta->is_dismissed( 'log-deleted-redirects' ) );
		$this->assertFalse( $cta->is_dismissed( 'redirects-bulk-migration' ) );
	}

	public function test_malformed_option_is_treated_as_no_dismissals(): void {
		$this->options['fv_erh_cta_dismissed'] = 'not-an-array';

		$this->assertFalse( $this->cta()->is_dismissed( 'log-storefront-layer' ) );
	}
}
