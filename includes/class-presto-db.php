<?php
/**
 * Database schema and persistence helpers.
 *
 * @package Presto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database access layer for Presto.
 */
class Presto_DB {
	/**
	 * Activate the plugin on one or all sites.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 */
	public static function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::create_schema();
				restore_current_blog();
			}

			return;
		}

		self::create_schema();
	}

	/**
	 * Run schema migrations when the stored DB version lags the plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'presto_db_version' ) !== PRESTO_DB_VERSION ) {
			self::create_schema();
		}
	}

	/**
	 * Get the events table name for the current site.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'presto_events';
	}

	/**
	 * Get the categories table name for the current site.
	 *
	 * @return string
	 */
	public static function categories_table() {
		global $wpdb;

		return $wpdb->prefix . 'presto_categories';
	}

	/**
	 * Create or update custom tables.
	 */
	public static function create_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events_table    = self::events_table();
		$categories_table = self::categories_table();

		$events_sql = "CREATE TABLE {$events_table} (
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			location varchar(255) NOT NULL DEFAULT '',
			category_slug varchar(191) NOT NULL,
			description text NULL,
			all_day tinyint(1) NOT NULL DEFAULT 0,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (slug),
			KEY start_at (start_at),
			KEY end_at (end_at),
			KEY category_slug (category_slug),
			KEY name (name)
		) {$charset_collate};";

		$categories_sql = "CREATE TABLE {$categories_table} (
			slug varchar(191) NOT NULL,
			name varchar(255) NOT NULL,
			color char(7) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (slug),
			KEY name (name)
		) {$charset_collate};";

		dbDelta( $events_sql );
		dbDelta( $categories_sql );
		self::reorder_events_columns();

		update_option( 'presto_db_version', PRESTO_DB_VERSION );
	}

	/**
	 * Reorder existing event columns to match Presto's admin field flow.
	 */
	private static function reorder_events_columns() {
		global $wpdb;

		$events_table = self::events_table();
		$expected     = array( 'name', 'slug', 'location', 'category_slug', 'description', 'all_day', 'start_at', 'end_at', 'created_at', 'updated_at' );
		$columns      = $wpdb->get_col( 'DESCRIBE ' . $events_table, 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( array_slice( $columns, 0, count( $expected ) ) === $expected ) {
			return;
		}

		if ( array_diff( $expected, $columns ) ) {
			return;
		}

		$wpdb->query(
			'ALTER TABLE ' . $events_table . '
				MODIFY name varchar(255) NOT NULL FIRST,
				MODIFY slug varchar(191) NOT NULL AFTER name,
				MODIFY location varchar(255) NOT NULL DEFAULT \'\' AFTER slug,
				MODIFY category_slug varchar(191) NOT NULL AFTER location,
				MODIFY description text NULL AFTER category_slug,
				MODIFY all_day tinyint(1) NOT NULL DEFAULT 0 AFTER description,
				MODIFY start_at datetime NOT NULL AFTER all_day,
				MODIFY end_at datetime NOT NULL AFTER start_at,
				MODIFY created_at datetime NOT NULL AFTER end_at,
				MODIFY updated_at datetime NOT NULL AFTER created_at'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Drop custom tables and options for the current site.
	 */
	public static function drop_schema() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::events_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::categories_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( 'presto_db_version' );
	}

	/**
	 * Return a category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return array|null
	 */
	public static function get_category( $slug ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::categories_table() . ' WHERE slug = %s', $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	/**
	 * Count categories matching query args.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count_categories( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT COUNT(*) FROM ' . self::categories_table() . ' WHERE ' . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (int) $wpdb->get_var( self::prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get categories with event counts.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get_categories( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'  => '',
			'orderby' => 'name',
			'order'   => 'ASC',
			'number'  => 0,
			'offset'  => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$orderby_map = array(
			'name'  => 'c.name',
			'slug'  => 'c.slug',
			'count' => 'event_count',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'c.name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(c.name LIKE %s OR c.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT c.slug, c.name, c.color, c.created_at, c.updated_at, COUNT(e.slug) AS event_count
			FROM ' . self::categories_table() . ' c
			LEFT JOIN ' . self::events_table() . ' e ON e.category_slug = c.slug
			WHERE ' . implode( ' AND ', $where ) . '
			GROUP BY c.slug, c.name, c.color, c.created_at, c.updated_at
			ORDER BY ' . $orderby . ' ' . $order; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( (int) $args['number'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['number'];
			$params[] = (int) $args['offset'];
		}

		return $wpdb->get_results( self::prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Insert a category.
	 *
	 * @param array $data Category data.
	 * @return bool
	 */
	public static function insert_category( $data ) {
		global $wpdb;

		return false !== $wpdb->insert(
			self::categories_table(),
			array(
				'slug'       => $data['slug'],
				'name'       => $data['name'],
				'color'      => $data['color'],
				'created_at' => $data['created_at'],
				'updated_at' => $data['updated_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update a category and keep event references in sync when the slug changes.
	 *
	 * @param string $old_slug Existing slug.
	 * @param array  $data Category data.
	 * @return bool
	 */
	public static function update_category( $old_slug, $data ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::categories_table(),
			array(
				'slug'       => $data['slug'],
				'name'       => $data['name'],
				'color'      => $data['color'],
				'updated_at' => $data['updated_at'],
			),
			array( 'slug' => $old_slug ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $old_slug !== $data['slug'] ) {
			$references_updated = $wpdb->update(
				self::events_table(),
				array( 'category_slug' => $data['slug'] ),
				array( 'category_slug' => $old_slug ),
				array( '%s' ),
				array( '%s' )
			);

			if ( false === $references_updated ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Count events for a category slug.
	 *
	 * @param string $slug Category slug.
	 * @return int
	 */
	public static function count_events_for_category( $slug ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::events_table() . ' WHERE category_slug = %s', $slug ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Delete categories that are not referenced by events.
	 *
	 * @param array $slugs Category slugs.
	 * @return array Deleted and blocked slugs.
	 */
	public static function delete_categories( $slugs ) {
		global $wpdb;

		$result = array(
			'deleted' => array(),
			'blocked' => array(),
		);

		foreach ( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) as $slug ) {
			if ( self::count_events_for_category( $slug ) > 0 ) {
				$result['blocked'][] = $slug;
				continue;
			}

			$deleted = $wpdb->delete( self::categories_table(), array( 'slug' => $slug ), array( '%s' ) );

			if ( false !== $deleted && $deleted > 0 ) {
				$result['deleted'][] = $slug;
			}
		}

		return $result;
	}

	/**
	 * Return an event by slug.
	 *
	 * @param string $slug Event slug.
	 * @return array|null
	 */
	public static function get_event( $slug ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT e.*, c.name AS category_name, c.color AS category_color
				FROM ' . self::events_table() . ' e
				LEFT JOIN ' . self::categories_table() . ' c ON c.slug = e.category_slug
				WHERE e.slug = %s',
				$slug
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	/**
	 * Return the next upcoming event whose name contains a search string.
	 *
	 * @param string $name Event name fragment.
	 * @return array|null
	 */
	public static function get_next_event_by_name( $name ) {
		global $wpdb;

		$like        = '%' . $wpdb->esc_like( $name ) . '%';
		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$now         = current_time( 'mysql' );

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT e.*
				FROM ' . self::events_table() . ' e
				WHERE e.name LIKE %s
				AND ( ( e.all_day = 1 AND e.start_at >= %s ) OR ( e.all_day = 0 AND e.start_at >= %s ) )
				ORDER BY e.start_at ASC, e.slug ASC
				LIMIT 1',
				$like,
				$today_start,
				$now
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	/**
	 * Count events matching query args.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count_events( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(e.name LIKE %s OR e.slug LIKE %s OR e.location LIKE %s OR e.description LIKE %s OR c.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT COUNT(*)
			FROM ' . self::events_table() . ' e
			LEFT JOIN ' . self::categories_table() . ' c ON c.slug = e.category_slug
			WHERE ' . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (int) $wpdb->get_var( self::prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get events with category metadata.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get_events( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'     => '',
			'start_date' => '',
			'end_date'   => '',
			'orderby'    => 'start',
			'order'      => 'ASC',
			'number'     => 0,
			'offset'     => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$orderby_map = array(
			'name'       => 'e.name',
			'slug'       => 'e.slug',
			'location'   => 'e.location',
			'category'   => 'c.name',
			'all_day'    => 'e.all_day',
			'start'      => 'e.start_at',
			'start_date' => 'e.start_at',
			'start_time' => 'e.start_at',
			'end'        => 'e.end_at',
			'end_date'   => 'e.end_at',
			'end_time'   => 'e.end_at',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'e.start_at';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(e.name LIKE %s OR e.slug LIKE %s OR e.location LIKE %s OR e.description LIKE %s OR c.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $args['start_date'] ) {
			$where[]  = 'e.end_at >= %s';
			$params[] = $args['start_date'] . ' 00:00:00';
		}

		if ( '' !== $args['end_date'] ) {
			$where[]  = 'e.start_at <= %s';
			$params[] = $args['end_date'] . ' 23:59:59';
		}

		$sql = 'SELECT e.*, c.name AS category_name, c.color AS category_color
			FROM ' . self::events_table() . ' e
			LEFT JOIN ' . self::categories_table() . ' c ON c.slug = e.category_slug
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY ' . $orderby . ' ' . $order . ', e.slug ASC'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( (int) $args['number'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['number'];
			$params[] = (int) $args['offset'];
		}

		return $wpdb->get_results( self::prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Insert an event.
	 *
	 * @param array $data Event data.
	 * @return bool
	 */
	public static function insert_event( $data ) {
		global $wpdb;

		return false !== $wpdb->insert(
			self::events_table(),
			array(
				'name'          => $data['name'],
				'slug'          => $data['slug'],
				'location'      => $data['location'],
				'category_slug' => $data['category_slug'],
				'description'   => $data['description'],
				'all_day'       => (int) $data['all_day'],
				'start_at'      => $data['start_at'],
				'end_at'        => $data['end_at'],
				'created_at'    => $data['created_at'],
				'updated_at'    => $data['updated_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update an event.
	 *
	 * @param string $old_slug Existing slug.
	 * @param array  $data Event data.
	 * @return bool
	 */
	public static function update_event( $old_slug, $data ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::events_table(),
			array(
				'name'          => $data['name'],
				'slug'          => $data['slug'],
				'location'      => $data['location'],
				'category_slug' => $data['category_slug'],
				'description'   => $data['description'],
				'all_day'       => (int) $data['all_day'],
				'start_at'      => $data['start_at'],
				'end_at'        => $data['end_at'],
				'updated_at'    => $data['updated_at'],
			),
			array( 'slug' => $old_slug ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Delete events by slug.
	 *
	 * @param array $slugs Event slugs.
	 * @return int
	 */
	public static function delete_events( $slugs ) {
		global $wpdb;

		$deleted = 0;

		foreach ( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) as $slug ) {
			$result = $wpdb->delete( self::events_table(), array( 'slug' => $slug ), array( '%s' ) );

			if ( false !== $result ) {
				$deleted += (int) $result;
			}
		}

		return $deleted;
	}

	/**
	 * Prepare a query only when placeholders are present.
	 *
	 * @param string $sql SQL query.
	 * @param array  $params Placeholder values.
	 * @return string
	 */
	private static function prepare( $sql, $params ) {
		global $wpdb;

		if ( empty( $params ) ) {
			return $sql;
		}

		return $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
