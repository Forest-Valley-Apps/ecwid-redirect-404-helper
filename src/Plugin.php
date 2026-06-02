<?php
/**
 * Core plugin bootstrap.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper;

use FV\WPEcwidRedirectHelper\Admin\Menu;
use FV\WPEcwidRedirectHelper\Capture\NotFoundCapture;
use FV\WPEcwidRedirectHelper\Log\Schema;
use FV\WPEcwidRedirectHelper\Redirect\Redirector;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 *
 * Wires WordPress hooks: the admin pages (404 log dashboard, manual redirects,
 * connection settings) on admin requests; the manual-301 redirector and 404
 * capture on front-end requests.
 */
final class Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public const VERSION = '0.1.0';

	/**
	 * Shared singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor — use {@see Plugin::instance()}.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			// Covers plugin updates, which do not fire the activation hook.
			// Costs one autoloaded-option compare on admin requests only.
			Schema::maybe_migrate();

			( new Menu() )->register();

			return;
		}

		// Redirector first (priority 5: ahead of core's canonical-redirect
		// guess at 10 and the capture at 20) so an explicit rule wins and a
		// matched 301 is never logged as a 404.
		( new Redirector() )->register();
		( new NotFoundCapture() )->register();
	}

	/**
	 * Activation callback: create/upgrade the plugin tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::migrate();
	}

	/**
	 * Deactivation callback. No-op for the scaffold.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally empty.
	}
}
