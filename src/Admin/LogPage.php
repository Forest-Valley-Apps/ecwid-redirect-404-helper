<?php
/**
 * 404 log dashboard page.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Collision\CollisionScanner;
use FV\WPEcwidRedirectHelper\Log\CsvExporter;
use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Upsell\UpgradeCta;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;

defined( 'ABSPATH' ) || exit;

/**
 * The 404 Log screen: the list table, its delete actions, and the CSV export.
 *
 * Deletes run on the page's `load-` event (wired by {@see Menu}) so they can
 * follow post/redirect/get before admin.php prints any output; the CSV export
 * runs through admin-post.php. Every mutation is capability- + nonce-guarded.
 */
final class LogPage {

	/**
	 * Admin page slug (also the top-level menu slug).
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'fv-erh-404-log';

	/**
	 * Required capability for the page and all of its actions.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The admin-post action that streams the CSV export.
	 *
	 * @var string
	 */
	public const ACTION_EXPORT = 'fv_erh_export_csv';

	/**
	 * Catalog lookups per "Check catalog" click.
	 *
	 * @var int
	 */
	private const CHECK_BATCH = 25;

	/**
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * Collision scanner.
	 *
	 * @var CollisionScanner
	 */
	private CollisionScanner $collisions;

	/**
	 * Paid-tier CTA renderer.
	 *
	 * @var UpgradeCta
	 */
	private UpgradeCta $cta;

	/**
	 * Constructor.
	 *
	 * @param NotFoundLog|null      $log        Log repository (injectable for tests).
	 * @param CollisionScanner|null $collisions Collision scanner (injectable for tests).
	 * @param UpgradeCta|null       $cta        Paid-tier CTA renderer (injectable for tests).
	 */
	public function __construct( ?NotFoundLog $log = null, ?CollisionScanner $collisions = null, ?UpgradeCta $cta = null ) {
		$this->log        = $log ?? new NotFoundLog();
		$this->collisions = $collisions ?? new CollisionScanner();
		$this->cta        = $cta ?? new UpgradeCta();
	}

