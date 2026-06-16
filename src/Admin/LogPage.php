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
use FV\WPEcwidRedirectHelper\Upsell\AppButton;
use FV\WPEcwidRedirectHelper\Upsell\DeepLink;
use FV\WPEcwidRedirectHelper\Upsell\UpgradeCta;
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'redirect-404-helper-for-ecwid' ) );
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'redirect-404-helper-for-ecwid' ) );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'redirect-404-helper-for-ecwid' ) );
		}

		$table = new LogListTable( $this->log, self::current_query_args() );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT ),
			self::ACTION_EXPORT
		);

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '404 Log', 'redirect-404-helper-for-ecwid' ) . '</h1>';
		echo ' <a href="' . esc_url( $export_url ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'redirect-404-helper-for-ecwid' ) . '</a>';
		$this->render_check_catalog_button();
		( new AppButton() )->render( DeepLink::TARGET_HOME );
		echo '<hr class="wp-header-end" />';

		$this->render_notice();
		$this->render_collision_panel();
		$this->render_upgrade_cta();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$table->search_box( __( 'Search URLs', 'redirect-404-helper-for-ecwid' ), 'fv-erh-404' );
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
			? sprintf( __( 'Check catalog (%d)', 'redirect-404-helper-for-ecwid' ), $pending )
			: __( 'Check catalog', 'redirect-404-helper-for-ecwid' );

		echo ' <a href="' . esc_url( $url ) . '" class="page-title-action">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Render the false-404 collision panel.
	 *
	 * Render-safe: only the cached scan result is read. A cold cache shows a
	 * one-line "scan pending" and schedules a one-off background scan — the
	 * unindexable REGEXP query never runs inline in a page render (the
	 * explicit Rescan action is the synchronous path). With no collisions
	 * only a one-line all-clear with a Rescan link is shown.
	 *
	 * @return void
	 */
	private function render_collision_panel(): void {
		$collisions = $this->collisions->cached_collisions();

		$rescan_url = $this->action_url( 'rescan_collisions' );

		if ( null === $collisions ) {
			$this->collisions->schedule_scan();

			echo '<p class="fv-erh-collision-allclear">'
				. esc_html__( 'Slug check: scan pending — results appear after the next background run.', 'redirect-404-helper-for-ecwid' )
				. ' <a href="' . esc_url( $rescan_url ) . '">' . esc_html__( 'Scan now', 'redirect-404-helper-for-ecwid' ) . '</a></p>';

			return;
		}

		if ( array() === $collisions ) {
			echo '<p class="fv-erh-collision-allclear">'
				. esc_html__( 'Slug check: no page slugs collide with Ecwid URL patterns.', 'redirect-404-helper-for-ecwid' )
				. ' <a href="' . esc_url( $rescan_url ) . '">' . esc_html__( 'Rescan', 'redirect-404-helper-for-ecwid' ) . '</a></p>';

			return;
		}

		echo '<div class="fv-erh-collision-panel">';
		echo '<h2>' . esc_html__( 'False-404 warning: slug collisions with Ecwid', 'redirect-404-helper-for-ecwid' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'These published pages have slugs ending in Ecwid\'s product/category URL pattern (-p123 / -c123). The Ecwid store widget hijacks such URLs and renders a "not found" store page instead of your content — visitors see a 404 even though the page exists. Rename the slug (e.g. add a word after the number) to fix it.', 'redirect-404-helper-for-ecwid' )
			. '</p>';

		echo '<table class="widefat striped fv-erh-collision-table"><thead><tr>'
			. '<th>' . esc_html__( 'Page', 'redirect-404-helper-for-ecwid' ) . '</th>'
			. '<th>' . esc_html__( 'Slug', 'redirect-404-helper-for-ecwid' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'redirect-404-helper-for-ecwid' ) . '</th>'
			. '<th>' . esc_html__( 'Actions', 'redirect-404-helper-for-ecwid' ) . '</th>'
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
				echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit slug', 'redirect-404-helper-for-ecwid' ) . '</a>';
			}
			if ( is_string( $view_link ) && '' !== $view_link ) {
				echo ' | <a href="' . esc_url( $view_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'redirect-404-helper-for-ecwid' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( $rescan_url ) . '" class="button">' . esc_html__( 'Rescan now', 'redirect-404-helper-for-ecwid' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Surface the most relevant paid-tier CTA for what the log actually holds.
	 *
	 * At most one CTA, and only when the data earns it — and it carries the
	 * merchant's own count, because a specific, counted problem is what converts:
	 * deleted-product redirects when there are "deleted" verdicts to act on,
	 * otherwise the storefront-layer 404s the WordPress layer cannot reach. With
	 * neither, nothing shows. Each is dismissible (handled by {@see UpgradeCta}).
	 *
	 * The deleted count short-circuits the storefront sum: when there are deleted
	 * verdicts to act on, the (two) storefront COUNT queries are never run.
	 *
	 * @return void
	 */
	private function render_upgrade_cta(): void {
		$deleted = $this->log->count( array( 'verdict' => VerdictChecker::VERDICT_DELETED ) );

		$spec = self::upgrade_cta_spec(
			$deleted,
			$deleted > 0 ? 0 : $this->storefront_layer_count()
		);

		if ( null === $spec ) {
			return;
		}

		$this->cta->render( $spec['key'], $spec['heading'], $spec['body'], $spec['actions'] );
	}

	/**
	 * How many logged 404s are storefront-layer (Ecwid product/category routes).
	 *
	 * @return int
	 */
	private function storefront_layer_count(): int {
		$count = 0;
		foreach ( RowLayer::storefront_classifications() as $classification ) {
			$count += $this->log->count( array( 'classification' => $classification ) );
		}

		return $count;
	}

	/**
	 * The counted CTA to show for the given log counts, or null for none.
	 *
	 * Pure (counts in, descriptor out) so the copy, count, and deep-link target
	 * are unit-testable without a render. Deleted verdicts win over a plain
	 * storefront count because the app can *automate* those specifically.
	 *
	 * @param int $deleted_count    Rows with a "deleted" catalog verdict.
	 * @param int $storefront_count Storefront-layer rows (product + category).
	 * @return array{key:string,heading:string,body:string,actions:array<int,array{label:string,target:string}>}|null
	 */
	public static function upgrade_cta_spec( int $deleted_count, int $storefront_count ): ?array {
		if ( $deleted_count > 0 ) {
			return array(
				'key'     => 'log-deleted-redirects',
				'heading' => sprintf(
					/* translators: %d: number of deleted products/categories still returning 404s. */
					_n(
						'%d deleted product is still returning 404s',
						'%d deleted products are still returning 404s',
						$deleted_count,
						'redirect-404-helper-for-ecwid'
					),
					$deleted_count
				),
				'body'    => __( 'These are products or categories you deleted. The Redirect & 404 Manager app redirects a deleted item automatically — to its parent category or your homepage — the moment it is removed, so you never hand-fix them.', 'redirect-404-helper-for-ecwid' ),
				'actions' => array(
					array(
						'label'  => __( 'Automate deleted redirects', 'redirect-404-helper-for-ecwid' ),
						'target' => DeepLink::TARGET_DELETED_REDIRECTS,
					),
				),
			);
		}

		if ( $storefront_count > 0 ) {
			return array(
				'key'     => 'log-storefront-layer',
				'heading' => sprintf(
					/* translators: %d: number of storefront-layer 404s WordPress cannot redirect. */
					_n(
						'You have %d storefront 404 WordPress cannot redirect',
						'You have %d storefront 404s WordPress cannot redirect',
						$storefront_count,
						'redirect-404-helper-for-ecwid'
					),
					$storefront_count
				),
				'body'    => __( 'These product and category 404s happen inside the embedded store, in the visitor\'s browser — they never reach WordPress, so a WordPress-layer redirect cannot catch them. The Redirect & 404 Manager app redirects them at the storefront layer, the only place these can be fixed.', 'redirect-404-helper-for-ecwid' ),
				'actions' => array(
					array(
						'label'  => __( 'Open them in the app', 'redirect-404-helper-for-ecwid' ),
						'target' => DeepLink::TARGET_WP_REPORTED_404S,
					),
				),
			);
		}

		return null;
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
				$this->print_notice( 'success', __( 'Selected 404 entries deleted.', 'redirect-404-helper-for-ecwid' ) );
				break;

			case 'verdicts-checked':
				$checked = isset( $_GET['fv_erh_checked'] ) ? absint( $_GET['fv_erh_checked'] ) : 0;
				$left    = isset( $_GET['fv_erh_left'] ) ? absint( $_GET['fv_erh_left'] ) : 0;

				$this->print_notice(
					'success',
					sprintf(
						/* translators: 1: entities checked this run, 2: entities still pending. */
						__( 'Catalog check complete: %1$d entities checked, %2$d still pending.', 'redirect-404-helper-for-ecwid' ),
						$checked,
						$left
					)
				);
				break;

			case 'verdicts-unavailable':
				$this->print_notice( 'warning', __( 'Catalog check unavailable: connect your Ecwid store first (Settings).', 'redirect-404-helper-for-ecwid' ) );
				break;

			case 'collisions-rescanned':
				$this->print_notice( 'success', __( 'Slug collision scan refreshed.', 'redirect-404-helper-for-ecwid' ) );
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
}
