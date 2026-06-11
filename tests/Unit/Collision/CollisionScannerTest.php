<?php
/**
 * Unit tests for the false-404 slug collision scanner.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Collision;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Collision\CollisionScanner;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use Mockery;

/**
 * Covers the slug pattern, the scan query, caching, invalidation, the
 * deferred background scan, and the fingerprinted dismissal.
 */
final class CollisionScannerTest extends WpdbTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb->posts = 'wp_posts';

		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'attachment' => 'attachment',
			)
		);
	}

	private function scanner(): CollisionScanner {
		return new CollisionScanner();
	}

	public function test_slug_collides_matches_ecwid_markers_case_sensitively(): void {
		$scanner = $this->scanner();

		$this->assertTrue( $scanner->slug_collides( 'about-us-c123' ) );
		$this->assertTrue( $scanner->slug_collides( 'team-p9' ) );
		$this->assertFalse( $scanner->slug_collides( 'about-us' ) );
		$this->assertFalse( $scanner->slug_collides( 'plan-c' ) );
		$this->assertFalse( $scanner->slug_collides( 'c123' ), 'marker requires the leading dash' );
		$this->assertFalse( $scanner->slug_collides( 'about-us-c123-more' ), 'marker must end the slug' );
		// Ecwid only generates lowercase markers; uppercase is an ordinary slug.
		$this->assertFalse( $scanner->slug_collides( 'about-us-C123' ) );
	}

	public function test_scan_queries_published_public_types_without_attachments(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->scanner()->get_collisions();

		$this->assertCount( 1, $this->prepared );
		$sql  = $this->prepared[0]['sql'];
		$args = $this->prepared[0]['args'][0];

		$this->assertStringContainsString( "post_status = 'publish'", $sql );
		$this->assertStringContainsString( 'REGEXP %s', $sql );
		$this->assertStringContainsString( 'IN (%s,%s)', $sql, 'two public types, attachments excluded' );
		$this->assertSame( array( 'post', 'page', '-(p|c)[0-9]+$', 100 ), $args );
	}

	public function test_scan_filters_uppercase_matches_and_caches_result(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'ID'         => '5',
					'post_name'  => 'about-us-c123',
					'post_title' => 'About us',
					'post_type'  => 'page',
				),
				array(
					// MySQL REGEXP is case-insensitive; this must be dropped.
					'ID'         => '6',
					'post_name'  => 'team-P9',
					'post_title' => 'Team',
					'post_type'  => 'page',
				),
			)
		);

		$captured = array();
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				static function ( $key, $value, $ttl ) use ( &$captured ) {
					$captured = array(
						'key'   => $key,
						'value' => $value,
						'ttl'   => $ttl,
					);
					return true;
				}
			);

		$collisions = $this->scanner()->get_collisions();

		$this->assertSame(
			array(
				array(
					'id'    => 5,
					'slug'  => 'about-us-c123',
					'title' => 'About us',
					'type'  => 'page',
				),
			),
			$collisions
		);
		$this->assertSame( CollisionScanner::TRANSIENT, $captured['key'] );
		$this->assertSame( $collisions, $captured['value'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $captured['ttl'] );
	}

	public function test_cached_result_short_circuits_the_query(): void {
		$cached = array(
			array(
				'id'    => 5,
				'slug'  => 'about-us-c123',
				'title' => 'About us',
				'type'  => 'page',
			),
		);
		Functions\when( 'get_transient' )->justReturn( $cached );

		$this->wpdb->shouldReceive( 'get_results' )->never();

		$this->assertSame( $cached, $this->scanner()->get_collisions() );
	}

	public function test_force_rescan_bypasses_cache(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\when( 'set_transient' )->justReturn( true );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), $this->scanner()->get_collisions( true ) );
	}

	public function test_register_wires_the_invalidation_and_deferred_scan_hooks(): void {
		Actions\expectAdded( 'save_post' )->once();
		Actions\expectAdded( CollisionScanner::SCAN_EVENT )->once();

		$this->scanner()->register();
	}

	public function test_schedule_scan_queues_a_single_event(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CollisionScanner::SCAN_EVENT )
			->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with( Mockery::type( 'int' ), CollisionScanner::SCAN_EVENT );

		$this->scanner()->schedule_scan();
	}

	public function test_schedule_scan_never_double_schedules(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CollisionScanner::SCAN_EVENT )
			->andReturn( time() + 10 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->scanner()->schedule_scan();
	}

	public function test_run_scheduled_scan_refreshes_a_cold_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->scanner()->run_scheduled_scan();

		$this->assertCount( 1, $this->prepared, 'a cold cache must run the scan query' );
	}

	public function test_run_scheduled_scan_is_a_noop_on_a_warm_cache(): void {
		// An empty array is a valid warm result ("no collisions").
		Functions\when( 'get_transient' )->justReturn( array() );

		$this->wpdb->shouldReceive( 'get_results' )->never();

		$this->scanner()->run_scheduled_scan();

		$this->assertCount( 0, $this->prepared );
	}

	public function test_unschedule_scan_clears_the_pending_event(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( CollisionScanner::SCAN_EVENT );

		CollisionScanner::unschedule_scan();
	}

	public function test_cached_collisions_never_scans(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->wpdb->shouldReceive( 'get_results' )->never();

		$this->assertNull( $this->scanner()->cached_collisions() );
	}

	public function test_maybe_invalidate_on_colliding_slug(): void {
		Functions\expect( 'delete_transient' )->once()->with( CollisionScanner::TRANSIENT );

		$post            = new \stdClass();
		$post->post_name = 'sale-p77';

		$this->scanner()->maybe_invalidate( 10, $post );
	}

	public function test_maybe_invalidate_when_post_was_in_cached_set(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				array(
					'id'    => 10,
					'slug'  => 'old-c5',
					'title' => 'Old',
					'type'  => 'page',
				),
			)
		);
		Functions\expect( 'delete_transient' )->once()->with( CollisionScanner::TRANSIENT );

		$post            = new \stdClass();
		$post->post_name = 'renamed-clean-slug';

		$this->scanner()->maybe_invalidate( 10, $post );
	}

	public function test_maybe_invalidate_ignores_unrelated_posts(): void {
		Functions\when( 'get_transient' )->justReturn( array() );
		Functions\expect( 'delete_transient' )->never();

		$post            = new \stdClass();
		$post->post_name = 'ordinary-page';

		$this->scanner()->maybe_invalidate( 99, $post );
	}

	public function test_dismissal_is_keyed_to_the_collision_set(): void {
		$set_a = array(
			array(
				'id'    => 5,
				'slug'  => 'a-c1',
				'title' => 'A',
				'type'  => 'page',
			),
		);
		$set_b = array_merge(
			$set_a,
			array(
				array(
					'id'    => 6,
					'slug'  => 'b-p2',
					'title' => 'B',
					'type'  => 'page',
				),
			)
		);

		$scanner = $this->scanner();

		$stored = '';
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);

		$scanner->dismiss( $set_a );

		$this->assertTrue( $scanner->is_dismissed( $set_a ) );
		// A new collision changes the fingerprint and resurfaces the notice.
		$this->assertFalse( $scanner->is_dismissed( $set_b ) );
	}
}
