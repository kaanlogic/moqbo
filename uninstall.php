<?php
/**
 * Moqbo uninstall cleanup.
 *
 * @package Moqbo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-moqbo-db.php';
require_once __DIR__ . '/includes/class-moqbo-settings.php';

if ( is_multisite() ) {
	$moqbo_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $moqbo_site_ids as $moqbo_site_id ) {
		switch_to_blog( $moqbo_site_id );
		Moqbo_DB::drop_schema();
		delete_option( Moqbo_Settings::OPTION_NAME );
		restore_current_blog();
	}
} else {
	Moqbo_DB::drop_schema();
	delete_option( Moqbo_Settings::OPTION_NAME );
}
