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
	 * Object cache group for Presto query results.
	 */
	const CACHE_GROUP = 'presto';

	/**
	 * Object cache key for cache generation invalidation.
	 */
	const CACHE_LAST_CHANGED_KEY = 'last_changed';

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
		self::flush_cache();
	}

	/**
	 * Reorder existing event columns to match Presto's admin field flow.
	 */
	private static function reorder_events_columns() {
		global $wpdb;

		$events_table = self::events_table();
		$expected     = array( 'name', 'slug', 'location', 'category_slug', 'description', 'all_day', 'start_at', 'end_at', 'created_at', 'updated_at' );
		$columns      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection for a custom table during migration.
			$wpdb->prepare( 'DESCRIBE %i', $events_table ),
			0
		);

		if ( array_slice( $columns, 0, count( $expected ) ) === $expected ) {
			return;
		}

		if ( array_diff( $expected, $columns ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional custom table migration.
		$altered = $wpdb->query(
			$wpdb->prepare(
				'ALTER TABLE %i
				MODIFY name varchar(255) NOT NULL FIRST,
				MODIFY slug varchar(191) NOT NULL AFTER name,
				MODIFY location varchar(255) NOT NULL DEFAULT \'\' AFTER slug,
				MODIFY category_slug varchar(191) NOT NULL AFTER location,
				MODIFY description text NULL AFTER category_slug,
				MODIFY all_day tinyint(1) NOT NULL DEFAULT 0 AFTER description,
				MODIFY start_at datetime NOT NULL AFTER all_day,
				MODIFY end_at datetime NOT NULL AFTER start_at,
				MODIFY created_at datetime NOT NULL AFTER end_at,
				MODIFY updated_at datetime NOT NULL AFTER created_at',
				$events_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( false !== $altered ) {
			self::flush_cache();
		}
	}

	/**
	 * Drop custom tables and options for the current site.
	 */
	public static function drop_schema() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::events_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional uninstall cleanup for a custom table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::categories_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional uninstall cleanup for a custom table.

		delete_option( 'presto_db_version' );
		self::flush_cache();
	}

	/**
	 * Return a category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return array|null
	 */
	public static function get_category( $slug ) {
		global $wpdb;

		$cache_key = self::cache_key( 'category', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$category = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Presto cache group.
			$wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', self::categories_table(), $slug ),
			ARRAY_A
		);

		wp_cache_set( $cache_key, $category, self::CACHE_GROUP );

		return $category;
	}

	/**
	 * Count categories matching query args.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count_categories( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
			)
		);

		$cache_key = self::cache_key( 'count_categories', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql    = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );
		$params = array_merge( array( self::categories_table() ), $params );
		$query  = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE clauses are fixed internally and table names are prepared as identifiers.
		$count  = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query prepared above and cached with the Presto cache group.

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP );

		return $count;
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
		$args['number'] = (int) $args['number'];
		$args['offset'] = (int) $args['offset'];

		$orderby_map = array(
			'name'  => 'c.name',
			'slug'  => 'c.slug',
			'count' => 'event_count',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'c.name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$cache_key = self::cache_key( 'categories', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(c.name LIKE %s OR c.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT c.slug, c.name, c.color, c.created_at, c.updated_at, COUNT(e.slug) AS event_count
			FROM %i c
			LEFT JOIN %i e ON e.category_slug = c.slug
			WHERE ' . implode( ' AND ', $where ) . '
			GROUP BY c.slug, c.name, c.color, c.created_at, c.updated_at
			ORDER BY ' . $orderby . ' ' . $order;

		$params = array_merge( array( self::categories_table(), self::events_table() ), $params );

		if ( $args['number'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $args['number'];
			$params[] = $args['offset'];
		}

		$query      = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE and ORDER BY clauses are built from fixed clauses and whitelisted columns.
		$categories = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query prepared above and cached with the Presto cache group.

		wp_cache_set( $cache_key, $categories, self::CACHE_GROUP );

		return $categories;
	}

	/**
	 * Insert a category.
	 *
	 * @param array $data Category data.
	 * @return bool
	 */
	public static function insert_category( $data ) {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write; cache invalidated on success.
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

		if ( false !== $inserted ) {
			self::flush_cache();
		}

		return false !== $inserted;
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

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated on success.
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
			$references_updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated on success.
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

		self::flush_cache();

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

		$cache_key = self::cache_key( 'count_events_for_category', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Presto cache group.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE category_slug = %s', self::events_table(), $slug )
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP );

		return $count;
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

			$deleted = $wpdb->delete( self::categories_table(), array( 'slug' => $slug ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated after deletes.

			if ( false !== $deleted && $deleted > 0 ) {
				$result['deleted'][] = $slug;
			}
		}

		if ( ! empty( $result['deleted'] ) ) {
			self::flush_cache();
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

		$cache_key = self::cache_key( 'event', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$event = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Presto cache group.
			$wpdb->prepare(
				'SELECT e.*, c.name AS category_name, c.color AS category_color
				FROM %i e
				LEFT JOIN %i c ON c.slug = e.category_slug
				WHERE e.slug = %s',
				self::events_table(),
				self::categories_table(),
				$slug
			),
			ARRAY_A
		);

		wp_cache_set( $cache_key, $event, self::CACHE_GROUP );

		return $event;
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
		$cache_key   = self::cache_key( 'next_event_by_name', array( $name, current_time( 'Y-m-d H:i' ) ) );
		$cached      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$event = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with a short-lived cache key.
			$wpdb->prepare(
				'SELECT e.*
				FROM %i e
				WHERE e.name LIKE %s
				AND ( ( e.all_day = 1 AND e.start_at >= %s ) OR ( e.all_day = 0 AND e.start_at >= %s ) )
				ORDER BY e.start_at ASC, e.slug ASC
				LIMIT 1',
				self::events_table(),
				$like,
				$today_start,
				$now
			),
			ARRAY_A
		);

		wp_cache_set( $cache_key, $event, self::CACHE_GROUP, MINUTE_IN_SECONDS );

		return $event;
	}

	/**
	 * Count events matching query args.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count_events( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
			)
		);

		$cache_key = self::cache_key( 'count_events', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

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
			FROM %i e
			LEFT JOIN %i c ON c.slug = e.category_slug
			WHERE ' . implode( ' AND ', $where );
		$params = array_merge( array( self::events_table(), self::categories_table() ), $params );
		$query  = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE clauses are fixed internally and table names are prepared as identifiers.
		$count  = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query prepared above and cached with the Presto cache group.

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP );

		return $count;
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
		$args['number'] = (int) $args['number'];
		$args['offset'] = (int) $args['offset'];

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
			'created_at' => 'e.created_at',
			'updated_at' => 'e.updated_at',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'e.start_at';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$cache_key = self::cache_key( 'events', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

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
			FROM %i e
			LEFT JOIN %i c ON c.slug = e.category_slug
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY ' . $orderby . ' ' . $order . ', e.slug ASC';

		$params = array_merge( array( self::events_table(), self::categories_table() ), $params );

		if ( $args['number'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $args['number'];
			$params[] = $args['offset'];
		}

		$query  = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE and ORDER BY clauses are built from fixed clauses and whitelisted columns.
		$events = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query prepared above and cached with the Presto cache group.

		wp_cache_set( $cache_key, $events, self::CACHE_GROUP );

		return $events;
	}

	/**
	 * Insert an event.
	 *
	 * @param array $data Event data.
	 * @return bool
	 */
	public static function insert_event( $data ) {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write; cache invalidated on success.
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

		if ( false !== $inserted ) {
			self::flush_cache();
		}

		return false !== $inserted;
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

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated on success.
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

		if ( false !== $updated ) {
			self::flush_cache();
		}

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
			$result = $wpdb->delete( self::events_table(), array( 'slug' => $slug ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated after deletes.

			if ( false !== $result ) {
				$deleted += (int) $result;
			}
		}

		if ( $deleted > 0 ) {
			self::flush_cache();
		}

		return $deleted;
	}

	/**
	 * Get a cache key scoped to the current blog and cache generation.
	 *
	 * @param string $context Cache context.
	 * @param array  $parts Cache key parts.
	 * @return string
	 */
	private static function cache_key( $context, $parts = array() ) {
		$payload = wp_json_encode(
			array(
				'blog_id'      => get_current_blog_id(),
				'context'      => $context,
				'last_changed' => self::get_cache_last_changed(),
				'parts'        => $parts,
			)
		);

		if ( false === $payload ) {
			$payload = serialize( $parts ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cache key fallback for non-JSON-encodable values.
		}

		return 'presto_' . md5( $payload );
	}

	/**
	 * Get the cache generation marker.
	 *
	 * @return string
	 */
	private static function get_cache_last_changed() {
		$last_changed = wp_cache_get( self::CACHE_LAST_CHANGED_KEY, self::CACHE_GROUP );

		if ( false === $last_changed ) {
			$last_changed = microtime();
			wp_cache_set( self::CACHE_LAST_CHANGED_KEY, $last_changed, self::CACHE_GROUP );
		}

		return $last_changed;
	}

	/**
	 * Invalidate cached Presto query results.
	 */
	private static function flush_cache() {
		wp_cache_set( self::CACHE_LAST_CHANGED_KEY, microtime(), self::CACHE_GROUP );
	}
}
