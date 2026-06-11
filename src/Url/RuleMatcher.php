<?php
/**
 * Redirect rule lookup + wildcard matching.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a fast lookup from a set of redirect rules and matches a request path
 * against it: exact map first, then longest-prefix wildcard, with clean-URL
 * (`/foo`) ↔ hash-URL (`#!/foo`) cross-format retries.
 *
 * Pure logic — no WordPress and no network dependency. This is a PHP port of
 * the parent product's storefront matcher
 * (`apps/storefront-js/src/lib/matchers.js`: `buildLookup`, `matchWildcard`,
 * `getAltPath`) together with its `normalizePath` dependency
 * (`apps/storefront-js/src/lib/normalize.js`).
 *
 * Rules are associative arrays:
 *   array(
 *     'source'      => '/old-path' | '/prefix/*' | '*<suffix>',
 *     'destination' => '/new-path' | '/new-prefix/*',
 *     'type'        => (optional) caller-defined label,
 *     'active'      => (optional) bool; a rule is active unless explicitly false,
 *   )
 *
 * A match result is an associative array:
 *   array(
 *     'source'      => the rule's original source,
 *     'destination' => the resolved destination (wildcard suffix substituted),
 *     'type'        => the rule's type (or null),
 *     'base_path'   => the matched base path prefix (embedded-store mount), or '',
 *     'wildcard'    => bool: whether a wildcard rule produced this result,
 *   )
 */
final class RuleMatcher {

	/**
	 * Build a lookup structure from a rule config.
	 *
	 * @param array $config array{exact?:array<int,array>,wildcard?:array<int,array>}.
	 * @return array{map:array<string,array>,wildcards:array<int,array>} Exact rules
	 *               keyed by normalized source, plus wildcard rules sorted by
	 *               descending source length (most specific first).
	 */
	public function build_lookup( array $config ): array {
		$exact_map = array();
		$wildcards = array();

		if ( ! empty( $config['exact'] ) ) {
			foreach ( $config['exact'] as $rule ) {
				if ( false !== ( $rule['active'] ?? true ) ) {
					$exact_map[ $this->normalize_path( (string) $rule['source'] ) ] = $rule;
				}
			}
		}

		if ( ! empty( $config['wildcard'] ) ) {
			$index = 0;
			foreach ( $config['wildcard'] as $w_rule ) {
				if ( false !== ( $w_rule['active'] ?? true ) ) {
					// Preserve original order so the sort is stable across PHP
					// versions, mirroring the stable Array.prototype.sort in JS.
					$wildcards[] = array(
						'rule'  => $w_rule,
						'order' => $index,
					);
					++$index;
				}
			}

			usort(
				$wildcards,
				static function ( array $a, array $b ): int {
					$diff = strlen( (string) $b['rule']['source'] ) - strlen( (string) $a['rule']['source'] );
					return 0 !== $diff ? $diff : ( $a['order'] - $b['order'] );
				}
			);

			$wildcards = array_map(
				static function ( array $entry ): array {
					return $entry['rule'];
				},
				$wildcards
			);
		}

		return array(
			'map'       => $exact_map,
			'wildcards' => $wildcards,
		);
	}

