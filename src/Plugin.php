<?php
/**
 * Core plugin bootstrap.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper;

use FV\WPEcwidRedirectHelper\Admin\SettingsPage;
use FV\WPEcwidRedirectHelper\Capture\NotFoundCapture;
use FV\WPEcwidRedirectHelper\Log\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 *
 * Wires WordPress hooks: the connection settings screen (Session 3) and 404
 * capture (Session 4); dashboard and redirect features land in later sessions.
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

			( new SettingsPage() )->register();

			return;
		}

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
