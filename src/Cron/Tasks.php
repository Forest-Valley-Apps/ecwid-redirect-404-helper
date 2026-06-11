<?php
/**
 * Scheduled background tasks.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Cron;

use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Collision\CollisionScanner;
use FV\WPEcwidRedirectHelper\Connection\ConnectionState;
use FV\WPEcwidRedirectHelper\Connection\EcwidPluginDiscovery;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's single hourly WP-Cron event. It does three quiet things,
 * cheapest first so the bounded warms can never starve behind the verdict
 * batch's HTTP loop:
 *
 *  1. Refreshes the slug-collision cache when it is missing, so the site-wide
 *     warning notice (which never scans on its own) can appear within the hour
 *     of a collision being introduced.
 *  2. Warms the app-installed flag so the paid-tier CTAs can pick their
 *     destination (deep-link vs. App Market listing) from cache during a render,
 *     never blocking a page load on that fetch — and only while the merchant is
 *     connected, because the readme's privacy disclosure promises the backend is
 *     contacted only between Connect and Disconnect.
 *  3. Resolves a capped, time-budgeted batch of catalog verdicts
 *     ({@see VerdictChecker}), so newly captured Ecwid 404s get labeled without
 *     the merchant clicking "Check catalog" — gated on the same connection by
 *     the checker factory.
 *
 * Scheduled on activation; {@see self::ensure_scheduled()} re-schedules from
 * admin requests because plugin *updates* do not fire the activation hook
 * (same reasoning as `Schema::maybe_migrate()`). Cleared on deactivation.
 */
final class Tasks {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'fv_erh_hourly_tasks';

	/**
	 * Catalog lookups per cron run.
	 *
	 * @var int
	 */
	private const CRON_BATCH = 50;

	/**
	 * Register the cron callback.
	 *
	 * Must run on every request type (cron requests are neither admin nor
	 * ordinary front-end), so {@see \FV\WPEcwidRedirectHelper\Plugin::register()}
	 * calls this unconditionally.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule the hourly event if it is not scheduled yet.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The hourly run.
	 *
	 * @return void
	 */
	public function run(): void {
		$scanner = new CollisionScanner();
		if ( null === $scanner->cached_collisions() ) {
			$scanner->get_collisions();
		}

		// Warm the app-installed flag for the paid-tier CTAs — only while
		// connected (the merchant's explicit opt-in to backend contact; the CTA
		// degrades gracefully to the App Market listing when the flag is cold).
		// A missing route (before prod promotion) returns null and is not
		// cached, so this is a harmless no-op until the endpoint is live.
		if ( ( new ConnectionState() )->is_connected() ) {
			$discovery = EcwidPluginDiscovery::discover();
			if ( $discovery->has_store_id() ) {
				BackendClient::for_store( $discovery->store_id() )->get_app_installed();
			}
		}

		// The verdict batch runs last: it is the only step that can spend real
		// time (sequential catalog HTTP), and it caps itself with a wall-clock
		// budget so the whole run stays inside a typical max_execution_time.
		$checker = VerdictChecker::for_current_connection();
		if ( null !== $checker ) {
			$checker->run( self::CRON_BATCH );
		}
	}
}
