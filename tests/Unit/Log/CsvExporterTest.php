<?php
/**
 * Unit tests for the CSV exporter.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Log;

use FV\WPEcwidRedirectHelper\Log\CsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips rows through an in-memory stream: URLs with commas, quotes and
 * even newlines must survive the CSV encoding intact.
 */
final class CsvExporterTest extends TestCase {

	/**
	 * Write rows and parse the CSV back.
	 *
	 * @param array<int,array<string,mixed>> $rows Log rows.
	 * @return array<int,array<string,string>> Parsed rows keyed by header.
	 */
	private function round_trip( array $rows ): array {
		$stream = fopen( 'php://temp', 'r+' );

		( new CsvExporter() )->write( $stream, $rows );

		rewind( $stream );

		$header = fgetcsv( $stream );
		$parsed = array();

		while ( false !== ( $line = fgetcsv( $stream ) ) ) {
			$parsed[] = array_combine( $header, $line );
		}

		fclose( $stream );

		return $parsed;
	}

	public function test_header_row_lists_the_exported_columns(): void {
		$stream = fopen( 'php://temp', 'r+' );
		( new CsvExporter() )->write( $stream, array() );
		rewind( $stream );

		$this->assertSame(
			array( 'url_path', 'classification', 'entity_id', 'verdict', 'status', 'hit_count', 'first_seen', 'last_seen', 'referrer' ),
			fgetcsv( $stream )
		);

		fclose( $stream );
	}

	public function test_commas_quotes_and_newlines_round_trip(): void {
		$nasty_path     = '/search,results/"quoted"/x';
		$nasty_referrer = "https://ref.example/?q=\"a,b\"\nsecond-line";

		$parsed = $this->round_trip(
			array(
				array(
					'url_path'       => $nasty_path,
					'classification' => 'product',
					'entity_id'      => 123,
					'status'         => 'new',
					'hit_count'      => 7,
					'first_seen'     => '2026-06-01 10:00:00',
					'last_seen'      => '2026-06-02 11:30:00',
					'referrer'       => $nasty_referrer,
				),
			)
		);

		$this->assertCount( 1, $parsed );
		$this->assertSame( $nasty_path, $parsed[0]['url_path'] );
		$this->assertSame( $nasty_referrer, $parsed[0]['referrer'] );
		$this->assertSame( '123', $parsed[0]['entity_id'] );
		$this->assertSame( '7', $parsed[0]['hit_count'] );
	}

	public function test_formula_injection_is_neutralized(): void {
		$parsed = $this->round_trip(
			array(
				array(
					'url_path' => '=HYPERLINK("http://evil.example")',
					'referrer' => '@SUM(1+1)',
				),
				array(
					'url_path' => '+1234567890',
					'referrer' => '-2+3',
				),
			)
		);

		// Leading formula triggers are prefixed with a single quote so the
		// spreadsheet reads them as text; ordinary values are left alone.
		$this->assertSame( "'=HYPERLINK(\"http://evil.example\")", $parsed[0]['url_path'] );
		$this->assertSame( "'@SUM(1+1)", $parsed[0]['referrer'] );
		$this->assertSame( "'+1234567890", $parsed[1]['url_path'] );
		$this->assertSame( "'-2+3", $parsed[1]['referrer'] );
	}

	public function test_missing_keys_become_empty_fields_and_order_is_stable(): void {
		$parsed = $this->round_trip(
			array(
				array(
					'url_path'  => '/sparse',
					'hit_count' => 1,
					// Everything else absent.
				),
			)
		);

		$this->assertSame( '/sparse', $parsed[0]['url_path'] );
		$this->assertSame( '', $parsed[0]['classification'] );
		$this->assertSame( '', $parsed[0]['referrer'] );
	}
}
