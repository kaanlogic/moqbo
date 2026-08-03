<?php
/**
 * Moqbo API registration.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moqbo API endpoint registration.
 */
class Moqbo_API {
	/**
	 * REST API namespace.
	 */
	const REST_NAMESPACE = 'moqbo/v1';

	/**
	 * Maximum event query span in days.
	 */
	const MAX_EVENT_QUERY_DAYS = 366;

	/**
	 * Maximum visible calendar range in days.
	 */
	const MAX_CALENDAR_QUERY_DAYS = 62;

	/**
	 * Maximum events returned to one calendar view.
	 */
	const MAX_CALENDAR_EVENTS = 100;

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
			'/calendar-events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_calendar_events' ),
					'permission_callback' => array( __CLASS__, 'get_calendar_events_permissions_check' ),
					'args'                => self::get_calendar_events_args(),
				),
				'schema' => array( __CLASS__, 'get_calendar_events_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_events' ),
					'permission_callback' => array( __CLASS__, 'get_events_permissions_check' ),
					'args'                => self::get_events_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_event' ),
					'permission_callback' => array( __CLASS__, 'create_event_permissions_check' ),
					'args'                => self::create_event_args(),
				),
				'schema' => array( __CLASS__, 'get_event_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_event' ),
					'permission_callback' => array( __CLASS__, 'get_events_permissions_check' ),
					'args'                => self::get_item_args(),
				),
				'schema' => array( __CLASS__, 'get_event_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_categories' ),
					'permission_callback' => array( __CLASS__, 'get_categories_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_category' ),
					'permission_callback' => array( __CLASS__, 'create_category_permissions_check' ),
					'args'                => self::create_category_args(),
				),
				'schema' => array( __CLASS__, 'get_category_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/categories/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_category' ),
					'permission_callback' => array( __CLASS__, 'get_categories_permissions_check' ),
					'args'                => self::get_item_args(),
				),
				'schema' => array( __CLASS__, 'get_category_schema' ),
			)
		);
	}

	/**
	 * Check whether the calendar data endpoint is available.
	 *
	 * @return true|WP_Error
	 */
	public static function get_calendar_events_permissions_check() {
		return Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_SHORTCODE )
			? true
			: new WP_Error( 'moqbo_calendar_disabled', __( 'The Moqbo calendar shortcode is disabled.', 'moqbo' ), array( 'status' => 403 ) );
	}

	/**
	 * Return events for one visible calendar range.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_calendar_events( $request ) {
		$start_date = (string) $request->get_param( 'start_date' );
		$end_date   = (string) $request->get_param( 'end_date' );
		$start      = self::parse_date( $start_date );
		$end        = self::parse_date( $end_date );

		if ( ! $start || ! $end || $end < $start ) {
			return new WP_Error( 'moqbo_invalid_date_range', __( 'Enter a valid calendar date range.', 'moqbo' ), array( 'status' => 400 ) );
		}

		if ( $start->diff( $end )->days > self::MAX_CALENDAR_QUERY_DAYS ) {
			return new WP_Error( 'moqbo_calendar_range_too_large', __( 'The requested calendar date range is too large.', 'moqbo' ), array( 'status' => 400 ) );
		}

		$events = Moqbo_DB::get_events(
			array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'orderby'    => 'start',
				'order'      => 'ASC',
				'number'     => self::MAX_CALENDAR_EVENTS,
			)
		);

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		return rest_ensure_response(
			array(
				'events' => Moqbo_Shortcode::prepare_events( $events ),
			)
		);
	}

	/**
	 * Return the event resource schema.
	 *
	 * @return array
	 */
	public static function get_event_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moqbo_event',
			'type'       => 'object',
			'properties' => array(
				'name'          => array( 'description' => __( 'Event name', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_TEXT_LENGTH ),
				'slug'          => array( 'description' => __( 'Event slug', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_SLUG_LENGTH ),
				'location'      => array( 'description' => __( 'Event location', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_TEXT_LENGTH ),
				'category_slug' => array( 'description' => __( 'Event category slug', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_SLUG_LENGTH ),
				'description'   => array( 'description' => __( 'Event description', 'moqbo' ), 'type' => 'string' ),
				'start_at'      => array( 'description' => __( 'Event start date and time', 'moqbo' ), 'type' => 'string' ),
				'end_at'        => array( 'description' => __( 'Event end date and time', 'moqbo' ), 'type' => 'string' ),
				'all_day'       => array( 'description' => __( 'Whether the event is all-day', 'moqbo' ), 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Return the internal calendar-data response schema.
	 *
	 * @return array
	 */
	public static function get_calendar_events_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moqbo_calendar_events',
			'type'       => 'object',
			'properties' => array(
				'events' => array(
					'description' => __( 'Prepared visible calendar events', 'moqbo' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
				),
			),
		);
	}

	/**
	 * Return the category resource schema.
	 *
	 * @return array
	 */
	public static function get_category_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moqbo_category',
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'description' => __( 'Category name', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_TEXT_LENGTH ),
				'slug'        => array( 'description' => __( 'Category slug', 'moqbo' ), 'type' => 'string', 'maxLength' => Moqbo_DB::MAX_SLUG_LENGTH ),
				'color'       => array( 'description' => __( 'Category color', 'moqbo' ), 'type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$' ),
				'event_count' => array( 'description' => __( 'Number of events in the category', 'moqbo' ), 'type' => 'integer', 'minimum' => 0, 'readonly' => true ),
			),
		);
	}

	/**
	 * Check whether the events GET endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function get_events_permissions_check( $request ) {
		$permission = self::endpoint_availability_check(
			Moqbo_Settings::API_GET_EVENTS_ENABLED,
			'moqbo_api_get_events_disabled',
			__( 'The Moqbo events GET endpoint is disabled.', 'moqbo' )
		);

		return is_wp_error( $permission ) ? $permission : self::authentication_check( $request );
	}

	/**
	 * Check whether the events POST endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function create_event_permissions_check( $request ) {
		$permission = self::endpoint_availability_check(
			Moqbo_Settings::API_POST_EVENTS_ENABLED,
			'moqbo_api_post_events_disabled',
			__( 'The Moqbo events POST endpoint is disabled.', 'moqbo' )
		);

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return self::token_authentication_check( $request );
	}

	/**
	 * Check whether the categories GET endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function get_categories_permissions_check( $request ) {
		$permission = self::endpoint_availability_check(
			Moqbo_Settings::API_GET_CATEGORIES_ENABLED,
			'moqbo_api_get_categories_disabled',
			__( 'The Moqbo categories GET endpoint is disabled.', 'moqbo' )
		);

		return is_wp_error( $permission ) ? $permission : self::authentication_check( $request );
	}

	/**
	 * Check whether the categories POST endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function create_category_permissions_check( $request ) {
		$permission = self::endpoint_availability_check(
			Moqbo_Settings::API_POST_CATEGORIES_ENABLED,
			'moqbo_api_post_categories_disabled',
			__( 'The Moqbo categories POST endpoint is disabled.', 'moqbo' )
		);

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return self::token_authentication_check( $request );
	}

	/**
	 * Return events that overlap the requested date range.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_events( $request ) {
		$start_date = (string) $request->get_param( 'start_date' );
		$end_date   = (string) $request->get_param( 'end_date' );
		$start      = self::parse_date( $start_date );
		$end        = self::parse_date( $end_date );

		if ( '' === $start_date || '' === $end_date ) {
			return new WP_Error(
				'moqbo_missing_date_range',
				__( 'Start date and end date are required.', 'moqbo' ),
				array(
					'status' => 400,
					'params' => array( 'start_date', 'end_date' ),
				)
			);
		}

		if ( ! $start || ! $end ) {
			return new WP_Error(
				'moqbo_invalid_date',
				__( 'Start date and end date must use YYYY-MM-DD format.', 'moqbo' ),
				array( 'status' => 400 )
			);
		}

		if ( $end < $start ) {
			return new WP_Error(
				'moqbo_invalid_date_range',
				__( 'End date must be on or after start date.', 'moqbo' ),
				array( 'status' => 400 )
			);
		}

		if ( $start->diff( $end )->days > self::MAX_EVENT_QUERY_DAYS ) {
			return new WP_Error(
				'moqbo_date_range_too_large',
				sprintf(
					/* translators: %d: maximum date-range length in days. */
					__( 'The requested date range cannot exceed %d days.', 'moqbo' ),
					self::MAX_EVENT_QUERY_DAYS
				),
				array( 'status' => 400 )
			);
		}

		$events = Moqbo_DB::get_events(
			array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'orderby'    => 'start',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		return rest_ensure_response( array_map( array( __CLASS__, 'prepare_event' ), $events ) );
	}

	/**
	 * Return one event by slug.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_event( $request ) {
		$event = Moqbo_DB::get_event( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		if ( ! $event ) {
			return new WP_Error( 'moqbo_event_not_found', __( 'The requested event was not found.', 'moqbo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::prepare_event( $event ) );
	}

	/**
	 * Create an event from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_event( $request ) {
		$validated = self::validate_event_request( $request );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now                     = current_time( 'mysql' );
		$validated['created_at'] = $now;
		$validated['updated_at'] = $now;

		$saved = Moqbo_DB::insert_event( $validated );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = rest_ensure_response( self::prepare_event( $validated ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/events/' . rawurlencode( $validated['slug'] ) ) );

		return $response;
	}

	/**
	 * Return event categories.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_categories() {
		$categories = Moqbo_DB::get_categories(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		return rest_ensure_response( array_map( array( __CLASS__, 'prepare_category' ), $categories ) );
	}

	/**
	 * Return one category by slug.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_category( $request ) {
		$category = Moqbo_DB::get_category( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $category ) ) {
			return $category;
		}

		if ( ! $category ) {
			return new WP_Error( 'moqbo_category_not_found', __( 'The requested category was not found.', 'moqbo' ), array( 'status' => 404 ) );
		}

		$count = Moqbo_DB::count_events_for_category( $category['slug'] );

		if ( is_wp_error( $count ) ) {
			return $count;
		}

		$category['event_count'] = $count;

		return rest_ensure_response( self::prepare_category( $category ) );
	}

	/**
	 * Create a category from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_category( $request ) {
		$validated = self::validate_category_request( $request );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now                     = current_time( 'mysql' );
		$validated['created_at'] = $now;
		$validated['updated_at'] = $now;

		$saved = Moqbo_DB::insert_category( $validated );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$validated['event_count'] = 0;
		$response                 = rest_ensure_response( self::prepare_category( $validated ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/categories/' . rawurlencode( $validated['slug'] ) ) );

		return $response;
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
	 * Sanitize a REST text argument.
	 *
	 * @param mixed $value Text value.
	 * @return string
	 */
	public static function sanitize_text_arg( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize a REST textarea argument.
	 *
	 * @param mixed $value Textarea value.
	 * @return string
	 */
	public static function sanitize_textarea_arg( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Sanitize a REST slug argument.
	 *
	 * @param mixed $value Slug value.
	 * @return string
	 */
	public static function sanitize_slug_arg( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_title( (string) $value );
	}

	/**
	 * Sanitize a REST color argument.
	 *
	 * @param mixed $value Color value.
	 * @return string
	 */
	public static function sanitize_color_arg( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$color = sanitize_hex_color( (string) $value );

		return $color ? $color : '';
	}

	/**
	 * Check whether the Moqbo API feature is enabled.
	 *
	 * @return true|WP_Error
	 */
	private static function base_permissions_check() {
		if ( Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_API ) ) {
			return true;
		}

		return new WP_Error(
			'moqbo_api_disabled',
			__( 'The Moqbo API is disabled.', 'moqbo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check whether an endpoint is enabled.
	 *
	 * @param string          $endpoint_setting Endpoint setting key.
	 * @param string          $disabled_code Disabled endpoint error code.
	 * @param string          $disabled_message Disabled endpoint error message.
	 * @return true|WP_Error
	 */
	private static function endpoint_availability_check( $endpoint_setting, $disabled_code, $disabled_message ) {
		$permission = self::base_permissions_check();

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( ! Moqbo_Settings::is_feature_enabled( $endpoint_setting ) ) {
			return new WP_Error(
				$disabled_code,
				$disabled_message,
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check optional bearer token authentication.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	private static function authentication_check( $request ) {
		if ( ! Moqbo_Settings::is_feature_enabled( Moqbo_Settings::API_AUTH_REQUIRED ) ) {
			return true;
		}

		return self::token_authentication_check( $request );
	}

	/**
	 * Require a valid configured bearer token.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	private static function token_authentication_check( $request ) {
		$expected_token = Moqbo_Settings::get_api_token();

		if ( '' === $expected_token ) {
			return new WP_Error(
				'moqbo_api_token_not_configured',
				__( 'A Moqbo API token is required, but no token is configured.', 'moqbo' ),
				array( 'status' => 403 )
			);
		}

		$provided_token = self::get_authorization_token( $request );

		if ( '' === $provided_token || ! hash_equals( $expected_token, $provided_token ) ) {
			return new WP_Error(
				'moqbo_api_invalid_token',
				__( 'A valid Moqbo API token is required.', 'moqbo' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Get a token from the Authorization header.
	 *
	 * Accepts "Bearer <token>".
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private static function get_authorization_token( $request ) {
		$header = $request->get_header( 'authorization' );
		$header = is_string( $header ) ? trim( $header ) : '';

		if ( preg_match( '/^Bearer[\t ]+([A-Za-z0-9._~+\/=\-]{32,255})$/iD', $header, $matches ) && Moqbo_Settings::is_valid_api_token( $matches[1] ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Get REST args for event listing.
	 *
	 * @return array
	 */
	private static function get_events_args() {
		return array(
			'start_date' => array(
				'description'       => __( 'Start date (YYYY-MM-DD)', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 10,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
			),
			'end_date'   => array(
				'description'       => __( 'End date (YYYY-MM-DD)', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 10,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
			),
		);
	}

	/**
	 * Get REST args for visible calendar events.
	 *
	 * @return array
	 */
	private static function get_calendar_events_args() {
		return array(
			'start_date' => array(
				'description'       => __( 'Visible range start date', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 10,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
			),
			'end_date'   => array(
				'description'       => __( 'Visible range end date', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 10,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
			),
		);
	}

	/**
	 * Get REST args for an item route.
	 *
	 * @return array
	 */
	private static function get_item_args() {
		return array(
			'slug' => array(
				'description'       => __( 'Resource slug', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_SLUG_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_slug_arg' ),
			),
		);
	}

	/**
	 * Get REST args for event creation.
	 *
	 * @return array
	 */
	private static function create_event_args() {
		return array(
			'name'               => array(
				'description'       => __( 'Event name', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_TEXT_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'slug'               => array(
				'description'       => __( 'Event slug', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_SLUG_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_slug_arg' ),
			),
			'location'           => array(
				'description'       => __( 'Event location', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_TEXT_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'category_slug'      => array(
				'description'       => __( 'Existing event category slug', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_SLUG_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_slug_arg' ),
			),
			'description'        => array(
				'description'       => __( 'Event description', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_DESCRIPTION_BYTES,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_textarea_arg' ),
			),
			'start_at'           => array(
				'description'       => __( 'Start date (YYYY-MM-DD HH:MM:SS)', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 19,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'end_at'             => array(
				'description'       => __( 'End date (YYYY-MM-DD HH:MM:SS)', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 19,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'all_day'           => array(
				'description'       => __( 'Whether the event is all-day', 'moqbo' ),
				'type'              => 'boolean',
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get REST args for category creation.
	 *
	 * @return array
	 */
	private static function create_category_args() {
		return array(
			'name'  => array(
				'description'       => __( 'Category name', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_TEXT_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'slug'  => array(
				'description'       => __( 'Category slug', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => Moqbo_DB::MAX_SLUG_LENGTH,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_slug_arg' ),
			),
			'color' => array(
				'description'       => __( 'Category color (Hex)', 'moqbo' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 7,
				'maxLength'         => 7,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'sanitize_color_arg' ),
			),
		);
	}

	/**
	 * Validate REST event creation data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array|WP_Error
	 */
	private static function validate_event_request( $request ) {
		$errors      = new WP_Error();
		$name        = self::sanitize_text_arg( $request->get_param( 'name' ) );
		$slug        = self::sanitize_slug_arg( $request->get_param( 'slug' ) );
		$location    = self::sanitize_text_arg( $request->get_param( 'location' ) );
		$description = self::sanitize_textarea_arg( $request->get_param( 'description' ) );

		if ( '' === $name ) {
			self::add_validation_error( $errors, 'missing_name', __( 'Event name is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $name, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			self::add_validation_error( $errors, 'name_too_long', __( 'Event name is too long.', 'moqbo' ) );
		}

		if ( '' === $slug ) {
			self::add_validation_error( $errors, 'missing_slug', __( 'Event slug is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			self::add_validation_error( $errors, 'slug_too_long', __( 'Event slug is too long.', 'moqbo' ) );
		}

		if ( '' === $location ) {
			self::add_validation_error( $errors, 'missing_location', __( 'Event location is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $location, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			self::add_validation_error( $errors, 'location_too_long', __( 'Event location is too long.', 'moqbo' ) );
		}

		if ( '' === $description ) {
			self::add_validation_error( $errors, 'missing_description', __( 'Event description is required.', 'moqbo' ) );
		}

		if ( strlen( $description ) > Moqbo_DB::MAX_DESCRIPTION_BYTES ) {
			self::add_validation_error( $errors, 'description_too_long', __( 'Event description is too long.', 'moqbo' ) );
		}

		$existing_event = '' !== $slug ? Moqbo_DB::get_event( $slug ) : null;

		if ( is_wp_error( $existing_event ) ) {
			return $existing_event;
		}

		if ( $existing_event ) {
			self::add_validation_error( $errors, 'duplicate_slug', __( 'An event with this slug already exists.', 'moqbo' ) );
		}

		$category_slug = self::sanitize_slug_arg( $request->get_param( 'category_slug' ) );

		if ( self::exceeds_character_limit( $category_slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			self::add_validation_error( $errors, 'category_slug_too_long', __( 'Event category slug is too long.', 'moqbo' ) );
		}

		$category = '' !== $category_slug ? Moqbo_DB::get_category( $category_slug ) : null;

		if ( is_wp_error( $category ) ) {
			return $category;
		}

		if ( ! $category ) {
			self::add_validation_error( $errors, 'missing_category', __( 'Choose an existing event category.', 'moqbo' ) );
		}

		$all_day       = (bool) $request->get_param( 'all_day' );
		$datetime_range = self::validate_event_datetime_range( $request->get_param( 'start_at' ), $request->get_param( 'end_at' ), $all_day );

		if ( is_wp_error( $datetime_range ) ) {
			$errors->merge_from( $datetime_range );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'slug'          => $slug,
			'name'          => $name,
			'location'      => $location,
			'start_at'      => $datetime_range['start']->format( 'Y-m-d H:i:s' ),
			'end_at'        => $datetime_range['end']->format( 'Y-m-d H:i:s' ),
			'all_day'       => $all_day ? 1 : 0,
			'description'   => $description,
			'category_slug' => $category_slug,
		);
	}

	/**
	 * Validate REST category creation data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array|WP_Error
	 */
	private static function validate_category_request( $request ) {
		$errors = new WP_Error();
		$name   = self::sanitize_text_arg( $request->get_param( 'name' ) );
		$slug   = self::sanitize_slug_arg( $request->get_param( 'slug' ) );
		$color  = self::sanitize_color_arg( $request->get_param( 'color' ) );

		if ( '' === $name ) {
			self::add_validation_error( $errors, 'missing_name', __( 'Category name is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $name, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			self::add_validation_error( $errors, 'name_too_long', __( 'Category name is too long.', 'moqbo' ) );
		}

		if ( '' === $slug ) {
			self::add_validation_error( $errors, 'missing_slug', __( 'Category slug is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			self::add_validation_error( $errors, 'slug_too_long', __( 'Category slug is too long.', 'moqbo' ) );
		}

		$existing_category = '' !== $slug ? Moqbo_DB::get_category( $slug ) : null;

		if ( is_wp_error( $existing_category ) ) {
			return $existing_category;
		}

		if ( $existing_category ) {
			self::add_validation_error( $errors, 'duplicate_slug', __( 'A category with this slug already exists.', 'moqbo' ) );
		}

		if ( ! $color || ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
			self::add_validation_error( $errors, 'invalid_color', __( 'Choose a valid hex color.', 'moqbo' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'slug'  => $slug,
			'name'  => $name,
			'color' => $color,
		);
	}

	/**
	 * Add a REST validation error.
	 *
	 * @param WP_Error $errors Error collection.
	 * @param string   $code Error code.
	 * @param string   $message Error message.
	 */
	private static function add_validation_error( $errors, $code, $message ) {
		$errors->add( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * Check a UTF-8 string against a character limit.
	 *
	 * @param string $value String value.
	 * @param int    $maximum Maximum characters.
	 * @return bool
	 */
	private static function exceeds_character_limit( $value, $maximum ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );

		return $length > $maximum;
	}

	/**
	 * Prepare an event for the public API response.
	 *
	 * @param array $event Event row.
	 * @return array
	 */
	private static function prepare_event( $event ) {
		return array(
			'name'          => $event['name'],
			'slug'          => $event['slug'],
			'location'      => wp_strip_all_tags( isset( $event['location'] ) ? (string) $event['location'] : '' ),
			'category_slug' => sanitize_title( isset( $event['category_slug'] ) ? (string) $event['category_slug'] : '' ),
			'description'   => wp_strip_all_tags( isset( $event['description'] ) ? (string) $event['description'] : '' ),
			'start_at'      => $event['start_at'],
			'end_at'        => $event['end_at'],
			'all_day'       => ! empty( $event['all_day'] ),
		);
	}

	/**
	 * Prepare a category for the public API response.
	 *
	 * @param array $category Category row.
	 * @return array
	 */
	private static function prepare_category( $category ) {
		$stored_color = isset( $category['color'] ) && is_string( $category['color'] ) ? $category['color'] : '';
		$color        = preg_match( '/\A#[0-9A-Fa-f]{6}\z/', $stored_color ) ? strtolower( $stored_color ) : '#2271b1';

		return array(
			'name'        => wp_strip_all_tags( isset( $category['name'] ) ? (string) $category['name'] : '' ),
			'slug'        => sanitize_title( isset( $category['slug'] ) ? (string) $category['slug'] : '' ),
			'color'       => $color,
			'event_count' => isset( $category['event_count'] ) ? (int) $category['event_count'] : 0,
		);
	}

	/**
	 * Validate the event datetime range.
	 *
	 * @param mixed $start_value Start datetime value.
	 * @param mixed $end_value End datetime value.
	 * @param bool  $all_day Whether the event is all-day.
	 * @return array|WP_Error
	 */
	private static function validate_event_datetime_range( $start_value, $end_value, $all_day ) {
		$errors = new WP_Error();
		$start  = self::validate_local_datetime_arg( $start_value, __( 'Start', 'moqbo' ) );
		$end    = self::validate_local_datetime_arg( $end_value, __( 'End', 'moqbo' ) );

		if ( is_wp_error( $start ) ) {
			$errors->merge_from( $start );
		}

		if ( is_wp_error( $end ) ) {
			$errors->merge_from( $end );
		}

		if ( ! is_wp_error( $start ) && ! is_wp_error( $end ) ) {
			if ( $all_day && ( '00:00:00' !== $start->format( 'H:i:s' ) || '00:00:00' !== $end->format( 'H:i:s' ) ) ) {
				self::add_validation_error( $errors, 'invalid_all_day_time', __( 'All-day events must start and end at midnight.', 'moqbo' ) );
			}

			if ( $all_day && $end < $start ) {
				self::add_validation_error( $errors, 'end_before_start', __( 'End date cannot be earlier than the start date.', 'moqbo' ) );
			}

			if ( ! $all_day && $end <= $start ) {
				self::add_validation_error( $errors, 'end_before_start', __( 'Timed events must end after they start.', 'moqbo' ) );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Validate a local datetime argument using the WordPress timezone.
	 *
	 * @param mixed  $value Datetime value.
	 * @param string $label Field label.
	 * @return DateTimeImmutable|WP_Error
	 */
	private static function validate_local_datetime_arg( $value, $label ) {
		$errors = new WP_Error();
		$value  = self::sanitize_text_arg( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value ) ) {
			self::add_validation_error(
				$errors,
				'invalid_datetime_format',
				sprintf(
					/* translators: %s: field label. */
					__( '%s date/time must use YYYY-MM-DD HH:MM:SS format.', 'moqbo' ),
					$label
				)
			);
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$dt     = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();

		if ( ! $dt || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $dt->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new WP_Error(
				'invalid_datetime',
				sprintf(
					/* translators: %s: field label. */
					__( '%s date/time is not valid in the sites timezone.', 'moqbo' ),
					$label
				),
				array( 'status' => 400 )
			);
		}

		return $dt;
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
