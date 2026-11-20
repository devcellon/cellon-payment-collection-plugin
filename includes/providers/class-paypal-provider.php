<?php
/**
 * PayPal Buttons, server-side order flow.
 *
 *   1. Browser → POST /checkouts/{token}/providers/paypal/intent (this plugin)
 *      → API POST /payment/paypal/first-sale/payments/create-order with quiz_result_id, email and
 *        validated return_url / cancel_url. The API creates the order with its PayPal credentials.
 *   2. The browser shows PayPal's approval with the returned order_id (client id from the API).
 *   3. Browser → POST /checkouts/{token}/providers/paypal/confirm with the order id only
 *      → API POST /payment/paypal/first-sale/payments/confirm. The API captures (or verifies the capture
 *        of) the order with PayPal and decides whether it is paid.
 *
 * The browser never gets an OAuth token or app secret, never captures, and nothing it claims about
 * the result (payment_status, charge_id, payer ids) is forwarded. Both bodies carry the fields of the
 * reference integration (16types, and the old theme code): payment_method_type on create-order;
 * payment_method / payment_status / charge_id / item_price / payer ids on confirm. Those are fixed
 * server-side values, never browser input; without them the API records the PayPal sale as failed.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Checkout\Checkout_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Paypal_Provider extends Abstract_Provider {

	public function slug(): string {
		return 'paypal';
	}

	public function label(): string {
		return 'PayPal';
	}

	public function default_placement(): string {
		return 'direct';
	}

	protected function confirm_fields(): array {
		return [
			'paypal_order_id' => [ 'type' => 'string', 'required' => true, 'max' => 64, 'pattern' => '/^[A-Za-z0-9-]{6,64}$/' ],
		];
	}

	public function build_intent_body( Checkout_Context $context, array $input, ?int $account_id ) {
		// No browser fields pass the (empty) intent allow-list; return / cancel URLs are validated below.
		$body = parent::build_intent_body( $context, $input, $account_id );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$fallback                    = $context->page_url !== '' ? $context->page_url : home_url( '/' );
		$body['payment_method_type'] = 'paypal';
		$body['return_url']          = $this->safe_url( $input['return_url'] ?? '', $fallback );
		$body['cancel_url']          = $this->safe_url( $input['cancel_url'] ?? '', $fallback );
		return $body;
	}

	/**
	 * The confirm body of the reference integration. The browser only sends the approved order id
	 * (confirm_fields); everything added here is a constant. The API still captures the order with
	 * PayPal itself (server-side capture: empty charge_id / payer ids, as 16types sends them).
	 */
	public function build_confirm_body( Checkout_Context $context, array $input, ?int $account_id ) {
		$body = parent::build_confirm_body( $context, $input, $account_id );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return $body + [
			'payment_method'        => 'paypal',
			'payment_status'        => 'paid',
			'charge_id'             => '',
			'item_price'            => 0,
			'paypal_customer_email' => '',
			'payment_method_id'     => '',
			'paypal_customer_id'    => '',
		];
	}

	public function api_routes(): array {
		return [
			'intent'  => '/payment/paypal/first-sale/payments/create-order',
			'confirm' => '/payment/paypal/first-sale/payments/confirm',
		];
	}

	/** iqpro's paypal-checkout.js pushes no dataLayer events; wwiqtest / wwpersonality do. */
	public function site_events( string $site, int $blog_id ): array {
		$events = parent::site_events( $site, $blog_id );
		if ( $site === 'iqpro' ) {
			$events['success'] = '';
			$events['failed']  = '';
		}
		return $events;
	}

	public function browser_options( array $env ): array {
		$site = (string) ( $env['site'] ?? '' );
		return [
			'style'     => [ 'color' => 'blue', 'height' => $site === 'iqpro' ? 50 : 55 ],
			'sdkParams' => [ 'disable-funding' => 'credit,card,paylater,venmo', 'vault' => 'true' ],
		];
	}

	public function intent_reference( array $data ): ?string {
		return isset( $data['order_id'] ) && is_scalar( $data['order_id'] ) ? (string) $data['order_id'] : null;
	}

	public function confirm_reference( array $input ): ?string {
		return isset( $input['paypal_order_id'] ) && is_string( $input['paypal_order_id'] ) ? $input['paypal_order_id'] : null;
	}

	/**
	 * Only URLs on this site (or a host added through the filter) go to PayPal as return / cancel URL;
	 * anything else becomes the checkout page, so the order can never send the buyer elsewhere.
	 *
	 * @param mixed $url
	 */
	private function safe_url( $url, string $fallback ): string {
		if ( ! is_string( $url ) || $url === '' ) {
			return $fallback;
		}
		$url   = esc_url_raw( $url, [ 'https', 'http' ] );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $fallback;
		}
		$allowed = (array) apply_filters( 'cellon_payment_collection_paypal_return_hosts', [ (string) wp_parse_url( home_url(), PHP_URL_HOST ) ] );
		if ( ! in_array( strtolower( $parts['host'] ), array_map( 'strtolower', $allowed ), true ) ) {
			return $fallback;
		}
		if ( ( $parts['scheme'] ?? '' ) !== 'https' && is_ssl() ) {
			return $fallback;
		}
		return $url;
	}
}
