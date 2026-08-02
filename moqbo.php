<?php
/**
 * Plugin Name: Moqbo – Lightweight Calendar
 * Description: Moqbo is a lightweight calendar plugin with no frills or unnecessary bloat.
 * Version: 1.0.0
 * Author: kaanlogic
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: moqbo
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOQBO_VERSION', '1.0.0' );
define( 'MOQBO_FILE', __FILE__ );
define( 'MOQBO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOQBO_URL', plugin_dir_url( __FILE__ ) );

require_once MOQBO_DIR . 'includes/class-moqbo-db.php';
require_once MOQBO_DIR . 'includes/class-moqbo-settings.php';
require_once MOQBO_DIR . 'includes/class-moqbo-admin.php';
require_once MOQBO_DIR . 'includes/class-moqbo-shortcode.php';
require_once MOQBO_DIR . 'includes/class-moqbo-api.php';

/**
 * Plugin bootstrap.
 */
final class Moqbo {
	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( 'Moqbo_Shortcode', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( 'Moqbo_Shortcode', 'maybe_enqueue_assets' ) );
		Moqbo_API::init();

		if ( is_admin() ) {
			Moqbo_Admin::init();
		}
	}
}

/**
 * Activation callback.
 *
 * @param bool $network_wide Whether the plugin is network activated.
 */
function moqbo_activate( $network_wide ) {
	Moqbo_DB::activate( $network_wide );
}

register_activation_hook( __FILE__, 'moqbo_activate' );

Moqbo::init();
