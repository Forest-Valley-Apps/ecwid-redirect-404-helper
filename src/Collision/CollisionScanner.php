<?php
/**
 * False-404 slug collision scanner.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Collision;

defined( 'ABSPATH' ) || exit;

/**
 * Finds WordPress content whose slug ends in Ecwid's `-p<id>` / `-c<id>`
 * marker — the pattern the official Ecwid plugin's URL matcher hijacks and
 * tries to render as a store page, manufacturing a 404 *inside* the store
 * widget even though the WP page itself is perfectly fine.
 *
 * Those manufactured 404s happen client-side on an existing WP page, so they
 * never reach the server-side 404 log — this scanner is the proactive
 * counterpart that warns before visitors hit them.
 *
 * The scan is one REGEXP query over published public content, cached in a
 * transient and never run on ordinary admin loads: it refreshes when the
 * dashboard panel renders on a cold cache, on demand (Rescan), via cron, and
 * is invalidated by relevant `save_post` events.
 *
 * Dismissal of the companion admin notice is keyed to a fingerprint of the
 * colliding post ids, so a *new* collision resurfaces the notice after an old
 * one was dismissed.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class CollisionScanner {

	/**
	 * Transient holding the cached scan result.
	 *
	 * @var string
	 */
	public const TRANSIENT = 'fv_erh_collisions';

	/**
	 * Option holding the fingerprint of the dismissed collision set.
	 *
	 * @var string
	 */
	public const DISMISSED_OPTION = 'fv_erh_collision_dismissed';

	/**
	 * How long, in seconds, a scan result is cached.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cap on reported collisions (a site with more has a systemic naming
	 * convention problem the first hundred already proves).
	 *
	 * @var int
	 */
	private const MAX_RESULTS = 100;

	/**
	 * PHP-side slug pattern (case-sensitive, like Ecwid's own matcher).
	 *
	 * @var string
	 */
	private const SLUG_PATTERN = '~-(p|c)\d+$~';

	/**
	 * MySQL REGEXP for the same pattern. MySQL matching is case-insensitive on
	 * normal collations, so results are re-filtered with {@see self::SLUG_PATTERN}.
	 *
	 * @var string
	 */
	private const SQL_PATTERN = '-(p|c)[0-9]+$';

	/**
	 * The colliding posts, from cache or a fresh scan.
	 *
	 * @param bool $force_rescan Bypass and refresh the cache.
	 * @return array<int,array{id:int,slug:string,title:string,type:string}>
	 */
	public function get_collisions( bool $force_rescan = false ): array {
		if ( ! $force_rescan ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$collisions = $this->scan();

		set_transient( self::TRANSIENT, $collisions, self::CACHE_TTL );

		return $collisions;
	}

	/**
	 * The cached collisions, or null when no scan result is cached.
	 *
	 * For surfaces that must never trigger a scan (the site-wide admin
	 * notice) — a cold cache simply shows nothing until the next scheduled
	 * or on-demand scan.
	 *
	 * @return array<int,array{id:int,slug:string,title:string,type:string}>|null
	 */
	public function cached_collisions(): ?array {
		$cached = get_transient( self::TRANSIENT );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Whether a slug would collide with Ecwid's URL matcher.
	 *
	 * @param string $slug Post slug.
	 * @return bool
	 */
	public function slug_collides( string $slug ): bool {
		return 1 === preg_match( self::SLUG_PATTERN, $slug );
	}

	/**
	 * Invalidate the cache when a saved post affects the collision set.
	 *
	 * Hooked to `save_post` (fires for classic, REST/Gutenberg, and
	 * programmatic saves): a post whose slug collides now, or one that was in
	 * the cached set (slug renamed away, post unpublished), drops the cache so
	 * the next scan reflects the change.
	 *
	 * @param int    $post_id Saved post id.
	 * @param object $post    The saved post (a WP_Post).
	 * @return void
	 */
	public function maybe_invalidate( int $post_id, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_name ) ) {
			return;
		}

		if ( $this->slug_collides( (string) $post->post_name ) ) {
			delete_transient( self::TRANSIENT );

			return;
		}

		$cached = $this->cached_collisions();
		if ( null !== $cached && in_array( $post_id, array_column( $cached, 'id' ), true ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Fingerprint a collision set for dismissal tracking.
	 *
	 * @param array<int,array{id:int}> $collisions Collision rows.
	 * @return string
	 */
	public function fingerprint( array $collisions ): string {
		$ids = array_column( $collisions, 'id' );
		sort( $ids );

		return md5( implode( ',', $ids ) );
	}

	/**
	 * Record the current collision set as dismissed.
	 *
	 * @param array<int,array{id:int}> $collisions Collision rows.
	 * @return void
	 */
	public function dismiss( array $collisions ): void {
		update_option( self::DISMISSED_OPTION, $this->fingerprint( $collisions ), false );
	}

	/**
	 * Whether this exact collision set has been dismissed.
	 *
	 * @param array<int,array{id:int}> $collisions Collision rows.
	 * @return bool
	 */
	public function is_dismissed( array $collisions ): bool {
		return $this->fingerprint( $collisions ) === (string) get_option( self::DISMISSED_OPTION, '' );
	}

	/**
	 * Run the slug scan over published public content.
	 *
	 * @return array<int,array{id:int,slug:string,title:string,type:string}>
	 */
	private function scan(): array {
		global $wpdb;

		// Attachments are "public" but their permalinks aren't slug-routed
		// pages a merchant would rename.
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		if ( array() === $types ) {
			return array();
		}

		$types        = array_values( array_map( 'strval', $types ) );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- %s placeholders generated to count, which the sniff cannot see.
			$wpdb->prepare(
				"SELECT ID, post_name, post_title, post_type FROM {$wpdb->posts}
				WHERE post_status = 'publish'
					AND post_type IN ({$placeholders})
					AND post_name REGEXP %s
				ORDER BY post_type ASC, post_name ASC
				LIMIT %d",
				array_merge( $types, array( self::SQL_PATTERN, self::MAX_RESULTS ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$collisions = array();

		foreach ( $rows as $row ) {
			$slug = (string) $row['post_name'];

			// MySQL REGEXP matched case-insensitively; Ecwid's matcher only
			// hijacks lowercase markers.
			if ( ! $this->slug_collides( $slug ) ) {
				continue;
			}

			$collisions[] = array(
				'id'    => (int) $row['ID'],
				'slug'  => $slug,
				'title' => (string) $row['post_title'],
				'type'  => (string) $row['post_type'],
			);
		}

		return $collisions;
	}
}
