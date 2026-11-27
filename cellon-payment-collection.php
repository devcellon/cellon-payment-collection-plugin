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
	'includes/class-settings.php',
	'includes/class-logger.php',
	'includes/class-flow-log.php',
	'includes/api/class-api-response.php',
	'includes/api/class-http-transport.php',
	'includes/api/interface-payment-api.php',
	'includes/api/class-current-payment-api.php',
	'includes/api/class-common-payment-api.php',
	'includes/api/class-customer-api.php',
	'includes/providers/interface-provider.php',
	'includes/providers/interface-one-call-provider.php',
	'includes/providers/interface-three-ds-provider.php',
	'includes/providers/abstract-provider.php',
	'includes/providers/class-stripe-provider.php',
	'includes/providers/class-solidgate-provider.php',
	'includes/providers/class-paypal-provider.php',
	'includes/providers/class-bridgerpay-provider.php',
	'includes/providers/class-evervault-provider.php',
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

// The ACF options page (Settings → Payment Collection) registers on acf/init, so hook it now.
\Cellon\PaymentCollection\Settings::register();

/**
 * The plugin instance (shortcode, REST routes, render()). Installed is not switched on: themes decide
 * between their old payment markup and the plugin with cellon_payment_collection_enabled().
 */
function cellon_payment_collection(): \Cellon\PaymentCollection\Plugin {
	return \Cellon\PaymentCollection\Plugin::instance();
}

/**
 * True when the plugin checkout should render on this page: the plugin is active (this function exists)
 * and "Use the plugin checkout" is On in Settings → Payment Collection (ACF) for this site and the
 * current WPML language. Call it after `init`, e.g. in a page template.
 *
 * @param string $language WPML language code; '' = the current language.
 */
function cellon_payment_collection_enabled( string $language = '' ): bool {
	return \Cellon\PaymentCollection\Settings::enabled( $language );
}

/**
 * True when the plugin checkout is on for every language of this site, i.e. no page of the site still
 * runs the theme's old payment code. For code shared by all languages, such as old REST routes.
 */
function cellon_payment_collection_enabled_everywhere(): bool {
	return \Cellon\PaymentCollection\Settings::enabled_everywhere();
}

// After the theme's functions.php, so theme filters for providers and the API adapter apply.
// Shortcodes (init), REST routes (rest_api_init) and admin-ajax guards all register later than this.
add_action( 'after_setup_theme', 'cellon_payment_collection', 20 );
