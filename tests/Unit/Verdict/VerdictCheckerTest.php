<?php
/**
 * Unit tests for the deleted-vs-typo verdict checker.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit\Verdict;

use Brain\Monkey\Functions;
use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Api\EcwidCatalogClient;
use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Tests\Unit\WpdbTestCase;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;

/**
 * Covers the verdict decision matrix and the batch/lazy-fetch behaviour.
 *
 * The catalog and backend clients are real instances over a URL-routed
 * `wp_remote_get` stub (both classes are final); the log repository runs
 * against the shared $wpdb mock.
 */
final class VerdictCheckerTest extends WpdbTestCase {

	private const STORE_ID = 130416012;
	private const TOKEN    = 'public_AbC123';

	/**
	 * HTTP GETs to the backend deleted-entities endpoint this test observed.
	 *
	 * @var int
	 */
	private int $deleted_endpoint_calls = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->deleted_endpoint_calls = 0;

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['code'] ?? 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// The backend client's transient cache: always cold, writes accepted.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Build a checker whose HTTP layer is routed by URL.
	 *
	 * @param array<int,string> $catalog_status  Entity id => 'exists'|'not-found'|'unknown'.
	 * @param array|string      $deleted_response 'untracked', 'error', or
	 *                                            array{products:int[],categories:int[]}.
	 * @return VerdictChecker
	 */
	private function checker( array $catalog_status, $deleted_response ): VerdictChecker {
		$calls = &$this->deleted_endpoint_calls;

		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url ) use ( $catalog_status, $deleted_response, &$calls ) {
				if ( false !== strpos( $url, '/api/storefront/deleted/' ) ) {
					++$calls;

					if ( 'untracked' === $deleted_response ) {
						return array(
							'code' => 404,
							'body' => json_encode( array( 'error' => 'store-not-tracked' ) ),
						);
					}

					if ( 'error' === $deleted_response ) {
						return array(
							'code' => 503,
							'body' => 'down',
						);
					}

					return array(
						'code' => 200,
						'body' => json_encode( array_merge( array( 'v' => 1 ), $deleted_response ) ),
					);
				}

				// Catalog entity lookup URLs end in /products/<id>?... or /categories/<id>?...
				if ( preg_match( '~/(?:products|categories)/(\d+)\?~', $url, $m ) ) {
					$status = $catalog_status[ (int) $m[1] ] ?? 'unknown';
					$code   = array(
						'exists'    => 200,
						'not-found' => 404,
						'unknown'   => 500,
					)[ $status ];

					return array( 'code' => $code );
				}

				return array( 'code' => 500 );
			}
		);

		return new VerdictChecker(
			new EcwidCatalogClient( self::STORE_ID, self::TOKEN ),
			new BackendClient( self::STORE_ID, BackendClient::STAGING_BASE_URL ),
			new NotFoundLog()
		);
	}

	/**
	 * Stub the repository reads: the entities needing a verdict + the remaining count.
	 *
	 * @param array<int,array{classification:string,entity_id:int}> $entities  Batch rows.
	 * @param int                                                   $remaining Count after the run.
	 */
	private function stub_log_reads( array $entities, int $remaining = 0 ): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $entities );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( $remaining );
	}

	/**
	 * Expect one set_verdict() write for an entity.
	 *
	 * @param string $classification Expected classification.
	 * @param int    $entity_id      Expected entity id.
	 * @param string $verdict        Expected verdict.
	 */
	private function expect_verdict_write( string $classification, int $entity_id, string $verdict ): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->withArgs(
				function ( $table, $data, $where ) use ( $classification, $entity_id, $verdict ) {
					return 'wp_fv_erh_404_log' === $table
						&& $verdict === $data['verdict']
						&& '' !== $data['verdict_checked_at']
						&& $classification === $where['classification']
						&& $entity_id === $where['entity_id'];
				}
			)
			->andReturn( 1 );
	}

	public function test_live_entity_is_verdicted_in_catalog(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 42,
				),
			)
		);
		$this->expect_verdict_write( 'product', 42, VerdictChecker::VERDICT_IN_CATALOG );

		$result = $this->checker( array( 42 => 'exists' ), array() )->run();

		$this->assertSame( 1, $result['checked'] );
		// A live entity never consults the deletion history.
		$this->assertSame( 0, $this->deleted_endpoint_calls );
	}

	public function test_absent_entity_with_deletion_record_is_deleted(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 42,
				),
			)
		);
		$this->expect_verdict_write( 'product', 42, VerdictChecker::VERDICT_DELETED );

		$checker = $this->checker(
			array( 42 => 'not-found' ),
			array(
				'products'   => array( 42 ),
				'categories' => array(),
			)
		);

		$this->assertSame( 1, $checker->run()['checked'] );
	}

	public function test_absent_entity_without_deletion_record_never_existed(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 99999,
				),
			)
		);
		$this->expect_verdict_write( 'product', 99999, VerdictChecker::VERDICT_NEVER_EXISTED );

		$checker = $this->checker(
			array( 99999 => 'not-found' ),
			array(
				'products'   => array( 42 ),
				'categories' => array(),
			)
		);

		$this->assertSame( 1, $checker->run()['checked'] );
	}

	public function test_category_verdict_uses_the_categories_list(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'category',
					'entity_id'      => 7,
				),
			)
		);
		$this->expect_verdict_write( 'category', 7, VerdictChecker::VERDICT_DELETED );

		$checker = $this->checker(
			array( 7 => 'not-found' ),
			array(
				// Same id in the *products* list must not count for a category.
				'products'   => array(),
				'categories' => array( 7 ),
			)
		);

		$this->assertSame( 1, $checker->run()['checked'] );
	}

	public function test_untracked_store_yields_not_in_catalog(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 42,
				),
			)
		);
		$this->expect_verdict_write( 'product', 42, VerdictChecker::VERDICT_NOT_IN_CATALOG );

		$checker = $this->checker( array( 42 => 'not-found' ), 'untracked' );

		$this->assertSame( 1, $checker->run()['checked'] );
	}

	public function test_catalog_unknown_writes_no_verdict(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 42,
				),
			),
			1
		);
		$this->wpdb->shouldReceive( 'update' )->never();

		$result = $this->checker( array( 42 => 'unknown' ), array() )->run();

		$this->assertSame( 0, $result['checked'] );
		$this->assertSame( 1, $result['remaining'] );
	}

	public function test_backend_error_leaves_absent_entity_unverdicted(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 42,
				),
			),
			1
		);
		$this->wpdb->shouldReceive( 'update' )->never();

		$result = $this->checker( array( 42 => 'not-found' ), 'error' )->run();

		$this->assertSame( 0, $result['checked'] );
	}

	public function test_deletion_history_is_fetched_once_per_run(): void {
		$this->stub_log_reads(
			array(
				array(
					'classification' => 'product',
					'entity_id'      => 1,
				),
				array(
					'classification' => 'product',
					'entity_id'      => 2,
				),
			)
		);
		$this->expect_verdict_write( 'product', 1, VerdictChecker::VERDICT_DELETED );
		$this->expect_verdict_write( 'product', 2, VerdictChecker::VERDICT_NEVER_EXISTED );

		$checker = $this->checker(
			array(
				1 => 'not-found',
				2 => 'not-found',
			),
			array(
				'products'   => array( 1 ),
				'categories' => array(),
			)
		);

		$result = $checker->run();

		$this->assertSame( 2, $result['checked'] );
		$this->assertSame( 1, $this->deleted_endpoint_calls );
	}

	public function test_empty_batch_short_circuits(): void {
		$this->stub_log_reads( array(), 0 );
		$this->wpdb->shouldReceive( 'update' )->never();

		Functions\when( 'wp_remote_get' )->alias(
			static function () {
				self::fail( 'No HTTP call expected for an empty batch.' );
			}
		);

		$checker = new VerdictChecker(
			new EcwidCatalogClient( self::STORE_ID, self::TOKEN ),
			new BackendClient( self::STORE_ID, BackendClient::STAGING_BASE_URL ),
			new NotFoundLog()
		);

		$this->assertSame(
			array(
				'checked'   => 0,
				'remaining' => 0,
			),
			$checker->run()
		);
	}

	public function test_for_current_connection_null_when_not_connected(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertNull( VerdictChecker::for_current_connection() );
	}
}
