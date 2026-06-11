<?php
/**
 * Server-side 404 capture.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Capture;

use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Connection\ConnectionState;
use FV\WPEcwidRedirectHelper\Connection\EcwidPluginDiscovery;
use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Request\RequestPath;
use FV\WPEcwidRedirectHelper\Url\RuleMatcher;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;

defined( 'ABSPATH' ) || exit;

/**
 * Records every front-end 404 into the log, classified as an Ecwid product,
 * an Ecwid category, or an ordinary WP page.
 *
 * No-latency rule: on a normal (non-404) request the hook body is a single
 * `is_404()` call — nothing else runs. The capture work itself (one upsert,
 * optionally one fire-and-forget report) happens only on actual 404 renders,
 * which are never served from a page cache anyway.
 *
 * The stored path is normalized exactly like the redirect matcher normalizes
 * rule sources ({@see RequestPath::current()}: query string stripped, leading
 * slash, trailing slash trimmed), so the dashboard, the manual-301 redirector
 * and the matcher always talk about the same string.
 *
 * Ecwid-classified 404s are additionally reported to the hosted backend, but
 * only while the merchant is connected — clicking Connect is the explicit
 * opt-in to the hosted service, whose dashboard these reports feed. Reports
 * carry the store id discovered live from the Ecwid plugin (the same id the
 * verdict feature resolves), not the Connect-time snapshot, so re-pointing
 * the Ecwid plugin never reports the new store's 404s against the old one.
 * WP-page 404s are never reported; they stay local.
 *
 * Hooked at priority 20 so any redirect feature running at default priority
 * (which exits before rendering) wins without ever logging a false 404.
 */
final class NotFoundCapture {

	/**
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * Ecwid URL classifier.
	 *
	 * @var UrlClassifier
	 */
	private UrlClassifier $classifier;

	/**
	 * Path normalizer (shared with the rule matcher).
	 *
	 * @var RuleMatcher
	 */
	private RuleMatcher $matcher;

	/**
	 * Connect opt-in state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $state;

	/**
	 * Constructor.
	 *
	 * @param NotFoundLog|null     $log        Log repository (injectable for tests).
	 * @param UrlClassifier|null   $classifier Classifier (injectable for tests).
	 * @param RuleMatcher|null     $matcher    Path normalizer (injectable for tests).
	 * @param ConnectionState|null $state      Connect state (injectable for tests).
	 */
	public function __construct(
		?NotFoundLog $log = null,
		?UrlClassifier $classifier = null,
		?RuleMatcher $matcher = null,
		?ConnectionState $state = null
	) {
		$this->log        = $log ?? new NotFoundLog();
		$this->classifier = $classifier ?? new UrlClassifier();
		$this->matcher    = $matcher ?? new RuleMatcher();
		$this->state      = $state ?? new ConnectionState();
	}

	/**
	 * Register the capture hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_record' ), 20 );
	}

	/**
	 * Record the current request when it is a 404.
	 *
	 * @return void
	 */
	public function maybe_record(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = RequestPath::current( $this->matcher );
		if ( '' === $path ) {
			return;
		}

		$referrer = esc_url_raw( (string) wp_get_raw_referer() );
		$entity   = $this->classifier->classify( $path );

		$this->log->record( $path, $referrer, $entity['type'], (int) $entity['id'] );

		if ( null === $entity['id'] || ! $this->state->is_connected() ) {
			return;
		}

		// Report against the live store id from the Ecwid plugin, not the
		// Connect-time snapshot, so this stays in lockstep with the verdict
		// feature when the merchant re-points the Ecwid plugin.
		$discovery = EcwidPluginDiscovery::discover();
		if ( ! $discovery->has_store_id() ) {
			return;
		}

		// Fire-and-forget (non-blocking, 1s timeout) — never delays the render.
		BackendClient::for_store( $discovery->store_id() )
			->report_404( $path, '' !== $referrer ? $referrer : null );
	}
}
