<?php
/**
 * Server-only configuration. The plugin's only own setting is its on/off switch, an ACF options page
 * (Settings → Payment Collection, per site and per WPML language; see Settings). Everything else:
 *
 *   API URL, API secret       the quiz-funnel-core plugin's settings (Settings → CellonNexus):
 *                             cellonnexus_env, cellonnexus_api_url_<env>, cellonnexus_api_secret
 *   x-host, ip_address, UA    the Core plugin's Request_Helper, so payment calls send exactly what
 *                             its quiz-result calls send
 *   redirect pages            the Core plugin's page map (quiz_funnel_redirect_page_ids), read through
 *                             cellonnexus()->quiz->get_redirect_url() in Checkout_Context
 *   languages                 the template, through shortcode attributes (see Frontend\Shortcode)
 *
 * The few switches that are not Core settings are code-level only (wp-config.php constant or filter):
 *
 *   CELLON_PAYMENT_API_ADAPTER   current | common                     default current
 *   CELLON_PAYMENT_DEBUG         true writes payment steps to the PHP error log
 *   CELLON_PAYMENT_FLOW_LOG      'paypal' (comma list, or true) writes every API call of that provider's
 *                                payments, up to the cross sale, with full bodies to one text file per quiz
 *                                wp-content/cellon-payment-logs/<provider>-flow-quiz-<id>.txt (Flow_Log)
 *   cellon_payment_collection_enabled_providers   filter, default every provider the plugin has
 *   cellon_payment_collection_legacy_guards       filter, default none (old flows keep working)
 *
 * Provider public keys (Stripe publishable key, PayPal client id, Solidgate merchant, BridgerPay
 * cashier key) are never configured here: they come from the API response for the quiz result.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {

	/** API adapter that talks to today's per-gateway routes. */
	const API_CURRENT = 'current';

	/** API adapter for the common first-sale API (one initiate / confirm route for every gateway). */
	const API_COMMON = 'common';

	/** A confirm can wait ~60 s on the API (BridgerPay, Vault). The Core plugin's 40 s is too short for it. */
	const API_TIMEOUT = 65;

	/** The Core plugin's fallback when no URL is saved for the selected environment. */
	const CORE_DEFAULT_URL = 'https://api-cellonnexus-stage.project-demo.info/api/v1/';

	/** @var array<string,mixed>|null */
	private static $api = null;

	/** @var string|null x-host of the checkout being served (stored when the page rendered). */
	private static $x_host_override = null;

	/** The quiz-funnel-core plugin is active (cellonnexus() and its Request_Helper exist). */
	public static function core_active(): bool {
		return function_exists( 'cellonnexus' ) && class_exists( '\Quiz_Funnel\Helpers\Request_Helper' );
	}

	/**
	 * REST requests run outside the page's WPML language; they reuse the x-host the checkout page
	 * computed, so the API sees the same site (iqpro.ai/ja) for the whole checkout.
	 */
	public static function use_x_host( string $host ): void {
		self::$x_host_override = $host !== '' ? $host : null;
	}

	public static function api_adapter(): string {
		$adapter = defined( 'CELLON_PAYMENT_API_ADAPTER' ) ? (string) CELLON_PAYMENT_API_ADAPTER : self::API_CURRENT;
		$adapter = (string) apply_filters( 'cellon_payment_collection_api_adapter', $adapter );
		return in_array( $adapter, [ self::API_CURRENT, self::API_COMMON ], true ) ? $adapter : self::API_CURRENT;
	}

	/**
	 * Providers switched on. Default: every registered provider, so a new provider class is on without
	 * editing a list. An API environment without a provider's routes answers 404 at intent and the
	 * sequence skips that provider silently.
	 *
	 * @param string[] $registered Slugs of the registered providers.
	 * @return string[]
	 */
	public static function enabled_providers( array $registered ): array {
		$enabled = apply_filters( 'cellon_payment_collection_enabled_providers', array_values( $registered ) );
		return array_values( array_map( 'sanitize_key', is_array( $enabled ) ? $enabled : [] ) );
	}

	/** @return string[] Old handlers this plugin answers with "retired" once a site is migrated. */
	public static function legacy_guards(): array {
		$guards = apply_filters( 'cellon_payment_collection_legacy_guards', [] );
		return array_values( array_map( 'sanitize_key', is_array( $guards ) ? $guards : [] ) );
	}

	public static function debug(): bool {
		return defined( 'CELLON_PAYMENT_DEBUG' ) && CELLON_PAYMENT_DEBUG;
	}

	/**
	 * API connection, read from the Core plugin's settings the same way its API client reads them.
	 *
	 * @return array{url:string, token:string, timeout:int}
	 */
	public static function api(): array {
		if ( self::$api !== null ) {
			return self::$api;
		}

		$url   = '';
		$token = '';
		if ( self::core_active() ) {
			$env   = (string) get_site_option( 'cellonnexus_env', 'staging' );
			$url   = (string) get_site_option( 'cellonnexus_api_url_' . sanitize_key( $env ), '' );
			$url   = $url !== '' ? $url : self::CORE_DEFAULT_URL;
			$token = (string) get_site_option( 'cellonnexus_api_secret', '' );
		}

		self::$api = [
			'url'     => rtrim( $url, '/' ),
			'token'   => trim( $token ),
			'timeout' => max( 10, (int) apply_filters( 'cellon_payment_collection_api_timeout', self::API_TIMEOUT ) ),
		];
		return self::$api;
	}

	public static function is_configured(): bool {
		$api = self::api();
		return $api['url'] !== '' && $api['token'] !== '';
	}

	/** The Core plugin's x-host (staging → production host, WPML "/<lang>"), or the one stored with the checkout. */
	public static function x_host(): string {
		if ( self::$x_host_override !== null ) {
			return self::$x_host_override;
		}
		return self::core_active() ? (string) \Quiz_Funnel\Helpers\Request_Helper::get_x_host() : '';
	}

	/** Customer IP as the Core plugin sends it (`ip_address`, used by the API for pricing and GeoIP). */
	public static function client_ip(): string {
		return self::core_active() ? (string) \Quiz_Funnel\Helpers\Request_Helper::get_ip() : '';
	}

	public static function user_agent(): string {
		return self::core_active() ? (string) \Quiz_Funnel\Helpers\Request_Helper::get_user_agent() : '';
	}
}
