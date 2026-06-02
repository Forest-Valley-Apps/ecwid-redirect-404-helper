<?php
/**
 * Shared base for tests that need a mocked $wpdb.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Installs a Mockery $wpdb (prefix `wp_`) into $GLOBALS around each test and
 * captures every `prepare()` call into {@see self::$prepared} so assertions can
 * inspect the SQL and its arguments. `prepare()` returns `PREPARED_<n>` so
 * follow-up `query()` expectations can be matched per statement.
 */
abstract class WpdbTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every prepare() call captured as ['sql' => …, 'args' => […]].
	 *
	 * @var array<int,array{sql:string,args:array<int,mixed>}>
	 */
	protected array $prepared = array();

	/**
	 * The shared wpdb mock (also installed as $GLOBALS['wpdb']).
	 *
	 * @var Mockery\MockInterface
	 */
	protected $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->prepared = array();

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARACTER SET utf8mb4' )
			->byDefault();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql, ...$args ) {
					$this->prepared[] = array(
						'sql'  => $sql,
						'args' => $args,
					);

					return 'PREPARED_' . count( $this->prepared );
				}
			)
			->byDefault();

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}
}
