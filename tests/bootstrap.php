<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * These tests run without a WordPress install — WordPress functions are stubbed
 * by Brain Monkey. The WP integration harness (wp-phpunit) is wired in a later
 * session when features need a real WordPress runtime.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

// Plugin source files guard on ABSPATH; define it so they load under test.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
