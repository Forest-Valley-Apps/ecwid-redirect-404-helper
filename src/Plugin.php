<?php
/**
 * Core plugin bootstrap.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper;

use FV\WPEcwidRedirectHelper\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 *
 * Wires WordPress hooks. The connection settings screen (Session 3) is the first
 * feature registered here; capture, dashboard and redirect features land in
 * later sessions.
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
			( new SettingsPage() )->register();
		}
	}

	/**
	 * Activation callback. No-op for the scaffold (schema arrives in Session 4).
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Intentionally empty — no database work yet.
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
