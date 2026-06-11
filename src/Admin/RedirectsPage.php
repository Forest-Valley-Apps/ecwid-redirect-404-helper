<?php
/**
 * Manual redirects admin page.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Redirect\RedirectStore;
use FV\WPEcwidRedirectHelper\Request\RequestPath;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Upsell\UpgradeCta;
use FV\WPEcwidRedirectHelper\Url\RuleMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * The Redirects screen: create/enable/disable/delete manual WP-layer 301s.
 *
 * Add/toggle/single-delete run through admin-post.php; the list table's bulk
 * delete runs on the page's `load-` event (wired by {@see Menu}). Every
 * mutation is capability- + nonce-guarded and follows post/redirect/get.
 *
 * Creating an exact rule marks the matching 404-log row as redirected, which
 * is what makes the one-click "Create redirect" flow from the log close the
 * loop.
 */
final class RedirectsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'fv-erh-redirects';

	/**
	 * Required capability for the page and all of its actions.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The admin-post action that creates a rule.
	 *
	 * @var string
	 */
	public const ACTION_ADD = 'fv_erh_redirect_add';

	/**
	 * The admin-post action that enables/disables a rule.
	 *
	 * @var string
	 */
	public const ACTION_TOGGLE = 'fv_erh_redirect_toggle';

	/**
	 * The admin-post action that deletes a single rule.
	 *
	 * @var string
	 */
	public const ACTION_DELETE = 'fv_erh_redirect_delete';

	/**
	 * Rule repository.
	 *
	 * @var RedirectStore
	 */
	private RedirectStore $store;

	/**
	 * 404 log repository (for marking rows redirected).
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * Path normalizer.
	 *
	 * @var RuleMatcher
	 */
	private RuleMatcher $matcher;

	/**
	 * Paid-tier CTA renderer.
	 *
	 * @var UpgradeCta
	 */
	private UpgradeCta $cta;

	/**
	 * Constructor.
	 *
	 * @param RedirectStore|null $store   Rule repository (injectable for tests).
	 * @param NotFoundLog|null   $log     Log repository (injectable for tests).
	 * @param RuleMatcher|null   $matcher Path normalizer (injectable for tests).
	 * @param UpgradeCta|null    $cta     Paid-tier CTA renderer (injectable for tests).
	 */
	public function __construct(
		?RedirectStore $store = null,
		?NotFoundLog $log = null,
		?RuleMatcher $matcher = null,
		?UpgradeCta $cta = null
	) {
		$this->store   = $store ?? new RedirectStore();
		$this->log     = $log ?? new NotFoundLog();
		$this->matcher = $matcher ?? new RuleMatcher();
		$this->cta     = $cta ?? new UpgradeCta();
	}

	/**
	 * Register the action handlers (menu placement is owned by {@see Menu}).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_ADD, array( $this, 'handle_add' ) );
		add_action( 'admin_post_' . self::ACTION_TOGGLE, array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
	}

	/**
	 * Create a rule from the add form.
	 *
	 * @return void
	 */
	public function handle_add(): void {
		$this->guard( self::ACTION_ADD );

		// Percent-decoded like the request paths these rules must match
		// ({@see RequestPath::decode()}) — sanitize_text_field() would delete
		// every `%xx` octet, corrupting any non-ASCII source before storage.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard() right above; inputs are percent-decoded with control characters rejected, then validated by RedirectStore::add().
		$source      = isset( $_POST['fv_source'] ) ? RequestPath::decode( (string) wp_unslash( $_POST['fv_source'] ) ) : '';
		$destination = isset( $_POST['fv_destination'] ) ? RequestPath::decode( (string) wp_unslash( $_POST['fv_destination'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = $this->store->add( $source, $destination );

		if ( RedirectStore::ADDED === $result && false === strpos( $source, '*' ) ) {
			$this->log->mark_redirected( $this->matcher->normalize_path( trim( $source ) ) );
		}

		$this->redirect_back( $result );
	}

	/**
	 * Enable/disable a rule from its row action.
	 *
	 * @return void
	 */
	public function handle_toggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard() right below (the nonce covers the id).
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->guard( self::ACTION_TOGGLE . '_' . $id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request verified above.
		$state = isset( $_GET['state'] ) && '1' === sanitize_key( wp_unslash( $_GET['state'] ) );

		if ( $id > 0 ) {
			$this->store->set_active( $id, $state );
		}

		$this->redirect_back( $state ? 'enabled' : 'disabled' );
	}

	/**
	 * Delete a single rule from its row action.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard() right below (the nonce covers the id).
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->guard( self::ACTION_DELETE . '_' . $id );

		if ( $id > 0 ) {
			$this->store->delete( array( $id ) );
		}

		$this->redirect_back( 'deleted' );
	}

	/**
	 * Process the list table's bulk delete before the page renders.
	 *
	 * Runs on `load-{page_hook}`: a redirect is still possible here because
	 * admin.php has not printed anything yet.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; verified below before any mutation.
		if ( 'delete' !== Menu::requested_action() || ! isset( $_REQUEST['ids'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		check_admin_referer( 'bulk-fv-erh-redirects' );

		$ids = isset( $_REQUEST['ids'] ) ? array_filter( array_map( 'absint', (array) $_REQUEST['ids'] ) ) : array();

		if ( array() !== $ids ) {
			$this->store->delete( $ids );
		}

		wp_safe_redirect( add_query_arg( 'fv_erh_notice', 'deleted', $this->page_url() ) );
		exit;
	}

	/**
	 * Render the Redirects screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ecwid-redirect-404-helper' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Redirects', 'ecwid-redirect-404-helper' ) . '</h1>';

		$this->render_notice();
		$this->render_scope_note();
		$this->render_add_form();

		$table = new RedirectListTable( $this->store->all() );
		$table->prepare_items();

		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->display();
		echo '</form>';

		$this->render_upgrade_cta();

		echo '</div>';
	}

	/**
	 * Surface the bulk-mapping / migration-import CTA.
	 *
	 * In-context here because this is exactly where the merchant feels the free
	 * tier's deliberate limit: one rule at a time, by hand. The app does the
	 * same job in bulk and for migrations. Dismissible and shown once-then-gone.
	 *
	 * @return void
	 */
	private function render_upgrade_cta(): void {
		$this->cta->render(
			'redirects-bulk-migration',
			__( 'Migrating a store, or fixing many URLs at once?', 'ecwid-redirect-404-helper' ),
			__( 'These redirects are added one at a time, by hand. The Redirect & 404 Manager app maps old URLs to new ones in bulk and imports a migration map from Shopify, WooCommerce, or BigCommerce — the same work, without the per-URL effort.', 'ecwid-redirect-404-helper' ),
			array(
				array(
					'label'  => __( 'Bulk-map URLs in the app', 'ecwid-redirect-404-helper' ),
					'target' => DeepLink::TARGET_BULK_MAPPING,
				),
				array(
					'label'  => __( 'Import a migration', 'ecwid-redirect-404-helper' ),
					'target' => DeepLink::TARGET_MIGRATION_IMPORT,
				),
			)
		);
	}

	/**
	 * Capability + nonce guard shared by every admin-post handler.
	 *
	 * @param string $action The action/nonce name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the redirects page with a notice code, then stop.
	 *
	 * @param string $notice Notice code understood by {@see self::notice_message()}.
	 * @return void
	 */
	private function redirect_back( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'fv_erh_notice', $notice, $this->page_url() ) );
		exit;
	}

	/**
	 * The redirects page URL.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Render the WP-layer scope note.
	 *
	 * This framing is deliberate (and review-relevant): the free plugin
	 * redirects at the WordPress layer only, and we say so instead of letting
	 * it be mistaken for the storefront-layer capability of the paid app.
	 *
	 * @return void
	 */
	private function render_scope_note(): void {
		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__(
			'These are WordPress-layer redirects: the server answers with an HTTP 301 for any URL on this site that would otherwise be a 404. They cannot redirect between pages inside the embedded Ecwid storefront — those navigations happen in the visitor\'s browser and never reach WordPress. Storefront-layer redirects are what the Redirect & 404 Manager app inside Ecwid provides.',
			'ecwid-redirect-404-helper'
		);
		echo '</p></div>';
	}

	/**
	 * Render the add-rule form.
	 *
	 * @return void
	 */
	private function render_add_form(): void {
		// Prefill from the 404 log's "Create redirect" link. Display-only: the
		// value just seeds the (nonce-guarded) form below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$prefill = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

		echo '<h2>' . esc_html__( 'Add redirect', 'ecwid-redirect-404-helper' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_ADD );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ADD ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="fv_source">' . esc_html__( 'Source path', 'ecwid-redirect-404-helper' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="fv_source" name="fv_source" value="' . esc_attr( $prefill ) . '" placeholder="/old-page" required />';
		echo '<p class="description">' . esc_html__( 'Wildcards: /old-blog/* forwards the matched part to a /new-blog/* destination; *-p123 matches any path ending in -p123.', 'ecwid-redirect-404-helper' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="fv_destination">' . esc_html__( 'Destination', 'ecwid-redirect-404-helper' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="fv_destination" name="fv_destination" placeholder="/new-page" required />';
		echo '<p class="description">' . esc_html__( 'A path on this site (/new-page) or a full URL (https://example.com/new-page).', 'ecwid-redirect-404-helper' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Add redirect', 'ecwid-redirect-404-helper' ) );
		echo '</form>';
	}

	/**
	 * Render the notice for the current redirect, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// Display-only read of our own post/redirect/get marker; the originating
		// action was already nonce-verified before this redirect was issued.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['fv_erh_notice'] ) ? sanitize_key( wp_unslash( $_GET['fv_erh_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		list( $type, $message ) = $this->notice_message( $code );
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Map a notice code to a [type, message] pair.
	 *
	 * @param string $code Notice code from the redirect.
	 * @return array{0:string,1:string} The notice type ('success'/'error') and message.
	 */
	private function notice_message( string $code ): array {
		switch ( $code ) {
			case RedirectStore::ADDED:
				return array( 'success', __( 'Redirect created. It will serve on the next request for the source URL.', 'ecwid-redirect-404-helper' ) );
			case RedirectStore::INVALID_SOURCE:
				return array( 'error', __( 'The source must be a path on this site, like /old-page or /old-section/*.', 'ecwid-redirect-404-helper' ) );
			case RedirectStore::INVALID_DESTINATION:
				return array( 'error', __( 'The destination must be a path (/new-page) or a full http(s) URL, and must differ from the source.', 'ecwid-redirect-404-helper' ) );
			case RedirectStore::DUPLICATE:
				return array( 'error', __( 'A redirect for this source already exists.', 'ecwid-redirect-404-helper' ) );
			case 'deleted':
				return array( 'success', __( 'Redirect deleted.', 'ecwid-redirect-404-helper' ) );
			case 'enabled':
				return array( 'success', __( 'Redirect enabled.', 'ecwid-redirect-404-helper' ) );
			case 'disabled':
				return array( 'success', __( 'Redirect disabled.', 'ecwid-redirect-404-helper' ) );
			default:
				return array( '', '' );
		}
	}
}
