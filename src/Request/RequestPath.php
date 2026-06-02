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
 * the exact same string for the same request — normalized like the matcher
 * normalizes rule sources ({@see RuleMatcher::normalize_path()}: query string
 * stripped, leading slash, trailing slash trimmed).
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

		$raw = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		// Isolate the path component. This drops any scheme/host smuggled into
		// the request target — a protocol-relative '//evil.example/x' would
		// otherwise survive with the foreign host intact — plus the query
		// string (a real REQUEST_URI never carries a fragment).
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path || 0 !== strpos( $path, '/' ) ) {
			return '';
		}

		return $matcher->normalize_path( $path );
	}
}
