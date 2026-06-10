<?php
/**
 * Plainday uninstall cleanup.
 *
 * @package Plainday
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-plainday-db.php';

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		Plainday_DB::drop_schema();
		restore_current_blog();
	}
} else {
	Plainday_DB::drop_schema();
}
