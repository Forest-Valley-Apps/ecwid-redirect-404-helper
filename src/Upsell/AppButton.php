<?php
/**
 * The persistent, branded "open the app" button each admin screen carries.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Upsell;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reusable brand anchor a plugin screen places in its title bar: an
 * outlined button carrying the app's own logo that opens the hosted Redirect &
 * 404 Manager app (or its App Market listing when the merchant has not installed
 * it yet). A screen renders it at most once; the 404 Log is the first to do so,
 * with the remaining screens (Redirects, Settings, Upgrade) to follow.
 *
 * Distinct from {@see UpgradeCta}: that surfaces dismissible, data-driven "do
 * this in the app" prompts in the page body; this is the undismissable "the app
 * lives here" link in the header. Like every paid-tier surface it only ever
 * *opens* the app — the plugin never writes over the API.
 */
final class AppButton {

	/**
	 * Capability required to see the button (matches the plugin's admin screens).
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Deep-link builder, or null until first needed.
	 *
	 * Resolved lazily ({@see self::deep_link()}) so merely constructing this does
	 * no store discovery or cache reads; that work only happens on render.
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
	 * Render the button. Designed to sit beside an `<h1 class="wp-heading-inline">`
	 * before the `.wp-header-end` marker; it floats itself to the top-right via its
	 * own class.
	 *
	 * @param string $target Deep-link target to open (default: the app home).
	 * @return void
	 */
	public function render( string $target = DeepLink::TARGET_HOME ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$deep_link = $this->deep_link();

		$label = $deep_link->can_deep_link()
			? __( 'Open in Redirect & 404 Manager', 'redirect-404-helper-for-ecwid' )
			: __( 'Get the Redirect & 404 Manager app', 'redirect-404-helper-for-ecwid' );

		// The logo is decorative (the adjacent label names the destination), so its
		// alt is empty; the ↗ marks a link that opens the app in a new tab.
		printf(
			'<a class="fv-erh-app-btn" href="%1$s" target="_blank" rel="noopener noreferrer">'
				. '<img class="fv-erh-app-btn__logo" src="%2$s" alt="" width="20" height="10" />'
				. '<span>%3$s</span><span aria-hidden="true">↗</span></a>',
			esc_url( $deep_link->url_for( $target ) ),
			esc_url( plugins_url( 'assets/img/app-logo.svg', FV_ERH_PLUGIN_FILE ) ),
			esc_html( $label )
		);
	}
}
