<?php
/**
 * Presto uninstall cleanup.
 *
 * @package Presto
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-presto-db.php';
require_once __DIR__ . '/includes/class-presto-settings.php';

if ( is_multisite() ) {
	$presto_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $presto_site_ids as $presto_site_id ) {
		switch_to_blog( $presto_site_id );
		Presto_DB::drop_schema();
		delete_option( Presto_Settings::OPTION_NAME );
		restore_current_blog();
	}
} else {
	Presto_DB::drop_schema();
	delete_option( Presto_Settings::OPTION_NAME );
}
