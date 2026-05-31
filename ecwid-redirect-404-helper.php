<?php
/**
 * Plugin Name:       Redirect & 404 Helper for Ecwid
 * Plugin URI:        https://apps.fv.dev/redirect-404-manager/
 * Description:       Ecwid-aware 404 logging and redirects for WordPress — the only 404/redirect tool that understands Ecwid's embedded store URLs.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Forest Valley
 * Author URI:        https://apps.fv.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ecwid-redirect-404-helper
 * Domain Path:       /languages
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper;

defined( 'ABSPATH' ) || exit;

define( 'FV_ERH_VERSION', '0.1.0' );
define( 'FV_ERH_PLUGIN_FILE', __FILE__ );
define( 'FV_ERH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * The shipped plugin carries no Composer runtime dependency — Composer is used
 * only for development tooling (PHPCS, PHPUnit). This loader mirrors the PSR-4
 * mapping declared in composer.json so runtime needs no `vendor/` directory.
 *
 * @param string $class_name Fully-qualified class name being loaded.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'FV\\WPEcwidRedirectHelper\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, $length ) );
		$file     = __DIR__ . '/src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->register();
	}
);
