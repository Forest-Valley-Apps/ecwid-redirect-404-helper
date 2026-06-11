<?php
/**
 * Unit tests for the current-request path extraction.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Request;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Request\RequestPath;
use FV\WPEcwidRedirectHelper\Url\RuleMatcher;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Pins the request-target → matchable-path seam shared by the redirector and
 * the 404 capture: path-component isolation, percent-decoding (paths are
 * compared in decoded form), the structure-preserving escapes, and
 * control-character rejection.
 */
final class RequestPathTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The shared path normalizer under test.
	 *
	 * @var RuleMatcher
	 */
	private RuleMatcher $matcher;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->matcher = new RuleMatcher();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Resolve the path for a given request target.
	 *
	 * @param string $request_uri The raw REQUEST_URI to serve.
	 * @return string
	 */
	private function current_for( string $request_uri ): string {
		$_SERVER['REQUEST_URI'] = $request_uri;

		return RequestPath::current( $this->matcher );
	}

	public function test_missing_request_uri_yields_empty(): void {
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertSame( '', RequestPath::current( $this->matcher ) );
	}

	public function test_malformed_request_target_yields_empty(): void {
		$this->assertSame( '', $this->current_for( 'http://:80' ) );
	}

	public function test_absolute_form_target_is_reduced_to_its_path(): void {
		$this->assertSame( '/x', $this->current_for( 'http://host/x' ) );
	}

	public function test_target_without_leading_slash_yields_empty(): void {
		$this->assertSame( '', $this->current_for( 'foo/bar' ) );
	}

	public function test_percent_encoded_multibyte_path_is_decoded(): void {
		// `%xx` octets are decoded, never deleted: a non-ASCII URL must reach
		// the log, the matcher and the backend report intact.
		$this->assertSame( '/café-p123', $this->current_for( '/caf%C3%A9-p123?utm_source=mail' ) );
	}

	public function test_encoded_uppercase_multibyte_is_lowercased(): void {
		$this->assertSame( '/café-p123', $this->current_for( '/Caf%C3%89-p123' ) );
	}

	public function test_structural_escapes_stay_encoded(): void {
		// `%2F` would merge path segments, `%23` would read as a fragment
		// marker and `%3F` as a query-string marker (normalize_path truncates
		// the path at either) — all deliberately stay encoded (then lowercased).
		$this->assertSame( '/a%2fb', $this->current_for( '/a%2Fb' ) );
		$this->assertSame( '/x%23y', $this->current_for( '/x%23y' ) );
		// A literal `?` in the slug would lose everything after it; the encoded
		// form must survive so `/sale%3Fitem-p123` is not truncated to `/sale`.
		$this->assertSame( '/sale%3fitem-p123', $this->current_for( '/sale%3Fitem-p123' ) );
	}

	public function test_path_decoding_to_control_characters_is_rejected(): void {
		$this->assertSame( '', $this->current_for( '/bad%00path' ) );
		$this->assertSame( '', $this->current_for( '/bad%0Apath' ) );
	}

	public function test_encoded_request_path_matches_decoded_rule_source(): void {
		// The merchant types the decoded rule `/café-p123`; the request
		// arrives percent-encoded. Both ends meet in decoded form.
		$path = $this->current_for( '/caf%C3%A9-p123' );

		$lookup = $this->matcher->build_lookup(
			array(
				'exact' => array(
					array(
						'source'      => '/café-p123',
						'destination' => '/new-coffee',
					),
				),
			)
		);

		$result = $this->matcher->match_path( $path, $lookup );

		$this->assertNotNull( $result );
		$this->assertSame( '/new-coffee', $result['destination'] );
	}
}
