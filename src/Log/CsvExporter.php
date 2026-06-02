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
				$line[] = (string) ( $row[ $key ] ?? '' );
			}

			fputcsv( $stream, $line );
		}
	}
}
