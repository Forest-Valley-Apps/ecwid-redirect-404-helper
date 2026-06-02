<?php
/**
 * Verifies a discovered Ecwid connection against the live services.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Connection;

use FV\WPEcwidRedirectHelper\Api\BackendClient;
use FV\WPEcwidRedirectHelper\Api\EcwidCatalogClient;

defined( 'ABSPATH' ) || exit;

/**
 * Confirms a connection actually works by exercising both services it depends on:
 *  1. the hosted backend's rules endpoint — proves the service is reachable and
 *     answering for this store id (public, no auth). The backend serves an empty
 *     ruleset for *any* well-formed store id, so this is a liveness check, not
 *     proof the store is registered there. As a side effect a successful check
 *     primes the cached ruleset (see {@see BackendClient::ping()}).
 *  2. the Ecwid catalog — proves the discovered public token authenticates
 *     against the discovered store id. A token issued for a *different* store is
 *     rejected by Ecwid's own store-scoping (401/403), so a stale store-id/token
 *     pairing surfaces here; there is no additional client-side cross-check.
 *
 * The verdict is one of the result constants so the caller (the settings screen)
 * can show a specific, actionable message rather than a bare pass/fail.
 */
final class ConnectionVerifier {

	/**
	 * Result: both checks passed.
	 *
	 * @var string
	 */
	public const OK = 'ok';

	/**
	 * Result: the backend could not be reached or did not answer with a ruleset.
	 *
	 * @var string
	 */
	public const BACKEND_UNREACHABLE = 'backend-unreachable';

	/**
	 * Result: the catalog rejected the public token.
	 *
	 * @var string
	 */
	public const AUTH_FAILED = 'auth-failed';

	/**
	 * Result: the catalog could not be reached to confirm the token.
	 *
	 * @var string
	 */
	public const CATALOG_UNREACHABLE = 'catalog-unreachable';

	/**
	 * Hosted-backend client.
	 *
	 * @var BackendClient
	 */
	private BackendClient $backend;

	/**
	 * Ecwid catalog client.
	 *
	 * @var EcwidCatalogClient
	 */
	private EcwidCatalogClient $catalog;

	/**
	 * Constructor.
	 *
	 * @param BackendClient      $backend Hosted-backend client.
	 * @param EcwidCatalogClient $catalog Ecwid catalog client.
	 */
	public function __construct( BackendClient $backend, EcwidCatalogClient $catalog ) {
		$this->backend = $backend;
		$this->catalog = $catalog;
	}

	/**
	 * Run both checks and return a single verdict.
	 *
	 * @return string One of the result constants.
	 */
	public function verify(): string {
		if ( ! $this->backend->ping() ) {
			return self::BACKEND_UNREACHABLE;
		}

		switch ( $this->catalog->verify_credentials() ) {
			case EcwidCatalogClient::AUTH_OK:
				return self::OK;
			case EcwidCatalogClient::AUTH_FAILED:
				return self::AUTH_FAILED;
			default:
				return self::CATALOG_UNREACHABLE;
		}
	}
}
