<?php
/**
 * Deleted-vs-typo catalog verdict checker.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Verdict;

use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Api\EcwidCatalogClient;
use FV\WPEcwidRedirectHelper\Connection\ConnectionState;
use FV\WPEcwidRedirectHelper\Connection\EcwidPluginDiscovery;
use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves, for each Ecwid-classified 404, what its entity id means *now*:
 *
 *  - {@see self::VERDICT_IN_CATALOG}     — the entity is live (the catalog
 *    answered 200): the 404 is a broken link/path, not a missing entity.
 *  - {@see self::VERDICT_DELETED}        — absent from the catalog AND in the
 *    hosted app's webhook-sourced deletion history: genuinely deleted.
 *  - {@see self::VERDICT_NEVER_EXISTED}  — absent from the catalog, the store
 *    is tracked by the hosted app, but no deletion is on record: a mistyped /
 *    fabricated id. (Honest caveat: an entity deleted *before* the hosted app
 *    was installed has no deletion record and lands here too.)
 *  - {@see self::VERDICT_NOT_IN_CATALOG} — absent from the catalog and the
 *    store never installed the hosted app, so deleted-vs-typo cannot be told
 *    apart. The dashboard labels this honestly.
 *
 * The public storefront token alone cannot make the deleted/typo distinction —
 * Ecwid's deleted-items endpoint requires the secret token this plugin must
 * never touch — which is why the hosted app's deletion history is consulted.
 * Note the public token also only sees *enabled* entities, so a disabled
 * product reads as absent from the catalog.
 *
 * Lookups are batched and capped ({@see self::run()}), deduplicated per entity
 * (one catalog call covers every logged path pointing at the same id), cached
 * on the log rows themselves, and re-checked only after
 * {@see self::RECHECK_TTL}. Nothing here ever runs on a front-end request.
 */
final class VerdictChecker {

	/**
	 * Verdict: the entity is live in the catalog — the link is broken, not the entity.
	 *
	 * @var string
	 */
	public const VERDICT_IN_CATALOG = 'in-catalog';

	/**
	 * Verdict: deleted (webhook-sourced deletion record exists).
	 *
	 * @var string
	 */
	public const VERDICT_DELETED = 'deleted';

	/**
	 * Verdict: no such entity on record — a mistyped or fabricated id.
	 *
	 * @var string
	 */
	public const VERDICT_NEVER_EXISTED = 'never-existed';

	/**
	 * Verdict: absent from the catalog; no deletion history available for this store.
	 *
	 * @var string
	 */
	public const VERDICT_NOT_IN_CATALOG = 'not-in-catalog';

	/**
	 * Seconds before a stored verdict is considered stale and re-checked.
	 *
	 * @var int
	 */
	public const RECHECK_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Default maximum catalog lookups per run.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH = 25;

	/**
	 * Ecwid catalog client (public storefront token).
	 *
	 * @var EcwidCatalogClient
	 */
	private EcwidCatalogClient $catalog;

	/**
	 * Hosted-backend client (deletion history).
	 *
	 * @var BackendClient
	 */
	private BackendClient $backend;

	/**
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * Constructor.
	 *
	 * @param EcwidCatalogClient $catalog Catalog client.
	 * @param BackendClient      $backend Hosted-backend client.
	 * @param NotFoundLog        $log     Log repository.
	 */
	public function __construct( EcwidCatalogClient $catalog, BackendClient $backend, NotFoundLog $log ) {
		$this->catalog = $catalog;
		$this->backend = $backend;
		$this->log     = $log;
	}

	/**
	 * Build a checker for the current connection, or null when not connected.
	 *
	 * The single gating point for every caller (dashboard action, cron): the
	 * merchant must have clicked Connect and the Ecwid plugin's credentials
	 * must still be discoverable.
	 *
	 * @return self|null
	 */
	public static function for_current_connection(): ?self {
		if ( ! ( new ConnectionState() )->is_connected() ) {
			return null;
		}

		$discovery = EcwidPluginDiscovery::discover();
		if ( ! $discovery->is_ready() ) {
			return null;
		}

		return new self(
			new EcwidCatalogClient( $discovery->store_id(), $discovery->public_token() ),
			BackendClient::for_store( $discovery->store_id() ),
			new NotFoundLog()
		);
	}

