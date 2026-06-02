<?php
/**
 * 404 log dashboard page.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Log\CsvExporter;
use FV\WPEcwidRedirectHelper\Log\NotFoundLog;

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
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * Constructor.
	 *
	 * @param NotFoundLog|null $log Log repository (injectable for tests).
	 */
	public function __construct( ?NotFoundLog $log = null ) {
		$this->log = $log ?? new NotFoundLog();
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
		if ( 'delete' !== Menu::requested_action() ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecwid-redirect-404-helper' ) );
		}

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
		echo '<hr class="wp-header-end" />';

		$this->render_notice();

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
	 * @return array{search:string,classification:string,status:string,orderby:string,order:string}
	 */
	public static function current_query_args(): array {
		$classification = isset( $_GET['classification'] ) ? sanitize_key( wp_unslash( $_GET['classification'] ) ) : '';
		$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! isset( LogListTable::classification_labels()[ $classification ] ) ) {
			$classification = '';
		}

		if ( ! isset( LogListTable::status_labels()[ $status ] ) ) {
			$status = '';
		}

		return array(
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'classification' => $classification,
			'status'         => $status,
			'orderby'        => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'          => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
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
	 * Render the notice for the current redirect, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// Display-only read of our own post/redirect/get marker; the originating
		// action was already nonce-verified before this redirect was issued.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['fv_erh_notice'] ) ? sanitize_key( wp_unslash( $_GET['fv_erh_notice'] ) ) : '';

		if ( 'log-deleted' !== $code ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Selected 404 entries deleted.', 'ecwid-redirect-404-helper' )
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
			.fv-erh-ellipsis { display:inline-block; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
		</style>';
	}
}
