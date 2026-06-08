<?php
/**
 * Unit tests for the hosted-app deep-link builder.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Upsell;

use Brain\Monkey;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers URL construction for installed vs. not-installed, the storeless
 * fallback, and target validation.
 */
final class DeepLinkTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const STORE  = 130416012;
	private const SLUG   = 'seo-redirect-manager';
	private const MARKET = 'https://www.ecwid.com/apps';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// rawurlencode is a native PHP function; the builder uses no WP helpers.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_installed_deep_links_to_target_screen(): void {
		$link = new DeepLink( self::STORE, self::SLUG, true, self::MARKET );

		$this->assertSame(
			'https://my.ecwid.com/store/130416012#app:name=seo-redirect-manager&app_state=bulk-mapping',
			$link->url_for( DeepLink::TARGET_BULK_MAPPING )
		);
		$this->assertTrue( $link->can_deep_link() );
	}

	public function test_unknown_target_degrades_to_home(): void {
		$link = new DeepLink( self::STORE, self::SLUG, true, self::MARKET );

		$this->assertSame(
			'https://my.ecwid.com/store/130416012#app:name=seo-redirect-manager&app_state=home',
			$link->url_for( 'not-a-real-target' )
		);
	}

	public function test_not_installed_points_at_listing(): void {
		$link = new DeepLink( self::STORE, self::SLUG, false, self::MARKET );

		$this->assertSame(
			'https://my.ecwid.com/store/130416012#apps:view=app&name=seo-redirect-manager',
			$link->url_for( DeepLink::TARGET_STOREFRONT_LAYER )
		);
		$this->assertFalse( $link->can_deep_link() );
	}

	public function test_indeterminate_install_state_points_at_listing(): void {
		$link = new DeepLink( self::STORE, self::SLUG, null, self::MARKET );

		$this->assertSame(
			'https://my.ecwid.com/store/130416012#apps:view=app&name=seo-redirect-manager',
			$link->url_for( DeepLink::TARGET_DELETED_REDIRECTS )
		);
		$this->assertFalse( $link->can_deep_link() );
	}

	public function test_no_store_id_falls_back_to_generic_market_url(): void {
		$link = new DeepLink( 0, self::SLUG, true, self::MARKET );

		$this->assertSame( self::MARKET, $link->url_for( DeepLink::TARGET_BULK_MAPPING ) );
		$this->assertFalse( $link->can_deep_link() );
	}

	public function test_dev_slug_is_substituted(): void {
		$link = new DeepLink( self::STORE, DeepLink::SLUG_DEV, true, self::MARKET );

		$this->assertSame(
			'https://my.ecwid.com/store/130416012#app:name=seo-redirect-manager-dev&app_state=migration-import',
			$link->url_for( DeepLink::TARGET_MIGRATION_IMPORT )
		);
	}
}
