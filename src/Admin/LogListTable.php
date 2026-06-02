<?php
/**
 * 404 log list table.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Log\NotFoundLog;
use FV\WPEcwidRedirectHelper\Url\UrlClassifier;
use FV\WPEcwidRedirectHelper\Verdict\VerdictChecker;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the captured 404s: classification badge, hit count, last seen,
 * status, with search, classification/status filters, sortable columns and a
 * bulk Delete action. Reads via {@see NotFoundLog::query()}; never writes —
 * the page controller owns every mutation.
 */
final class LogListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * The current request's filter/sort state ({@see LogPage::current_query_args()}).
	 *
	 * @var array<string,string>
	 */
	private array $query_args;

	/**
	 * Constructor.
	 *
	 * @param NotFoundLog          $log        Log repository.
	 * @param array<string,string> $query_args The request's filter/sort state.
	 */
	public function __construct( NotFoundLog $log, array $query_args ) {
		parent::__construct(
			array(
				'singular' => 'fv-erh-404',
				'plural'   => 'fv-erh-404s',
				'ajax'     => false,
			)
		);

		$this->log        = $log;
		$this->query_args = $query_args;
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'             => '<input type="checkbox" />',
			'url_path'       => __( 'URL', 'ecwid-redirect-404-helper' ),
			'classification' => __( 'Type', 'ecwid-redirect-404-helper' ),
			'verdict'        => __( 'Catalog', 'ecwid-redirect-404-helper' ),
			'hit_count'      => __( 'Hits', 'ecwid-redirect-404-helper' ),
			'referrer'       => __( 'Last referrer', 'ecwid-redirect-404-helper' ),
			'status'         => __( 'Status', 'ecwid-redirect-404-helper' ),
			'last_seen'      => __( 'Last seen', 'ecwid-redirect-404-helper' ),
		);
	}

	/**
	 * Sortable columns (the repository whitelists the actual SQL column).
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'url_path'       => array( 'url_path', false ),
			'classification' => array( 'classification', false ),
			'hit_count'      => array( 'hit_count', true ),
			'last_seen'      => array( 'last_seen', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'ecwid-redirect-404-helper' ),
		);
	}

	/**
	 * Load the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$args = $this->query_args;

		$total = $this->log->count( $args );

		$args['per_page'] = self::PER_PAGE;
		$args['paged']    = $this->get_pagenum();

		$this->items = $this->log->query( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Render the classification + status filter dropdowns.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$args = $this->query_args;

		echo '<div class="alignleft actions">';

		echo '<select name="classification">';
		echo '<option value="">' . esc_html__( 'All types', 'ecwid-redirect-404-helper' ) . '</option>';
		foreach ( self::classification_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['classification'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<select name="verdict">';
		echo '<option value="">' . esc_html__( 'All catalog verdicts', 'ecwid-redirect-404-helper' ) . '</option>';
		foreach ( self::verdict_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['verdict'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'ecwid-redirect-404-helper' ) . '</option>';
		foreach ( self::status_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['status'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'ecwid-redirect-404-helper' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Message shown when the log is empty.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No 404s logged yet. They will appear here as visitors hit missing URLs.', 'ecwid-redirect-404-helper' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * URL column: the path plus its row actions.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_url_path( $item ): string {
		$path = (string) $item['url_path'];
		$id   = (int) $item['id'];

		$create_url = add_query_arg(
			array(
				'page'   => RedirectsPage::PAGE_SLUG,
				'source' => rawurlencode( $path ),
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => LogPage::PAGE_SLUG,
					'action' => 'delete',
					'id'     => $id,
				),
				admin_url( 'admin.php' )
			),
			'fv_erh_log_delete_' . $id
		);

		$actions = array(
			'create-redirect' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $create_url ),
				esc_html__( 'Create redirect', 'ecwid-redirect-404-helper' )
			),
			'delete'          => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'ecwid-redirect-404-helper' )
			),
		);

		return '<strong>' . esc_html( $path ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Classification column: a colored badge.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_classification( $item ): string {
		$classification = (string) $item['classification'];
		$labels         = self::classification_labels();

		return sprintf(
			'<span class="fv-erh-badge fv-erh-badge--%1$s">%2$s</span>',
			esc_attr( $classification ),
			esc_html( $labels[ $classification ] ?? $classification )
		);
	}

	/**
	 * Catalog-verdict column: a colored badge, or a dash when unchecked.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_verdict( $item ): string {
		$verdict = (string) ( $item['verdict'] ?? '' );

		if ( '' === $verdict ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		$labels = self::verdict_labels();
		$titles = self::verdict_titles();

		return sprintf(
			'<span class="fv-erh-badge fv-erh-badge--verdict-%1$s" title="%2$s">%3$s</span>',
			esc_attr( $verdict ),
			esc_attr( $titles[ $verdict ] ?? '' ),
			esc_html( $labels[ $verdict ] ?? $verdict )
		);
	}

	/**
	 * Status column.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = (string) $item['status'];
		$labels = self::status_labels();

		return esc_html( $labels[ $status ] ?? $status );
	}

	/**
	 * Referrer column, ellipsized by CSS.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_referrer( $item ): string {
		$referrer = (string) $item['referrer'];

		if ( '' === $referrer ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		return '<span class="fv-erh-ellipsis" title="' . esc_attr( $referrer ) . '">' . esc_html( $referrer ) . '</span>';
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Log row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Human labels for the classification values.
	 *
	 * @return array<string,string>
	 */
	public static function classification_labels(): array {
		return array(
			UrlClassifier::TYPE_PRODUCT  => __( 'Ecwid product', 'ecwid-redirect-404-helper' ),
			UrlClassifier::TYPE_CATEGORY => __( 'Ecwid category', 'ecwid-redirect-404-helper' ),
			UrlClassifier::TYPE_WP_PAGE  => __( 'WP page', 'ecwid-redirect-404-helper' ),
		);
	}

	/**
	 * Human labels for the status values.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			NotFoundLog::STATUS_NEW        => __( 'New', 'ecwid-redirect-404-helper' ),
			NotFoundLog::STATUS_REDIRECTED => __( 'Redirected', 'ecwid-redirect-404-helper' ),
		);
	}

	/**
	 * Human labels for the catalog-verdict values.
	 *
	 * @return array<string,string>
	 */
	public static function verdict_labels(): array {
		return array(
			VerdictChecker::VERDICT_IN_CATALOG     => __( 'In catalog — broken link', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_DELETED        => __( 'Deleted', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_NEVER_EXISTED  => __( 'Never existed', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_NOT_IN_CATALOG => __( 'Not in catalog', 'ecwid-redirect-404-helper' ),
		);
	}

	/**
	 * Hover explanations for the catalog-verdict badges.
	 *
	 * @return array<string,string>
	 */
	public static function verdict_titles(): array {
		return array(
			VerdictChecker::VERDICT_IN_CATALOG     => __( 'This product/category is live in your Ecwid catalog — the URL or link pointing here is what is broken.', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_DELETED        => __( 'This item was deleted from your Ecwid catalog (deletion on record in Redirect & 404 Manager).', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_NEVER_EXISTED  => __( 'No item with this id is on record — most likely a mistyped or fabricated link. (Items deleted before Redirect & 404 Manager was installed also show here.)', 'ecwid-redirect-404-helper' ),
			VerdictChecker::VERDICT_NOT_IN_CATALOG => __( 'Not in your Ecwid catalog. Install the Redirect & 404 Manager app to tell deleted items apart from mistyped links.', 'ecwid-redirect-404-helper' ),
		);
	}
}
