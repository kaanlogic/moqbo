<?php
/**
 * Plugin settings accessors.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moqbo settings storage and feature flags.
 */
class Moqbo_Settings {
	/**
	 * Option name used to store Moqbo settings.
	 */
	const OPTION_NAME = 'moqbo_settings';

	/**
	 * Feature flag for the [moqbo] shortcode.
	 */
	const FEATURE_MOQBO_SHORTCODE = 'moqbo_shortcode';

	/**
	 * Feature flag for the [moqbo-getdate] shortcode.
	 */
	const FEATURE_MOQBO_GETDATE_SHORTCODE = 'moqbo_getdate_shortcode';

	/**
	 * Feature flag for the Moqbo API.
	 */
	const FEATURE_API = 'moqbo_api';

	/**
	 * API setting for requiring token authentication on GET endpoints.
	 */
	const API_AUTH_REQUIRED = 'api_auth_required';

	/**
	 * API bearer token setting.
	 */
	const API_TOKEN = 'api_token';

	/**
	 * Minimum API token length.
	 */
	const API_TOKEN_MIN_LENGTH = 32;

	/**
	 * Maximum API token length.
	 */
	const API_TOKEN_MAX_LENGTH = 255;

	/**
	 * API setting for enabling the events GET endpoint.
	 */
	const API_GET_EVENTS_ENABLED = 'api_get_events_enabled';

	/**
	 * API setting for enabling the events POST endpoint.
	 */
	const API_POST_EVENTS_ENABLED = 'api_post_events_enabled';

	/**
	 * API setting for enabling the categories GET endpoint.
	 */
	const API_GET_CATEGORIES_ENABLED = 'api_get_categories_enabled';

	/**
	 * API setting for enabling the categories POST endpoint.
	 */
	const API_POST_CATEGORIES_ENABLED = 'api_post_categories_enabled';

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			self::FEATURE_MOQBO_SHORTCODE         => true,
			self::FEATURE_MOQBO_GETDATE_SHORTCODE => true,
			self::FEATURE_API                      => true,
			self::API_AUTH_REQUIRED                => false,
			self::API_TOKEN                        => '',
			self::API_GET_EVENTS_ENABLED           => true,
			self::API_POST_EVENTS_ENABLED          => false,
			self::API_GET_CATEGORIES_ENABLED       => true,
			self::API_POST_CATEGORIES_ENABLED      => false,
		);
	}

	/**
	 * Get boolean setting keys.
	 *
	 * @return array
	 */
	private static function boolean_keys() {
		return array(
			self::FEATURE_MOQBO_SHORTCODE,
			self::FEATURE_MOQBO_GETDATE_SHORTCODE,
			self::FEATURE_API,
			self::API_AUTH_REQUIRED,
			self::API_GET_EVENTS_ENABLED,
			self::API_POST_EVENTS_ENABLED,
			self::API_GET_CATEGORIES_ENABLED,
			self::API_POST_CATEGORIES_ENABLED,
		);
	}

	/**
	 * Get stored settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_NAME, null );

		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		foreach ( self::boolean_keys() as $key ) {
			$settings[ $key ] = ! empty( $settings[ $key ] );
		}

		$token                       = isset( $settings[ self::API_TOKEN ] ) && is_string( $settings[ self::API_TOKEN ] ) ? $settings[ self::API_TOKEN ] : '';
		$settings[ self::API_TOKEN ] = self::is_valid_api_token( $token ) ? $token : '';

		return $settings;
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param mixed $value Raw submitted option value.
	 * @return array
	 */
	public static function sanitize( $value ) {
		$value     = is_array( $value ) ? $value : array();
		$sanitized = array();

		foreach ( self::boolean_keys() as $key ) {
			$sanitized[ $key ] = ! empty( $value[ $key ] );
		}

		$token = isset( $value[ self::API_TOKEN ] ) && is_string( $value[ self::API_TOKEN ] ) ? wp_unslash( $value[ self::API_TOKEN ] ) : '';

		if ( '' !== $token && ! self::is_valid_api_token( $token ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'moqbo_invalid_api_token',
				sprintf(
					/* translators: 1: minimum token length, 2: maximum token length. */
					__( 'The API token must contain between %1$d and %2$d characters using only letters, numbers, dots, underscores, tildes, plus signs, slashes, equals signs, and hyphens.', 'moqbo' ),
					self::API_TOKEN_MIN_LENGTH,
					self::API_TOKEN_MAX_LENGTH
				)
			);

			return self::get();
		}

		$sanitized[ self::API_TOKEN ] = $token;

		return $sanitized;
	}

	/**
	 * Check whether a feature is enabled.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function is_feature_enabled( $feature ) {
		$settings = self::get();

		return ! empty( $settings[ $feature ] );
	}

	/**
	 * Get the configured API bearer token.
	 *
	 * @return string
	 */
	public static function get_api_token() {
		$settings = self::get();

		return isset( $settings[ self::API_TOKEN ] ) ? (string) $settings[ self::API_TOKEN ] : '';
	}

	/**
	 * Validate an API token without normalizing its credential value.
	 *
	 * @param mixed $token Token value.
	 * @return bool
	 */
	public static function is_valid_api_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/\A[A-Za-z0-9._~+\/=\-]{32,255}\z/D', $token );
	}
}
