<?php
/**
 * Categories list table.
 *
 * @package Plainday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native-style category list table.
 */
class Plainday_Categories_List_Table extends WP_List_Table {
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
	 * Prepare items.
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total_items  = Plainday_DB::count_categories( array( 'search' => $search ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->items           = Plainday_DB::get_categories(
			array(
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
				'number'  => $per_page,
				'offset'  => ( $current_page - 1 ) * $per_page,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
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
			'name'        => __( 'Name', 'plainday' ),
			'slug'        => __( 'Slug', 'plainday' ),
			'color'       => __( 'Color', 'plainday' ),
			'event_count' => __( 'Event Count', 'plainday' ),
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
			'delete' => __( 'Delete', 'plainday' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Category item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="category[]" value="%s">', esc_attr( $item['slug'] ) );
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
				'page'     => 'plainday-categories',
				'action'   => 'edit',
				'category' => rawurlencode( $item['slug'] ),
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'plainday-categories',
					'action'   => 'delete',
					'category' => rawurlencode( $item['slug'] ),
				),
				admin_url( 'admin.php' )
			),
			'plainday_delete_category_' . $item['slug']
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'plainday' ) ),
			'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'plainday' ) ),
		);

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Color column.
	 *
	 * @param array $item Category item.
	 * @return string
	 */
	protected function column_color( $item ) {
		return sprintf(
			'<span class="plainday-color-swatch" style="background:%1$s"></span><code>%1$s</code>',
			esc_attr( $item['color'] )
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
		esc_html_e( 'No categories found.', 'plainday' );
	}
}
