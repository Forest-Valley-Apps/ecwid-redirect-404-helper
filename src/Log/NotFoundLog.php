<?php
/**
 * Repository for captured 404s.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Writes captured 404s to the log table.
 *
 * Each distinct (normalized) path is one row, keyed by its md5 in `url_hash`;
 * a repeat hit increments `hit_count` and bumps `last_seen` in the same
 * statement (`INSERT … ON DUPLICATE KEY UPDATE`), so concurrent hits on a new
 * path can never race into duplicate rows.
 *
 * Growth is capped: when a *new* row lands, the oldest rows (by `last_seen`)
 * beyond the cap are pruned. The count check runs only on fresh inserts —
 * repeat hits, the overwhelmingly common case, cost exactly one query.
 *
 * Direct queries against our own custom table are the point of this class, so
 * the WordPress.DB direct-query/caching sniffs are disabled file-wide instead
 * of per-line.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class NotFoundLog {

	/**
	 * Row status: captured, not yet handled by the merchant.
	 *
	 * (S5 adds the handled states; capture only ever writes this one.)
	 *
	 * @var string
	 */
	public const STATUS_NEW = 'new';

	/**
	 * Default maximum number of log rows kept.
	 *
	 * Mirrors the hosted backend's own 10k cap on reported 404s.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_ROWS = 10000;

	/**
	 * Max length stored for a path/referrer.
	 *
	 * Deliberately mirrors `BackendClient::MAX_REPORT_FIELD` (the backend's
	 * report cap) so the locally-stored path and the reported one are always
	 * the same string — but it is a separate constant on purpose: this one
	 * bounds *our own column*, that one bounds the *HTTP payload*.
	 *
	 * @var int
	 */
	private const MAX_FIELD = 2048;

	/**
	 * Record a 404 hit: insert a new row or increment the existing one.
	 *
	 * Failures are deliberately swallowed: this runs while a 404 page renders,
	 * and a logging error must never break the response. A failed INSERT
	 * (returning false) simply skips the row and the prune check.
	 *
	 * @param string $path           Normalized request path (non-empty).
	 * @param string $referrer       Referrer URL ('' when absent).
	 * @param string $classification One of the UrlClassifier TYPE_* values.
	 * @param int    $entity_id      Ecwid entity id (0 for a WP page).
	 * @return void
	 */
	public function record( string $path, string $referrer, string $classification, int $entity_id ): void {
		global $wpdb;

		if ( '' === $path ) {
			return;
		}

		$path     = $this->clamp( $path );
		$referrer = $this->clamp( $referrer );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$table    = Schema::log_table();

		// A repeat hit keeps the row's existing referrer unless the new hit
		// carries one — the most recent *known* origin is the useful one.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::log_table().
				"INSERT INTO {$table}
					(url_hash, url_path, referrer, classification, entity_id, status, hit_count, first_seen, last_seen)
				VALUES (%s, %s, %s, %s, %d, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE
					hit_count = hit_count + 1,
					last_seen = VALUES(last_seen),
					referrer  = IF(VALUES(referrer) = '', referrer, VALUES(referrer))",
				md5( $path ),
				$path,
				$referrer,
				$classification,
				$entity_id,
				self::STATUS_NEW,
				$now,
				$now
			)
		);

		// MySQL reports 1 affected row for an insert, 2 for a duplicate-key
		// update — only a genuinely new row can push the table over the cap.
		if ( 1 === $result ) {
			$this->prune();
		}
	}

	/**
	 * Delete the oldest rows beyond the cap.
	 *
	 * @return void
	 */
	private function prune(): void {
		global $wpdb;

		/**
		 * Filter the maximum number of 404 log rows kept.
		 *
		 * @param int $max_rows Default cap (10000).
		 */
		$max = max( 100, (int) apply_filters( 'fv_erh_404_log_max_rows', self::DEFAULT_MAX_ROWS ) );

		$table = Schema::log_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::log_table().
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count <= $max ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::log_table().
				"DELETE FROM {$table} ORDER BY last_seen ASC, id ASC LIMIT %d",
				$count - $max
			)
		);
	}

	/**
	 * Clamp a stored field to the shared maximum length.
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	private function clamp( string $value ): string {
		if ( mb_strlen( $value ) <= self::MAX_FIELD ) {
			return $value;
		}

		return (string) mb_substr( $value, 0, self::MAX_FIELD );
	}
}
