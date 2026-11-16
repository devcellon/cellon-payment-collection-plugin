<?php
/**
 * Per-site behaviour ported from the themes' payment scripts, so one plugin reproduces each site:
 *
 *   iqpro          js/stripe-new.js, js/solidgate.js, js/paypal-checkout.js
 *   wwiqtest       js/stripe_new.js, js/solidgate.js, js/paypal-subscription-checkout.js
 *   wwpersonality  js/stripe_new.js, js/solidgate.js, js/paypal-subscription-checkout.js
 *
 * What it decides (all server-side, at render):
 *   - whether the page must collect the customer's real email: whenever the quiz result has none
 *     (empty or the dummy address), on every site and in every language
 *   - labels from the page's ACF fields (pay button, processing, success message)
 *   - site-wide analytics (first_sale_fronted, Chargeblast) and the server `ab_testing_main_total`
 *   - the render environment (`env`) every provider gets for its browser options
 *
 * What differs per provider (its look, its event names, its labels) is not here: each provider class
 * declares it (Provider::browser_options(), Provider::site_events()).
 *
 * Languages are not decided here. The template passes them as shortcode attributes, so each site keeps
 * its language rules in its own templates:
 *   locale            Stripe Elements locale ("ja", "zh-HK", "auto")                default auto
 *   direction         ltr | rtl   card form text direction (Stripe and Solidgate)   default ltr
 *   solidgate_locale  Solidgate form locale ("ar-SA"); its language is the part before "-"
 *   payment_methods   Stripe methods next to the card, comma separated ("card,alipay")   default card
 *   email_error       message when the collected email is invalid
 *                     default ACF email_collection_error_msg, else Email_Texts for the language
 *   collect_email     auto | 1 | 0   override the email-collection rule              default auto
 *
 * The site is CELLON_PAYMENT_SITE (iqpro | wwiqtest | wwpersonality) when defined, else detected from
 * the host. Everything is filterable: `cellon_payment_collection_site_profile`.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Site;

use Cellon\PaymentCollection\Checkout\Checkout_Context;
use Cellon\PaymentCollection\Config;
use Cellon\PaymentCollection\Providers\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Profile {

	const DUMMY_EMAIL = '_dummy@cellon.ai';

	public static function site(): string {
		if ( defined( 'CELLON_PAYMENT_SITE' ) && CELLON_PAYMENT_SITE !== '' ) {
			return sanitize_key( (string) CELLON_PAYMENT_SITE );
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( strpos( $host, 'iqpro' ) !== false ) {
			return 'iqpro';
		}
		if ( strpos( $host, 'wwiqtest' ) !== false ) {
			return 'wwiqtest';
		}
		if ( strpos( $host, 'personality' ) !== false ) {
			return 'wwpersonality';
		}
		return 'generic';
	}

	/**
	 * @param array<string,string>   $atts      Shortcode attributes.
	 * @param array<string,Provider> $providers Providers of this checkout, by slug.
	 * @return array<string,mixed>
	 */
	public static function build( Checkout_Context $context, array $atts, array $providers = [] ): array {
		$site    = self::site();
		$blog_id = (int) get_current_blog_id();
		$page_id = (int) get_queried_object_id();
		$lang    = self::language_atts( $atts );

		$email_texts   = Email_Texts::for_locale( $lang['locale'] );
		$collect_email = self::collect_email( $context->email );
		if ( in_array( $atts['collect_email'] ?? 'auto', [ '0', '1' ], true ) ) {
			$collect_email = $atts['collect_email'] === '1';
		}
		$country = self::country();

		$labels = [
			'pay'         => self::acf( 'get_result_button__label', $page_id, __( 'Pay now', 'cellon-payment-collection' ) ),
			'processing'  => self::acf( 'processing', $page_id, __( 'Processing…', 'cellon-payment-collection' ) ),
			'separator'   => Message_Texts::for_locale( $lang['locale'] )['separator'] ?? __( 'Or Pay with Card', 'cellon-payment-collection' ),
			'success'     => self::acf( 'payment_successful_message', $page_id, 'Your Payment has been Successful! Redirecting...' ),
			'email'       => $email_texts['label'],
			'email_error' => $lang['email_error'] !== '' ? $lang['email_error'] : self::acf( 'email_collection_error_msg', $page_id, $email_texts['error'] ),
			'price'       => self::price_label( $context ),
			'redirect_failed' => 'Payment failed',
		];

		$profile = [
			'site'          => $site,
			'blog_id'       => $blog_id,
			'country'       => $country,
			'customer_ip'   => Config::client_ip(),
			'collect_email' => $collect_email,
			'processing_notice' => true,  // green "Processing your payment…" line while paying (gateways may opt out)
			'labels'        => $labels,
			'theme_markup'  => true,
			'preloader'     => '.preload',
			'pay_class'     => 'orange-btn big float-right',
			'events'        => self::events( $site, $blog_id, $providers ),
			// Core plugin parity: wwiqtest / wwpersonality fire it from their payment handlers, iqpro does not.
			'fire_ab_event' => in_array( $site, [ 'wwiqtest', 'wwpersonality' ], true ),
			// What every provider's browser_options() gets (the shortcode adds ids, layout, entries, context)
			'env'           => [
				'site'          => $site,
				'blog_id'       => $blog_id,
				'page_id'       => $page_id,
				'lang'          => $lang,
				'collect_email' => $collect_email,
				'country'       => $country,
				'customer_ip'   => Config::client_ip(),
			],
		];

		/**
		 * Change any part of the site profile (events, labels, email collection, provider environment).
		 *
		 * @param array            $profile
		 * @param Checkout_Context $context
		 * @param array            $atts
		 */
		return (array) apply_filters( 'cellon_payment_collection_site_profile', $profile, $context, $atts );
	}

	/**
	 * Collect the email whenever the quiz result has no real one (empty, or the dummy address), in every
	 * language. iqpro and wwpersonality already did this (is_email_position_ab_test() returns true for all
	 * languages); wwiqtest did it only on the Japanese blog (iq_feature('jap_site')) and now does it on all.
	 * A template turns it off with collect_email="0".
	 */
	public static function collect_email( string $email ): bool {
		return $email === '' || stripos( $email, self::DUMMY_EMAIL ) !== false;
	}

	/**
	 * Language settings the template passed as shortcode attributes, cleaned.
	 *
	 * @param array<string,string> $atts
	 * @return array{locale:string, rtl:bool, solidgate_locale:string, methods:string[], email_error:string}
	 */
	private static function language_atts( array $atts ): array {
		$locale = preg_replace( '/[^A-Za-z-]/', '', (string) ( $atts['locale'] ?? '' ) );
		$sg     = preg_replace( '/[^A-Za-z-]/', '', (string) ( $atts['solidgate_locale'] ?? '' ) );

		$methods = [ 'card' ];
		foreach ( explode( ',', (string) ( $atts['payment_methods'] ?? '' ) ) as $method ) {
			$method = sanitize_key( $method );
			if ( $method !== '' && ! in_array( $method, $methods, true ) ) {
				$methods[] = $method;
			}
		}

		return [
			'locale'           => $locale !== '' && strlen( $locale ) <= 10 ? $locale : 'auto',
			'rtl'              => strtolower( (string) ( $atts['direction'] ?? '' ) ) === 'rtl',
			'solidgate_locale' => strlen( $sg ) <= 10 ? $sg : '',
			'methods'          => $methods,
			'email_error'      => trim( wp_strip_all_tags( (string) ( $atts['email_error'] ?? '' ) ) ),
		];
	}

	/** Theme getVisitorCountry() (GeoIP), else the Core plugin's helper, else US. */
	private static function country(): string {
		$country = '';
		if ( function_exists( 'getVisitorCountry' ) ) {
			$value   = getVisitorCountry();
			$country = is_array( $value ) ? (string) ( $value['country_code'] ?? '' ) : (string) $value;
		} elseif ( class_exists( '\Quiz_Funnel\Helpers\Request_Helper' ) ) {
			$value   = \Quiz_Funnel\Helpers\Request_Helper::get_visitor_country();
			$country = is_array( $value ) ? (string) ( $value['country_code'] ?? '' ) : '';
		}
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', $country ) );
		return strlen( $country ) === 2 ? $country : 'US';
	}

	/** The theme's price line: first-sale label, or first + cross sale when the cross sale is compulsory. */
	private static function price_label( Checkout_Context $context ): string {
		$display = $context->display;
		if ( ! empty( $display['cross_sale_compulsory'] ) && ! empty( $display['first_and_cross_sale_price_label'] ) ) {
			return (string) $display['first_and_cross_sale_price_label'];
		}
		return (string) ( $display['first_sale_price_label'] ?? '' );
	}

	/** A text ACF field of the page, or the fallback. Providers use it for their labels. */
	public static function acf( string $field, int $page_id, string $fallback ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $fallback;
		}
		$value = get_field( $field, $page_id ?: false );
		return is_string( $value ) && trim( $value ) !== '' ? trim( wp_strip_all_tags( $value ) ) : $fallback;
	}

	/**
	 * dataLayer / analytics / gtag events, as the themes fire them today. The names per provider come from
	 * each provider class (Provider::site_events()); the rest is the same on every provider.
	 *
	 * @param array<string,Provider> $providers
	 * @return array<string,mixed>
	 */
	private static function events( string $site, int $blog_id, array $providers ): array {
		$gateways = [];
		foreach ( $providers as $slug => $provider ) {
			$gateways[ $slug ] = $provider->site_events( $site, $blog_id );
		}
		return [
			'gateways'           => $gateways,
			// wwiqtest: dataLayer "first_sale_fronted" once per quiz result (localStorage)
			'first_sale_fronted' => $site === 'wwiqtest',
			// email collection: cookie user_email, Chargeblast.identify, dataLayer email_insert, analytics email_submitted
			'chargeblast'        => true,
		];
	}
}
