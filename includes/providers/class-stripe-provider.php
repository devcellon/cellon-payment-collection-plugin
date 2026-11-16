<?php
/**
 * Stripe (Payment Element, Express Checkout wallets on the same intent).
 *
 * Intent: the API creates the PaymentIntent on the account it picks and returns client_secret,
 * public_key (publishable key of that account) and payment_provider_detail_id. Confirm: the browser
 * confirms with Stripe, then the API reads the PaymentIntent from Stripe and records the sale.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Provider extends Abstract_Provider {

	public function slug(): string {
		return 'stripe';
	}

	public function label(): string {
		return 'Stripe';
	}

	protected function intent_fields(): array {
		return [
			// Methods the Vault accepts on the intent; the browser core sends ["card"] by default.
			'payment_method_type' => [
				'type'   => 'list',
				'values' => (array) apply_filters( 'cellon_payment_collection_stripe_method_types', [ 'card', 'alipay', 'paypal' ] ),
			],
		];
	}

	protected function confirm_fields(): array {
		return [
			'payment_intent_id' => [ 'type' => 'string', 'required' => true, 'max' => 255, 'pattern' => '/^pi_[A-Za-z0-9_]+$/' ],
		];
	}

	public function intent_reference( array $data ): ?string {
		if ( ! empty( $data['payment_intent_id'] ) && is_string( $data['payment_intent_id'] ) ) {
			return $data['payment_intent_id'];
		}
		// client_secret is "pi_XXX_secret_YYY"
		if ( ! empty( $data['client_secret'] ) && is_string( $data['client_secret'] ) && preg_match( '/^(pi_[A-Za-z0-9]+)_secret_/', $data['client_secret'], $m ) ) {
			return $m[1];
		}
		return null;
	}

	public function confirm_reference( array $input ): ?string {
		return isset( $input['payment_intent_id'] ) && is_string( $input['payment_intent_id'] ) ? $input['payment_intent_id'] : null;
	}

	public function api_routes(): array {
		return [
			'intent'  => '/payment/stripe/create-payment-intent',
			'confirm' => '/payment/stripe/first-sale/payments/confirm',
		];
	}

	/** Back from a 3DS / Alipay page: ?payment_intent=pi_…&redirect_status=… */
	public function redirect_return( array $query ): ?array {
		$pi     = isset( $query['payment_intent'] ) ? sanitize_text_field( wp_unslash( (string) $query['payment_intent'] ) ) : '';
		$status = isset( $query['redirect_status'] ) ? sanitize_key( wp_unslash( (string) $query['redirect_status'] ) ) : '';
		if ( $pi !== '' && preg_match( '/^pi_[A-Za-z0-9_]+$/', $pi ) ) {
			return [ 'reference' => $pi, 'status' => $status ];
		}
		return $status === 'failed' ? [ 'status' => 'failed' ] : null;
	}

	/**
	 * Payment Element + Express Checkout (Apple Pay / Google Pay in the page's wallet container) as the
	 * themes' stripe-new.js / stripe_new.js set them up, and Stripe's Contact Details Element for the email
	 * when the checkout collects it.
	 */
	public function browser_options( array $env ): array {
		$site    = (string) ( $env['site'] ?? '' );
		$lang    = (array) ( $env['lang'] ?? [] );
		$rtl     = ! empty( $lang['rtl'] );
		$methods = ! empty( $lang['methods'] ) ? array_values( (array) $lang['methods'] ) : [ 'card' ];
		$ids     = (array) ( $env['ids'] ?? [] );
		$context = $env['context'] ?? null;

		$payment_element = [
			'wallets'            => [ 'applePay' => 'never', 'googlePay' => 'never', 'link' => 'never' ],
			'terms'              => [ 'card' => 'never' ],
			'fields'             => [ 'billingDetails' => [ 'address' => [ 'country' => 'never', 'postalCode' => 'auto' ] ] ],
			'paymentMethodOrder' => $methods,
			'defaultValues'      => [ 'paymentMethod' => [ 'type' => 'card' ] ],
		];
		if ( $site === 'iqpro' ) {
			$payment_element['layout'] = [ 'type' => 'tabs' ];
		}

		$express = [
			'buttonHeight'   => $site === 'iqpro' ? 50 : 55,
			'paymentMethods' => [ 'applePay' => 'always', 'googlePay' => 'always', 'link' => 'never', 'amazonPay' => 'never', 'paypal' => 'never' ],
			'layout'         => $site === 'wwiqtest' ? [ 'maxColumns' => 1, 'maxRows' => 2 ] : [ 'maxColumns' => 2, 'maxRows' => 0, 'overflow' => 'never' ],
		];
		if ( $site === 'wwpersonality' ) {
			$express['business'] = [ 'name' => ' ' ];
		}
		// Stacked express row: Stripe stacks its wallet buttons too (Apple Pay over Google Pay)
		if ( ( $env['express_layout'] ?? 'row' ) === 'stack' ) {
			$express['layout'] = [ 'maxColumns' => 1, 'maxRows' => 0, 'overflow' => 'never' ];
		}

		$options = [
			'paymentMethodTypes' => $methods,
			'elements'           => [ 'locale' => (string) ( $lang['locale'] ?? 'auto' ) ],
			'appearance'         => [
				'theme'     => 'stripe',
				'variables' => [ 'fontFamily' => 'montserratsemibold, sans-serif', 'fontSizeBase' => '16px', 'colorText' => '#121212', 'colorDanger' => '#eb1c26', 'colorBackground' => '#ffffff' ],
				'rules'     => [ '.Input' => [ 'padding' => '15px', 'lineHeight' => '1.4', 'textAlign' => $rtl ? 'right' : 'left' ], '.Input::placeholder' => [ 'color' => '#888' ] ],
			],
			'paymentElement'     => $payment_element,
			'country'            => (string) ( $env['country'] ?? 'US' ),
			'customerIp'         => (string) ( $env['customer_ip'] ?? '' ),
		];
		if ( ! empty( $ids['wallets'] ) ) {
			$options['expressCheckout'] = [ 'container' => '#' . $ids['wallets'], 'options' => $express ];
		}

		// Deferred (Elements before the intent, as the themes do) needs the publishable key from the provider
		// list and the amount; without them the intent is created at mount and its key is used.
		$has_key = false;
		foreach ( (array) ( $env['entries'] ?? [] ) as $entry ) {
			if ( ( $entry['slug'] ?? '' ) === $this->slug() && ! empty( $entry['public_key'] ) ) {
				$has_key = true;
			}
		}
		if ( $has_key && $context && $context->amount !== null && $context->currency !== '' ) {
			$options['deferred'] = [ 'setupFutureUsage' => 'off_session' ];
		}

		/**
		 * Stripe asks for the email with its Contact Details Element (as stripe-new.js did) when the checkout
		 * collects the email. False: the page's own email field is used instead.
		 *
		 * @param bool  $use
		 * @param array $env
		 */
		if ( ! empty( $env['collect_email'] ) && apply_filters( 'cellon_payment_collection_stripe_email_element', true, $env ) ) {
			// `options` (Stripe's element options) may be added through the adapter-options filter
			$options['contactDetails'] = [ 'enabled' => true ];
		}
		return $options;
	}
}
