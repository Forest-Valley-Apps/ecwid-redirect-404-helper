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
 * The free/paid line is drawn at **effort, not capability**, and the page says
 * so: the free plugin finds, classifies, and hand-fixes 404s at the WordPress
 * layer; the app automates and bulk-handles them, and reaches the storefront
 * layer a WordPress plugin cannot.
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
	 * Deep-link builder.
	 *
	 * @var DeepLink
	 */
	private DeepLink $deep_link;

	/**
	 * Constructor.
	 *
	 * @param DeepLink|null $deep_link Deep-link builder (injectable for tests).
	 */
	public function __construct( ?DeepLink $deep_link = null ) {
		$this->deep_link = $deep_link ?? DeepLink::from_environment();
	}

	/**
	 * Render the upgrade page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ecwid-redirect-404-helper' ) );
		}

		$this->print_style();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Upgrade — Powered by Redirect & 404 Manager', 'ecwid-redirect-404-helper' ) . '</h1>';

		echo '<p class="fv-erh-upgrade__lead">';
		echo esc_html__(
			'This free plugin finds, classifies, and lets you hand-fix 404s at the WordPress layer. The Redirect & 404 Manager app — which runs inside your Ecwid admin — automates that work in bulk and reaches the Ecwid storefront layer a WordPress plugin cannot. Each button below opens the app on the matching screen; nothing is changed on your store until you act there.',
			'ecwid-redirect-404-helper'
		);
		echo '</p>';

		if ( ! $this->deep_link->can_deep_link() ) {
			echo '<p class="fv-erh-upgrade__note">'
				. esc_html__( 'Connect your Ecwid store (Settings) to deep-link straight into these screens. Until then the buttons open the app listing.', 'ecwid-redirect-404-helper' )
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
				'title'  => __( 'Bulk old→new URL mapping', 'ecwid-redirect-404-helper' ),
				'body'   => __( 'Map many old URLs to their new destinations at once, instead of adding redirects one at a time — the migration workhorse generic tools cannot do for Ecwid.', 'ecwid-redirect-404-helper' ),
				'cta'    => __( 'Open bulk mapping', 'ecwid-redirect-404-helper' ),
			),
			array(
				'target' => DeepLink::TARGET_MIGRATION_IMPORT,
				'title'  => __( 'Migration redirect import', 'ecwid-redirect-404-helper' ),
				'body'   => __( 'Import a redirect map from a Shopify, WooCommerce, or BigCommerce migration so old store URLs keep working after the move to Ecwid.', 'ecwid-redirect-404-helper' ),
				'cta'    => __( 'Open migration import', 'ecwid-redirect-404-helper' ),
			),
			array(
				'target' => DeepLink::TARGET_DELETED_REDIRECTS,
				'title'  => __( 'Automatic deleted-product redirects', 'ecwid-redirect-404-helper' ),
				'body'   => __( 'When a product or category is deleted, the app redirects its URL automatically (to the parent category or your homepage) — no manual rule per deletion.', 'ecwid-redirect-404-helper' ),
				'cta'    => __( 'Open auto-redirects', 'ecwid-redirect-404-helper' ),
			),
			array(
				'target' => DeepLink::TARGET_STOREFRONT_LAYER,
				'title'  => __( 'Storefront-layer redirects', 'ecwid-redirect-404-helper' ),
				'body'   => __( 'Catch 404s inside the embedded Ecwid storefront — the product/category sub-routes that happen in the browser and never reach WordPress, so WordPress-layer redirects cannot touch them.', 'ecwid-redirect-404-helper' ),
				'cta'    => __( 'Open storefront redirects', 'ecwid-redirect-404-helper' ),
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
			esc_url( $this->deep_link->url_for( $feature['target'] ) ),
			esc_html( $feature['cta'] )
		);
		echo '</div>';
	}

	/**
	 * Print the page style block.
	 *
	 * @return void
	 */
	private function print_style(): void {
		echo '<style>
			.fv-erh-upgrade__lead { max-width:760px; font-size:14px; color:#2c3e50; }
			.fv-erh-upgrade__note { max-width:760px; color:#646970; }
			.fv-erh-upgrade__grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; max-width:760px; margin-top:16px; }
			.fv-erh-upgrade__card { background:#fff; border:1px solid #c3c4c7; border-top:3px solid #27ae60; padding:4px 16px 16px; }
			.fv-erh-upgrade__card h2 { font-size:15px; color:#2c3e50; }
			.fv-erh-upgrade__card p { color:#2c3e50; }
		</style>';
	}
}
