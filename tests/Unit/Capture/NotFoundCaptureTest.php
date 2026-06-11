<?php
/**
 * Unit tests for the server-side 404 capture.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Capture;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Capture\NotFoundCapture;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;

/**
 * Exercises the full capture path through the real classifier, normalizer,
 * log repository and backend client — only the WordPress boundary (query
 * flags, superglobals, options, HTTP, $wpdb) is stubbed.
 */
final class NotFoundCaptureTest extends WpdbTestCase {

	private const STORE_ID = 130416012;

	/**
	 * The WordPress options served to get_option(), keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array();

		// Duplicate-key update by default: no prune path in these tests.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 2 )->byDefault();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_get_raw_referer' )->justReturn( 'https://ref.example' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return $this->options[ $name ] ?? $default_value;
			}
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Mark the merchant as connected and configure the live Ecwid store id.
	 *
	 * The connection snapshot always freezes STORE_ID; the live id the Ecwid
	 * plugin currently points at is configurable to exercise drift.
	 *
	 * @param int $live_store_id The discovered `ecwid_store_id` option value.
	 */
	private function connect( int $live_store_id = self::STORE_ID ): void {
		$this->options['fv_erh_connection'] = array(
			'connected' => true,
			'store_id'  => self::STORE_ID,
		);
		$this->options['ecwid_store_id']    = (string) $live_store_id;
	}

	public function test_register_adds_late_template_redirect_hook(): void {
		Actions\expectAdded( 'template_redirect' )
			->once()
			->whenHappen(
				static function ( $callback, $priority ): void {
					self::assertSame( 20, $priority );
				}
			);

		( new NotFoundCapture() )->register();
	}

	public function test_non_404_request_does_nothing(): void {
		Functions\when( 'is_404' )->justReturn( false );
		$_SERVER['REQUEST_URI'] = '/perfectly-fine-page';

		$this->wpdb->shouldReceive( 'query' )->never();

		( new NotFoundCapture() )->maybe_record();

		$this->assertCount( 0, $this->prepared );
	}

	public function test_wp_page_404_is_logged_with_normalized_path_but_never_reported(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/missing-page/?utm_source=mail';
		$this->connect();

		// Even connected, a WP-page 404 stays local.
		Functions\expect( 'wp_remote_post' )->never();

		( new NotFoundCapture() )->maybe_record();

		$this->assertCount( 1, $this->prepared );

		$args = $this->prepared[0]['args'];
		// Query string and trailing slash are normalized away.
		$this->assertSame( '/missing-page', $args[1] );
		$this->assertSame( 'https://ref.example', $args[2] );
		$this->assertSame( 'wp-page', $args[3] );
		$this->assertSame( 0, $args[4] );
	}

	public function test_ecwid_product_404_is_classified_and_reported_when_connected(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/store/old-shirt-p123?variant=2';
		$this->connect();

		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $request_args ) use ( &$captured ) {
					$captured['url']  = $url;
					$captured['args'] = $request_args;

					return array();
				}
			);

		( new NotFoundCapture() )->maybe_record();

		// Logged locally, classified as the product.
		$args = $this->prepared[0]['args'];
		$this->assertSame( '/store/old-shirt-p123', $args[1] );
		$this->assertSame( 'product', $args[3] );
		$this->assertSame( 123, $args[4] );

		// Reported to the backend, fire-and-forget.
		$this->assertStringContainsString( '/api/storefront/404', $captured['url'] );
		$this->assertFalse( $captured['args']['blocking'], 'reports must be fire-and-forget' );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( self::STORE_ID, $body['storeId'] );
		$this->assertSame( '/store/old-shirt-p123', $body['urlPath'] );
		$this->assertSame( 'https://ref.example', $body['referrer'] );
	}

	public function test_ecwid_category_404_is_not_reported_when_disconnected(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/store/summer-c45';
		// No connect(): the merchant never opted in.

		Functions\expect( 'wp_remote_post' )->never();

		( new NotFoundCapture() )->maybe_record();

		// Still logged locally.
		$args = $this->prepared[0]['args'];
		$this->assertSame( '/store/summer-c45', $args[1] );
		$this->assertSame( 'category', $args[3] );
		$this->assertSame( 45, $args[4] );
	}

	public function test_report_targets_the_live_store_id_not_the_connect_snapshot(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/store/old-shirt-p123';
		// The Ecwid plugin was re-pointed after Connect: the live id differs
		// from the frozen snapshot, and the report must follow the live id.
		$this->connect( 999888777 );

		$captured = array();
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $request_args ) use ( &$captured ) {
					$captured['args'] = $request_args;

					return array();
				}
			);

		( new NotFoundCapture() )->maybe_record();

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 999888777, $body['storeId'] );
	}

	public function test_connected_404_is_not_reported_without_a_discovered_store_id(): void {
		Functions\when( 'is_404' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/store/old-shirt-p123';
		// Connected, but the Ecwid plugin no longer has a store configured.
		$this->connect();
		unset( $this->options['ecwid_store_id'] );

		Functions\expect( 'wp_remote_post' )->never();

		( new NotFoundCapture() )->maybe_record();

		// Still logged locally.
		$args = $this->prepared[0]['args'];
		$this->assertSame( '/store/old-shirt-p123', $args[1] );
		$this->assertSame( 'product', $args[3] );
	}

	public function test_host_smuggling_request_uri_is_reduced_to_its_path(): void {
		Functions\when( 'is_404' )->justReturn( true );
		// A protocol-relative request target must not poison the log with a
		// foreign host: only the path component may be stored.
		$_SERVER['REQUEST_URI'] = '//evil.example/promo-p9';

		( new NotFoundCapture() )->maybe_record();

		$args = $this->prepared[0]['args'];
		$this->assertSame( '/promo-p9', $args[1] );
		$this->assertSame( 'product', $args[3] );
		$this->assertSame( 9, $args[4] );
	}

	public function test_404_without_request_uri_is_ignored(): void {
		Functions\when( 'is_404' )->justReturn( true );
		unset( $_SERVER['REQUEST_URI'] );

		$this->wpdb->shouldReceive( 'query' )->never();

		( new NotFoundCapture() )->maybe_record();

		$this->assertCount( 0, $this->prepared );
	}
}
