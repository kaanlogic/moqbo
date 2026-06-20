<?php
/**
 * Plugin settings accessors.
 *
 * @package Presto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presto settings storage and feature flags.
 */
class Presto_Settings {
	/**
	 * Option name used to store Presto settings.
	 */
	const OPTION_NAME = 'presto_settings';

	/**
	 * Feature flag for the [presto] shortcode.
	 */
	const FEATURE_PRESTO_SHORTCODE = 'presto_shortcode';

	/**
	 * Feature flag for the [presto-getdate] shortcode.
	 */
	const FEATURE_PRESTO_GETDATE_SHORTCODE = 'presto_getdate_shortcode';

	/**
	 * Feature flag for the Presto API.
	 */
	const FEATURE_API = 'presto_api';

	/**
	 * API setting for requiring token authentication.
	 */
	const API_AUTH_REQUIRED = 'api_auth_required';

	/**
	 * API bearer token setting.
	 */
	const API_TOKEN = 'api_token';

	/**
	 * API setting for enabling the events GET endpoint.
	 */
	const API_GET_EVENTS_ENABLED = 'api_get_events_enabled';

	/**
	 * API setting for enabling the events POST endpoint.
	 */
	const API_POST_EVENTS_ENABLED = 'api_post_events_enabled';

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			self::FEATURE_PRESTO_SHORTCODE         => true,
			self::FEATURE_PRESTO_GETDATE_SHORTCODE => true,
			self::FEATURE_API                      => true,
			self::API_AUTH_REQUIRED                => false,
			self::API_TOKEN                        => '',
			self::API_GET_EVENTS_ENABLED           => true,
			self::API_POST_EVENTS_ENABLED          => false,
		);
	}

	/**
	 * Get boolean setting keys.
	 *
	 * @return array
	 */
	private static function boolean_keys() {
		return array(
			self::FEATURE_PRESTO_SHORTCODE,
			self::FEATURE_PRESTO_GETDATE_SHORTCODE,
			self::FEATURE_API,
			self::API_AUTH_REQUIRED,
			self::API_GET_EVENTS_ENABLED,
			self::API_POST_EVENTS_ENABLED,
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

		$settings[ self::API_TOKEN ] = is_scalar( $settings[ self::API_TOKEN ] ) ? sanitize_text_field( (string) $settings[ self::API_TOKEN ] ) : '';

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

		$sanitized[ self::API_TOKEN ] = isset( $value[ self::API_TOKEN ] ) && is_scalar( $value[ self::API_TOKEN ] ) ? sanitize_text_field( (string) $value[ self::API_TOKEN ] ) : '';

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
}
