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

		$match = $this->matcher->match_path( $path, $this->matcher->build_lookup( $this->store->lookup_config() ) );
		if ( null === $match || ! is_string( $match['destination'] ) || '' === $match['destination'] ) {
			return;
		}

		// The matcher port faithfully reproduces the parent's `/prefix/*`
		// doubled-slash substitution quirk (pinned in RuleMatcherTest). These
		// rules are our own, not the parent's, and a `//` in a destination
		// path is never what the merchant meant — collapse it. The lookbehind
		// spares the scheme's `://` in absolute destinations.
		$destination = (string) preg_replace( '#(?<!:)//+#', '/', $match['destination'] );

		$this->store->record_hit( (string) $match['source'] );

		// Not wp_safe_redirect(): the destination is an admin-defined redirect
		// target (manage_options + nonce-guarded CRUD, validated on save as a
		// site-relative path or absolute http(s) URL) — external hosts are an
		// intentional capability.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( $destination, 301, 'Redirect & 404 Helper for Ecwid' );
		( $this->terminator )();
	}
}
