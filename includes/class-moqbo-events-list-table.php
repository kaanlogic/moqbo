<?php
/**
 * Events list table.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native-style event list table.
 */
class Moqbo_Events_List_Table extends WP_List_Table {
	/**
	 * Initialize table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
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
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'start'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total_items  = Moqbo_DB::count_events( array( 'search' => $search ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->items           = Moqbo_DB::get_events(
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
			'name'        => __( 'Name', 'moqbo' ),
			'slug'        => __( 'Slug', 'moqbo' ),
			'location'    => __( 'Location', 'moqbo' ),
			'category'    => __( 'Event Category', 'moqbo' ),
			'description' => __( 'Description', 'moqbo' ),
			'all_day'     => __( 'All-day event', 'moqbo' ),
			'start_date'  => __( 'Start Date', 'moqbo' ),
			'start_time'  => __( 'Start Time', 'moqbo' ),
			'end_date'    => __( 'End Date', 'moqbo' ),
			'end_time'    => __( 'End Time', 'moqbo' ),
			'created_at'  => __( 'Created At', 'moqbo' ),
			'updated_at'  => __( 'Updated At', 'moqbo' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'       => array( 'name', false ),
			'slug'       => array( 'slug', false ),
			'location'   => array( 'location', false ),
			'category'   => array( 'category', false ),
			'all_day'    => array( 'all_day', false ),
			'start_date' => array( 'start_date', false ),
			'start_time' => array( 'start_time', false ),
			'end_date'   => array( 'end_date', false ),
			'end_time'   => array( 'end_time', false ),
			'created_at' => array( 'created_at', false ),
			'updated_at' => array( 'updated_at', false ),
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
	 * @param array $item Event item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="event[]" value="%s">', esc_attr( $item['slug'] ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Event item.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'   => 'moqbo-add-event',
				'action' => 'edit',
				'event'  => rawurlencode( $item['slug'] ),
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'moqbo',
					'action' => 'delete',
					'event'  => rawurlencode( $item['slug'] ),
				),
				admin_url( 'admin.php' )
			),
			'moqbo_delete_event_' . $item['slug']
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
	 * Default column rendering.
	 *
	 * @param array  $item Event item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'slug':
				return esc_html( $item['slug'] );
			case 'location':
				return esc_html( $item['location'] );
			case 'category':
				return esc_html( $item['category_name'] ? $item['category_name'] : $item['category_slug'] );
			case 'description':
				return esc_html( wp_trim_words( wp_strip_all_tags( $item['description'] ), 18 ) );
			case 'all_day':
				return (bool) $item['all_day'] ? esc_html__( 'Yes', 'moqbo' ) : esc_html__( 'No', 'moqbo' );
			case 'start_date':
				return esc_html( $this->format_event_date( $item['start_at'] ) );
			case 'start_time':
				return (bool) $item['all_day'] ? '&mdash;' : esc_html( $this->format_event_time( $item['start_at'] ) );
			case 'end_date':
				return esc_html( $this->format_event_date( $item['end_at'] ) );
			case 'end_time':
				return (bool) $item['all_day'] ? '&mdash;' : esc_html( $this->format_event_time( $item['end_at'] ) );
			case 'created_at':
				return esc_html( $this->format_event_datetime( $item['created_at'] ) );
			case 'updated_at':
				return esc_html( $this->format_event_datetime( $item['updated_at'] ) );
			default:
				return '';
		}
	}

	/**
	 * Format a stored local datetime as a date.
	 *
	 * @param string $datetime Stored local datetime.
	 * @return string
	 */
	private function format_event_date( $datetime ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $datetime, wp_timezone() );

		if ( ! $dt ) {
			return $datetime;
		}

		return wp_date( get_option( 'date_format' ), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format a stored local datetime as a time.
	 *
	 * @param string $datetime Stored local datetime.
	 * @return string
	 */
	private function format_event_time( $datetime ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $datetime, wp_timezone() );

		if ( ! $dt ) {
			return $datetime;
		}

		return wp_date( get_option( 'time_format' ), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format a stored local datetime as a date and time.
	 *
	 * @param string $datetime Stored local datetime.
	 * @return string
	 */
	private function format_event_datetime( $datetime ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $datetime, wp_timezone() );

		if ( ! $dt ) {
			return $datetime;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Empty table text.
	 */
	public function no_items() {
		esc_html_e( 'No events found.', 'moqbo' );
	}
}
