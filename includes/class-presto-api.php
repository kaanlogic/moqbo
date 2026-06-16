<?php
/**
 * Presto API registration.
 *
 * @package Presto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presto API endpoint registration.
 */
class Presto_API {
	/**
	 * REST API namespace.
	 */
	const REST_NAMESPACE = 'presto/v1';

	/**
	 * Register API hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_events' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => array(
						'start_date' => array(
							'description'       => __( 'Start date for the requested event range, inclusive, in YYYY-MM-DD format.', 'presto' ),
							'type'              => 'string',
							'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
						),
						'end_date'   => array(
							'description'       => __( 'End date for the requested event range, inclusive, in YYYY-MM-DD format.', 'presto' ),
							'type'              => 'string',
							'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Check whether the public Presto API is enabled.
	 *
	 * @return true|WP_Error
	 */
	public static function permissions_check() {
		if ( Presto_Settings::is_feature_enabled( Presto_Settings::FEATURE_API ) ) {
			return true;
		}

		return new WP_Error(
			'presto_api_disabled',
			__( 'The Presto API is disabled.', 'presto' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return events that overlap the requested date range.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_events( $request ) {
		$query      = $request->get_query_params();
		$start_date = self::sanitize_date_arg( isset( $query['start_date'] ) ? $query['start_date'] : '' );
		$end_date   = self::sanitize_date_arg( isset( $query['end_date'] ) ? $query['end_date'] : '' );
		$start      = self::parse_date( $start_date );
		$end        = self::parse_date( $end_date );

		if ( '' === $start_date || '' === $end_date ) {
			return new WP_Error(
				'presto_missing_date_range',
				__( 'Start date and end date are required.', 'presto' ),
				array(
					'status' => 400,
					'params' => array( 'start_date', 'end_date' ),
				)
			);
		}

		if ( ! $start || ! $end ) {
			return new WP_Error(
				'presto_invalid_date',
				__( 'Start date and end date must use YYYY-MM-DD format.', 'presto' ),
				array( 'status' => 400 )
			);
		}

		if ( $end < $start ) {
			return new WP_Error(
				'presto_invalid_date_range',
				__( 'End date must be on or after start date.', 'presto' ),
				array( 'status' => 400 )
			);
		}

		$events = Presto_DB::get_events(
			array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'orderby'    => 'start',
				'order'      => 'ASC',
			)
		);

		return rest_ensure_response( array_map( array( __CLASS__, 'prepare_event' ), $events ) );
	}

	/**
	 * Sanitize a REST date argument.
	 *
	 * @param mixed $date Date value.
	 * @return string
	 */
	public static function sanitize_date_arg( $date ) {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		return sanitize_text_field( (string) $date );
	}

	/**
	 * Prepare an event for the public API response.
	 *
	 * @param array $event Event row.
	 * @return array
	 */
	private static function prepare_event( $event ) {
		$category_name  = ! empty( $event['category_name'] ) ? $event['category_name'] : $event['category_slug'];
		$category_color = sanitize_hex_color( isset( $event['category_color'] ) ? (string) $event['category_color'] : '' );

		return array(
			'slug'           => $event['slug'],
			'name'           => $event['name'],
			'location'       => wp_strip_all_tags( isset( $event['location'] ) ? (string) $event['location'] : '' ),
			'category_slug'  => $event['category_slug'],
			'category_name'  => $category_name,
			'category_color' => $category_color ? $category_color : '',
			'description'    => wp_strip_all_tags( isset( $event['description'] ) ? (string) $event['description'] : '' ),
			'all_day'        => (bool) $event['all_day'],
			'start_at'       => $event['start_at'],
			'end_at'         => $event['end_at'],
			'start_date'     => substr( $event['start_at'], 0, 10 ),
			'end_date'       => substr( $event['end_at'], 0, 10 ),
			'created_at'     => $event['created_at'],
			'updated_at'     => $event['updated_at'],
		);
	}

	/**
	 * Parse a YYYY-MM-DD date argument.
	 *
	 * @param mixed $date Date value.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$dt     = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();

		if ( ! $dt || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $dt->format( 'Y-m-d' ) === $date ? $dt : null;
	}
}
