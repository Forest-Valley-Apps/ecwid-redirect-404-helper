<?php
/**
 * Builds deep-links into the hosted Redirect & 404 Manager app.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Upsell;

use FV\WPEcwidRedirectHelper\Connection\EcwidPluginDiscovery;

defined( 'ABSPATH' ) || exit;

/**
 * Constructs the URL a paid-tier CTA points at: when the app is installed, a
 * deep-link that opens the hosted Redirect & 404 Manager app inside the
 * merchant's Ecwid admin on the screen matching what the free plugin surfaced;
 * otherwise the App Market listing so they can install it.
 *
 * **Surface, don't call.** This only ever builds a link the merchant clicks —
 * the plugin never writes redirects over the API. The merchant performs the
 * action inside the app they already have.
 *
 * URL shapes and the target-key mechanism are the hosted app's contract,
 * confirmed 2026-06-07 (see `docs/parent-product-tasks.md` Part 2):
 *  - Installed:  `https://my.ecwid.com/store/{storeId}#app:name=<slug>&app_state=<key>`
 *  - Not installed (listing): `https://my.ecwid.com/store/{storeId}#apps:view=app&name=<slug>`
 * The target key travels in Ecwid's `app_state` parameter; the slug is
 * `seo-redirect-manager` on prod, `seo-redirect-manager-dev` on dev/staging.
 *
 * Whether the app is installed is resolved render-safely by
 * {@see AppInstallStatus} (cache-only). When that is indeterminate, this falls
 * back to the listing URL — which works whether or not the app is installed.
 */
final class DeepLink {

	/**
	 * Target: bulk old→new URL mapping screen.
	 *
	 * @var string
	 */
	public const TARGET_BULK_MAPPING = 'bulk-mapping';

	/**
	 * Target: migration import (Shopify / Woo / BigCommerce → Ecwid).
	 *
	 * @var string
	 */
	public const TARGET_MIGRATION_IMPORT = 'migration-import';

	/**
	 * Target: automatic deleted-product redirect settings.
	 *
	 * @var string
	 */
	public const TARGET_DELETED_REDIRECTS = 'deleted-redirects';

	/**
	 * Target: storefront-layer (JS) redirect settings.
	 *
	 * @var string
	 */
	public const TARGET_STOREFRONT_LAYER = 'storefront-layer';

	/**
	 * Target: app home / dashboard (also the fallback for any unknown target).
	 *
	 * @var string
	 */
	public const TARGET_HOME = 'home';

	/**
	 * The recognised target keys. An unknown target degrades to TARGET_HOME,
	 * matching the contract the hosted app implements (unknown → home, never an
	 * error).
	 *
	 * @var string[]
	 */
	private const TARGETS = array(
		self::TARGET_BULK_MAPPING,
		self::TARGET_MIGRATION_IMPORT,
		self::TARGET_DELETED_REDIRECTS,
		self::TARGET_STOREFRONT_LAYER,
		self::TARGET_HOME,
	);

	/**
	 * Production app slug, as registered in the Ecwid App Market.
	 *
	 * @var string
	 */
	public const SLUG_PROD = 'seo-redirect-manager';

	/**
	 * Dev/staging app slug.
	 *
	 * @var string
	 */
	public const SLUG_DEV = 'seo-redirect-manager-dev';

	/**
	 * Deep-link template for opening the installed app on a target screen.
	 *
	 * @var string
	 */
	private const DEEP_LINK_TEMPLATE = 'https://my.ecwid.com/store/{store_id}#app:name={slug}&app_state={target}';

	/**
	 * Template for the in-control-panel App Market listing (app not installed).
	 *
	 * @var string
	 */
	private const LISTING_TEMPLATE = 'https://my.ecwid.com/store/{store_id}#apps:view=app&name={slug}';

	/**
	 * Generic fallback when no store id is known (cannot build a store-scoped
	 * control-panel URL). Filterable via `fv_erh_app_market_url`.
	 *
	 * @var string
	 */
	public const DEFAULT_MARKET_URL = 'https://www.ecwid.com/apps';

	/**
	 * Discovered Ecwid store id (0 when none).
	 *
	 * @var int
	 */
	private int $store_id;

	/**
	 * App Market slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Whether the app is installed (null when indeterminate).
	 *
	 * @var bool|null
	 */
	private ?bool $installed;

	/**
	 * Generic (storeless) App Market URL.
	 *
	 * @var string
	 */
	private string $market_url;

	/**
	 * Constructor.
	 *
	 * @param int       $store_id   Discovered Ecwid store id (0 when none).
	 * @param string    $slug       App Market slug.
	 * @param bool|null $installed  Whether the app is installed (null = unknown).
	 * @param string    $market_url Generic fallback URL when no store id is known.
	 */
	public function __construct( int $store_id, string $slug, ?bool $installed, string $market_url = self::DEFAULT_MARKET_URL ) {
		$this->store_id   = $store_id;
		$this->slug       = $slug;
		$this->installed  = $installed;
		$this->market_url = $market_url;
	}

	/**
	 * Build an instance from the live environment (discovery + filters + caches).
	 *
	 * @return self
	 */
	public static function from_environment(): self {
		$store_id = EcwidPluginDiscovery::discover()->store_id();

		/**
		 * Filter the App Market slug. Defaults to the production slug; point at
		 * the dev slug when testing against staging (alongside
		 * `fv_erh_backend_base_url`).
		 *
		 * @param string $slug Default production slug.
		 */
		$slug = (string) apply_filters( 'fv_erh_paid_app_slug', self::SLUG_PROD );

		/**
		 * Filter the generic App Market URL used when no store id is known.
		 *
		 * @param string $market_url Default generic listing URL.
		 */
		$market_url = (string) apply_filters( 'fv_erh_app_market_url', self::DEFAULT_MARKET_URL );

		// Render-safe: cache-only, never blocks on the network.
		$installed = $store_id > 0 ? AppInstallStatus::for_store( $store_id )->is_installed() : null;

		return new self( $store_id, $slug, $installed, $market_url );
	}

	/**
	 * The URL a CTA for the given target should point at.
	 *
	 * Deep-links into the app only when it is known to be installed; otherwise
	 * (not installed, or indeterminate) returns the App Market listing — so a CTA
	 * always has a valid, useful destination.
	 *
	 * @param string $target One of the TARGET_* keys (unknown → home).
	 * @return string
	 */
	public function url_for( string $target ): string {
		$target = in_array( $target, self::TARGETS, true ) ? $target : self::TARGET_HOME;

		if ( $this->store_id <= 0 ) {
			return $this->market_url;
		}

		if ( true === $this->installed ) {
			return $this->expand( self::DEEP_LINK_TEMPLATE, $target );
		}

		return $this->expand( self::LISTING_TEMPLATE, $target );
	}

	/**
	 * Whether a CTA will deep-link into the installed app (vs. the listing).
	 *
	 * @return bool
	 */
	public function can_deep_link(): bool {
		return $this->store_id > 0 && true === $this->installed;
	}

	/**
	 * Substitute the store id, slug, and target into a URL template.
	 *
	 * @param string $template Template with {store_id}/{slug}/{target} tokens.
	 * @param string $target   Validated target key.
	 * @return string
	 */
	private function expand( string $template, string $target ): string {
		return str_replace(
			array( '{store_id}', '{slug}', '{target}' ),
			array( (string) $this->store_id, rawurlencode( $this->slug ), rawurlencode( $target ) ),
			$template
		);
	}
}
