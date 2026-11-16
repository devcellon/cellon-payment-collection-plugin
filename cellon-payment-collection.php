<?php
/**
 * Plugin Name:       Cellon Payment Collection
 * Description:       Shared payment checkout for the Cellon quiz funnels (iqpro, wwiqtest, wwpersonality). One shortcode renders the gateways the Cellon API configures for the quiz result, in priority order with in-place fallback, plus direct providers such as PayPal. Every payment call runs server-side; the browser never sees the API token or a provider secret.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cellon
 * License:           GPL-2.0-or-later
 * Text Domain:       cellon-payment-collection
 *
 * @package Cellon\PaymentCollection
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CELLON_PC_VERSION', '1.1.0' );
define( 'CELLON_PC_FILE', __FILE__ );
define( 'CELLON_PC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CELLON_PC_URL', plugin_dir_url( __FILE__ ) );

$cellon_pc_files = [
	'includes/class-config.php',
	'includes/class-logger.php',
	'includes/api/class-api-response.php',
	'includes/api/class-http-transport.php',
	'includes/api/interface-payment-api.php',
	'includes/api/class-current-payment-api.php',
	'includes/api/class-common-payment-api.php',
	'includes/providers/interface-provider.php',
	'includes/providers/abstract-provider.php',
	'includes/providers/class-stripe-provider.php',
	'includes/providers/class-solidgate-provider.php',
	'includes/providers/class-paypal-provider.php',
	'includes/providers/class-bridgerpay-provider.php',
	'includes/providers/class-provider-registry.php',
	'includes/checkout/class-provider-config.php',
	'includes/checkout/class-checkout-context.php',
	'includes/checkout/class-context-resolver.php',
	'includes/checkout/class-checkout-session.php',
	'includes/checkout/class-session-store.php',
	'includes/checkout/class-payment-orchestrator.php',
	'includes/site/class-email-texts.php',
	'includes/site/class-message-texts.php',
	'includes/site/class-site-profile.php',
	'includes/http/class-rest-controller.php',
	'includes/frontend/class-assets.php',
	'includes/frontend/class-shortcode.php',
	'includes/migration/class-legacy-guard.php',
	'includes/class-plugin.php',
];
foreach ( $cellon_pc_files as $cellon_pc_file ) {
	require_once CELLON_PC_DIR . $cellon_pc_file;
}
unset( $cellon_pc_files, $cellon_pc_file );

/**
 * The plugin instance. Themes can check `function_exists( 'cellon_payment_collection' )`
 * to know the shared checkout is installed before they skip their old payment markup.
 */
function cellon_payment_collection(): \Cellon\PaymentCollection\Plugin {
	return \Cellon\PaymentCollection\Plugin::instance();
}

// After the theme's functions.php, so theme filters for providers and the API adapter apply.
// Shortcodes (init), REST routes (rest_api_init) and admin-ajax guards all register later than this.
add_action( 'after_setup_theme', 'cellon_payment_collection', 20 );
