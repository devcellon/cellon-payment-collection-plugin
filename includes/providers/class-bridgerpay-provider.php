<?php
/**
 * BridgerPay (Checkout widget or BLOX card fields).
 *
 * Uses only the routes of the API `bridgerpay-gateway` release that the browser core was verified
 * against (nexus-payment-gateways/docs/payment-plugin-bridgerpay-v0.1.md). On by default; an API
 * environment without these routes answers 404 at intent and the checkout moves to the next provider.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bridgerpay_Provider extends Abstract_Provider {

	const ORDER_ID = '/^[A-Za-z0-9_-]{1,128}$/';

	public function slug(): string {
		return 'bridgerpay';
	}

	public function label(): string {
		return 'BridgerPay';
	}

	public function operations(): array {
		return [ 'intent', 'confirm', 'charge_token' ];
	}

	protected function intent_fields(): array {
		return [
			// Session look and fields; the Vault allow-lists the keys again (422 on anything else).
			'bridgerpay_session' => [ 'type' => 'object' ],
			'bridgerpay_mode'    => [ 'type' => 'enum', 'values' => [ 'blox', 'checkout' ] ],
		];
	}

	protected function confirm_fields(): array {
		return [
			'order_id' => [ 'type' => 'string', 'required' => true, 'max' => 128, 'pattern' => self::ORDER_ID ],
		];
	}

	protected function charge_token_fields(): array {
		return [
			'order_id'          => [ 'type' => 'string', 'required' => true, 'max' => 128, 'pattern' => self::ORDER_ID ],
			'card_holder_name'  => [ 'type' => 'string', 'required' => true, 'max' => 120 ],
			// BLOX tokens, not card numbers: the card data stays in BridgerPay's iframes.
			'credit_card_token' => [ 'type' => 'string', 'required' => true, 'max' => 1024 ],
			'encrypted_cvv'     => [ 'type' => 'string', 'max' => 2048 ],
		];
	}

	/** Routes of the API `bridgerpay-gateway` release. */
	public function api_routes(): array {
		return [
			'intent'       => '/payment/bridgerpay/create-payment-intent',
			'confirm'      => '/payment/bridgerpay/first-sale/payments/confirm',
			'charge_token' => '/payment/bridgerpay/first-sale/payments/charge-token',
		];
	}

	/**
	 * Widget session look, the BLOX field labels / placeholders, and the wallet slot. Mode: the account's
	 * checkout_mode. With BLOX, Google Pay / Apple Pay open on a wallet-only Checkout session in the page's
	 * wallet slot (the same element Stripe's and Solidgate's wallets use); 'wallet' => false turns it off.
	 */
	public function browser_options( array $env ): array {
		$page_id = (int) ( $env['page_id'] ?? 0 );
		$ids     = (array) ( $env['ids'] ?? [] );
		return [
			'wallet'            => true,
			'walletContainerId' => (string) ( $ids['wallets'] ?? '' ),
			// alwaysVisibleInputs: the widget shows its own email input (the session carries the quiz
			// result's dummy address) once the API generation forwards bridgerpay_session to the Vault.
			'session' => [ 'singlePaymentMethod' => 'credit_card', 'theme' => 'light', 'payMode' => true, 'alwaysVisibleInputs' => [ 'email' => [ 'credit_card' ] ] ],
			'blox'    => [
				'labels'       => [ 'cardHolder' => 'Name on card', 'cardNumber' => 'Card number', 'cardExpiry' => 'Expiry', 'cardCvv' => 'CVC' ],
				'placeholders' => [ 'cardNumber' => \Cellon\PaymentCollection\Site\Site_Profile::acf( 'credit_card_placeholder', $page_id, 'Card Number' ), 'cardExpiry' => 'MM / YY', 'cardCvv' => 'CVC' ],
				'style'        => [ 'base' => [ 'font' => '16px montserratsemibold, sans-serif', 'color' => '#121212', '::placeholder' => [ 'color' => '#888888' ] ], 'invalid' => [ 'color' => '#eb1c26' ] ],
			],
		];
	}

	public function intent_reference( array $data ): ?string {
		return isset( $data['order_id'] ) && is_scalar( $data['order_id'] ) ? (string) $data['order_id'] : null;
	}

	public function confirm_reference( array $input ): ?string {
		return isset( $input['order_id'] ) && is_scalar( $input['order_id'] ) ? (string) $input['order_id'] : null;
	}
}
