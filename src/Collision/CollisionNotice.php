<?php
/**
 * Site-wide admin notice for slug collisions.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Collision;

use FV\WPEcwidRedirectHelper\Admin\LogPage;

defined( 'ABSPATH' ) || exit;

/**
 * Warns administrators — anywhere in wp-admin — when published content slugs
 * collide with Ecwid's `-p<id>` / `-c<id>` URL pattern.
 *
 * Deliberately read-only against the scan cache ({@see CollisionScanner::cached_collisions()}):
 * an ordinary admin load never triggers a scan. Dismissal is persistent but
 * keyed to the collision set's fingerprint, so a *new* collision resurfaces
 * the notice. The 404 Log page is skipped — its panel already shows the full
 * detail there.
 */
final class CollisionNotice {

	/**
	 * The admin-post action that records a dismissal.
	 *
	 * @var string
	 */
	public const ACTION_DISMISS = 'fv_erh_dismiss_collisions';

	/**
	 * Required capability to see and dismiss the notice.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Maximum slugs listed inline in the notice.
	 *
	 * @var int
	 */
	private const MAX_LISTED = 3;

	/**
	 * Collision scanner.
	 *
	 * @var CollisionScanner
	 */
	private CollisionScanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param CollisionScanner|null $scanner Scanner (injectable for tests).
	 */
	public function __construct( ?CollisionScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CollisionScanner();
	}

	/**
	 * Register the notice and its dismiss handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the notice when there are undismissed collisions.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// The 404 Log page renders the full panel; no double warning there.
		// Display-only routing read, no mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( LogPage::PAGE_SLUG === $page ) {
			return;
		}

		$collisions = $this->scanner->cached_collisions();
		if ( null === $collisions || array() === $collisions || $this->scanner->is_dismissed( $collisions ) ) {
			return;
		}

		$slugs = array_slice( array_column( $collisions, 'slug' ), 0, self::MAX_LISTED );

		$dashboard_url = admin_url( 'admin.php?page=' . LogPage::PAGE_SLUG );
		$dismiss_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ),
			self::ACTION_DISMISS
		);

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'Redirect & 404 Helper for Ecwid:', 'ecwid-redirect-404-helper' )
			. '</strong> ';

		printf(
			/* translators: 1: number of pages, 2: example slugs. */
			esc_html(
				_n(
					'%1$d published page has a slug ending in an Ecwid product/category pattern (%2$s). The Ecwid store widget hijacks such URLs and shows a "not found" store page instead of your content.',
					'%1$d published pages have slugs ending in an Ecwid product/category pattern (%2$s). The Ecwid store widget hijacks such URLs and shows a "not found" store page instead of your content.',
					count( $collisions ),
					'ecwid-redirect-404-helper'
				)
			),
			(int) count( $collisions ),
			esc_html( implode( ', ', $slugs ) )
		);

		echo ' <a href="' . esc_url( $dashboard_url ) . '">'
			. esc_html__( 'Review the affected pages', 'ecwid-redirect-404-helper' )
			. '</a> | <a href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'Dismiss', 'ecwid-redirect-404-helper' )
			. '</a></p></div>';
	}

	/**
	 * Record the dismissal of the current collision set (post/redirect/get).
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		check_admin_referer( self::ACTION_DISMISS );

		$collisions = $this->scanner->cached_collisions();
		if ( null !== $collisions ) {
			$this->scanner->dismiss( $collisions );
		}

		$fallback = admin_url();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $fallback );
		exit;
	}
}