	/**
	 * Register the export handler (menu placement is owned by {@see Menu}).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( $this, 'handle_export' ) );
	}

	/**
	 * Process delete actions before the page renders (post/redirect/get).
	 *
	 * Runs on `load-{page_hook}`: a redirect is still possible here because
	 * admin.php has not printed anything yet.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		$handlers = array(
			'delete'            => array( $this, 'handle_delete' ),
			'check_catalog'     => array( $this, 'handle_check_catalog' ),
			'rescan_collisions' => array( $this, 'handle_rescan_collisions' ),
		);

		$action = Menu::requested_action();
		if ( ! isset( $handlers[ $action ] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		$handlers[ $action ]();
	}

	/**
	 * Delete the selected log rows (post/redirect/get).
	 *
	 * @return void
	 */
	private function handle_delete(): void {
		// Single row link carries an id; the bulk form carries ids[].
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified per-branch below.
		$single_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

		if ( $single_id > 0 ) {
			check_admin_referer( 'fv_erh_log_delete_' . $single_id );
			$ids = array( $single_id );
		} else {
			check_admin_referer( 'bulk-fv-erh-404s' );
			$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) $_REQUEST['ids'] ) : array();
		}

		$ids = array_filter( $ids );

		if ( array() !== $ids ) {
			$this->log->delete( $ids );
		}

		wp_safe_redirect( add_query_arg( 'fv_erh_notice', 'log-deleted', $this->page_url( true ) ) );
		exit;
	}

	/**
	 * Run a capped catalog-verdict batch (post/redirect/get).
	 *
	 * @return void
	 */
	private function handle_check_catalog(): void {
		check_admin_referer( 'fv_erh_check_catalog' );

		$checker = VerdictChecker::for_current_connection();

		if ( null === $checker ) {
			wp_safe_redirect( add_query_arg( 'fv_erh_notice', 'verdicts-unavailable', $this->page_url( true ) ) );
			exit;
		}

		$result = $checker->run( self::CHECK_BATCH );

		wp_safe_redirect(
			add_query_arg(
				array(
					'fv_erh_notice'  => 'verdicts-checked',
					'fv_erh_checked' => $result['checked'],
					'fv_erh_left'    => $result['remaining'],
				),
				$this->page_url( true )
			)
		);
		exit;
	}

	/**
	 * Force a fresh collision scan (post/redirect/get).
	 *
	 * @return void
	 */
	private function handle_rescan_collisions(): void {
		check_admin_referer( 'fv_erh_rescan_collisions' );

		$this->collisions->get_collisions( true );

		wp_safe_redirect( add_query_arg( 'fv_erh_notice', 'collisions-rescanned', $this->page_url( true ) ) );
		exit;
	}

	/**
	 * Stream the whole log as a CSV download.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

		check_admin_referer( self::ACTION_EXPORT );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=404-log-' . gmdate( 'Ymd-His' ) . '.csv' );

		// Streaming straight to the response body — WP_Filesystem has no
		// equivalent for php://output.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( 'php://output', 'w' );

		( new CsvExporter() )->write( $stream, $this->log->all_chunked() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream );
		exit;
	}

	/**
	 * Render the 404 Log screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ecwid-redirect-404-helper' ) );
		}

		$table = new LogListTable( $this->log, self::current_query_args() );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT ),
			self::ACTION_EXPORT
		);

		$this->print_styles();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '404 Log', 'ecwid-redirect-404-helper' ) . '</h1>';
		echo ' <a href="' . esc_url( $export_url ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'ecwid-redirect-404-helper' ) . '</a>';
		$this->render_check_catalog_button();
		echo '<hr class="wp-header-end" />';

		$this->render_notice();
		$this->render_collision_panel();
		$this->render_upgrade_cta();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->search_box( __( 'Search URLs', 'ecwid-redirect-404-helper' ), 'fv-erh-404' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The dashboard query args from the current request.
	 *
	 * Display-only filter state (no mutation), so no nonce is involved; every
	 * value is sanitized and the repository additionally whitelists
	 * orderby/order.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 *
	 * @return array{search:string,classification:string,status:string,verdict:string,orderby:string,order:string}
	 */
	public static function current_query_args(): array {
		$classification = isset( $_GET['classification'] ) ? sanitize_key( wp_unslash( $_GET['classification'] ) ) : '';
		$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$verdict        = isset( $_GET['verdict'] ) ? sanitize_key( wp_unslash( $_GET['verdict'] ) ) : '';

		if ( ! isset( LogListTable::classification_labels()[ $classification ] ) ) {
			$classification = '';
		}

		if ( ! isset( LogListTable::status_labels()[ $status ] ) ) {
			$status = '';
		}

		if ( ! isset( LogListTable::verdict_labels()[ $verdict ] ) ) {
			$verdict = '';
		}

		return array(
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'classification' => $classification,
			'status'         => $status,
			'verdict'        => $verdict,
			'orderby'        => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'          => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Nonce'd URL for one of this page's own actions.
	 *
	 * The nonce action is always `fv_erh_<action>`, matching what each
	 * handler passes to `check_admin_referer()`.
	 *
	 * @param string $action Action name (e.g. 'check_catalog').
	 * @return string
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => $action,
				),
				admin_url( 'admin.php' )
			),
			'fv_erh_' . $action
		);
	}

	/**
	 * The page URL, optionally preserving the current filter state.
	 *
	 * @param bool $with_filters Whether to carry the current filters along.
	 * @return string
	 */
	private function page_url( bool $with_filters = false ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( ! $with_filters ) {
			return $url;
		}

		// The search term travels as `s` in the list table form.
		$args      = self::current_query_args();
		$args['s'] = $args['search'];
		unset( $args['search'] );

		return add_query_arg( array_filter( $args, 'strlen' ), $url );
	}

	/**
	 * Render the "Check catalog" header button when a connection is available.
	 *
	 * @return void
	 */
	private function render_check_catalog_button(): void {
		$checker = VerdictChecker::for_current_connection();
		if ( null === $checker ) {
			return;
		}

		$pending = $checker->pending_count();

		$url = $this->action_url( 'check_catalog' );

		$label = $pending > 0
			/* translators: %d: number of Ecwid entities awaiting a catalog check. */
			? sprintf( __( 'Check catalog (%d)', 'ecwid-redirect-404-helper' ), $pending )
			: __( 'Check catalog', 'ecwid-redirect-404-helper' );

		echo ' <a href="' . esc_url( $url ) . '" class="page-title-action">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Render the false-404 collision panel.
	 *
	 * Runs the (cached) scan — this page is the one admin surface allowed to
	 * trigger it. With no collisions only a one-line all-clear with a Rescan
	 * link is shown.
	 *
	 * @return void
	 */
	private function render_collision_panel(): void {
		$collisions = $this->collisions->get_collisions();

		$rescan_url = $this->action_url( 'rescan_collisions' );

		if ( array() === $collisions ) {
			echo '<p class="fv-erh-collision-allclear">'
				. esc_html__( 'Slug check: no page slugs collide with Ecwid URL patterns.', 'ecwid-redirect-404-helper' )
				. ' <a href="' . esc_url( $rescan_url ) . '">' . esc_html__( 'Rescan', 'ecwid-redirect-404-helper' ) . '</a></p>';

			return;
		}

		echo '<div class="fv-erh-collision-panel">';
		echo '<h2>' . esc_html__( 'False-404 warning: slug collisions with Ecwid', 'ecwid-redirect-404-helper' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'These published pages have slugs ending in Ecwid\'s product/category URL pattern (-p123 / -c123). The Ecwid store widget hijacks such URLs and renders a "not found" store page instead of your content — visitors see a 404 even though the page exists. Rename the slug (e.g. add a word after the number) to fix it.', 'ecwid-redirect-404-helper' )
			. '</p>';

		echo '<table class="widefat striped fv-erh-collision-table"><thead><tr>'
			. '<th>' . esc_html__( 'Page', 'ecwid-redirect-404-helper' ) . '</th>'
			. '<th>' . esc_html__( 'Slug', 'ecwid-redirect-404-helper' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'ecwid-redirect-404-helper' ) . '</th>'
			. '<th>' . esc_html__( 'Actions', 'ecwid-redirect-404-helper' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $collisions as $collision ) {
			$edit_link = get_edit_post_link( $collision['id'] );
			$view_link = get_permalink( $collision['id'] );

			echo '<tr>';
			echo '<td>' . esc_html( $collision['title'] ) . '</td>';
			echo '<td><code>' . esc_html( $collision['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( $collision['type'] ) . '</td>';
			echo '<td>';
			if ( is_string( $edit_link ) && '' !== $edit_link ) {
				echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit slug', 'ecwid-redirect-404-helper' ) . '</a>';
			}
			if ( is_string( $view_link ) && '' !== $view_link ) {
				echo ' | <a href="' . esc_url( $view_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'ecwid-redirect-404-helper' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( $rescan_url ) . '" class="button">' . esc_html__( 'Rescan now', 'ecwid-redirect-404-helper' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Surface the most relevant paid-tier CTA for what the log actually holds.
	 *
	 * At most one CTA, and only when the data earns it: deleted-product
	 * redirects when there are "deleted" verdicts to act on, otherwise
	 * storefront-layer redirects when there are Ecwid sub-route 404s the
	 * WordPress layer cannot reach. With neither, nothing shows. Each is
	 * dismissible (handled by {@see UpgradeCta}).
	 *
	 * @return void
	 */
	private function render_upgrade_cta(): void {
		if ( $this->log->count( array( 'verdict' => VerdictChecker::VERDICT_DELETED ) ) > 0 ) {
			$this->cta->render(
				'log-deleted-redirects',
				__( 'Deleted products are still 404ing', 'ecwid-redirect-404-helper' ),
				__( 'Some of these 404s are products or categories you deleted. The Redirect & 404 Manager app redirects a deleted item automatically — to its parent category or your homepage — the moment it is removed, so you never hand-fix them.', 'ecwid-redirect-404-helper' ),
				array(
					array(
						'label'  => __( 'Automate deleted redirects', 'ecwid-redirect-404-helper' ),
						'target' => DeepLink::TARGET_DELETED_REDIRECTS,
					),
				)
			);

			return;
		}

		// `||` short-circuits, so the category COUNT is skipped whenever the
		// product COUNT already finds Ecwid 404s.
		$has_ecwid_404s = $this->log->count( array( 'classification' => UrlClassifier::TYPE_PRODUCT ) ) > 0
			|| $this->log->count( array( 'classification' => UrlClassifier::TYPE_CATEGORY ) ) > 0;

		if ( $has_ecwid_404s ) {
			$this->cta->render(
				'log-storefront-layer',
				__( 'Some 404s happen inside the Ecwid storefront', 'ecwid-redirect-404-helper' ),
				__( 'These product and category 404s occur inside the embedded store, in the visitor\'s browser — they never reach WordPress, so a WordPress-layer redirect cannot catch them. The Redirect & 404 Manager app redirects at the storefront layer, which is the only place these can be fixed.', 'ecwid-redirect-404-helper' ),
				array(
					array(
						'label'  => __( 'Fix storefront 404s in the app', 'ecwid-redirect-404-helper' ),
						'target' => DeepLink::TARGET_STOREFRONT_LAYER,
					),
				)
			);
		}
	}

	/**
	 * Render the notice for the current redirect, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// Display-only read of our own post/redirect/get marker; the originating
		// action was already nonce-verified before this redirect was issued.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['fv_erh_notice'] ) ? sanitize_key( wp_unslash( $_GET['fv_erh_notice'] ) ) : '';

		switch ( $code ) {
			case 'log-deleted':
				$this->print_notice( 'success', __( 'Selected 404 entries deleted.', 'ecwid-redirect-404-helper' ) );
				break;

			case 'verdicts-checked':
				$checked = isset( $_GET['fv_erh_checked'] ) ? absint( $_GET['fv_erh_checked'] ) : 0;
				$left    = isset( $_GET['fv_erh_left'] ) ? absint( $_GET['fv_erh_left'] ) : 0;

				$this->print_notice(
					'success',
					sprintf(
						/* translators: 1: entities checked this run, 2: entities still pending. */
						__( 'Catalog check complete: %1$d entities checked, %2$d still pending.', 'ecwid-redirect-404-helper' ),
						$checked,
						$left
					)
				);
				break;

			case 'verdicts-unavailable':
				$this->print_notice( 'warning', __( 'Catalog check unavailable: connect your Ecwid store first (Settings).', 'ecwid-redirect-404-helper' ) );
				break;

			case 'collisions-rescanned':
				$this->print_notice( 'success', __( 'Slug collision scan refreshed.', 'ecwid-redirect-404-helper' ) );
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Print one dismissible admin notice.
	 *
	 * @param string $type    Notice type ('success' or 'warning').
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private function print_notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Print the small style block for badges and ellipsized cells.
	 *
	 * @return void
	 */
	private function print_styles(): void {
		echo '<style>
			.fv-erh-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; line-height:1.6; color:#fff; }
			.fv-erh-badge--product { background:#27ae60; }
			.fv-erh-badge--category { background:#16a085; }
			.fv-erh-badge--wp-page { background:#95a5a6; }
			.fv-erh-badge--verdict-in-catalog { background:#2980b9; }
			.fv-erh-badge--verdict-deleted { background:#c0392b; }
			.fv-erh-badge--verdict-never-existed { background:#e67e22; }
			.fv-erh-badge--verdict-not-in-catalog { background:#7f8c8d; }
			.fv-erh-ellipsis { display:inline-block; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
			.fv-erh-collision-panel { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; padding:1px 12px 12px; margin:12px 0; }
			.fv-erh-collision-table { max-width:760px; }
			.fv-erh-collision-allclear { color:#646970; }
		</style>';
	}
}
