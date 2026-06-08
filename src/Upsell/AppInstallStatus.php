<?php
/**
 * Resolves whether the hosted app is installed, from cached signals only.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Upsell;

use FV\WPEcwidRedirectHelper\Api\BackendClient;

defined( 'ABSPATH' ) || exit;

/**
 * Render-safe resolver for "does this store have the paid app installed?".
 *
 * The CTA destination (deep-link into the app vs. the App Market listing) hinges
 * on this. It is consulted while admin pages render, so it must **never block on
 * the network** — it reads only what is already cached:
 *
 *  1. The authoritative app-status flag, when warmed (the hourly cron fetches it
 *     via {@see BackendClient::get_app_installed()}).
 *  2. Failing that, the deleted-endpoint `tracked` flag as a proxy for "installed
 *     at some point" — already warmed by the verdict feature for connected stores.
 *  3. Failing both, `null` (indeterminate) — the caller falls back to the listing
 *     URL, which works whether or not the app is installed.
 *
 * The proxy is only a fallback for missing data, never an override of a definite
 * `false` from app-status (a since-uninstalled store reads `tracked:true` but
 * `installed:false`, and the authoritative `false` must win). See
 * `docs/parent-product-tasks.md` Part 3.
 */
final class AppInstallStatus {

	/**
	 * Backend client for the store.
	 *
	 * @var BackendClient
	 */
	private BackendClient $client;

	/**
	 * Constructor.
	 *
	 * @param BackendClient $client Backend client for the store.
	 */
	public function __construct( BackendClient $client ) {
		$this->client = $client;
	}

	/**
	 * Build a resolver for a store against the site-configured backend.
	 *
	 * @param int $store_id Ecwid store id.
	 * @return self
	 */
	public static function for_store( int $store_id ): self {
		return new self( BackendClient::for_store( $store_id ) );
	}

	/**
	 * Whether the app is installed, from cache only.
	 *
	 * @return bool|null True/false when known; null when no cached signal exists.
	 */
	public function is_installed(): ?bool {
		$authoritative = $this->client->peek_app_installed();
		if ( null !== $authoritative ) {
			return $authoritative;
		}

		// Proxy: present only as a fallback when the authoritative flag is absent.
		return $this->client->peek_deleted_tracked();
	}
}