	/**
	 * Match a path against a built lookup.
	 *
	 * Order: exact on the normalized path, exact on the cross-format alt path,
	 * wildcard on the normalized path, then wildcard on the alt path.
	 *
	 * Deliberate non-port: when a hash path (`#!/…`) matches a wildcard, the
	 * parent's `processRules()` (redirect.js) re-prepends `#!/` to a
	 * slash-rooted destination so the browser keeps hash-based navigation —
	 * the "hash-prefix loss" fix in the storefront gotchas reference. That
	 * step is client-side navigation repair and is out of scope server-side:
	 * the WP layer never serves a request for a hash route, so callers here
	 * receive the destination un-restored, with the `#!` left in `base_path`
	 * (pinned in RuleMatcherTest).
	 *
	 * @param string $path   Request path or hash route.
	 * @param array  $lookup A structure returned by {@see RuleMatcher::build_lookup()}.
	 * @return array|null The match result array, or null when nothing matches.
	 */
	public function match_path( string $path, array $lookup ): ?array {
		$normalized = $this->normalize_path( $path );
		$wildcards  = $lookup['wildcards'] ?? array();
		$map        = $lookup['map'] ?? array();

		if ( isset( $map[ $normalized ] ) ) {
			return $this->exact_result( $map[ $normalized ] );
		}

		$alt = $this->get_alt_path( $normalized );

		if ( null !== $alt && isset( $map[ $alt ] ) ) {
			return $this->exact_result( $map[ $alt ] );
		}

		$wildcard_match = $this->match_wildcard( $normalized, $wildcards );

		if ( null !== $wildcard_match ) {
			return $wildcard_match;
		}

		if ( null !== $alt ) {
			return $this->match_wildcard( $alt, $wildcards );
		}

		return null;
	}

	/**
	 * Match a path against the wildcard rules using prefix/suffix matching.
	 *
	 * Trailing wildcard `{prefix}/*` matches paths that start with the prefix
	 * and appends the matched suffix to the destination. Leading wildcard
	 * `*{suffix}` matches paths that end with the suffix (guarding against
	 * partial-id matches by requiring a non-digit boundary).
	 *
	 * @param string $path      Normalized request path.
	 * @param array  $wildcards Wildcard rules, most specific first.
	 * @return array|null The match result array, or null when nothing matches.
	 */
	public function match_wildcard( string $path, array $wildcards ): ?array {
		foreach ( $wildcards as $w_rule ) {
			$source_pattern = $this->normalize_path( (string) $w_rule['source'] );

			// Remove the trailing `/*` to get the prefix; fall back to a bare `*`.
			$star_idx = strrpos( $source_pattern, '/*' );
			if ( false === $star_idx ) {
				$star_idx = strrpos( $source_pattern, '*' );
			}

			if ( false === $star_idx ) {
				continue;
			}

			$prefix = substr( $source_pattern, 0, $star_idx );

			if ( '' === $prefix ) {
				// Leading wildcard `*{suffix}` — match paths that END with the suffix.
				$suffix_pattern = substr( $source_pattern, $star_idx + 1 );
				if ( '' === $suffix_pattern ) {
					// Bare `*` with nothing after it — skip.
					continue;
				}

				$path_len   = strlen( $path );
				$suffix_len = strlen( $suffix_pattern );

				if ( $path_len >= $suffix_len
					&& substr( $path, $path_len - $suffix_len ) === $suffix_pattern ) {
					// The character before the match must not be a digit, so a
					// suffix id never matches the tail of a longer number.
					$char_before = $path_len > $suffix_len ? $path[ $path_len - $suffix_len - 1 ] : '';
					if ( ctype_digit( $char_before ) ) {
						continue;
					}

					return $this->wildcard_result(
						(string) $w_rule['destination'],
						$w_rule,
						''
					);
				}
			} else {
				// Trailing wildcard `{prefix}/*` — match paths that START with the prefix.
				$prefix_pos = strpos( $path, $prefix );
				$prefix_len = strlen( $prefix );

				if ( false !== $prefix_pos && strlen( $path ) >= $prefix_pos + $prefix_len ) {
					$suffix = substr( $path, $prefix_pos + $prefix_len );

					// Replace `*` in the destination with the matched suffix.
					$dest_pattern  = (string) $w_rule['destination'];
					$dest_star_idx = strrpos( $dest_pattern, '*' );

					if ( false !== $dest_star_idx ) {
						$destination = substr( $dest_pattern, 0, $dest_star_idx ) . $suffix;
					} else {
						$destination = $dest_pattern;
					}

					return $this->wildcard_result(
						$destination,
						$w_rule,
						substr( $path, 0, $prefix_pos )
					);
				}
			}
		}

		return null;
	}

