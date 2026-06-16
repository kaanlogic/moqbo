<?php
/**
 * Presto API registration stub.
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
	 * Register API hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes when the API feature is enabled.
	 */
	public static function register_routes() {
		if ( ! Presto_Settings::is_feature_enabled( Presto_Settings::FEATURE_API ) ) {
			return;
		}

		// Stub for future Presto event API routes.
	}
}
