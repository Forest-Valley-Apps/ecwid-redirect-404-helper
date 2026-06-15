<?php
/**
 * Unit tests for the 404-log layer derivation.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Admin\RowLayer;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the classification → layer mapping and the verdict → deep-link target
 * routing — the funnel boundary, derived from existing columns only.
 */
final class RowLayerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// label() is the only translated method; return the raw string.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_products_and_categories_are_storefront_layer(): void {
		$this->assertSame( RowLayer::LAYER_STOREFRONT, RowLayer::for_classification( UrlClassifier::TYPE_PRODUCT ) );
		$this->assertSame( RowLayer::LAYER_STOREFRONT, RowLayer::for_classification( UrlClassifier::TYPE_CATEGORY ) );
	}

	public function test_wp_pages_are_wp_layer(): void {
		$this->assertSame( RowLayer::LAYER_WP, RowLayer::for_classification( UrlClassifier::TYPE_WP_PAGE ) );
	}

	public function test_storefront_classifications_are_product_and_category(): void {
		// The set the counted CTA sums over; pinned so it stays in lockstep with
		// for_classification()'s notion of "storefront layer".
		$this->assertSame(
			array( UrlClassifier::TYPE_PRODUCT, UrlClassifier::TYPE_CATEGORY ),
			RowLayer::storefront_classifications()
		);
	}

	public function test_unknown_classification_defaults_to_wp_layer(): void {
		// Conservative default: the free "Create redirect" still does something.
		$this->assertSame( RowLayer::LAYER_WP, RowLayer::for_classification( '' ) );
		$this->assertSame( RowLayer::LAYER_WP, RowLayer::for_classification( 'something-else' ) );
	}

	public function test_deleted_verdict_routes_to_deleted_redirects(): void {
		$this->assertSame(
			DeepLink::TARGET_DELETED_REDIRECTS,
			RowLayer::deep_link_target( VerdictChecker::VERDICT_DELETED )
		);
	}

	public function test_other_verdicts_route_to_storefront_layer(): void {
		$this->assertSame( DeepLink::TARGET_STOREFRONT_LAYER, RowLayer::deep_link_target( '' ) );
		$this->assertSame( DeepLink::TARGET_STOREFRONT_LAYER, RowLayer::deep_link_target( VerdictChecker::VERDICT_IN_CATALOG ) );
		$this->assertSame( DeepLink::TARGET_STOREFRONT_LAYER, RowLayer::deep_link_target( VerdictChecker::VERDICT_NEVER_EXISTED ) );
	}

	public function test_labels_distinguish_the_layers(): void {
		$this->assertNotSame( RowLayer::label( RowLayer::LAYER_WP ), RowLayer::label( RowLayer::LAYER_STOREFRONT ) );
		$this->assertNotSame( '', RowLayer::label( RowLayer::LAYER_WP ) );
		$this->assertNotSame( '', RowLayer::label( RowLayer::LAYER_STOREFRONT ) );
	}
}
