<?php
/**
 * Unit tests for the manual-redirect repository.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Redirect;

use FV\WPEcwidRedirectHelper\Redirect\RedirectStore;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;

/**
 * Covers create validation (source/destination/duplicate/loop/cycle), the
 * normalize-then-hash storage contract, deletes, the active toggle, hit
 * recording, and the exact/wildcard lookup split.
 */
final class RedirectStoreTest extends WpdbTestCase {

	/**
	 * Arguments captured from the last $wpdb->insert() call.
	 *
	 * @var array<string,mixed>
	 */
	private array $inserted = array();

	protected function setUp(): void {
		parent::setUp();

		$this->inserted = array();

		// Default: no existing rule, inserts succeed, and the loop check's
		// lookup_config() read sees an empty active set.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$this->wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				function ( $table, $data ) {
					$this->inserted = array( 'table' => $table ) + $data;

					return 1;
				}
			)
			->byDefault();
	}

	public function test_add_normalizes_source_and_stores_hash(): void {
		$result = ( new RedirectStore() )->add( ' /Old-Page/ ', '/new-page' );

		$this->assertSame( RedirectStore::ADDED, $result );
		$this->assertSame( 'wp_fv_erh_redirects', $this->inserted['table'] );
		$this->assertSame( '/old-page', $this->inserted['source'] );
		$this->assertSame( md5( '/old-page' ), $this->inserted['source_hash'] );
		$this->assertSame( '/new-page', $this->inserted['destination'] );
		$this->assertSame( 0, $this->inserted['is_wildcard'] );
		$this->assertSame( 1, $this->inserted['active'] );
	}

	public function test_add_flags_wildcard_sources(): void {
		$result = ( new RedirectStore() )->add( '/old-blog/*', '/blog/*' );

		$this->assertSame( RedirectStore::ADDED, $result );
		$this->assertSame( 1, $this->inserted['is_wildcard'] );
	}

	public function test_add_accepts_absolute_http_destination(): void {
		$result = ( new RedirectStore() )->add( '/moved', 'https://example.com/landed' );

		$this->assertSame( RedirectStore::ADDED, $result );
		$this->assertSame( 'https://example.com/landed', $this->inserted['destination'] );
	}

	public function test_add_rejects_root_bare_star_and_empty_sources(): void {
		$store = new RedirectStore();

		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::INVALID_SOURCE, $store->add( '/', '/x' ) );
		$this->assertSame( RedirectStore::INVALID_SOURCE, $store->add( '*', '/x' ) );
		$this->assertSame( RedirectStore::INVALID_SOURCE, $store->add( '', '/x' ) );
	}

	public function test_add_rejects_non_http_destinations_and_loops(): void {
		$store = new RedirectStore();

		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/a', 'javascript:alert(1)' ) );
		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/a', '' ) );
		// Protocol-relative sneaks an external host in as a "path".
		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/a', '//evil.example/x' ) );
		// Source == destination after normalization is a redirect loop.
		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/a', '/A/' ) );
	}

	public function test_add_rejects_a_two_rule_cycle(): void {
		// /a → /b is live; adding /b → /a would 301 visitors back and forth
		// until the browser gives up (ERR_TOO_MANY_REDIRECTS).
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'source'      => '/a',
						'destination' => '/b',
						'is_wildcard' => '0',
					),
				)
			);
		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::INVALID_DESTINATION, ( new RedirectStore() )->add( '/b', '/a' ) );
	}

	public function test_add_rejects_a_wildcard_destination_inside_its_own_source_scope(): void {
		$store = new RedirectStore();

		$this->wpdb->shouldReceive( 'insert' )->never();
		// The self-scope check needs no DB read — it fails before the
		// existing-rules probe.
		$this->wpdb->shouldReceive( 'get_results' )->never();

		// `/docs/v2/*` re-matches `/docs/*` ⇒ /docs/v2/v2/… unbounded chain.
		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/docs/*', '/docs/v2/*' ) );
		// `/old/landing` re-matches `/old/*` ⇒ self-301 if the landing 404s.
		$this->assertSame( RedirectStore::INVALID_DESTINATION, $store->add( '/old/*', '/old/landing' ) );
	}

	public function test_add_rejects_a_transitive_three_rule_cycle(): void {
		// /a → /b and /b → /c are live; adding /c → /a closes a three-rule
		// cycle /c → /a → /b → /c. No two sources are string-equal to the new
		// destination, so only the full chain walk (parent detectLoop parity)
		// catches it.
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
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
		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::INVALID_DESTINATION, ( new RedirectStore() )->add( '/c', '/a' ) );
	}

	public function test_add_allows_a_two_rule_chain_that_does_not_close(): void {
		// /a → /b is live; adding /b → /c extends the chain but never returns to
		// /a, so the walk terminates — a chain, not a loop (save must succeed).
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'source'      => '/a',
						'destination' => '/b',
						'is_wildcard' => '0',
					),
				)
			);

		$this->assertSame( RedirectStore::ADDED, ( new RedirectStore() )->add( '/b', '/c' ) );
	}

	public function test_add_rejects_an_exact_rule_cycling_back_through_a_new_wildcard(): void {
		// /b → /a/x is live; adding /a/* → /b closes the cycle
		// /a/x → /b → /a/x even though no two sources are string-equal.
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'source'      => '/b',
						'destination' => '/a/x',
						'is_wildcard' => '0',
					),
				)
			);
		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::INVALID_DESTINATION, ( new RedirectStore() )->add( '/a/*', '/b' ) );
	}

	public function test_add_allows_a_chain_into_a_different_rule(): void {
		// /a → /b is live; adding /c → /a is a chain (/c → /a → /b), not a
		// cycle — the parent only warns on chains, so the save must succeed.
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'source'      => '/a',
						'destination' => '/b',
						'is_wildcard' => '0',
					),
				)
			);

		$this->assertSame( RedirectStore::ADDED, ( new RedirectStore() )->add( '/c', '/a' ) );
	}

	public function test_add_allows_a_wildcard_move_between_distinct_prefixes(): void {
		$result = ( new RedirectStore() )->add( '/old-category/*', '/new-category/*' );

		$this->assertSame( RedirectStore::ADDED, $result );
		$this->assertSame( 1, $this->inserted['is_wildcard'] );
	}

	public function test_add_rejects_duplicate_source(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( array( 'id' => 7 ) );
		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( RedirectStore::DUPLICATE, ( new RedirectStore() )->add( '/old-page', '/new-page' ) );
	}

	public function test_delete_builds_an_in_clause_over_int_ids(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' );

		( new RedirectStore() )->delete( array( 3, '8', 0 ) );

		$this->assertStringContainsString( 'DELETE FROM wp_fv_erh_redirects WHERE id IN (%d,%d)', $this->prepared[0]['sql'] );
		$this->assertSame( array( array( 3, 8 ) ), $this->prepared[0]['args'] );
	}

	public function test_delete_with_no_valid_ids_is_a_noop(): void {
		$this->wpdb->shouldReceive( 'query' )->never();

		( new RedirectStore() )->delete( array( 0, 'abc' ) );

		$this->assertCount( 0, $this->prepared );
	}

	public function test_set_active_updates_the_flag(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_fv_erh_redirects',
				array( 'active' => 0 ),
				array( 'id' => 5 ),
				array( '%d' ),
				array( '%d' )
			);

		( new RedirectStore() )->set_active( 5, false );
	}

	public function test_record_hit_bumps_count_keyed_by_source_hash(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' );

		( new RedirectStore() )->record_hit( '/old-page' );

		$sql = $this->prepared[0]['sql'];
		$this->assertStringContainsString( 'UPDATE wp_fv_erh_redirects', $sql );
		$this->assertStringContainsString( 'hit_count = hit_count + 1', $sql );
		$this->assertSame( md5( '/old-page' ), $this->prepared[0]['args'][1] );
	}

	public function test_lookup_config_splits_active_rules_by_wildcard_flag(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'source'      => '/old-page',
						'destination' => '/new-page',
						'is_wildcard' => '0',
					),
					array(
						'source'      => '/old-blog/*',
						'destination' => '/blog/*',
						'is_wildcard' => '1',
					),
				)
			);

		$config = ( new RedirectStore() )->lookup_config();

		$this->assertCount( 1, $config['exact'] );
		$this->assertCount( 1, $config['wildcard'] );
		$this->assertSame( '/old-page', $config['exact'][0]['source'] );
		$this->assertSame( '/old-blog/*', $config['wildcard'][0]['source'] );
	}
}