	/**
	 * Check a capped batch of entities and store their verdicts.
	 *
	 * An indeterminate answer (catalog transport error, backend error) writes
	 * no verdict, so the entity is naturally retried on a later run.
	 *
	 * @param int $max_lookups Maximum catalog lookups this run.
	 * @return array{checked:int,remaining:int} Entities resolved this run and
	 *                                          entities still needing a verdict.
	 */
	public function run( int $max_lookups = self::DEFAULT_BATCH ): array {
		$stale_before = $this->stale_before();
		$entities     = $this->log->entities_needing_verdict( $stale_before, max( 1, $max_lookups ) );

		$checked = 0;
		$now     = gmdate( 'Y-m-d H:i:s' );

		// Fetched lazily: only a NOT_FOUND answer needs the deletion history,
		// and the client caches it in a transient across runs anyway.
		$deleted_loaded = false;
		$deleted        = null;

		foreach ( $entities as $entity ) {
			$status = $this->entity_status( $entity['classification'], $entity['entity_id'] );

			if ( EcwidCatalogClient::UNKNOWN === $status ) {
				continue;
			}

			if ( EcwidCatalogClient::EXISTS === $status ) {
				$this->log->set_verdict( $entity['classification'], $entity['entity_id'], self::VERDICT_IN_CATALOG, $now );
				++$checked;
				continue;
			}

			// NOT_FOUND — consult the hosted app's deletion history.
			if ( ! $deleted_loaded ) {
				$deleted        = $this->backend->get_deleted_entities();
				$deleted_loaded = true;
			}

			$verdict = $this->absent_verdict( $entity['classification'], $entity['entity_id'], $deleted );
			if ( null === $verdict ) {
				continue;
			}

			$this->log->set_verdict( $entity['classification'], $entity['entity_id'], $verdict, $now );
			++$checked;
		}

		return array(
			'checked'   => $checked,
			'remaining' => $this->log->count_entities_needing_verdict( $stale_before ),
		);
	}

	/**
	 * Count the entities currently needing a verdict (for the dashboard button).
	 *
	 * @return int
	 */
	public function pending_count(): int {
		return $this->log->count_entities_needing_verdict( $this->stale_before() );
	}

	/**
	 * The cutoff datetime: verdicts checked before it are stale.
	 *
	 * @return string GMT datetime.
	 */
	private function stale_before(): string {
		return gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_TTL );
	}

	/**
	 * Look up an entity's live catalog status.
	 *
	 * @param string $classification Entity classification (product/category).
	 * @param int    $entity_id      Ecwid entity id.
	 * @return string One of the EcwidCatalogClient EXISTS / NOT_FOUND / UNKNOWN constants.
	 */
	private function entity_status( string $classification, int $entity_id ): string {
		if ( UrlClassifier::TYPE_PRODUCT === $classification ) {
			return $this->catalog->product_exists( $entity_id );
		}

		return $this->catalog->category_exists( $entity_id );
	}

	/**
	 * Resolve the verdict for an entity that is absent from the catalog.
	 *
	 * @param string     $classification Entity classification (product/category).
	 * @param int        $entity_id      Ecwid entity id.
	 * @param array|null $deleted        Result of {@see BackendClient::get_deleted_entities()}.
	 * @return string|null One of the VERDICT_* constants, or null when indeterminate.
	 */
	private function absent_verdict( string $classification, int $entity_id, ?array $deleted ): ?string {
		if ( null === $deleted ) {
			// Backend unreachable: deleted-vs-typo cannot be resolved right now.
			return null;
		}

		if ( empty( $deleted['tracked'] ) ) {
			return self::VERDICT_NOT_IN_CATALOG;
		}

		$list = UrlClassifier::TYPE_PRODUCT === $classification
			? $deleted['products']
			: $deleted['categories'];

		return in_array( $entity_id, $list, true )
			? self::VERDICT_DELETED
			: self::VERDICT_NEVER_EXISTED;
	}
}
