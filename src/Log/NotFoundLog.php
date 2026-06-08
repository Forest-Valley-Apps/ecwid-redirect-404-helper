<?php
/**
 * Repository for captured 404s.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Log;

use FV\WPEcwidRedirectHelper\Url\UrlClassifier;

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
 * the WordPress.DB direct-query/caching sniffs are disabled file-wide. The
 * prepared-SQL sniffs are disabled file-wide too: every query interpolates the
 * table name from Schema::log_table() (a trusted constant, never user input)
 * and builds its %-placeholders alongside their args, which the sniffs cannot
 * see through.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders
 */
final class NotFoundLog {

	/**
	 * Row status: captured, not yet handled by the merchant.
	 *
	 * @var string
	 */
	public const STATUS_NEW = 'new';

	/**
	 * Row status: the merchant created a redirect for this path.
	 *
	 * @var string
	 */
	public const STATUS_REDIRECTED = 'redirected';

	/**
	 * Sortable dashboard columns mapped to their SQL column.
	 *
	 * The whitelist is the injection guard: anything not listed here falls
	 * back to `last_seen`.
	 *
	 * @var array<string,string>
	 */
	private const SORTABLE = array(
		'url_path'       => 'url_path',
		'classification' => 'classification',
		'hit_count'      => 'hit_count',
		'first_seen'     => 'first_seen',
		'last_seen'      => 'last_seen',
	);

