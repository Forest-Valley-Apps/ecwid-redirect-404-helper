<?php
/**
 * Unit tests for the front-end manual-301 redirector.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Redirect;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Redirect\Redirector;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use RuntimeException;

/**
 * Exercises the full redirect path through the real store, matcher and
 * request-path normalizer — only the WordPress boundary (query flags,
 * superglobals, $wpdb, wp_redirect) is stubbed. The injected terminator
 * throws instead of exiting so the tests survive a served redirect.
 */
final class RedirectorTest extends WpdbTestCase {

	protected function setUp(): void {
		parent::setUp();

		// record_hit()'s UPDATE; the tests assert via the captured prepare() calls.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * A redirector whose terminator throws instead of exiting.
	 *
	 * @return Redirector
	 */
	private function redirector(): Redirector {
		return new Redirector(
			null,
			null,
			static function (): void {
				throw new RuntimeException( 'terminated' );
			}
		);
	}

	/**
	 * Serve the active-rules query with fixed rows.
	 *
	 * @param array<int,array<string,string>> $rows Redirect rule rows.
	 * @return void
	 */
	private function with_rules( array $rows ): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
	}

	public function test_register_hooks_before_canonical_redirect_and_capture(): void {
		Actions\expectAdded( 'template_redirect' )
			->once()
			->whenHappen(
				static function ( $callback, $priority ): void {
					self::assertSame( 5, $priority );
				}
			);

		( new Redirector() )->register();
	}

	public function test_non_404_request_does_no_work_at_all(): void {
		Functions\when( 'is_404' )->justReturn( false );
		$_SERVER['REQUEST_URI'] = '/healthy-page';

		$this->wpdb->shouldReceive( 'get_results' )->never();
		Functions\expect( 'wp_redirect' )->never();

		$this->redirector()->maybe_redirect();
	}

	public function test_exact_rule_301s_the_normalized_request_and_records_the_hit(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/Old-Page/?utm_source=mail';

		$this->with_rules(
			array(
				array(
					'source'      => '/old-page',
					'destination' => '/new-page',
					'is_wildcard' => '0',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( '/new-page', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}

		// The hit was recorded against the matched rule's source hash.
		$this->assertStringContainsString( 'hit_count = hit_count + 1', $this->prepared[0]['sql'] );
		$this->assertSame( md5( '/old-page' ), $this->prepared[0]['args'][1] );
	}

	public function test_wildcard_rule_forwards_the_matched_suffix(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/old-blog/some-post';

		$this->with_rules(
			array(
				array(
					'source'      => '/old-blog*',
					'destination' => '/blog*',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( '/blog/some-post', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_slash_star_wildcard_destination_is_collapsed_to_a_single_slash(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/old-blog/some-post';

		// The `/prefix/*` form makes the raw matcher emit `/blog//some-post`
		// (pinned parent quirk) — the redirector must serve the clean path.
		$this->with_rules(
			array(
				array(
					'source'      => '/old-blog/*',
					'destination' => '/blog/*',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( '/blog/some-post', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_wildcard_matched_mid_path_reapplies_the_mount_prefix(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/shop/old-category/widget';

		// The wildcard prefix matches mid-path (ported parity); the matcher
		// returns the leading `/shop` as base_path, and — like the parent's
		// executeRedirect() — it must be re-prepended to the site-relative
		// destination, or the store's mount prefix is lost in the 301.
		$this->with_rules(
			array(
				array(
					'source'      => '/old-category/*',
					'destination' => '/new-category/*',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( '/shop/new-category/widget', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}

		$this->assertSame( md5( '/old-category/*' ), $this->prepared[0]['args'][1] );
	}

	public function test_base_path_is_not_prepended_to_absolute_destinations(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/shop/old-category/widget';

		$this->with_rules(
			array(
				array(
					'source'      => '/old-category/*',
					'destination' => 'https://example.com/cat/*',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( 'https://example.com/cat/widget', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_destination_equal_to_the_request_path_is_not_served(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/old/landing';

		// `/old/landing` 404s and matches `/old/*` — whose destination IS
		// `/old/landing`. Serving that 301 would bounce the browser straight
		// back here; the backstop must fall through to the plain 404 render.
		$this->with_rules(
			array(
				array(
					'source'      => '/old/*',
					'destination' => '/old/landing',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )->never();

		$this->redirector()->maybe_redirect();

		// Bailed before the hit was recorded.
		$this->assertCount( 0, $this->prepared );
	}

	public function test_destination_rematching_the_same_rule_is_not_served(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/docs/page';

		// A self-prefixing wildcard (`/docs/*` → `/docs/v2/*`) resolves to
		// `/docs/v2/page`, which the same rule matches again — an unbounded
		// `/docs/v2/v2/…` chain if served. Save-time validation rejects this
		// shape now; the runtime backstop covers rows that predate it.
		$this->with_rules(
			array(
				array(
					'source'      => '/docs/*',
					'destination' => '/docs/v2/*',
					'is_wildcard' => '1',
				),
			)
		);

		Functions\expect( 'wp_redirect' )->never();

		$this->redirector()->maybe_redirect();

		$this->assertCount( 0, $this->prepared );
	}

	public function test_chain_into_a_different_rule_still_serves_the_first_hop(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/a';

		// /a → /b → /c is a chain, not a loop: each hop is a separate request
		// the browser follows, so the backstop must not over-bail.
		$this->with_rules(
			array(
				array(
					'source'      => '/a',
					'destination' => '/b',
					'is_wildcard' => '0',
				),
				array(
					'source'      => '/b',
					'destination' => '/c',
					'is_wildcard' => '0',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( '/b', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_absolute_destination_keeps_its_scheme_slashes(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/moved';

		$this->with_rules(
			array(
				array(
					'source'      => '/moved',
					'destination' => 'https://example.com/landed',
					'is_wildcard' => '0',
				),
			)
		);

		Functions\expect( 'wp_redirect' )
			->once()
			->with( 'https://example.com/landed', 301, 'Redirect & 404 Helper for Ecwid' );

		try {
			$this->redirector()->maybe_redirect();
			$this->fail( 'A served redirect must terminate the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_unmatched_404_falls_through_to_the_404_render(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/never-mapped';

		$this->with_rules( array() );

		Functions\expect( 'wp_redirect' )->never();

		$this->redirector()->maybe_redirect();

		$this->assertCount( 0, $this->prepared );
	}
}
