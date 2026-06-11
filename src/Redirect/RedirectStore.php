<?php
/**
 * Repository for manual WP-layer redirect rules.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Redirect;

use FV\WPEcwidRedirectHelper\Log\Schema;
use FV\WPEcwidRedirectHelper\Url\RuleMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over the merchant's manual 301 rules.
 *
 * Sources are stored normalized ({@see RuleMatcher::normalize_path()}) so the
 * front-end matcher and the stored rule always compare the same string, and
 * keyed by their md5 in `source_hash` (UNIQUE) — one rule per source pattern.
 * `is_wildcard` is derived from the source at save time so
 * {@see self::lookup_config()} can split exact/wildcard rules with a column
 * read instead of re-parsing patterns on every 404.
 *
 * Direct queries against our own custom table are the point of this class, so
 * the WordPress.DB direct-query/caching sniffs are disabled file-wide instead
 * of per-line.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class RedirectStore {

	/**
	 * Add result: the rule was created.
	 *
	 * @var string
	 */
	public const ADDED = 'added';

	/**
	 * Add result: the source pattern is unusable.
	 *
	 * @var string
	 */
	public const INVALID_SOURCE = 'invalid-source';

	/**
	 * Add result: the destination is unusable (equals the source, or would
	 * create a redirect loop/chain — {@see self::creates_loop()}).
	 *
	 * @var string
	 */
	public const INVALID_DESTINATION = 'invalid-destination';

	/**
	 * Add result: a rule for this source already exists.
	 *
	 * @var string
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * Max length stored for a source/destination.
	 *
	 * Same bound as the 404 log's path column ({@see \FV\WPEcwidRedirectHelper\Log\NotFoundLog}),
	 * so a rule created from a logged 404 always fits.
	 *
	 * @var int
	 */
	private const MAX_FIELD = 2048;

	/**
	 * Path normalizer (shared with the front-end matcher).
	 *
	 * @var RuleMatcher
	 */
	private RuleMatcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param RuleMatcher|null $matcher Path normalizer (injectable for tests).
	 */
	public function __construct( ?RuleMatcher $matcher = null ) {
		$this->matcher = $matcher ?? new RuleMatcher();
	}

	/**
	 * Create a redirect rule.
	 *
	 * @param string $source      Source path or wildcard pattern (raw merchant input).
	 * @param string $destination Destination path or absolute http(s) URL.
	 * @return string One of the add-result constants.
	 */
	public function add( string $source, string $destination ): string {
		global $wpdb;

		$source      = $this->matcher->normalize_path( trim( $source ) );
		$destination = trim( $destination );

		if ( ! $this->is_valid_source( $source ) ) {
			return self::INVALID_SOURCE;
		}

		if ( ! $this->is_valid_destination( $destination )
			|| $this->matcher->normalize_path( $destination ) === $source ) {
			return self::INVALID_DESTINATION;
		}

		if ( null !== $this->find_by_source( $source ) ) {
			return self::DUPLICATE;
		}

		if ( $this->creates_loop( $source, $destination ) ) {
			return self::INVALID_DESTINATION;
		}

		$wpdb->insert(
			Schema::redirects_table(),
			array(
				'source_hash' => md5( $source ),
				'source'      => $source,
				'destination' => $destination,
				'is_wildcard' => false !== strpos( $source, '*' ) ? 1 : 0,
				'active'      => 1,
				'hit_count'   => 0,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return self::ADDED;
	}

	/**
	 * Delete rules by id.
	 *
	 * @param array<int,int> $ids Rule ids.
	 * @return void
	 */
	public function delete( array $ids ): void {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() === $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = Schema::redirects_table();

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- table from Schema::redirects_table(); %d placeholders generated to count, which the sniff cannot see.
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);
	}

	/**
	 * Enable or disable a rule.
	 *
	 * @param int  $id     Rule id.
	 * @param bool $active New state.
	 * @return void
	 */
	public function set_active( int $id, bool $active ): void {
		global $wpdb;

		$wpdb->update(
			Schema::redirects_table(),
			array( 'active' => $active ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Record a served redirect: bump the hit count and stamp the time.
	 *
	 * Keyed by the rule's source (the match result carries it verbatim).
	 *
	 * @param string $source The matched rule's stored source pattern.
	 * @return void
	 */
	public function record_hit( string $source ): void {
		global $wpdb;

		$table = Schema::redirects_table();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::redirects_table().
				"UPDATE {$table} SET hit_count = hit_count + 1, last_hit = %s WHERE source_hash = %s",
				gmdate( 'Y-m-d H:i:s' ),
				md5( $source )
			)
		);
	}

	/**
	 * All rules, newest first, for the admin list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::redirects_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::redirects_table().
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The active rules shaped for {@see RuleMatcher::build_lookup()}.
	 *
	 * @return array{exact:array<int,array>,wildcard:array<int,array>}
	 */
	public function lookup_config(): array {
		global $wpdb;

		$table = Schema::redirects_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::redirects_table().
		$rows = $wpdb->get_results( "SELECT source, destination, is_wildcard FROM {$table} WHERE active = 1", ARRAY_A );

		$config = array(
			'exact'    => array(),
			'wildcard' => array(),
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$bucket = (int) $row['is_wildcard'] ? 'wildcard' : 'exact';

			$config[ $bucket ][] = array(
				'source'      => (string) $row['source'],
				'destination' => (string) $row['destination'],
			);
		}

		return $config;
	}

	/**
	 * Look a rule up by its (normalized) source.
	 *
	 * @param string $source Normalized source pattern.
	 * @return array<string,mixed>|null The row, or null when absent.
	 */
	public function find_by_source( string $source ): ?array {
		global $wpdb;

		$table = Schema::redirects_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::redirects_table().
				"SELECT * FROM {$table} WHERE source_hash = %s",
				md5( $source )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a new rule would create a redirect loop.
	 *
	 * The save-time half of the loop protection (the parent product validates
	 * the same way in `packages/shared/src/validation.ts`; the Redirector
	 * keeps a runtime backstop for rows that predate this check). The new
	 * rule's destination is resolved to the path a served redirect would
	 * actually land on and probed through the real matcher, rejecting:
	 *
	 * - self-scope chains: the destination falls back inside the rule's own
	 *   source scope (`/docs/*` → `/docs/v2/*` ⇒ `/docs/v2/v2/…` unbounded;
	 *   `/old/*` → `/old/landing` ⇒ self-301 when the landing itself 404s);
	 * - one-hop cycles: an existing active rule sends the destination straight
	 *   back to the new source (`/a` → `/b` exists, adding `/b` → `/a`), or
	 *   back into the new rule's scope;
	 * - transitive cycles spanning any number of rules (`/a` → `/b`, `/b` →
	 *   `/c`, adding `/c` → `/a`), via {@see RedirectStore::chain_revisits()} —
	 *   the full source→destination chain walk the parent's `detectLoop()` does.
	 *
	 * Genuine chains into a *different* rule (`/c` → `/a` while `/a` → `/b`
	 * exists, with no return edge) are allowed, as in the parent (its
	 * `detectChain()` only warns).
	 *
	 * @param string $source      Normalized source pattern.
	 * @param string $destination Validated destination.
	 * @return bool
	 */
	private function creates_loop( string $source, string $destination ): bool {
		if ( '/' !== substr( $destination, 0, 1 ) ) {
			// Absolute http(s) destination — external, cannot re-enter the matcher.
			return false;
		}

		$probe       = $this->served_path( $destination );
		$self_lookup = $this->matcher->build_lookup(
			array(
				( false !== strpos( $source, '*' ) ? 'wildcard' : 'exact' ) => array(
					array(
						'source'      => $source,
						'destination' => $destination,
					),
				),
			)
		);

		if ( null !== $this->matcher->match_path( $probe, $self_lookup ) ) {
			return true;
		}

		// One read of the active set, shared by the transitive walk and the
		// one-hop probe (kept after the self-scope check so that stays DB-free).
		$config = $this->lookup_config();

		// Transitive cycle across any number of rules, ported from the parent's
		// detectLoop() (validation.ts): walk the exact source→destination graph
		// — hash-folded so `#!/foo` and `/foo` are one node — from the new
		// destination with the new edge added. The matcher-scope checks around
		// this only see a single hop; this catches `/a` → `/b`, `/b` → `/c`,
		// then adding `/c` → `/a`.
		if ( $this->chain_revisits( $source, $destination, $config ) ) {
			return true;
		}

		$existing = $this->matcher->match_path( $probe, $this->matcher->build_lookup( $config ) );
		if ( null === $existing
			|| ! is_string( $existing['destination'] )
			|| '/' !== substr( $existing['destination'], 0, 1 ) ) {
			return false;
		}

		$resolved = $this->served_path( $existing['destination'] );

		return $this->matcher->normalize_path( $resolved ) === $source
			|| null !== $this->matcher->match_path( $resolved, $self_lookup );
	}

	/**
	 * Whether following the exact source→destination chain from the new rule's
	 * destination revisits an already-seen node — a redirect loop spanning any
	 * number of rules. A faithful port of the parent's `detectLoop()`
	 * (`packages/shared/src/validation.ts`): sources and destinations are
	 * compared in hash-folded normalized form, the new edge is added to the
	 * graph, and the walk from the new destination is bounded by the rule count
	 * (≤ 50) so a pre-existing cycle cannot spin forever.
	 *
	 * This is an exact-string graph, so it does not reason about wildcard
	 * *scope* (the matcher-based checks in {@see RedirectStore::creates_loop()}
	 * cover that); the two together match the parent's behavior.
	 *
	 * @param string $source      Normalized source pattern.
	 * @param string $destination Validated destination.
	 * @param array  $config      The active rule set from {@see RedirectStore::lookup_config()}.
	 * @return bool
	 */
	private function chain_revisits( string $source, string $destination, array $config ): bool {
		$map = array();
		foreach ( $config as $rules ) {
			foreach ( $rules as $rule ) {
				$map[ $this->normalize_for_compare( (string) $rule['source'] ) ] =
					$this->normalize_for_compare( (string) $rule['destination'] );
			}
		}

		$start         = $this->normalize_for_compare( $source );
		$map[ $start ] = $this->normalize_for_compare( $destination );

		$current   = $map[ $start ];
		$visited   = array( $start => true );
		$max_depth = min( 50, count( $map ) + 1 );

		for ( $i = 0; $i < $max_depth; $i++ ) {
			if ( isset( $visited[ $current ] ) ) {
				return true;
			}
			$visited[ $current ] = true;

			if ( ! isset( $map[ $current ] ) ) {
				return false;
			}
			$current = $map[ $current ];
		}

		return false;
	}

	/**
	 * Normalize a path for loop-graph comparison: the matcher's normalization
	 * (lowercase, query/fragment stripped, trailing slash trimmed) plus the
	 * parent's hash-fold — `#!/foo` and `/foo` are the same logical page, so the
	 * cross-format storefront matcher means loop detection must treat them as
	 * one node too (`normalizeForCompare` in the parent's validation.ts).
	 *
	 * @param string $path A source or destination.
	 * @return string
	 */
	private function normalize_for_compare( string $path ): string {
		$normalized = $this->matcher->normalize_path( $path );

		if ( 0 === strpos( $normalized, '#!/' ) ) {
			return '/' . substr( $normalized, 3 );
		}

		if ( 0 === strpos( $normalized, '#/' ) ) {
			return '/' . substr( $normalized, 2 );
		}

		return $normalized;
	}

	/**
	 * The path a destination actually serves as: any trailing `*` resolved
	 * away and the matcher's doubled-slash substitution quirk collapsed,
	 * mirroring what the Redirector does before issuing the 301 — so the loop
	 * check probes the same string a browser would come back with.
	 *
	 * @param string $destination A site-relative destination (may carry a `*`).
	 * @return string
	 */
	private function served_path( string $destination ): string {
		$star_idx = strrpos( $destination, '*' );
		if ( false !== $star_idx ) {
			$destination = substr( $destination, 0, $star_idx );
		}

		return (string) preg_replace( '#(?<!:)//+#', '/', $destination );
	}

	/**
	 * Whether a normalized pattern can be a rule source.
	 *
	 * Accepts ordinary paths (`/old-page`), trailing wildcards (`/old/*`),
	 * leading wildcards (`*-p123`), and hash routes (`#!/old-product`) — but
	 * not the bare root, a lone `*`, or anything over the length cap.
	 *
	 * @param string $source Normalized source pattern.
	 * @return bool
	 */
	private function is_valid_source( string $source ): bool {
		if ( '/' === $source || '*' === $source || '#!/' === $source ) {
			return false;
		}

		if ( strlen( $source ) > self::MAX_FIELD ) {
			return false;
		}

		$first = substr( $source, 0, 1 );

		return '/' === $first || '*' === $first || '#' === $first;
	}

	/**
	 * Whether a destination is usable: a site-relative path (optionally with a
	 * trailing `*` for wildcard rules) or an absolute http(s) URL.
	 *
	 * @param string $destination Raw destination.
	 * @return bool
	 */
	private function is_valid_destination( string $destination ): bool {
		if ( '' === $destination || strlen( $destination ) > self::MAX_FIELD ) {
			return false;
		}

		if ( '/' === substr( $destination, 0, 1 ) ) {
			// A protocol-relative '//host/…' is NOT a site path — external
			// destinations must be explicit absolute http(s) URLs.
			return '/' !== substr( $destination, 1, 1 );
		}

		return 1 === preg_match( '#^https?://[^\s]+$#i', $destination );
	}
}
