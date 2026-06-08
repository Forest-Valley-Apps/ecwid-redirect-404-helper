<?php
/**
 * CSV writer for the 404 log.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Streams log rows as CSV.
 *
 * Pure stream-in/stream-out (no WordPress, no HTTP headers) so escaping —
 * commas, quotes, newlines inside URLs — is unit-testable against an in-memory
 * stream; `fputcsv()` does the RFC-4180 quoting. The admin-post handler owns
 * the headers and passes `php://output`.
 */
final class CsvExporter {

	/**
	 * Exported log-row keys, in column order. The keys double as the header
	 * labels.
	 *
	 * @var array<int,string>
	 */
	private const COLUMNS = array(
		'url_path',
		'classification',
		'entity_id',
		'verdict',
		'status',
		'hit_count',
		'first_seen',
		'last_seen',
		'referrer',
	);

	/**
	 * Write the header line and every row to a stream.
	 *
	 * @param resource $stream Writable stream.
	 * @param iterable $rows   Log rows (arrays keyed by column), e.g. the chunked generator.
	 * @return void
	 */
	public function write( $stream, iterable $rows ): void {
		fputcsv( $stream, self::COLUMNS );

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( self::COLUMNS as $key ) {
				$line[] = self::neutralize( (string) ( $row[ $key ] ?? '' ) );
			}

			fputcsv( $stream, $line );
		}
	}

	/**
	 * Defuse spreadsheet formula injection.
	 *
	 * Logged URLs and referrers are visitor-controlled, so a cell may begin with
	 * a character Excel/Sheets treats as the start of a formula. Prefixing such a
	 * value with a single quote forces the spreadsheet to read it as literal text
	 * without altering the underlying string for any other consumer.
	 *
	 * @param string $value Raw cell value.
	 * @return string Safe cell value.
	 */
	private static function neutralize( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
