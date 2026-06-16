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
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			self::FEATURE_PRESTO_SHORTCODE         => true,
			self::FEATURE_PRESTO_GETDATE_SHORTCODE => true,
			self::FEATURE_API                      => true,
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

		foreach ( self::defaults() as $key => $default ) {
			$settings[ $key ] = ! empty( $settings[ $key ] );
		}

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

		foreach ( self::defaults() as $key => $default ) {
			$sanitized[ $key ] = ! empty( $value[ $key ] );
		}

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
}
