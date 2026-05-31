<?php
/**
 * Ecwid URL classifier.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a URL or path as an Ecwid product, an Ecwid category, or an
 * ordinary WordPress page.
 *
 * Pure logic — no WordPress and no network dependency. This is a PHP port of
 * the parent product's canonical `extractEcwidEntity()` helper
 * (`packages/shared/src/validation.ts`), which detects Ecwid's
 * `-(p|c)<id>` slug suffixes and `/p/<id>` `/c/<id>` hash-route segments.
 *
 * Deliberate parity decisions, matching the canonical (backend) implementation
 * rather than the storefront JS variant:
 *  - Case-sensitive: Ecwid only ever generates lowercase `-p` / `-c` suffixes,
 *    so an upper-case `-P123` is treated as an ordinary slug, not a product.
 *  - Products are checked before categories, so a URL carrying both markers
 *    resolves to the product deterministically.
 *  - The legacy storefront-only `/cid/<id>` category route is NOT recognised;
 *    the canonical helper omits it on purpose.
 *
 * NOTE on false-404 collisions: this classifier cannot tell a real Ecwid
 * category from an ordinary WordPress page whose slug happens to end in
 * `-c123` (e.g. `/about-us-c123`). Detecting that collision is the job of the
 * false-404 warner in a later session; here such a slug is reported as a
 * category by design.
 */
final class UrlClassifier {

	/**
	 * Classification: the URL points at an Ecwid product.
	 *
	 * @var string
	 */
	public const TYPE_PRODUCT = 'product';

	/**
	 * Classification: the URL points at an Ecwid category.
	 *
	 * @var string
	 */
	public const TYPE_CATEGORY = 'category';

	/**
	 * Classification: the URL is an ordinary WordPress page (no Ecwid marker).
	 *
	 * @var string
	 */
	public const TYPE_WP_PAGE = 'wp-page';

	/**
	 * Classify a URL or path.
	 *
	 * @param string $url Absolute URL, path, or hash route.
	 * @return array{type:string,id:int|null} `type` is one of the TYPE_*
	 *                                         constants; `id` is the Ecwid
	 *                                         entity id, or null for a WP page.
	 */
	public function classify( string $url ): array {
		$entity = $this->extract_ecwid_entity( $url );

		if ( null === $entity ) {
			return array(
				'type' => self::TYPE_WP_PAGE,
				'id'   => null,
			);
		}

		return $entity;
	}

	/**
	 * Extract the Ecwid entity ({type, id}) carried by a URL, if any.
	 *
	 * @param string $url Absolute URL, path, or hash route.
	 * @return array{type:string,id:int}|null Null when no Ecwid marker is found.
	 */
	public function extract_ecwid_entity( string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}

		$matches = array();

		if ( preg_match( '~-p(\d+)(?:[/?#]|$)~', $url, $matches )
			|| preg_match( '~/p/(\d+)(?:[/?#]|$)~', $url, $matches ) ) {
			return array(
				'type' => self::TYPE_PRODUCT,
				'id'   => (int) $matches[1],
			);
		}

		if ( preg_match( '~-c(\d+)(?:[/?#]|$)~', $url, $matches )
			|| preg_match( '~/c/(\d+)(?:[/?#]|$)~', $url, $matches ) ) {
			return array(
				'type' => self::TYPE_CATEGORY,
				'id'   => (int) $matches[1],
			);
		}

		return null;
	}
}
