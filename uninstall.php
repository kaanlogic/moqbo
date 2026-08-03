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
	$moqbo_offset = 0;

	do {
		$moqbo_site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => Moqbo_DB::NETWORK_BATCH_SIZE,
				'offset'  => $moqbo_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		foreach ( $moqbo_site_ids as $moqbo_site_id ) {
			switch_to_blog( (int) $moqbo_site_id );

			try {
				Moqbo_DB::drop_schema();
				delete_option( Moqbo_Settings::OPTION_NAME );
			} finally {
				restore_current_blog();
			}
		}

		$moqbo_offset += Moqbo_DB::NETWORK_BATCH_SIZE;
	} while ( count( $moqbo_site_ids ) === Moqbo_DB::NETWORK_BATCH_SIZE );
} else {
	Moqbo_DB::drop_schema();
	delete_option( Moqbo_Settings::OPTION_NAME );
}
