<?php
/**
 * The plugin's only own setting is the on/off switch (ACF options page, values saved as options
 * cellon_payment_collection_<field> on every site). The API settings are the quiz-funnel-core plugin's.
 * Checkout sessions are transients and expire on their own (2 hours). This removes the switch, leftover
 * checkout locks and a settings row an early build saved, on every site of a network.
 *
 * @package Cellon\PaymentCollection
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function cellon_pc_uninstall_site(): void {
	global $wpdb;

	delete_option( 'cellon_payment_collection_settings' );
	foreach ( [ 'enabled', 'languages' ] as $field ) {
		delete_option( 'cellon_payment_collection_' . $field );
		delete_option( '_cellon_payment_collection_' . $field );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cpc\\_lock\\_%'" );
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $cellon_pc_site_id ) {
		switch_to_blog( (int) $cellon_pc_site_id );
		cellon_pc_uninstall_site();
		restore_current_blog();
	}
} else {
	cellon_pc_uninstall_site();
}
