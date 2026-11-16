<?php
/**
 * The plugin stores no settings (they are the quiz-funnel-core plugin's). Checkout sessions are transients
 * and expire on their own (2 hours). This removes leftover checkout locks and a settings row an early build saved.
 *
 * @package Cellon\PaymentCollection
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cellon_payment_collection_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cpc\\_lock\\_%'" );
