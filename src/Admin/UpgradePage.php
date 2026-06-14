<?php
/**
 * "Powered by Redirect & 404 Manager" upgrade page.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Upsell\DeepLink;

defined( 'ABSPATH' ) || exit;

/**
 * The single, navigated-to upgrade page: one honest place that lays out what
 * the hosted Redirect & 404 Manager app adds on top of the free plugin, each
 * with a deep-link into the app inside the merchant's Ecwid admin.
 *
 * This is not a CTA injected into another screen — the merchant chooses to open
 * it from the menu, so it lists every paid feature unconditionally. The
 * contextual, dismissible prompts elsewhere ({@see \FV\WPEcwidRedirectHelper\Upsell\UpgradeCta})
 * are what stay out of the way; this page is where the detail lives.
 *
 * The free/paid line is drawn at **layer + scale**, and the page says so: the
 * free plugin finds, classifies, and hand-fixes 404s at the WordPress layer; the
 * app bulk-handles and automates them, and reaches the storefront layer a
 * WordPress plugin physically cannot. (See `specs/helper-as-funnel-plan.md`.)
 */
final class UpgradePage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'fv-erh-upgrade';

	/**
	 * Required capability for the page.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Deep-link builder, or null until first needed.
	 *
	 * Built lazily ({@see self::deep_link()}) so constructing the page (which the
	 * admin menu does on every admin request) does no discovery or cache reads —
	 * that work only happens when the page actually renders.
	 *
	 * @var DeepLink|null
	 */
	private ?DeepLink $deep_link;

	/**
	 * Constructor.
	 *
	 * @param DeepLink|null $deep_link Deep-link builder (injectable for tests).
	 */
	public function __construct( ?DeepLink $deep_link = null ) {
		$this->deep_link = $deep_link;
	}

	/**
	 * The deep-link builder, resolved from the environment on first use.
	 *
	 * @return DeepLink
	 */
	private function deep_link(): DeepLink {
		if ( null === $this->deep_link ) {
			$this->deep_link = DeepLink::from_environment();
		}

		return $this->deep_link;
	}

	/**
	 * Render the upgrade page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'redirect-404-helper-for-ecwid' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Upgrade — Powered by Redirect & 404 Manager', 'redirect-404-helper-for-ecwid' ) . '</h1>';

		echo '<p class="fv-erh-upgrade__lead">';
		echo esc_html__(
			'This free plugin finds, classifies, and lets you hand-fix 404s at the WordPress layer. The Redirect & 404 Manager app — which runs inside your Ecwid admin — automates that work in bulk and reaches the Ecwid storefront layer a WordPress plugin cannot. Each button below opens the app on the matching screen; nothing is changed on your store until you act there.',
			'redirect-404-helper-for-ecwid'
		);
		echo '</p>';

		if ( ! $this->deep_link()->can_deep_link() ) {
			echo '<p class="fv-erh-upgrade__note">'
				. esc_html__( 'Connect your Ecwid store (Settings) to deep-link straight into these screens. Until then the buttons open the app listing.', 'redirect-404-helper-for-ecwid' )
				. '</p>';
		}

		echo '<div class="fv-erh-upgrade__grid">';
		foreach ( $this->features() as $feature ) {
			$this->render_feature( $feature );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * The paid features to list.
	 *
	 * @return array<int,array{target:string,title:string,body:string,cta:string}>
	 */
	private function features(): array {
		return array(
			array(
				'target' => DeepLink::TARGET_BULK_MAPPING,
				'title'  => __( 'Bulk old→new URL mapping', 'redirect-404-helper-for-ecwid' ),
				'body'   => __( 'Map many old URLs to their new destinations at once, instead of adding redirects one at a time — the migration workhorse generic tools cannot do for Ecwid.', 'redirect-404-helper-for-ecwid' ),
				'cta'    => __( 'Open bulk mapping', 'redirect-404-helper-for-ecwid' ),
			),
			array(
				'target' => DeepLink::TARGET_MIGRATION_IMPORT,
				'title'  => __( 'Migration redirect import', 'redirect-404-helper-for-ecwid' ),
				'body'   => __( 'Import a redirect map from a Shopify, WooCommerce, or BigCommerce migration so old store URLs keep working after the move to Ecwid.', 'redirect-404-helper-for-ecwid' ),
				'cta'    => __( 'Open migration import', 'redirect-404-helper-for-ecwid' ),
			),
			array(
				'target' => DeepLink::TARGET_DELETED_REDIRECTS,
				'title'  => __( 'Automatic deleted-product redirects', 'redirect-404-helper-for-ecwid' ),
				'body'   => __( 'When a product or category is deleted, the app redirects its URL automatically (to the parent category or your homepage) — no manual rule per deletion.', 'redirect-404-helper-for-ecwid' ),
				'cta'    => __( 'Open auto-redirects', 'redirect-404-helper-for-ecwid' ),
			),
			array(
				'target' => DeepLink::TARGET_STOREFRONT_LAYER,
				'title'  => __( 'Storefront-layer redirects', 'redirect-404-helper-for-ecwid' ),
				'body'   => __( 'Catch 404s inside the embedded Ecwid storefront — the product/category sub-routes that happen in the browser and never reach WordPress, so WordPress-layer redirects cannot touch them.', 'redirect-404-helper-for-ecwid' ),
				'cta'    => __( 'Open storefront redirects', 'redirect-404-helper-for-ecwid' ),
			),
		);
	}

	/**
	 * Render one feature card.
	 *
	 * @param array{target:string,title:string,body:string,cta:string} $feature Feature definition.
	 * @return void
	 */
	private function render_feature( array $feature ): void {
		echo '<div class="fv-erh-upgrade__card">';
		echo '<h2>' . esc_html( $feature['title'] ) . '</h2>';
		echo '<p>' . esc_html( $feature['body'] ) . '</p>';
		// The ↗ marks a link that opens the hosted app in a new tab.
		printf(
			'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s ↗</a>',
			esc_url( $this->deep_link()->url_for( $feature['target'] ) ),
			esc_html( $feature['cta'] )
		);
		echo '</div>';
	}
}
