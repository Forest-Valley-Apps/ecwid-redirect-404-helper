<?php
/**
 * Derives which URL layer a 404-log row can be fixed at, and where to route it.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;

defined( 'ABSPATH' ) || exit;

/**
 * The free-vs-app capability boundary, made visible per 404-log row.
 *
 * Every 404 is fixable at exactly one of two layers, and which one is *derived*
 * from the row's existing classification (and, for routing, its catalog
 * verdict) — there is no stored "layer" column:
 *
 *  - **WordPress layer** — ordinary WP URLs (the classifier's `wp-page`). The
 *    free plugin hand-fixes these itself with a real HTTP 301.
 *  - **Storefront layer** — Ecwid product/category sub-routes that 404 *inside*
 *    the embedded store, in the visitor's browser, never reaching WordPress. No
 *    WordPress plugin can 301 them; only the hosted app reaches this layer.
 *
 * This is the honest, review-safe funnel: the boundary is simply true, shown on
 * the merchant's own data, and routed — WP rows to the free redirect editor,
 * storefront rows to a deep-link into the app. **Surface, never perform** — this
 * class only decides where a link points; it never writes a redirect.
 *
 * Reused beyond the per-row split: F4's counted CTA counts the storefront-layer
 * rows via {@see self::for_classification()}. See `specs/helper-as-funnel-plan.md`
 * for the capability line F2–F5 obey.
 */
final class RowLayer {

	/**
	 * The WordPress layer — fixable by the free plugin's own 301s.
	 *
	 * @var string
	 */
	public const LAYER_WP = 'wp';

	/**
	 * The Ecwid storefront layer — only the hosted app can reach it.
	 *
	 * @var string
	 */
	public const LAYER_STOREFRONT = 'storefront';

	/**
	 * The layer a row with the given classification can be fixed at.
	 *
	 * Only Ecwid product/category routes are storefront-layer; anything else
	 * (including an unknown classification) defaults to the WordPress layer,
	 * where the free "Create redirect" action still does something useful.
	 *
	 * @param string $classification One of the {@see UrlClassifier} TYPE_* values.
	 * @return string Self::LAYER_WP or self::LAYER_STOREFRONT.
	 */
	public static function for_classification( string $classification ): string {
		$storefront = array( UrlClassifier::TYPE_PRODUCT, UrlClassifier::TYPE_CATEGORY );

		return in_array( $classification, $storefront, true )
			? self::LAYER_STOREFRONT
			: self::LAYER_WP;
	}

	/**
	 * Human label for a layer badge.
	 *
	 * @param string $layer Self::LAYER_WP or self::LAYER_STOREFRONT.
	 * @return string
	 */
	public static function label( string $layer ): string {
		return self::LAYER_STOREFRONT === $layer
			? __( 'Storefront', 'redirect-404-helper-for-ecwid' )
			: __( 'WordPress', 'redirect-404-helper-for-ecwid' );
	}

	/**
	 * The deep-link target a storefront-layer row should route to.
	 *
	 * A row confirmed to be a *deleted* catalog entity routes to the app's
	 * deleted-product auto-redirect screen; every other storefront row routes to
	 * the general storefront-layer redirect editor.
	 *
	 * @param string $verdict The row's catalog verdict (may be empty).
	 * @return string A {@see DeepLink} TARGET_* key.
	 */
	public static function deep_link_target( string $verdict ): string {
		return VerdictChecker::VERDICT_DELETED === $verdict
			? DeepLink::TARGET_DELETED_REDIRECTS
			: DeepLink::TARGET_STOREFRONT_LAYER;
	}
}
