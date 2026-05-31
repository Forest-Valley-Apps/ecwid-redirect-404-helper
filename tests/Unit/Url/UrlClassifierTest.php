<?php
/**
 * Unit tests for the Ecwid URL classifier.
 *
 * These mirror the parent product's canonical entity-extraction tests
 * (`packages/shared/src/__tests__/entity-helpers.test.ts` and
 * `entity-regex-parity.test.ts`) so the PHP port stays in lock-step with the
 * source of truth, plus the false-404 collision cases this plugin must reason
 * about.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Url;

use FV\WPEcwidRedirectHelper\Url\UrlClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FV\WPEcwidRedirectHelper\Url\UrlClassifier
 */
final class UrlClassifierTest extends TestCase {

	private UrlClassifier $classifier;

	protected function setUp(): void {
		parent::setUp();
		$this->classifier = new UrlClassifier();
	}

	/**
	 * Mirrors entity-helpers.test.ts "product matches" and "category matches".
	 *
	 * @dataProvider provide_ecwid_entities
	 *
	 * @param string $url      Input URL or path.
	 * @param string $expected Expected type.
	 * @param int    $expected_id Expected entity id.
	 */
	public function test_classifies_ecwid_entities( string $url, string $expected, int $expected_id ): void {
		$result = $this->classifier->classify( $url );

		$this->assertSame( $expected, $result['type'] );
		$this->assertSame( $expected_id, $result['id'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:int}>
	 */
	public static function provide_ecwid_entities(): array {
		return array(
			// Product — slug style.
			'product slug at end'             => array( '/Cool-Product-p123', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product slug trailing slash'     => array( '/Cool-Product-p123/', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product slug trailing query'     => array( '/Cool-Product-p123?utm=x', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product slug trailing hash'      => array( '/Cool-Product-p123#anchor', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product slug absolute url'       => array( 'https://example.com/Cool-Product-p456', UrlClassifier::TYPE_PRODUCT, 456 ),
			// Product — path style.
			'product path at end'             => array( '/p/123', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product path trailing segment'   => array( '/p/123/Cool-Product', UrlClassifier::TYPE_PRODUCT, 123 ),
			'product hash route'              => array( '#!/Some-Product/p/789', UrlClassifier::TYPE_PRODUCT, 789 ),
			// Category — slug style.
			'category slug at end'            => array( '/Summer-Sale-c456', UrlClassifier::TYPE_CATEGORY, 456 ),
			// Category — path style.
			'category path at end'            => array( '/c/456', UrlClassifier::TYPE_CATEGORY, 456 ),
			'category path trailing segment'  => array( '/c/456/sub', UrlClassifier::TYPE_CATEGORY, 456 ),
			'category hash route'             => array( '#!/category/Summer-Sale/c/456', UrlClassifier::TYPE_CATEGORY, 456 ),
			// Precedence — product is checked before category.
			'both markers resolves product'  => array( '/foo-p1/bar-c2', UrlClassifier::TYPE_PRODUCT, 1 ),
		);
	}

	/**
	 * Mirrors entity-helpers.test.ts "non-matches" — these are WordPress pages.
	 *
	 * @dataProvider provide_wp_pages
	 *
	 * @param string $url Input URL or path.
	 */
	public function test_non_ecwid_urls_are_wp_pages( string $url ): void {
		$result = $this->classifier->classify( $url );

		$this->assertSame( UrlClassifier::TYPE_WP_PAGE, $result['type'] );
		$this->assertNull( $result['id'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_wp_pages(): array {
		return array(
			'plain path'                   => array( '/about' ),
			'empty string'                 => array( '' ),
			'external url without entity'  => array( 'https://example.com/page' ),
			'word boundary digits'         => array( '/products' ),
			'slug missing digit terminator' => array( '/foo-p123abc' ),
		);
	}

	/**
	 * Mirrors entity-regex-parity.test.ts: the canonical helper is
	 * case-sensitive (Ecwid only emits lowercase `-p`/`-c`), so an upper-case
	 * `-P123` is an ordinary slug, not a product.
	 */
	public function test_classification_is_case_sensitive(): void {
		$result = $this->classifier->classify( '/Cool-Product-P123' );

		$this->assertSame( UrlClassifier::TYPE_WP_PAGE, $result['type'] );
		$this->assertNull( $result['id'] );
	}

	/**
	 * Mirrors entity-regex-parity.test.ts: the legacy storefront-only
	 * `/cid/<id>` category route is NOT recognised by the canonical helper.
	 */
	public function test_legacy_cid_route_is_not_recognised(): void {
		$result = $this->classifier->classify( '#!/category/Foo/cid/9' );

		$this->assertSame( UrlClassifier::TYPE_WP_PAGE, $result['type'] );
		$this->assertNull( $result['id'] );
	}

	/**
	 * False-404 collisions: an ordinary WordPress slug that happens to end in
	 * Ecwid's `-c<id>` / `-p<id>` pattern is reported as an Ecwid entity. The
	 * classifier cannot disambiguate this; the false-404 warner (later session)
	 * cross-checks against real WordPress pages. This test pins the behaviour so
	 * the warner can rely on it.
	 *
	 * @dataProvider provide_collision_slugs
	 *
	 * @param string $url         Colliding WordPress slug.
	 * @param string $expected    Expected (collision) type.
	 * @param int    $expected_id Expected entity id parsed from the slug.
	 */
	public function test_wp_slug_collisions_are_classified_as_ecwid_entities( string $url, string $expected, int $expected_id ): void {
		$result = $this->classifier->classify( $url );

		$this->assertSame( $expected, $result['type'] );
		$this->assertSame( $expected_id, $result['id'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:int}>
	 */
	public static function provide_collision_slugs(): array {
		return array(
			'about-us page colliding with category' => array( '/about-us-c123', UrlClassifier::TYPE_CATEGORY, 123 ),
			'blog post colliding with product'      => array( '/my-recap-p2024', UrlClassifier::TYPE_PRODUCT, 2024 ),
		);
	}

	public function test_extract_ecwid_entity_returns_null_for_wp_page(): void {
		$this->assertNull( $this->classifier->extract_ecwid_entity( '/contact' ) );
	}
}
