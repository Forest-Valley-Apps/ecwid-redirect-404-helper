<?php
/**
 * Manual redirects list table.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the merchant's manual 301 rules with enable/disable/delete row
 * actions and a bulk Delete. Receives the (small) rule set pre-fetched; the
 * page controller owns every mutation.
 */
final class RedirectListTable extends \WP_List_Table {

	/**
	 * Rows per page. Rule sets are small; this only guards pathological cases.
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * All redirect rules.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $rules All redirect rules, newest first.
	 */
	public function __construct( array $rules ) {
		parent::__construct(
			array(
				'singular' => 'fv-erh-redirect',
				'plural'   => 'fv-erh-redirects',
				'ajax'     => false,
			)
		);

		$this->rules = $rules;
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'source'      => __( 'Source', 'ecwid-redirect-404-helper' ),
			'destination' => __( 'Destination', 'ecwid-redirect-404-helper' ),
			'active'      => __( 'Status', 'ecwid-redirect-404-helper' ),
			'hit_count'   => __( 'Hits', 'ecwid-redirect-404-helper' ),
			'last_hit'    => __( 'Last used', 'ecwid-redirect-404-helper' ),
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
	 * Load the current page of rules.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$paged = $this->get_pagenum();

		$this->items = array_slice( $this->rules, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => count( $this->rules ),
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Message shown when no rules exist yet.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No redirects yet. Add one above, or create one from a logged 404.', 'ecwid-redirect-404-helper' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Source column: the pattern plus its row actions.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	protected function column_source( $item ): string {
		$id     = (int) $item['id'];
		$active = (int) $item['active'] > 0;

		$toggle_url = wp_nonce_url(
			admin_url(
				sprintf(
					'admin-post.php?action=%s&id=%d&state=%d',
					RedirectsPage::ACTION_TOGGLE,
					$id,
					$active ? 0 : 1
				)
			),
			RedirectsPage::ACTION_TOGGLE . '_' . $id
		);

		$delete_url = wp_nonce_url(
			admin_url( sprintf( 'admin-post.php?action=%s&id=%d', RedirectsPage::ACTION_DELETE, $id ) ),
			RedirectsPage::ACTION_DELETE . '_' . $id
		);

		$actions = array(
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $toggle_url ),
				$active
					? esc_html__( 'Disable', 'ecwid-redirect-404-helper' )
					: esc_html__( 'Enable', 'ecwid-redirect-404-helper' )
			),
			'delete' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'ecwid-redirect-404-helper' )
			),
		);

		return '<strong><code>' . esc_html( (string) $item['source'] ) . '</code></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Destination column.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	protected function column_destination( $item ): string {
		return '<code>' . esc_html( (string) $item['destination'] ) . '</code>';
	}

	/**
	 * Status column.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	protected function column_active( $item ): string {
		return (int) $item['active'] > 0
			? esc_html__( 'Active', 'ecwid-redirect-404-helper' )
			: esc_html__( 'Disabled', 'ecwid-redirect-404-helper' );
	}

	/**
	 * Last-used column.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	protected function column_last_hit( $item ): string {
		$last_hit = (string) ( $item['last_hit'] ?? '' );

		if ( '' === $last_hit ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		return esc_html( $last_hit );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Rule row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
