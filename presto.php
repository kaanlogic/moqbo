<?php
/**
 * Plugin Name: Presto – Lightweight Calendar
 * Description: Presto is a lightweight calendar plugin with no frills or unnecessary bloat.
 * Version: 1.0.0
 * Author: kaanlogic
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: presto
 *
 * @package Presto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESTO_VERSION', '1.0.0' );
define( 'PRESTO_DB_VERSION', '1' );
define( 'PRESTO_FILE', __FILE__ );
define( 'PRESTO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESTO_URL', plugin_dir_url( __FILE__ ) );

require_once PRESTO_DIR . 'includes/class-presto-db.php';
require_once PRESTO_DIR . 'includes/class-presto-settings.php';
require_once PRESTO_DIR . 'includes/class-presto-admin.php';
require_once PRESTO_DIR . 'includes/class-presto-shortcode.php';
require_once PRESTO_DIR . 'includes/class-presto-api.php';

/**
 * Plugin bootstrap.
 */
final class Presto {
	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( 'Presto_DB', 'maybe_upgrade' ) );
		add_action( 'init', array( 'Presto_Shortcode', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( 'Presto_Shortcode', 'maybe_enqueue_assets' ) );
		Presto_API::init();

		if ( is_admin() ) {
			Presto_Admin::init();
		}
	}
}

/**
 * Activation callback.
 *
 * @param bool $network_wide Whether the plugin is network activated.
 */
function presto_activate( $network_wide ) {
	Presto_DB::activate( $network_wide );
}

register_activation_hook( __FILE__, 'presto_activate' );

Presto::init();
