<?php
/**
 * Contextual, dismissible paid-tier upgrade CTAs.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Upsell;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the in-context "do this in the app" prompts and records their
 * dismissals.
 *
 * Review-safe by construction (no nagware): a CTA is only ever rendered by a
 * page that has a genuine reason to (a deleted-product verdict, an Ecwid
 * storefront 404, the redirects screen), each is dismissible, and a dismissal
 * is persistent and per-CTA — once dismissed, that prompt never returns. The
 * link itself only *opens* the hosted app; the plugin never writes over the API.
 */
final class UpgradeCta {

	/**
	 * The admin-post action that records a dismissal.
	 *
	 * @var string
	 */
	public const ACTION_DISMISS = 'fv_erh_dismiss_cta';

	/**
	 * Option holding the list of dismissed CTA keys.
	 *
	 * @var string
	 */
	private const OPTION = 'fv_erh_cta_dismissed';

	/**
	 * Required capability to see and dismiss a CTA.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Whether the shared style block has already been printed this request.
	 *
	 * @var bool
	 */
	private static bool $style_printed = false;

	/**
	 * Deep-link builder, or null until first needed.
	 *
	 * Built lazily ({@see self::deep_link()}) so merely constructing this class —
	 * which happens whenever an admin page is wired up — does no discovery or
	 * cache reads; that work only happens when a CTA actually renders.
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
	 * Register the dismiss handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Whether the given CTA has been dismissed.
	 *
	 * @param string $cta_key Stable CTA identifier.
	 * @return bool
	 */
	public function is_dismissed( string $cta_key ): bool {
		return in_array( $cta_key, $this->dismissed(), true );
	}

	/**
	 * Record the dismissal of a CTA (post/redirect/get).
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() right below (the nonce covers the cta key).
		$cta_key = isset( $_GET['cta'] ) ? sanitize_key( wp_unslash( $_GET['cta'] ) ) : '';

		check_admin_referer( self::ACTION_DISMISS . '_' . $cta_key );

		if ( '' !== $cta_key ) {
			$this->dismiss( $cta_key );
		}

		$fallback = admin_url();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $fallback );
		exit;
	}

	/**
	 * Render one contextual CTA, unless it has been dismissed.
	 *
	 * @param string $cta_key Stable CTA identifier (also the dismiss key).
	 * @param string $heading Box heading (already translated).
	 * @param string $body    Body copy (already translated, plain text).
	 * @param array  $actions Buttons; each ['label' => string, 'target' => string], first is primary.
	 * @return void
	 */
	public function render( string $cta_key, string $heading, string $body, array $actions ): void {
		if ( ! current_user_can( self::CAPABILITY ) || $this->is_dismissed( $cta_key ) || array() === $actions ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'cta', $cta_key, admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ) ),
			self::ACTION_DISMISS . '_' . $cta_key
		);

		$this->print_style();

		echo '<div class="fv-erh-cta">';
		echo '<span class="fv-erh-cta__tag">' . esc_html__( 'Paid feature', 'ecwid-redirect-404-helper' ) . '</span>';
		echo '<h2 class="fv-erh-cta__title">' . esc_html( $heading ) . '</h2>';
		echo '<p class="fv-erh-cta__body">' . esc_html( $body ) . '</p>';

		echo '<p class="fv-erh-cta__actions">';

		$primary = true;
		foreach ( $actions as $action ) {
			$label  = (string) ( $action['label'] ?? '' );
			$target = (string) ( $action['target'] ?? DeepLink::TARGET_HOME );
			if ( '' === $label ) {
				continue;
			}

			$classes = $primary ? 'button button-primary' : 'button';
			$primary = false;

			// The ↗ marks a link that opens the hosted app in a new tab.
			printf(
				'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s ↗</a> ',
				esc_attr( $classes ),
				esc_url( $this->deep_link()->url_for( $target ) ),
				esc_html( $label )
			);
		}

		echo '<a class="fv-erh-cta__dismiss" href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'Dismiss', 'ecwid-redirect-404-helper' )
			. '</a>';

		echo '</p>';
		echo '</div>';
	}

	/**
	 * The dismissed CTA keys.
	 *
	 * @return string[]
	 */
	private function dismissed(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * Persist a CTA dismissal.
	 *
	 * @param string $cta_key Stable CTA identifier.
	 * @return void
	 */
	private function dismiss( string $cta_key ): void {
		$dismissed = $this->dismissed();
		if ( in_array( $cta_key, $dismissed, true ) ) {
			return;
		}

		$dismissed[] = $cta_key;
		update_option( self::OPTION, $dismissed, false );
	}

	/**
	 * Print the shared CTA style block once per request.
	 *
	 * @return void
	 */
	private function print_style(): void {
		if ( self::$style_printed ) {
			return;
		}

		self::$style_printed = true;

		echo '<style>
			.fv-erh-cta { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #27ae60; padding:4px 16px 12px; margin:12px 0; max-width:760px; }
			.fv-erh-cta__tag { display:inline-block; margin-top:12px; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#fff; background:#27ae60; }
			.fv-erh-cta__title { margin:8px 0 4px; font-size:14px; color:#2c3e50; }
			.fv-erh-cta__body { margin:0 0 12px; color:#2c3e50; }
			.fv-erh-cta__actions { margin:0; }
			.fv-erh-cta__dismiss { margin-left:8px; color:#646970; text-decoration:none; }
		</style>';
	}
}
