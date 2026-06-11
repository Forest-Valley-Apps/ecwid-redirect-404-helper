<?php
/**
 * Front-end manual-301 redirector.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Redirect;

use FV\WPEcwidRedirectHelper\Request\RequestPath;
use FV\WPEcwidRedirectHelper\Url\RuleMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the merchant's manual WP-layer 301s.
 *
 * These rules fire **only on requests that would otherwise render a 404** —
 * existing pages are never hijacked, and the no-latency rule holds: on a
 * normal request the hook body is a single `is_404()` call. The rules query
 * (one SELECT) and matcher run only on actual 404 renders.
 *
 * Hooked at `template_redirect` priority 5: before core's `redirect_canonical`
 * (priority 10), whose 404-permalink *guess* must not outrank an explicit
 * merchant rule, and before the 404 capture (priority 20), which must not log
 * a false 404 for a request this redirect resolves. The query flags are set
 * before any `template_redirect` priority runs, so `is_404()` is reliable here.
 *
 * Scope (kept honest in the UI too): this is the WP layer. It cannot redirect
 * *between Ecwid sub-routes* of the embedded store — those never reach the
 * server as distinct requests; that is the storefront-JS layer, i.e. the
 * hosted app's territory.
 */
final class Redirector {

	/**
	 * Rule repository.
	 *
	 * @var RedirectStore
	 */
	private RedirectStore $store;

	/**
	 * Rule matcher (exact map + wildcards).
	 *
	 * @var RuleMatcher
	 */
	private RuleMatcher $matcher;

	/**
	 * Stops the request after a served redirect. `exit` in production; tests
	 * inject a throwing callable because `exit` would kill the test runner.
	 *
	 * @var callable
	 */
	private $terminator;

	/**
	 * Constructor.
	 *
	 * @param RedirectStore|null $store      Rule repository (injectable for tests).
	 * @param RuleMatcher|null   $matcher    Matcher (injectable for tests).
	 * @param callable|null      $terminator Request terminator (injectable for tests).
	 */
	public function __construct( ?RedirectStore $store = null, ?RuleMatcher $matcher = null, ?callable $terminator = null ) {
		$this->store      = $store ?? new RedirectStore();
		$this->matcher    = $matcher ?? new RuleMatcher();
		$this->terminator = $terminator ?? static function (): void {
			exit;
		};
	}

	/**
	 * Register the redirect hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 5 );
	}

	/**
	 * 301 the current request when a rule matches its (404) path.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = RequestPath::current( $this->matcher );
		if ( '' === $path ) {
			return;
		}

		$lookup = $this->matcher->build_lookup( $this->store->lookup_config() );
		$match  = $this->matcher->match_path( $path, $lookup );
		if ( null === $match || ! is_string( $match['destination'] ) || '' === $match['destination'] ) {
			return;
		}

		// The matcher port faithfully reproduces the parent's `/prefix/*`
		// doubled-slash substitution quirk (pinned in RuleMatcherTest). These
		// rules are our own, not the parent's, and a `//` in a destination
		// path is never what the merchant meant — collapse it. The lookbehind
		// spares the scheme's `://` in absolute destinations.
		$destination = (string) preg_replace( '#(?<!:)//+#', '/', $match['destination'] );

		// A wildcard rule matches its prefix anywhere in the path (ported
		// parity), and the matcher hands the part before it back as
		// `base_path` — the embedded-store mount. Re-prepend it to
		// site-relative destinations exactly like the parent's
		// `executeRedirect()` (redirect.js), so `/old-category/*` →
		// `/new-category/*` sends `/shop/old-category/widget` to
		// `/shop/new-category/widget`, not to a bare `/new-category/widget`.
		// Only a real mount prefix (`/shop`) is re-prepended. The matcher can
		// also hand back a `#!` base_path when a hash route matches a clean
		// wildcard — unreachable from a server request (which never carries a
		// fragment), but guarded so a Location header can never start with `#!`.
		$base_path = (string) ( $match['base_path'] ?? '' );
		if ( '/' === substr( $base_path, 0, 1 ) && '/' === substr( $destination, 0, 1 ) && false === strpos( $destination, '://' ) ) {
			$destination = $base_path . $destination;
		}

		// Runtime loop backstop ({@see RedirectStore::add()} rejects loops at
		// save time; rows predating that validation could still loop). A bail
		// here falls through to the plain 404 render — strictly better than a
		// 301 the browser would chase into ERR_TOO_MANY_REDIRECTS.
		if ( $this->loops( $path, $destination, $match, $lookup ) ) {
			return;
		}

		$this->store->record_hit( (string) $match['source'] );

		// Not wp_safe_redirect(): the destination is an admin-defined redirect
		// target (manage_options + nonce-guarded CRUD, validated on save as a
		// site-relative path or absolute http(s) URL) — external hosts are an
		// intentional capability.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( $destination, 301, 'Redirect & 404 Helper for Ecwid' );
		( $this->terminator )();
	}

	/**
	 * Whether serving this destination would (start to) loop.
	 *
	 * Two checks: the destination resolves to the very path being requested
	 * (`/old/*` → `/old/landing` requested as `/old/landing`), or it lands
	 * back inside the scope of the rule that just matched (`/docs/*` →
	 * `/docs/v2/*` ⇒ `/docs/v2/v2/…` unbounded). Chains into a *different*
	 * rule are left alone — the browser follows them hop by hop, and the
	 * save-time validation already refuses one-hop cycles.
	 *
	 * @param string $path        The normalized (404) request path.
	 * @param string $destination The resolved destination about to be served.
	 * @param array  $match       The match result that produced it.
	 * @param array  $lookup      The built rule lookup for this request.
	 * @return bool
	 */
	private function loops( string $path, string $destination, array $match, array $lookup ): bool {
		if ( '/' !== substr( $destination, 0, 1 ) || false !== strpos( $destination, '://' ) ) {
			// Absolute/external destination — it cannot re-enter this matcher.
			return false;
		}

		if ( $this->matcher->normalize_path( $destination ) === $path ) {
			return true;
		}

		$re_match = $this->matcher->match_path( $destination, $lookup );

		return null !== $re_match && ( $re_match['source'] ?? null ) === ( $match['source'] ?? null );
	}
}
