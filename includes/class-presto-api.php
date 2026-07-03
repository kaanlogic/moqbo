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
					'permission_callback' => array( __CLASS__, 'get_events_permissions_check' ),
					'args'                => self::get_events_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_event' ),
					'permission_callback' => array( __CLASS__, 'create_event_permissions_check' ),
					'args'                => self::create_event_args(),
				),
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
					'args'                => self::get_categories_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_category' ),
					'permission_callback' => array( __CLASS__, 'create_category_permissions_check' ),
					'args'                => self::create_category_args(),
				),
			)
		);
	}

	/**
	 * Check whether the events GET endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function get_events_permissions_check( $request ) {
		return self::endpoint_permissions_check(
			$request,
			Presto_Settings::API_GET_EVENTS_ENABLED,
			'presto_api_get_events_disabled',
			__( 'The Presto events GET endpoint is disabled.', 'presto' )
		);
	}

	/**
	 * Check whether the events POST endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function create_event_permissions_check( $request ) {
		return self::endpoint_permissions_check(
			$request,
			Presto_Settings::API_POST_EVENTS_ENABLED,
			'presto_api_post_events_disabled',
			__( 'The Presto events POST endpoint is disabled.', 'presto' )
		);
	}

	/**
	 * Check whether the categories GET endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function get_categories_permissions_check( $request ) {
		return self::endpoint_permissions_check(
			$request,
			Presto_Settings::API_GET_CATEGORIES_ENABLED,
			'presto_api_get_categories_disabled',
			__( 'The Presto categories GET endpoint is disabled.', 'presto' )
		);
	}

	/**
	 * Check whether the categories POST endpoint is available.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function create_category_permissions_check( $request ) {
		return self::endpoint_permissions_check(
			$request,
			Presto_Settings::API_POST_CATEGORIES_ENABLED,
			'presto_api_post_categories_disabled',
			__( 'The Presto categories POST endpoint is disabled.', 'presto' )
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

		if ( ! Presto_DB::insert_event( $validated ) ) {
			return new WP_Error(
				'presto_save_event_failed',
				__( 'The event could not be saved.', 'presto' ),
				array( 'status' => 500 )
			);
		}

		$event    = Presto_DB::get_event( $validated['slug'] );
		$response = rest_ensure_response( self::prepare_event( $event ? $event : $validated ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Return event categories.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_categories( $request ) {
		$categories = Presto_DB::get_categories(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);

		return rest_ensure_response( array_map( array( __CLASS__, 'prepare_category' ), $categories ) );
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

		if ( ! Presto_DB::insert_category( $validated ) ) {
			return new WP_Error(
				'presto_save_category_failed',
				__( 'The category could not be saved.', 'presto' ),
				array( 'status' => 500 )
			);
		}

		$category = Presto_DB::get_category( $validated['slug'] );
		$response = rest_ensure_response( self::prepare_category( $category ? $category : $validated ) );
		$response->set_status( 201 );

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
	 * Check whether the Presto API feature is enabled.
	 *
	 * @return true|WP_Error
	 */
	private static function base_permissions_check() {
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
	 * Check whether an endpoint is enabled and authenticated.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $endpoint_setting Endpoint setting key.
	 * @param string          $disabled_code Disabled endpoint error code.
	 * @param string          $disabled_message Disabled endpoint error message.
	 * @return true|WP_Error
	 */
	private static function endpoint_permissions_check( $request, $endpoint_setting, $disabled_code, $disabled_message ) {
		$permission = self::base_permissions_check();

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( ! Presto_Settings::is_feature_enabled( $endpoint_setting ) ) {
			return new WP_Error(
				$disabled_code,
				$disabled_message,
				array( 'status' => 403 )
			);
		}

		return self::authentication_check( $request );
	}

	/**
	 * Check optional bearer token authentication.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	private static function authentication_check( $request ) {
		if ( ! Presto_Settings::is_feature_enabled( Presto_Settings::API_AUTH_REQUIRED ) ) {
			return true;
		}

		$expected_token = Presto_Settings::get_api_token();

		if ( '' === $expected_token ) {
			return new WP_Error(
				'presto_api_token_not_configured',
				__( 'Presto API authentication is enabled, but no token is configured.', 'presto' ),
				array( 'status' => 403 )
			);
		}

		$provided_token = self::get_authorization_token( $request );

		if ( '' === $provided_token || ! hash_equals( $expected_token, $provided_token ) ) {
			return new WP_Error(
				'presto_api_invalid_token',
				__( 'A valid Presto API token is required.', 'presto' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Get a token from the Authorization header.
	 *
	 * Accepts either "Bearer <token>" or "Token <token>".
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private static function get_authorization_token( $request ) {
		$header = sanitize_text_field( (string) $request->get_header( 'authorization' ) );

		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		$header = trim( (string) $header );

		if ( preg_match( '/^(?:Bearer|Token)\s+(.+)$/i', $header, $matches ) ) {
			return trim( $matches[1] );
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
				'description'       => __( 'Start date (YYYY-MM-DD)', 'presto' ),
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
			),
			'end_date'   => array(
				'description'       => __( 'End date (YYYY-MM-DD)', 'presto' ),
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_date_arg' ),
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
				'description'       => __( 'Event name', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'slug'               => array(
				'description'       => __( 'Event slug', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			),
			'location'           => array(
				'description'       => __( 'Event location', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'category_slug'      => array(
				'description'       => __( 'Existing event category slug', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			),
			'description'        => array(
				'description'       => __( 'Event description', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_textarea_arg' ),
			),
			'start_at'           => array(
				'description'       => __( 'Start date (YYYY-MM-DD HH:MM:SS)', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'end_at'             => array(
				'description'       => __( 'End date (YYYY-MM-DD HH:MM:SS)', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
		);
	}

	/**
	 * Get REST args for category listing.
	 *
	 * @return array
	 */
	private static function get_categories_args() {
		return array();
	}

	/**
	 * Get REST args for category creation.
	 *
	 * @return array
	 */
	private static function create_category_args() {
		return array(
			'name'  => array(
				'description'       => __( 'Category name', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_arg' ),
			),
			'slug'  => array(
				'description'       => __( 'Category slug', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			),
			'color' => array(
				'description'       => __( 'Category color (Hex)', 'presto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_hex_color',
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
		$errors   = new WP_Error();
		$name     = self::sanitize_text_arg( $request->get_param( 'name' ) );
		$slug     = sanitize_title( (string) $request->get_param( 'slug' ) );
		$location = self::sanitize_text_arg( $request->get_param( 'location' ) );

		if ( '' === $name ) {
			self::add_validation_error( $errors, 'missing_name', __( 'Event name is required.', 'presto' ) );
		}

		if ( '' === $slug ) {
			self::add_validation_error( $errors, 'missing_slug', __( 'Event slug is required.', 'presto' ) );
		}

		if ( '' !== $slug && Presto_DB::get_event( $slug ) ) {
			self::add_validation_error( $errors, 'duplicate_slug', __( 'An event with this slug already exists.', 'presto' ) );
		}

		$category_slug = sanitize_title( (string) $request->get_param( 'category_slug' ) );

		if ( '' === $category_slug || ! Presto_DB::get_category( $category_slug ) ) {
			self::add_validation_error( $errors, 'missing_category', __( 'Choose an existing event category.', 'presto' ) );
		}

		$datetime_range = self::validate_event_datetime_range( $request->get_param( 'start_at' ), $request->get_param( 'end_at' ) );

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
			'all_day'       => $datetime_range['all_day'] ? 1 : 0,
			'description'   => self::sanitize_textarea_arg( $request->get_param( 'description' ) ),
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
		$slug   = sanitize_title( (string) $request->get_param( 'slug' ) );
		$color  = sanitize_hex_color( (string) $request->get_param( 'color' ) );

		if ( '' === $name ) {
			self::add_validation_error( $errors, 'missing_name', __( 'Category name is required.', 'presto' ) );
		}

		if ( '' === $slug ) {
			self::add_validation_error( $errors, 'missing_slug', __( 'Category slug is required.', 'presto' ) );
		}

		if ( '' !== $slug && Presto_DB::get_category( $slug ) ) {
			self::add_validation_error( $errors, 'duplicate_slug', __( 'A category with this slug already exists.', 'presto' ) );
		}

		if ( ! $color || ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
			self::add_validation_error( $errors, 'invalid_color', __( 'Choose a valid hex color.', 'presto' ) );
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
		);
	}

	/**
	 * Prepare a category for the public API response.
	 *
	 * @param array $category Category row.
	 * @return array
	 */
	private static function prepare_category( $category ) {
		$color = sanitize_hex_color( isset( $category['color'] ) ? (string) $category['color'] : '' );

		return array(
			'name'        => wp_strip_all_tags( isset( $category['name'] ) ? (string) $category['name'] : '' ),
			'slug'        => sanitize_title( isset( $category['slug'] ) ? (string) $category['slug'] : '' ),
			'color'       => $color ? strtolower( $color ) : '',
			'event_count' => isset( $category['event_count'] ) ? (int) $category['event_count'] : 0,
		);
	}

	/**
	 * Validate the event datetime range.
	 *
	 * @param mixed $start_value Start datetime value.
	 * @param mixed $end_value End datetime value.
	 * @return array|WP_Error
	 */
	private static function validate_event_datetime_range( $start_value, $end_value ) {
		$errors  = new WP_Error();
		$start   = self::validate_local_datetime_arg( $start_value, __( 'Start', 'presto' ) );
		$end     = self::validate_local_datetime_arg( $end_value, __( 'End', 'presto' ) );
		$all_day = false;

		if ( is_wp_error( $start ) ) {
			$errors->merge_from( $start );
		}

		if ( is_wp_error( $end ) ) {
			$errors->merge_from( $end );
		}

		if ( ! is_wp_error( $start ) && ! is_wp_error( $end ) ) {
			$all_day = self::is_all_day_range( $start, $end );

			if ( $all_day && $end < $start ) {
				self::add_validation_error( $errors, 'end_before_start', __( 'End date cannot be earlier than the start date.', 'presto' ) );
			}

			if ( ! $all_day && $end <= $start ) {
				self::add_validation_error( $errors, 'end_before_start', __( 'Timed events must end after they start.', 'presto' ) );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'start'   => $start,
			'end'     => $end,
			'all_day' => $all_day,
		);
	}

	/**
	 * Check whether a datetime range represents an all-day event.
	 *
	 * @param DateTimeImmutable $start Start datetime.
	 * @param DateTimeImmutable $end End datetime.
	 * @return bool
	 */
	private static function is_all_day_range( $start, $end ) {
		return '00:00:00' === $start->format( 'H:i:s' ) && '00:00:00' === $end->format( 'H:i:s' );
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
					__( '%s date/time must use YYYY-MM-DD HH:MM:SS format.', 'presto' ),
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
					__( '%s date/time is not valid in the sites timezone.', 'presto' ),
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
