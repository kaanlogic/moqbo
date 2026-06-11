<?php
/**
 * Plugin Name: Plainday
 * Description: A very simple event calendar
 * Version: 0.1.0
 * Author: kaanlogic
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: plainday
 *
 * @package Plainday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLAINDAY_VERSION', '0.1.0' );
define( 'PLAINDAY_DB_VERSION', '1' );
define( 'PLAINDAY_FILE', __FILE__ );
define( 'PLAINDAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLAINDAY_URL', plugin_dir_url( __FILE__ ) );

require_once PLAINDAY_DIR . 'includes/class-plainday-db.php';
require_once PLAINDAY_DIR . 'includes/class-plainday-admin.php';
require_once PLAINDAY_DIR . 'includes/class-plainday-shortcode.php';

/**
 * Plugin bootstrap.
 */
final class Plainday {
	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( 'Plainday_DB', 'maybe_upgrade' ) );
		add_action( 'init', array( 'Plainday_Shortcode', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( 'Plainday_Shortcode', 'maybe_enqueue_assets' ) );

		if ( is_admin() ) {
			Plainday_Admin::init();
		}
	}
}

/**
 * Activation callback.
 *
 * @param bool $network_wide Whether the plugin is network activated.
 */
function plainday_activate( $network_wide ) {
	Plainday_DB::activate( $network_wide );
}

register_activation_hook( __FILE__, 'plainday_activate' );

Plainday::init();
