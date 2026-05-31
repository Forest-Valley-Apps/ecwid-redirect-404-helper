<?php
/**
 * Unit tests for the redirect rule matcher.
 *
 * These exercise the PHP port of the parent product's storefront matcher
 * (`apps/storefront-js/src/lib/matchers.js` + `normalize.js`) and pin the
 * hash-URL, suffix-matching and wildcard-boundary edge cases documented in the
 * parent product's storefront gotchas reference.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Url;

use FV\WPEcwidRedirectHelper\Url\RuleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FV\WPEcwidRedirectHelper\Url\RuleMatcher
 */
final class RuleMatcherTest extends TestCase {

	private RuleMatcher $matcher;

	protected function setUp(): void {
		parent::setUp();
		$this->matcher = new RuleMatcher();
	}

	/* ----------------------------------------------------------------------
	 * normalize_path
	 * ------------------------------------------------------------------- */

	/**
	 * @dataProvider provide_normalize_cases
	 *
	 * @param string $input    Raw path.
	 * @param string $expected Normalized path.
	 */
	public function test_normalize_path( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->matcher->normalize_path( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_normalize_cases(): array {
		return array(
			'empty becomes root'            => array( '', '/' ),
			'root stays root'              => array( '/', '/' ),
			'lowercases and trims slash'   => array( '/Foo/', '/foo' ),
			'strips query string'          => array( '/Foo?x=1', '/foo' ),
			'strips fragment on clean url' => array( '/Foo#frag', '/foo' ),
			'strips query and fragment'    => array( '/Foo?x=1#y', '/foo' ),
			'adds leading slash'           => array( 'foo', '/foo' ),
			'preserves hash-bang prefix'   => array( '#!/Foo', '#!/foo' ),
			'preserves bare-hash prefix'   => array( '#/Foo/', '#/foo' ),
			'bare hash-bang kept as-is'    => array( '#!/', '#!/' ),
			'hash route strips query'      => array( '#!/Foo?utm=x', '#!/foo' ),
			'wildcard suffix kept'         => array( '*823816367', '*823816367' ),
		);
	}

	/* ----------------------------------------------------------------------
	 * build_lookup
	 * ------------------------------------------------------------------- */

	public function test_build_lookup_indexes_exact_rules_by_normalized_source(): void {
		$config = array(
			'exact' => array(
				array(
					'source'      => '/Old-Page/',
					'destination' => '/new-page',
				),
			),
		);

		$lookup = $this->matcher->build_lookup( $config );

		$this->assertArrayHasKey( '/old-page', $lookup['map'] );
		$this->assertSame( '/new-page', $lookup['map']['/old-page']['destination'] );
	}

	public function test_build_lookup_skips_inactive_rules(): void {
		$config = array(
			'exact'    => array(
				array(
					'source'      => '/disabled',
					'destination' => '/x',
					'active'      => false,
				),
				array(
					'source'      => '/enabled',
					'destination' => '/y',
				),
			),
			'wildcard' => array(
				array(
					'source'      => '/off/*',
					'destination' => '/x/*',
					'active'      => false,
				),
			),
		);

		$lookup = $this->matcher->build_lookup( $config );

		$this->assertArrayNotHasKey( '/disabled', $lookup['map'] );
		$this->assertArrayHasKey( '/enabled', $lookup['map'] );
		$this->assertCount( 0, $lookup['wildcards'] );
	}

	public function test_build_lookup_sorts_wildcards_most_specific_first(): void {
		$config = array(
			'wildcard' => array(
				array(
					'source'      => '/a/*',
					'destination' => '/x/*',
				),
				array(
					'source'      => '/a/long/prefix/*',
					'destination' => '/y/*',
				),
				array(
					'source'      => '/a/mid/*',
					'destination' => '/z/*',
				),
			),
		);

		$lookup  = $this->matcher->build_lookup( $config );
		$sources = array_map(
			static function ( array $rule ): string {
				return $rule['source'];
			},
			$lookup['wildcards']
		);

		$this->assertSame(
			array( '/a/long/prefix/*', '/a/mid/*', '/a/*' ),
			$sources
		);
	}

	public function test_build_lookup_keeps_equal_length_wildcards_in_order(): void {
		$config = array(
			'wildcard' => array(
				array(
					'source'      => '/aaa/*',
					'destination' => '/first/*',
				),
				array(
					'source'      => '/bbb/*',
					'destination' => '/second/*',
				),
			),
		);

		$lookup  = $this->matcher->build_lookup( $config );
		$sources = array_map(
			static function ( array $rule ): string {
				return $rule['source'];
			},
			$lookup['wildcards']
		);

		// Equal-length sources retain insertion order (stable sort).
		$this->assertSame( array( '/aaa/*', '/bbb/*' ), $sources );
	}

	/* ----------------------------------------------------------------------
	 * match_wildcard
	 * ------------------------------------------------------------------- */

	public function test_trailing_wildcard_appends_suffix_to_destination(): void {
		$wildcards = array(
			array(
				'source'      => '/old-category*',
				'destination' => '/new-category*',
			),
		);

		$result = $this->matcher->match_wildcard( '/old-category/sub-page', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-category/sub-page', $result['destination'] );
		$this->assertSame( '', $result['base_path'] );
		$this->assertTrue( $result['wildcard'] );
	}

	/**
	 * Faithful-port quirk: the `/prefix/*` (slash-star) source form strips the
	 * slash from the matched prefix while the destination keeps its own slash,
	 * so the substituted suffix yields a doubled slash. This mirrors the parent
	 * storefront matcher exactly (`matchWildcard` in matchers.js); the no-slash
	 * `/prefix*` form is the one that produces clean output. Pinned so the
	 * behaviour is a deliberate decision, not an accident.
	 */
	public function test_trailing_slash_star_form_reproduces_js_double_slash(): void {
		$wildcards = array(
			array(
				'source'      => '/old-category/*',
				'destination' => '/new-category/*',
			),
		);

		$result = $this->matcher->match_wildcard( '/old-category/sub-page', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-category//sub-page', $result['destination'] );
	}

	public function test_trailing_wildcard_matches_prefix_alone_with_empty_suffix(): void {
		$wildcards = array(
			array(
				'source'      => '/summer-sale*',
				'destination' => '/winter-sale*',
			),
		);

		$result = $this->matcher->match_wildcard( '/summer-sale', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/winter-sale', $result['destination'] );
	}

	public function test_trailing_wildcard_extracts_embedded_store_base_path(): void {
		$wildcards = array(
			array(
				'source'      => '/old-category*',
				'destination' => '/new-category*',
			),
		);

		$result = $this->matcher->match_wildcard( '/my-store/old-category/x', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-category/x', $result['destination'] );
		$this->assertSame( '/my-store', $result['base_path'] );
	}

	public function test_trailing_wildcard_without_destination_star_uses_destination_as_is(): void {
		$wildcards = array(
			array(
				'source'      => '/old/*',
				'destination' => '/new',
			),
		);

		$result = $this->matcher->match_wildcard( '/old/anything', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/new', $result['destination'] );
	}

	public function test_leading_wildcard_matches_suffix_with_nondigit_boundary(): void {
		$wildcards = array(
			array(
				'source'      => '*823816367',
				'destination' => '/winter-sale',
			),
		);

		$result = $this->matcher->match_wildcard( '#!/product-name/p/823816367', $wildcards );

		$this->assertNotNull( $result );
		$this->assertSame( '/winter-sale', $result['destination'] );
		$this->assertSame( '', $result['base_path'] );
	}

	public function test_leading_wildcard_rejects_partial_id_match(): void {
		$wildcards = array(
			array(
				'source'      => '*823816367',
				'destination' => '/winter-sale',
			),
		);

		// A longer number ending in the suffix must NOT match (digit boundary).
		$result = $this->matcher->match_wildcard( '#!/product/p/1823816367', $wildcards );

		$this->assertNull( $result );
	}

	public function test_match_wildcard_returns_null_when_nothing_matches(): void {
		$wildcards = array(
			array(
				'source'      => '/old/*',
				'destination' => '/new/*',
			),
		);

		$this->assertNull( $this->matcher->match_wildcard( '/unrelated', $wildcards ) );
	}

	/* ----------------------------------------------------------------------
	 * get_alt_path
	 * ------------------------------------------------------------------- */

	/**
	 * @dataProvider provide_alt_path_cases
	 *
	 * @param string      $input    Input path.
	 * @param string|null $expected Expected alternate, or null.
	 */
	public function test_get_alt_path( string $input, ?string $expected ): void {
		$this->assertSame( $expected, $this->matcher->get_alt_path( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function provide_alt_path_cases(): array {
		return array(
			'hash-bang to clean'      => array( '#!/foo', '/foo' ),
			'bare-hash to clean'      => array( '#/foo', '/foo' ),
			'clean to hash-bang'      => array( '/foo', '#!/foo' ),
			'clean to hash lowercased' => array( '/Foo', '#!/foo' ),
			'wildcard has no alt'     => array( '*823816367', null ),
		);
	}

	/* ----------------------------------------------------------------------
	 * match_path (orchestration)
	 * ------------------------------------------------------------------- */

	public function test_match_path_prefers_exact_over_wildcard(): void {
		$lookup = $this->matcher->build_lookup(
			array(
				'exact'    => array(
					array(
						'source'      => '/old-product',
						'destination' => '/exact-target',
					),
				),
				'wildcard' => array(
					array(
						'source'      => '/old-product*',
						'destination' => '/wildcard-target',
					),
				),
			)
		);

		$result = $this->matcher->match_path( '/old-product', $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/exact-target', $result['destination'] );
		$this->assertFalse( $result['wildcard'] );
	}

	public function test_match_path_normalizes_before_exact_lookup(): void {
		$lookup = $this->matcher->build_lookup(
			array(
				'exact' => array(
					array(
						'source'      => '/old-product',
						'destination' => '/new-product',
					),
				),
			)
		);

		$result = $this->matcher->match_path( '/Old-Product/?utm=x', $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-product', $result['destination'] );
	}

	public function test_match_path_falls_back_to_alt_format_clean_to_hash(): void {
		// Rule stored as a hash route; request arrives as a clean URL.
		$lookup = $this->matcher->build_lookup(
			array(
				'exact' => array(
					array(
						'source'      => '#!/old-product',
						'destination' => '/new-product',
					),
				),
			)
		);

		$result = $this->matcher->match_path( '/old-product', $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-product', $result['destination'] );
	}

	public function test_match_path_falls_back_to_alt_format_hash_to_clean(): void {
		// Rule stored as a clean URL; request arrives as a hash route.
		$lookup = $this->matcher->build_lookup(
			array(
				'exact' => array(
					array(
						'source'      => '/old-product',
						'destination' => '/new-product',
					),
				),
			)
		);

		$result = $this->matcher->match_path( '#!/old-product', $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-product', $result['destination'] );
	}

	public function test_match_path_uses_wildcard_when_no_exact_match(): void {
		$lookup = $this->matcher->build_lookup(
			array(
				'wildcard' => array(
					array(
						'source'      => '/blog*',
						'destination' => '/news*',
					),
				),
			)
		);

		$result = $this->matcher->match_path( '/blog/my-post', $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/news/my-post', $result['destination'] );
		$this->assertTrue( $result['wildcard'] );
	}

	public function test_match_path_returns_null_when_nothing_matches(): void {
		$lookup = $this->matcher->build_lookup(
			array(
				'exact'    => array(
					array(
						'source'      => '/known',
						'destination' => '/x',
					),
				),
				'wildcard' => array(
					array(
						'source'      => '/blog/*',
						'destination' => '/news/*',
					),
				),
			)
		);

		$this->assertNull( $this->matcher->match_path( '/totally-unknown', $lookup ) );
	}
}
