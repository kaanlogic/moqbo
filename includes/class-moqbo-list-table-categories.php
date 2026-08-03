<?php
/**
 * Categories list table.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native-style category list table.
 */
class Moqbo_List_Table_Categories extends WP_List_Table {

	/**
	 * Preparation error.
	 *
	 * @var WP_Error|null
	 */
	protected $error = null;

	/**
	 * Initialize table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'category',
				'plural'   => 'categories',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Return a preparation error.
	 *
	 * @return WP_Error|null
	 */
	public function get_error() {
		return $this->error;
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$search       = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$orderby      = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list ordering.
		$order        = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list ordering.
		$total_items  = Moqbo_DB::count_categories( array( 'search' => $search ) );

		if ( is_wp_error( $total_items ) ) {
			$this->error = $total_items;
			$total_items = 0;
		}

		$total_pages  = (int) ceil( $total_items / $per_page );
		$current_page = min( $current_page, max( 1, $total_pages ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->items           = Moqbo_DB::get_categories(
			array(
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
				'number'  => $per_page,
				'offset'  => ( $current_page - 1 ) * $per_page,
			)
		);

		if ( is_wp_error( $this->items ) ) {
			$this->error = $this->items;
			$this->items = array();
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => __( 'Name', 'moqbo' ),
			'slug'        => __( 'Slug', 'moqbo' ),
			'color'       => __( 'Color', 'moqbo' ),
			'event_count' => __( 'Event Count', 'moqbo' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'        => array( 'name', false ),
			'slug'        => array( 'slug', false ),
			'event_count' => array( 'count', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'moqbo' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Category item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="category[]" value="%1$s" id="cb-select-%1$s" aria-label="%2$s">', esc_attr( $item['slug'] ), esc_attr( sprintf( /* translators: %s: category name. */ __( 'Select %s', 'moqbo' ), $item['name'] ) ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Category item.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'     => 'moqbo-categories',
				'action'   => 'edit',
				'category' => rawurlencode( $item['slug'] ),
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'moqbo-categories',
					'action'   => 'delete',
					'category' => rawurlencode( $item['slug'] ),
				),
				admin_url( 'admin.php' )
			),
			'moqbo_delete_category_' . $item['slug']
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'moqbo' ) ),
			'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'moqbo' ) ),
		);

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Row actions already include the responsive toggle.
	 *
	 * @param array  $item Category item.
	 * @param string $column_name Column name.
	 * @param string $primary Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return '';
	}

	/**
	 * Color column.
	 *
	 * @param array $item Category item.
	 * @return string
	 */
	protected function column_color( $item ) {
		$color = isset( $item['color'] ) && is_string( $item['color'] ) && preg_match( '/\A#[0-9A-Fa-f]{6}\z/', $item['color'] ) ? strtolower( $item['color'] ) : '';

		if ( '' === $color ) {
			return esc_html__( 'Invalid color', 'moqbo' );
		}

		return sprintf(
			'<span class="moqbo-color-swatch" style="background:%1$s" aria-hidden="true"></span><code>%2$s</code>',
			esc_attr( $color ),
			esc_html( $color )
		);
	}

	/**
	 * Default column rendering.
	 *
	 * @param array  $item Category item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'slug':
				return esc_html( $item['slug'] );
			case 'event_count':
				return esc_html( (string) absint( $item['event_count'] ) );
			default:
				return '';
		}
	}

	/**
	 * Empty table text.
	 */
	public function no_items() {
		esc_html_e( 'No categories found.', 'moqbo' );
	}
}
