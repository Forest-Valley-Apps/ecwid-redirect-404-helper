<?php
/**
 * Unit tests for the 404 log repository.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Log;

use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use Mockery;

/**
 * Covers the upsert (insert vs duplicate-key increment), the empty-path guard,
 * field clamping, cap-based pruning, and the dashboard read side (filtered
 * query/count, deletes, status updates, chunked export).
 */
final class NotFoundLogTest extends WpdbTestCase {

	public function test_record_upserts_hashed_row_and_skips_prune_on_repeat_hit(): void {
		// 2 affected rows = duplicate-key update — an existing row, no prune.
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' )->andReturn( 2 );
		$this->wpdb->shouldReceive( 'get_var' )->never();

		( new NotFoundLog() )->record( '/gone', 'https://ref.example', 'wp-page', 0 );

		$this->assertCount( 1, $this->prepared );

		$sql = $this->prepared[0]['sql'];
		$this->assertStringContainsString( 'INSERT INTO wp_fv_erh_404_log', $sql );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringContainsString( 'hit_count = hit_count + 1', $sql );

		$args = $this->prepared[0]['args'];
		$this->assertSame( md5( '/gone' ), $args[0] );
		$this->assertSame( '/gone', $args[1] );
		$this->assertSame( 'https://ref.example', $args[2] );
		$this->assertSame( 'wp-page', $args[3] );
		$this->assertSame( 0, $args[4] );
		$this->assertSame( NotFoundLog::STATUS_NEW, $args[5] );
	}

	public function test_record_skips_empty_path(): void {
		$this->wpdb->shouldReceive( 'query' )->never();

		( new NotFoundLog() )->record( '', 'https://ref.example', 'wp-page', 0 );

		$this->assertCount( 0, $this->prepared );
	}

	public function test_record_clamps_overlong_fields_and_hashes_the_clamped_path(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );

		$long_path = '/' . str_repeat( 'a', 3000 );
		( new NotFoundLog() )->record( $long_path, 'https://ref.example/' . str_repeat( 'b', 3000 ), 'wp-page', 0 );

		$args = $this->prepared[0]['args'];
		$this->assertSame( 2048, mb_strlen( $args[1] ) );
		$this->assertSame( 2048, mb_strlen( $args[2] ) );
		// The hash must key the row exactly as stored, i.e. the clamped path.
		$this->assertSame( md5( $args[1] ), $args[0] );
	}

	public function test_fresh_insert_below_cap_counts_but_does_not_delete(): void {
		// 1 affected row = fresh insert — the cap must be checked.
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with(
				Mockery::on(
					static function ( $sql ): bool {
						return false !== strpos( $sql, 'SELECT COUNT(*) FROM wp_fv_erh_404_log' );
					}
				)
			)
			->andReturn( '50' );

		( new NotFoundLog() )->record( '/gone', '', 'wp-page', 0 );

		// Only the insert was prepared — no DELETE statement.
		$this->assertCount( 1, $this->prepared );
	}

	public function test_fresh_insert_above_cap_prunes_oldest_excess_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '10005' );
		// The prune DELETE is the second prepared statement.
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_2' )->andReturn( 5 );

		( new NotFoundLog() )->record( '/gone', '', 'product', 123 );

		$this->assertCount( 2, $this->prepared );

		$delete = $this->prepared[1];
		$this->assertStringContainsString( 'DELETE FROM wp_fv_erh_404_log', $delete['sql'] );
		$this->assertStringContainsString( 'ORDER BY last_seen ASC', $delete['sql'] );
		$this->assertSame( array( 5 ), $delete['args'] );
	}

	public function test_query_applies_filters_sort_and_pagination(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $value ) {
				return $value;
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED_1', ARRAY_A )
			->andReturn( array( array( 'id' => 1 ) ) );

		$rows = ( new NotFoundLog() )->query(
			array(
				'search'         => 'shirt',
				'classification' => 'product',
				'status'         => 'new',
				'orderby'        => 'hit_count',
				'order'          => 'asc',
				'per_page'       => 5,
				'paged'          => 3,
			)
		);

		$this->assertCount( 1, $rows );

		$sql = $this->prepared[0]['sql'];
		$this->assertStringContainsString( 'WHERE url_path LIKE %s AND classification = %s AND status = %s', $sql );
		$this->assertStringContainsString( 'ORDER BY hit_count ASC', $sql );
		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $sql );

		// Page 3 at 5 per page = offset 10.
		$this->assertSame( array( array( '%shirt%', 'product', 'new', 5, 10 ) ), $this->prepared[0]['args'] );
	}

	public function test_query_whitelists_orderby_against_injection(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		( new NotFoundLog() )->query( array( 'orderby' => 'evil; DROP TABLE x' ) );

		$this->assertStringContainsString( 'ORDER BY last_seen DESC', $this->prepared[0]['sql'] );
		$this->assertStringNotContainsString( 'DROP', $this->prepared[0]['sql'] );
	}

	public function test_count_without_filters_skips_prepare(): void {
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::pattern( '/^SELECT COUNT\(\*\) FROM wp_fv_erh_404_log\s*$/' ) )
			->andReturn( '42' );

		$this->assertSame( 42, ( new NotFoundLog() )->count() );
		$this->assertCount( 0, $this->prepared );
	}

	public function test_count_with_filters_prepares_the_where_clause(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED_1' )->andReturn( '7' );

		$this->assertSame( 7, ( new NotFoundLog() )->count( array( 'status' => 'new' ) ) );

		$this->assertStringContainsString( 'WHERE status = %s', $this->prepared[0]['sql'] );
		$this->assertSame( array( array( 'new' ) ), $this->prepared[0]['args'] );
	}

	public function test_delete_builds_an_in_clause_over_int_ids(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_1' );

		( new NotFoundLog() )->delete( array( 4, '9', 0 ) );

		$this->assertStringContainsString( 'DELETE FROM wp_fv_erh_404_log WHERE id IN (%d,%d)', $this->prepared[0]['sql'] );
		$this->assertSame( array( array( 4, 9 ) ), $this->prepared[0]['args'] );
	}

	public function test_delete_with_no_valid_ids_is_a_noop(): void {
		$this->wpdb->shouldReceive( 'query' )->never();

		( new NotFoundLog() )->delete( array( 0 ) );

		$this->assertCount( 0, $this->prepared );
	}

	public function test_mark_redirected_updates_the_row_by_path_hash(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_fv_erh_404_log',
				array( 'status' => NotFoundLog::STATUS_REDIRECTED ),
				array( 'url_hash' => md5( '/gone' ) ),
				array( '%s' ),
				array( '%s' )
			);

		( new NotFoundLog() )->mark_redirected( '/gone' );
	}

	public function test_all_chunked_pages_through_the_whole_table(): void {
		// First chunk full (2 of 2), second short (1) — iteration must stop there.
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED_1', ARRAY_A )
			->andReturn( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED_2', ARRAY_A )
			->andReturn( array( array( 'id' => 3 ) ) );

		$ids = array();
		foreach ( ( new NotFoundLog() )->all_chunked( 2 ) as $row ) {
			$ids[] = $row['id'];
		}

		$this->assertSame( array( 1, 2, 3 ), $ids );

		// LIMIT/OFFSET advanced by the chunk size.
		$this->assertSame( array( 2, 0 ), $this->prepared[0]['args'] );
		$this->assertSame( array( 2, 2 ), $this->prepared[1]['args'] );
	}
}
