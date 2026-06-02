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
 * field clamping, and cap-based pruning.
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
}