	/**
	 * Cross-format alternate path.
	 *
	 * Merchants may enter a clean URL (`/old-product`) while the store uses a
	 * hash route (`#!/old-product`), or vice versa. Returns the alternate form
	 * normalized, or null when there is no useful alternate.
	 *
	 * @param string $current_path The path to convert.
	 * @return string|null The normalized alternate path, or null.
	 */
	public function get_alt_path( string $current_path ): ?string {
		$alt = null;

		if ( 0 === strpos( $current_path, '#!/' ) ) {
			$alt = '/' . substr( $current_path, 3 );
		} elseif ( 0 === strpos( $current_path, '#/' ) ) {
			$alt = '/' . substr( $current_path, 2 );
		} elseif ( '/' === substr( $current_path, 0, 1 ) ) {
			$alt = '#!/' . substr( $current_path, 1 );
		}

		return null !== $alt ? $this->normalize_path( $alt ) : null;
	}

	/**
	 * Normalize a path: lowercase, strip query string, strip trailing slash
	 * (except root), and preserve hash-route prefixes (`#!/` or `#/`).
	 *
	 * Paths are compared in their (percent-)decoded form; this method does not
	 * encode or decode — the decode happens at the seams that feed it
	 * ({@see \FV\WPEcwidRedirectHelper\Request\RequestPath::decode()}: request
	 * extraction and the redirects admin form).
	 *
	 * @param string $path The raw path.
	 * @return string The normalized path.
	 */
	public function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}

		$normalized  = $path;
		$is_hash_url = 0 === strpos( $normalized, '#!/' ) || 0 === strpos( $normalized, '#/' );

		// Remove the query string.
		$q_idx = strpos( $normalized, '?' );
		if ( false !== $q_idx ) {
			$normalized = substr( $normalized, 0, $q_idx );
		}

		if ( ! $is_hash_url ) {
			// Non-hash path: remove the fragment.
			$h_idx = strpos( $normalized, '#' );
			if ( false !== $h_idx ) {
				$normalized = substr( $normalized, 0, $h_idx );
			}

			// Ensure a leading slash, except for wildcard patterns like `*<id>`.
			$first = substr( $normalized, 0, 1 );
			if ( '/' !== $first && '*' !== $first ) {
				$normalized = '/' . $normalized;
			}
		}

		// Remove a trailing slash, unless the path is root `/` or a bare `#!/`.
		$len = strlen( $normalized );
		if ( $len > 1 && '/' === $normalized[ $len - 1 ] && '#!/' !== $normalized ) {
			$normalized = substr( $normalized, 0, $len - 1 );
		}

		// Unicode-aware like the parent's toLowerCase(): plain strtolower()
		// would leave `/Крутой-Товар-p123` unmatched against its lowercase
		// rule. mbstring is not guaranteed on every host, hence the guard.
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $normalized, 'UTF-8' )
			: strtolower( $normalized );
	}

	/**
	 * Shape an exact-rule hit into a match result.
	 *
	 * @param array $rule The matched exact rule.
	 * @return array The match result array.
	 */
	private function exact_result( array $rule ): array {
		return array(
			'source'      => $rule['source'] ?? null,
			'destination' => $rule['destination'] ?? null,
			'type'        => $rule['type'] ?? null,
			'base_path'   => '',
			'wildcard'    => false,
		);
	}

	/**
	 * Shape a wildcard-rule hit into a match result.
	 *
	 * @param string $destination The resolved destination.
	 * @param array  $w_rule      The matched wildcard rule.
	 * @param string $base_path   The matched base path prefix.
	 * @return array The match result array.
	 */
	private function wildcard_result( string $destination, array $w_rule, string $base_path ): array {
		return array(
			'source'      => $w_rule['source'] ?? null,
			'destination' => $destination,
			'type'        => $w_rule['type'] ?? null,
			'base_path'   => $base_path,
			'wildcard'    => true,
		);
	}
}