	/**
	 * Default maximum number of log rows kept.
	 *
	 * Mirrors the hosted backend's own 10k cap on reported 404s.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_ROWS = 10000;

	/**
	 * Shared WHERE fragment selecting Ecwid entities with a missing or stale
	 * verdict. Placeholders, in order: product type, category type, the
	 * stale-before cutoff. Used by {@see self::entities_needing_verdict()} and
	 * {@see self::count_entities_needing_verdict()} so the two can never drift.
	 *
	 * @var string
	 */
	private const NEEDS_VERDICT_WHERE = "WHERE classification IN (%s, %s)
					AND entity_id > 0
					AND (verdict = '' OR verdict_checked_at IS NULL OR verdict_checked_at < %s)";

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
	 * Query log rows for the dashboard.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $search         Substring to match in `url_path`.
	 *     @type string $classification Filter to one classification.
	 *     @type string $status         Filter to one status.
	 *     @type string $verdict        Filter to one catalog verdict.
	 *     @type string $orderby        One of the sortable columns (default `last_seen`).
	 *     @type string $order          'asc' or 'desc' (default 'desc').
	 *     @type int    $per_page       Page size (default 20).
	 *     @type int    $paged          1-based page number (default 1).
	 * }
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		list( $where_sql, $where_args ) = $this->build_where( $args );

		$orderby = self::SORTABLE[ $args['orderby'] ?? '' ] ?? 'last_seen';
		$order   = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per_page;

		$table = Schema::log_table();

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the WHERE placeholders and their args are built together; the sniff cannot count them.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema::log_table(); orderby/order whitelisted above.
				"SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				array_merge( $where_args, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count log rows matching the same filters as {@see self::query()}.
	 *
	 * @param array $args Filter arguments (search/classification/status).
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		list( $where_sql, $where_args ) = $this->build_where( $args );

		$table = Schema::log_table();
		$sql   = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		if ( array() !== $where_args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from Schema::log_table(); placeholders built alongside their args.
			$sql = $wpdb->prepare( $sql, $where_args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above when it carries placeholders.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Delete log rows by id.
	 *
	 * @param array<int,int> $ids Row ids.
	 * @return void
	 */
	public function delete( array $ids ): void {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() === $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = Schema::log_table();

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- table from Schema::log_table(); %d placeholders generated to count, which the sniff cannot see.
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);
	}

	/**
	 * Mark the log row for a path as redirected.
	 *
	 * No-op when the path was never logged (e.g. a redirect created from
	 * scratch or a wildcard pattern).
	 *
	 * @param string $path Normalized path (a rule's exact source).
	 * @return void
	 */
	public function mark_redirected( string $path ): void {
		global $wpdb;

		$wpdb->update(
			Schema::log_table(),
			array( 'status' => self::STATUS_REDIRECTED ),
			array( 'url_hash' => md5( $path ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Distinct Ecwid entities whose verdict is missing or stale, neediest first.
	 *
	 * Groups by (classification, entity_id) because several logged paths can
	 * point at the same entity — the verdict checker spends one catalog lookup
	 * per entity, not per row. Entities with any unchecked row come first, then
	 * the stalest.
	 *
	 * @param string $stale_before Re-check verdicts older than this GMT datetime.
	 * @param int    $limit        Maximum number of entities returned.
	 * @return array<int,array{classification:string,entity_id:int}>
	 */
	public function entities_needing_verdict( string $stale_before, int $limit ): array {
		global $wpdb;

		$table = Schema::log_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT classification, entity_id FROM {$table}
				" . self::NEEDS_VERDICT_WHERE . "
				GROUP BY classification, entity_id
				ORDER BY MAX(verdict = '') DESC, MIN(verdict_checked_at) ASC
				LIMIT %d",
				UrlClassifier::TYPE_PRODUCT,
				UrlClassifier::TYPE_CATEGORY,
				$stale_before,
				max( 1, $limit )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'classification' => (string) $row['classification'],
					'entity_id'      => (int) $row['entity_id'],
				);
			},
			$rows
		);
	}

	/**
	 * Count the distinct Ecwid entities whose verdict is missing or stale.
	 *
	 * @param string $stale_before Re-check verdicts older than this GMT datetime.
	 * @return int
	 */
	public function count_entities_needing_verdict( string $stale_before ): int {
		global $wpdb;

		$table = Schema::log_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT classification, entity_id) FROM {$table}
				" . self::NEEDS_VERDICT_WHERE,
				UrlClassifier::TYPE_PRODUCT,
				UrlClassifier::TYPE_CATEGORY,
				$stale_before
			)
		);
	}

	/**
	 * Record a catalog verdict on every log row pointing at an entity.
	 *
	 * @param string $classification Entity classification (product/category).
	 * @param int    $entity_id      Ecwid entity id.
	 * @param string $verdict        One of the VerdictChecker VERDICT_* values.
	 * @param string $checked_at     GMT datetime of the check.
	 * @return void
	 */
	public function set_verdict( string $classification, int $entity_id, string $verdict, string $checked_at ): void {
		global $wpdb;

		$wpdb->update(
			Schema::log_table(),
			array(
				'verdict'            => $verdict,
				'verdict_checked_at' => $checked_at,
			),
			array(
				'classification' => $classification,
				'entity_id'      => $entity_id,
			),
			array( '%s', '%s' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Iterate every log row in chunks, newest first, for the CSV export.
	 *
	 * Keeps memory bounded at the chunk size instead of loading up to the
	 * 10k-row cap at once.
	 *
	 * @param int $chunk_size Rows per SELECT.
	 * @return \Generator<array<string,mixed>>
	 */
	public function all_chunked( int $chunk_size = 500 ): \Generator {
		global $wpdb;

		$chunk_size = max( 1, $chunk_size );
		$table      = Schema::log_table();
		$offset     = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::log_table().
					"SELECT * FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				),
				ARRAY_A
			);

			$rows    = is_array( $rows ) ? $rows : array();
			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				yield $row;
			}

			$offset += $chunk_size;
		} while ( $fetched === $chunk_size );
	}

	/**
	 * Build the WHERE clause + prepare args for the dashboard filters.
	 *
	 * @param array $args Filter arguments (search/classification/status).
	 * @return array{0:string,1:array<int,mixed>} The SQL ('' when unfiltered) and its args.
	 */
	private function build_where( array $args ): array {
		global $wpdb;

		$clauses    = array();
		$where_args = array();

		if ( '' !== (string) ( $args['search'] ?? '' ) ) {
			$clauses[]    = 'url_path LIKE %s';
			$where_args[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		if ( '' !== (string) ( $args['classification'] ?? '' ) ) {
			$clauses[]    = 'classification = %s';
			$where_args[] = (string) $args['classification'];
		}

		if ( '' !== (string) ( $args['status'] ?? '' ) ) {
			$clauses[]    = 'status = %s';
			$where_args[] = (string) $args['status'];
		}

		if ( '' !== (string) ( $args['verdict'] ?? '' ) ) {
			$clauses[]    = 'verdict = %s';
			$where_args[] = (string) $args['verdict'];
		}

		if ( array() === $clauses ) {
			return array( '', array() );
		}

		return array( 'WHERE ' . implode( ' AND ', $clauses ), $where_args );
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
