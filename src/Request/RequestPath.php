<?php
/**
 * Current-request path extraction.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Request;

use FV\WPEcwidRedirectHelper\Url\RuleMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the normalized path of the current front-end request.
 *
 * Shared by the manual-301 redirector and the 404 capture so both always see
 * the exact same string for the same request — percent-decoded
 * ({@see RequestPath::decode()}) and normalized like the matcher normalizes
 * rule sources ({@see RuleMatcher::normalize_path()}: query string stripped,
 * leading slash, trailing slash trimmed).
 */
final class RequestPath {

	/**
	 * The normalized path of the current request ('' when unavailable).
	 *
	 * @param RuleMatcher $matcher The shared path normalizer.
	 * @return string
	 */
	public static function current( RuleMatcher $matcher ): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// Deliberately NOT sanitize_text_field(): WP core *deletes* every
		// percent-encoded octet instead of decoding it, which would corrupt
		// any non-ASCII URL (`/caf%C3%A9-p123` → `/caf-p123`) before it
		// reaches the log, the matcher, or the backend report. Instead the
		// value is reduced to its path component below and decode() rejects
		// control characters outright.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		$raw = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

		// Isolate the path component. This drops any scheme/host smuggled into
		// the request target — a protocol-relative '//evil.example/x' would
		// otherwise survive with the foreign host intact — plus the query
		// string (a real REQUEST_URI never carries a fragment).
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path || 0 !== strpos( $path, '/' ) ) {
			return '';
		}

		$path = self::decode( $path );
		if ( '' === $path ) {
			return '';
		}

		return $matcher->normalize_path( $path );
	}

	/**
	 * Percent-decode a path for matching, rejecting control characters.
	 *
	 * Paths and rule sources are compared in their decoded form — the parent
	 * storefront matcher's documented convention (`normalize.js`): `%C3%A9`
	 * and `é` are the same path. Three escapes deliberately stay encoded
	 * because decoding them would change the path's *structure* rather than
	 * its spelling: `%2F` (`/`) would merge path segments, `%23` (`#`) would
	 * read as the fragment/hash-route marker, and `%3F` (`?`) as the
	 * query-string marker — both of which {@see RuleMatcher::normalize_path()}
	 * truncates the path at. A path carrying any control character — literal
	 * or decoded (`%00`, `%0A`, …) — is rejected as a whole.
	 *
	 * Shared with the redirects admin form so stored rule sources go through
	 * the exact same decode as the request paths they must match.
	 *
	 * @param string $path A path (possibly percent-encoded).
	 * @return string The decoded path, or '' when it contains control characters.
	 */
	public static function decode( string $path ): string {
		$decoded = (string) preg_replace_callback(
			'/%[0-9A-Fa-f]{2}/',
			static function ( array $matches ): string {
				$upper = strtoupper( $matches[0] );
				if ( '%2F' === $upper || '%23' === $upper || '%3F' === $upper ) {
					return $matches[0];
				}

				return rawurldecode( $matches[0] );
			},
			$path
		);

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
			return '';
		}

		return $decoded;
	}
}
