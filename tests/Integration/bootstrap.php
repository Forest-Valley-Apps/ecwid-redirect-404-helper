<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 *
 * Unlike tests/bootstrap.php (Brain Monkey, no WordPress), these tests need a
 * real WordPress runtime + MySQL — loaded from the WP test library installed by
 * tests/bin/install-wp-tests.sh. The plugin's own classes resolve through
 * Composer's PSR-4 autoloader, so the full plugin does not need to boot; the
 * tests call the migration entry points directly.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	fwrite(
		STDERR,
		"Could not find {$_functions}. Run tests/bin/install-wp-tests.sh first." . PHP_EOL
	);
	exit( 1 );
}

// Plugin classes (FV\WPEcwidRedirectHelper\*) via Composer PSR-4.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Give access to tests_add_filter() before the test bootstrap runs.
require_once $_functions;

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
