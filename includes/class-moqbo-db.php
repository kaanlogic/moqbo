<?php
/**
 * Database schema and persistence helpers.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database access layer for Moqbo.
 */
class Moqbo_DB {
	/**
	 * Number of sites processed per network batch.
	 */
	const NETWORK_BATCH_SIZE = 100;

	/**
	 * Maximum character length for stored slugs.
	 */
	const MAX_SLUG_LENGTH = 191;

	/**
	 * Maximum character length for names and locations.
	 */
	const MAX_TEXT_LENGTH = 255;

	/**
	 * Maximum byte length for MySQL TEXT values.
	 */
	const MAX_DESCRIPTION_BYTES = 65535;

	/**
	 * Object cache group for Moqbo query results.
	 */
	const CACHE_GROUP = 'moqbo';

	/**
	 * Object cache key for cache generation invalidation.
	 */
	const CACHE_LAST_CHANGED_KEY = 'last_changed';

	/**
	 * Register database lifecycle hooks.
	 */
	public static function init() {
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_site' ), 20, 2 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'filter_drop_tables' ), 10, 2 );
	}

	/**
	 * Activate the plugin on one or all sites.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 */
	public static function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			return self::run_network_install_batch( get_current_network_id() );
		}

		return self::create_schema();
	}

	/**
	 * Provision a newly initialized site when Moqbo is network-active.
	 *
	 * @param WP_Site $site New site object.
	 * @param array   $args Site initialization arguments.
	 */
	public static function initialize_site( $site, $args ) {
		unset( $args );
		$active = get_network_option( (int) $site->network_id, 'active_sitewide_plugins', array() );

		if ( ! isset( $active[ plugin_basename( MOQBO_FILE ) ] ) || ! wp_is_site_initialized( $site ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			self::create_schema();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Add Moqbo tables to permanent subsite cleanup.
	 *
	 * @param array $tables Tables WordPress will drop.
	 * @param int   $site_id Site ID.
	 * @return array
	 */
	public static function filter_drop_tables( $tables, $site_id ) {
		global $wpdb;

		$prefix   = $wpdb->get_blog_prefix( (int) $site_id );
		$tables[] = $prefix . 'moqbo_events';
		$tables[] = $prefix . 'moqbo_categories';

		return array_values( array_unique( $tables ) );
	}

	/**
	 * Install network sites synchronously in bounded batches.
	 *
	 * @param int $network_id Network ID.
	 * @return true|WP_Error
	 */
	public static function run_network_install_batch( $network_id ) {
		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'     => 'ids',
					'network_id' => (int) $network_id,
					'number'     => self::NETWORK_BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				try {
					$result = self::create_schema();

					if ( is_wp_error( $result ) ) {
						return $result;
					}
				} finally {
					restore_current_blog();
				}
			}

			$offset += self::NETWORK_BATCH_SIZE;
		} while ( count( $site_ids ) === self::NETWORK_BATCH_SIZE );

		return true;
	}

	/**
	 * Get the events table name for the current site.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'moqbo_events';
	}

	/**
	 * Get the categories table name for the current site.
	 *
	 * @return string
	 */
	public static function categories_table() {
		global $wpdb;

		return $wpdb->prefix . 'moqbo_categories';
	}

	/**
	 * Create custom tables.
	 *
	 * @return true|WP_Error
	 */
	public static function create_schema() {
		global $wpdb;

		// dbDelta() is defined in this core file and is called immediately below.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate  = $wpdb->get_charset_collate();
		$events_table     = self::events_table();
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
			KEY name (name(191))
		) {$charset_collate};";

		$categories_sql = "CREATE TABLE {$categories_table} (
			slug varchar(191) NOT NULL,
			name varchar(255) NOT NULL,
			color char(7) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (slug),
			KEY name (name(191))
		) {$charset_collate};";

		dbDelta( $events_sql );
		dbDelta( $categories_sql );

		$valid = self::validate_schema();

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		self::flush_cache();

		return true;
	}

	/**
	 * Drop custom tables for the current site.
	 */
	public static function drop_schema() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::events_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional uninstall cleanup for a custom table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::categories_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional uninstall cleanup for a custom table.

		self::flush_cache();
	}

	/**
	 * Return a category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return array|null|WP_Error
	 */
	public static function get_category( $slug ) {
		global $wpdb;

		$cache_key = self::cache_key( 'category', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$category = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
			$wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', self::categories_table(), $slug ),
			ARRAY_A
		);
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $category, self::CACHE_GROUP, $category ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );

		return $category;
	}

	/**
	 * Count categories matching query args.
	 *
	 * @param array $args Query args.
	 * @return int|WP_Error
	 */
	public static function count_categories( $args = array() ) {
		global $wpdb;
		$args           = wp_parse_args(
			$args,
			array(
				'search' => '',
			)
		);
		$args['search'] = is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$cache_key = self::cache_key( 'count_categories', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return (int) $cached;
		}

		$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i
				WHERE ( %s = \'\' OR name LIKE %s OR slug LIKE %s )',
				self::categories_table(),
				$args['search'],
				$like,
				$like
			)
		);
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, $count > 0 ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );

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
		$args            = wp_parse_args( $args, $defaults );
		$args['search']  = is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$args['orderby'] = is_scalar( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'name';
		$args['order']   = is_scalar( $args['order'] ) ? strtoupper( sanitize_key( (string) $args['order'] ) ) : 'ASC';
		$args['number']  = is_scalar( $args['number'] ) ? max( 0, (int) $args['number'] ) : 0;
		$args['offset']  = is_scalar( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$orderby_map = array(
			'name'  => 'name',
			'slug'  => 'slug',
			'count' => 'event_count',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'name';
		$order   = 'DESC' === $args['order'] ? 'DESC' : 'ASC';

		$cache_key = self::cache_key( 'categories', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$limit = $args['number'] > 0 ? $args['number'] : PHP_INT_MAX;

		if ( 'DESC' === $order ) {
			$categories = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
				$wpdb->prepare(
					'SELECT c.slug, c.name, c.color, c.created_at, c.updated_at, COUNT(e.slug) AS event_count
					FROM %i c
					LEFT JOIN %i e ON e.category_slug = c.slug
					WHERE ( %s = \'\' OR c.name LIKE %s OR c.slug LIKE %s )
					GROUP BY c.slug, c.name, c.color, c.created_at, c.updated_at
					ORDER BY %i DESC
					LIMIT %d OFFSET %d',
					self::categories_table(),
					self::events_table(),
					$args['search'],
					$like,
					$like,
					$orderby,
					$limit,
					$args['offset']
				),
				ARRAY_A
			);
		} else {
			$categories = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
				$wpdb->prepare(
					'SELECT c.slug, c.name, c.color, c.created_at, c.updated_at, COUNT(e.slug) AS event_count
					FROM %i c
					LEFT JOIN %i e ON e.category_slug = c.slug
					WHERE ( %s = \'\' OR c.name LIKE %s OR c.slug LIKE %s )
					GROUP BY c.slug, c.name, c.color, c.created_at, c.updated_at
					ORDER BY %i ASC
					LIMIT %d OFFSET %d',
					self::categories_table(),
					self::events_table(),
					$args['search'],
					$like,
					$like,
					$orderby,
					$limit,
					$args['offset']
				),
				ARRAY_A
			);
		}
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $categories, self::CACHE_GROUP, empty( $categories ) ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $categories;
	}

	/**
	 * Insert a category.
	 *
	 * @param array $data Category data.
	 * @return true|WP_Error
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

		if ( false === $inserted ) {
			return self::database_write_error( 'moqbo_save_category_failed', __( 'The category could not be saved.', 'moqbo' ) );
		}

		self::flush_cache();

		return true;
	}

	/**
	 * Update a category while keeping its slug immutable.
	 *
	 * @param string $old_slug Existing slug.
	 * @param array  $data Category data.
	 * @return true|WP_Error
	 */
	public static function update_category( $old_slug, $data ) {
		global $wpdb;

		if ( $old_slug !== $data['slug'] ) {
			return new WP_Error( 'moqbo_category_slug_immutable', __( 'Category slugs cannot be changed after creation.', 'moqbo' ) );
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated on success.
			self::categories_table(),
			array(
				'name'       => $data['name'],
				'color'      => $data['color'],
				'updated_at' => $data['updated_at'],
			),
			array( 'slug' => $old_slug ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return self::database_write_error( 'moqbo_save_category_failed', __( 'The category could not be saved.', 'moqbo' ) );
		}

		if ( 0 === $updated ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE slug = %s', self::categories_table(), $old_slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Missing-row verification.
			$error  = self::database_read_error();

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			if ( ! $exists ) {
				return new WP_Error( 'moqbo_missing_category', __( 'The category no longer exists.', 'moqbo' ) );
			}
		}

		self::flush_cache();

		return true;
	}

	/**
	 * Count events for a category slug.
	 *
	 * @param string $slug Category slug.
	 * @return int|WP_Error
	 */
	public static function count_events_for_category( $slug ) {
		global $wpdb;

		$cache_key = self::cache_key( 'count_events_for_category', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE category_slug = %s', self::events_table(), $slug )
		);
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, $count > 0 ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Delete categories that are not referenced by events.
	 *
	 * @param array $slugs Category slugs.
	 * @return array Structured delete result.
	 */
	public static function delete_categories( $slugs ) {
		global $wpdb;

		$result = array(
			'deleted' => array(),
			'blocked' => array(),
			'missing' => array(),
			'failed'  => array(),
		);

		foreach ( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) as $slug ) {
			$lock = self::acquire_lock( 'write' );

			if ( is_wp_error( $lock ) ) {
				$result['failed'][] = $slug;
				continue;
			}

			try {
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE category_slug = %s', self::events_table(), $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked integrity check.

				if ( is_wp_error( self::database_read_error() ) ) {
					$result['failed'][] = $slug;
					continue;
				}

				if ( (int) $count > 0 ) {
					$result['blocked'][] = $slug;
					continue;
				}

				$deleted = $wpdb->delete( self::categories_table(), array( 'slug' => $slug ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated after deletes.

				if ( false === $deleted ) {
					$result['failed'][] = $slug;
				} elseif ( 0 === $deleted ) {
					$result['missing'][] = $slug;
				} else {
					$result['deleted'][] = $slug;
				}
			} finally {
				self::release_lock( 'write' );
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
	 * @return array|null|WP_Error
	 */
	public static function get_event( $slug ) {
		global $wpdb;

		$cache_key = self::cache_key( 'event', array( $slug ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$event = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
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
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $event, self::CACHE_GROUP, $event ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );

		return $event;
	}

	/**
	 * Return the next upcoming event whose name contains a search string.
	 *
	 * @param string $name Event name fragment.
	 * @return array|null|WP_Error
	 */
	public static function get_next_event_by_name( $name ) {
		global $wpdb;

		$like        = '%' . $wpdb->esc_like( $name ) . '%';
		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$now         = current_time( 'mysql' );
		$cache_key   = self::cache_key( 'next_event_by_name', array( $name, current_time( 'Y-m-d H:i' ) ) );
		$cached      = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
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
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $event, self::CACHE_GROUP, MINUTE_IN_SECONDS );

		return $event;
	}

	/**
	 * Count events matching query args.
	 *
	 * @param array $args Query args.
	 * @return int|WP_Error
	 */
	public static function count_events( $args = array() ) {
		global $wpdb;
		$args = array(
			'search' => isset( $args['search'] ) && is_scalar( $args['search'] )
				? sanitize_text_field( (string) $args['search'] )
				: '',
		);

		$cache_key = self::cache_key( 'count_events', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return (int) $cached;
		}

		$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i e
				LEFT JOIN %i c ON c.slug = e.category_slug
				WHERE ( %s = \'\' OR e.name LIKE %s OR e.slug LIKE %s OR e.location LIKE %s OR e.description LIKE %s OR c.name LIKE %s )',
				self::events_table(),
				self::categories_table(),
				$args['search'],
				$like,
				$like,
				$like,
				$like,
				$like
			)
		);
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, $count > 0 ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Get events with category metadata.
	 *
	 * @param array $args Query args.
	 * @return array|WP_Error
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
		$args               = wp_parse_args( $args, $defaults );
		$args['search']     = is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$args['start_date'] = is_scalar( $args['start_date'] ) ? sanitize_text_field( (string) $args['start_date'] ) : '';
		$args['end_date']   = is_scalar( $args['end_date'] ) ? sanitize_text_field( (string) $args['end_date'] ) : '';
		$args['orderby']    = is_scalar( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'start';
		$args['order']      = is_scalar( $args['order'] ) ? strtoupper( sanitize_key( (string) $args['order'] ) ) : 'ASC';
		$args['number']     = is_scalar( $args['number'] ) ? max( 0, (int) $args['number'] ) : 0;
		$args['offset']     = is_scalar( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$orderby_map = array(
			'name'       => 'name',
			'slug'       => 'slug',
			'location'   => 'location',
			'category'   => 'category_name',
			'all_day'    => 'all_day',
			'start'      => 'start_at',
			'start_date' => 'start_at',
			'start_time' => 'start_at',
			'end'        => 'end_at',
			'end_date'   => 'end_at',
			'end_time'   => 'end_at',
			'created_at' => 'created_at',
			'updated_at' => 'updated_at',
		);

		$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'start_at';
		$order   = 'DESC' === $args['order'] ? 'DESC' : 'ASC';

		$cache_key = self::cache_key( 'events', $args );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$like           = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$start_boundary = '' !== $args['start_date'] ? $args['start_date'] . ' 00:00:00' : '';
		$end_boundary   = '' !== $args['end_date'] ? $args['end_date'] . ' 23:59:59' : '';
		$limit          = $args['number'] > 0 ? $args['number'] : PHP_INT_MAX;

		if ( 'DESC' === $order ) {
			$events = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
				$wpdb->prepare(
					'SELECT e.*, c.name AS category_name, c.color AS category_color
					FROM %i e
					LEFT JOIN %i c ON c.slug = e.category_slug
					WHERE ( %s = \'\' OR e.name LIKE %s OR e.slug LIKE %s OR e.location LIKE %s OR e.description LIKE %s OR c.name LIKE %s )
					AND ( %s = \'\' OR e.end_at >= %s )
					AND ( %s = \'\' OR e.start_at <= %s )
					ORDER BY %i DESC, e.slug ASC
					LIMIT %d OFFSET %d',
					self::events_table(),
					self::categories_table(),
					$args['search'],
					$like,
					$like,
					$like,
					$like,
					$like,
					$args['start_date'],
					$start_boundary,
					$args['end_date'],
					$end_boundary,
					$orderby,
					$limit,
					$args['offset']
				),
				ARRAY_A
			);
		} else {
			$events = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query cached with the Moqbo cache group.
				$wpdb->prepare(
					'SELECT e.*, c.name AS category_name, c.color AS category_color
					FROM %i e
					LEFT JOIN %i c ON c.slug = e.category_slug
					WHERE ( %s = \'\' OR e.name LIKE %s OR e.slug LIKE %s OR e.location LIKE %s OR e.description LIKE %s OR c.name LIKE %s )
					AND ( %s = \'\' OR e.end_at >= %s )
					AND ( %s = \'\' OR e.start_at <= %s )
					ORDER BY %i ASC, e.slug ASC
					LIMIT %d OFFSET %d',
					self::events_table(),
					self::categories_table(),
					$args['search'],
					$like,
					$like,
					$like,
					$like,
					$like,
					$args['start_date'],
					$start_boundary,
					$args['end_date'],
					$end_boundary,
					$orderby,
					$limit,
					$args['offset']
				),
				ARRAY_A
			);
		}
		$error = self::database_read_error();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_cache_set( $cache_key, $events, self::CACHE_GROUP, empty( $events ) ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $events;
	}

	/**
	 * Insert an event.
	 *
	 * @param array $data Event data.
	 * @return true|WP_Error
	 */
	public static function insert_event( $data ) {
		global $wpdb;
		$lock = self::acquire_lock( 'write' );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$category_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE slug = %s', self::categories_table(), $data['category_slug'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked integrity check.
			$error           = self::database_read_error();

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			if ( ! $category_exists ) {
				return new WP_Error( 'moqbo_missing_category', __( 'Choose an existing event category.', 'moqbo' ) );
			}

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

			if ( false === $inserted ) {
				return self::database_write_error( 'moqbo_save_event_failed', __( 'The event could not be saved.', 'moqbo' ) );
			}

			self::flush_cache();

			return true;
		} finally {
			self::release_lock( 'write' );
		}
	}

	/**
	 * Update an event.
	 *
	 * @param string $old_slug Existing slug.
	 * @param array  $data Event data.
	 * @return true|WP_Error
	 */
	public static function update_event( $old_slug, $data ) {
		global $wpdb;
		$lock = self::acquire_lock( 'write' );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$category_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE slug = %s', self::categories_table(), $data['category_slug'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked integrity check.
			$error           = self::database_read_error();

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			if ( ! $category_exists ) {
				return new WP_Error( 'moqbo_missing_category', __( 'Choose an existing event category.', 'moqbo' ) );
			}

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

			if ( false === $updated ) {
				return self::database_write_error( 'moqbo_save_event_failed', __( 'The event could not be saved.', 'moqbo' ) );
			}

			if ( 0 === $updated ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE slug = %s', self::events_table(), $old_slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked missing-row verification.
				$error  = self::database_read_error();

				if ( is_wp_error( $error ) ) {
					return $error;
				}

				if ( ! $exists ) {
					return new WP_Error( 'moqbo_missing_event', __( 'The event no longer exists.', 'moqbo' ) );
				}
			}

			self::flush_cache();

			return true;
		} finally {
			self::release_lock( 'write' );
		}
	}

	/**
	 * Delete events by slug.
	 *
	 * @param array $slugs Event slugs.
	 * @return array Structured delete result.
	 */
	public static function delete_events( $slugs ) {
		global $wpdb;

		$result = array(
			'deleted' => array(),
			'missing' => array(),
			'failed'  => array(),
		);

		foreach ( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) as $slug ) {
			$deleted = $wpdb->delete( self::events_table(), array( 'slug' => $slug ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated after deletes.

			if ( false === $deleted ) {
				$result['failed'][] = $slug;
			} elseif ( 0 === $deleted ) {
				$result['missing'][] = $slug;
			} else {
				$result['deleted'][] = $slug;
			}
		}

		if ( ! empty( $result['deleted'] ) ) {
			self::flush_cache();
		}

		return $result;
	}

	/**
	 * Verify required schema postconditions after dbDelta().
	 *
	 * @return true|WP_Error
	 */
	private static function validate_schema() {
		global $wpdb;

		foreach ( array( self::events_table(), self::categories_table() ) as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-time schema verification.

			if ( $table !== $exists ) {
				return new WP_Error( 'moqbo_schema_missing_table', __( 'A required Moqbo database table could not be created.', 'moqbo' ) );
			}
		}

		return true;
	}

	/**
	 * Return a generic error after a failed database read.
	 *
	 * @return true|WP_Error
	 */
	private static function database_read_error() {
		global $wpdb;

		return '' === $wpdb->last_error
			? true
			: new WP_Error( 'moqbo_database_error', __( 'Moqbo could not read from the database.', 'moqbo' ), array( 'status' => 500 ) );
	}

	/**
	 * Return a generic database write error.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function database_write_error( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 500 ) );
	}

	/**
	 * Acquire a database advisory lock scoped to this site.
	 *
	 * @param string $context Lock context.
	 * @return true|WP_Error
	 */
	private static function acquire_lock( $context ) {
		global $wpdb;
		$name   = 'moqbo:' . md5( $wpdb->dbname . ':' . get_current_blog_id() . ':' . $context );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-engine advisory lock for custom-table integrity.

		return '1' === (string) $locked
			? true
			: new WP_Error( 'moqbo_database_lock_failed', __( 'Moqbo could not obtain a database lock. Try again.', 'moqbo' ), array( 'status' => 503 ) );
	}

	/**
	 * Release a database advisory lock.
	 *
	 * @param string $context Lock context.
	 */
	private static function release_lock( $context ) {
		global $wpdb;
		$name = 'moqbo:' . md5( $wpdb->dbname . ':' . get_current_blog_id() . ':' . $context );

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release matching custom-table advisory lock.
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

		return 'moqbo_' . md5( $payload );
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
	 * Invalidate cached Moqbo query results.
	 */
	private static function flush_cache() {
		wp_cache_set( self::CACHE_LAST_CHANGED_KEY, microtime(), self::CACHE_GROUP );
	}
}
