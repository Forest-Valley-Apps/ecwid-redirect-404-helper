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
 * Covers create validation (source/destination/duplicate/loop), the
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

		// Default: no existing rule, inserts succeed.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
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
