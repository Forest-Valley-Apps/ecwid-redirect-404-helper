<?php
/**
 * Unit tests for the 404-log counted upgrade-CTA selection.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Admin\LogPage;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use PHPUnit\Framework\TestCase;

/**
 * Covers which CTA the log surfaces for a given set of counts, that the copy
 * carries the merchant's own number, and that the storefront CTA routes to the
 * Ask-D `wp-reported-404s` target. The selection is pure (counts in, descriptor
 * out), so no render is needed.
 */
final class LogPageCtaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_404s_yields_no_cta(): void {
		$this->assertNull( LogPage::upgrade_cta_spec( 0, 0 ) );
	}

	public function test_deleted_verdicts_win_and_are_counted(): void {
		// Deleted present alongside a larger storefront count: deleted still wins,
		// because the app can automate those specifically.
		$spec = LogPage::upgrade_cta_spec( 3, 10 );

		$this->assertNotNull( $spec );
		$this->assertSame( 'log-deleted-redirects', $spec['key'] );
		$this->assertSame( DeepLink::TARGET_DELETED_REDIRECTS, $spec['actions'][0]['target'] );
		$this->assertSame( '3 deleted products are still returning 404s', $spec['heading'] );
	}

	public function test_storefront_count_routes_to_wp_reported_404s(): void {
		$spec = LogPage::upgrade_cta_spec( 0, 4 );

		$this->assertNotNull( $spec );
		$this->assertSame( 'log-storefront-layer', $spec['key'] );
		$this->assertSame( DeepLink::TARGET_WP_REPORTED_404S, $spec['actions'][0]['target'] );
		$this->assertSame( 'You have 4 storefront 404s WordPress cannot redirect', $spec['heading'] );
	}

	public function test_copy_is_singular_for_a_single_404(): void {
		$this->assertSame(
			'1 deleted product is still returning 404s',
			LogPage::upgrade_cta_spec( 1, 0 )['heading']
		);
		$this->assertSame(
			'You have 1 storefront 404 WordPress cannot redirect',
			LogPage::upgrade_cta_spec( 0, 1 )['heading']
		);
	}
}
