<?php
/**
 * Shared body building and input validation for provider handlers, and the defaults of the browser /
 * site parts of the Provider contract (gateway file at assets/js/gateways/<slug>.js, no options,
 * "<slug>_payment" events, no collected email, no redirect return, no per-provider routes).
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Checkout\Checkout_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Abstract_Provider implements Provider {

	/** Largest nested browser value accepted (payment_obj, bridgerpay_session), in bytes of JSON. */
	const MAX_NESTED_BYTES = 16384;

	public function default_placement(): string {
		return 'chain';
	}

	public function operations(): array {
		return [ 'intent', 'confirm' ];
	}

	/**
	 * Field specs per step: name => [ 'type' => string|email|url|list|object|enum, 'max' => int,
	 * 'pattern' => regex, 'values' => [...], 'required' => bool ].
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function intent_fields(): array {
		return [];
	}

	/** @return array<string,array<string,mixed>> */
	protected function confirm_fields(): array {
		return [];
	}

	/** @return array<string,array<string,mixed>> */
	protected function charge_token_fields(): array {
		return [];
	}

	public function build_intent_body( Checkout_Context $context, array $input, ?int $account_id ) {
		$fields = $this->validate( $input, $this->intent_fields(), 'intent' );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$body = array_merge( $this->intent_base( $context ), $fields, $this->account_fields( $account_id ) );
		$customer_email = $this->customer_email( $input, $context );
		if ( $customer_email !== '' ) {
			$body['customer_email'] = $customer_email;
		}
		return $body;
	}

	public function build_confirm_body( Checkout_Context $context, array $input, ?int $account_id ) {
		$fields = $this->validate( $input, $this->confirm_fields(), 'confirm' );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		return array_merge( $this->confirm_base( $context ), $fields, $this->account_fields( $account_id ) );
	}

	public function build_charge_token_body( Checkout_Context $context, array $input, ?int $account_id ) {
		if ( ! in_array( 'charge_token', $this->operations(), true ) ) {
			return new \WP_Error( 'operation_not_supported', __( 'This payment method does not charge card tokens.', 'cellon-payment-collection' ), [ 'status' => 404 ] );
		}
		$fields = $this->validate( $input, $this->charge_token_fields(), 'charge_token' );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$body = array_merge( $this->intent_base( $context ), $fields, $this->account_fields( $account_id ) );
		// The charge-token route takes the intent's body: the session's card token is bound to the
		// customer_email the session was opened with, so the charge names the same address.
		$customer_email = $this->customer_email( $input, $context );
		if ( $customer_email !== '' ) {
			$body['customer_email'] = $customer_email;
		}
		return $body;
	}

	public function intent_reference( array $data ): ?string {
		return null;
	}

	public function confirm_reference( array $input ): ?string {
		return null;
	}

	public function browser_intent_data( array $data ): array {
		return $data;
	}

	/** assets/js/gateways/<slug>.js of this plugin. A provider shipped elsewhere returns its own URL. */
	public function script_url(): string {
		return CELLON_PC_URL . 'assets/js/gateways/' . $this->slug() . '.js';
	}

	public function browser_options( array $env ): array {
		return [];
	}

	/** Default names: "<slug>_payment" / "<slug>_payment_failed", analytics first_sale on. */
	public function site_events( string $site, int $blog_id ): array {
		return [
			'success'     => $this->slug() . '_payment',
			'failed'      => $this->slug() . '_payment_failed',
			'first_sale'  => true,
			'submit_gtag' => '',
		];
	}

	public function collected_email( array $input ): string {
		return '';
	}

	public function redirect_return( array $query ): ?array {
		return null;
	}

	public function api_routes(): array {
		return [];
	}

	/** A real address, not the funnel's dummy one. */
	protected static function real_email( $value ): string {
		$email = is_string( $value ) ? sanitize_email( $value ) : '';
		return is_email( $email ) && stripos( $email, '_dummy@cellon.ai' ) === false ? $email : '';
	}

	/**
	 * Intent fields every first-sale route takes. All from the server-side context, never from the browser.
	 *
	 * @return array<string,mixed>
	 */
	protected function intent_base( Checkout_Context $context ): array {
		return $context->payment_fields( true );
	}

	/**
	 * Confirm routes reject `email` (422 "email is not allowed").
	 *
	 * @return array<string,mixed>
	 */
	protected function confirm_base( Checkout_Context $context ): array {
		return $context->payment_fields( false );
	}

	/** @return array<string,mixed> */
	protected function account_fields( ?int $account_id ): array {
		return [
			'payment_provider_slug'      => $this->slug(),
			'payment_provider_detail_id' => $account_id,
		];
	}

	/**
	 * An address typed on the page (not yet on the customer). Common API only; the current routes drop it.
	 *
	 * @param array<string,mixed> $input
	 */
	protected function customer_email( array $input, Checkout_Context $context ): string {
		if ( empty( $input['customer_email'] ) || ! is_string( $input['customer_email'] ) ) {
			return '';
		}
		$email = sanitize_email( $input['customer_email'] );
		return is_email( $email ) && strcasecmp( $email, $context->email ) !== 0 ? $email : '';
	}

	/**
	 * Keep only allow-listed fields and validate them. Unknown keys are dropped silently; the context
	 * keys (quiz_result_id, email, prc_id ...) are not in any allow-list, so the browser cannot set them.
	 *
	 * @param array<string,mixed>               $input
	 * @param array<string,array<string,mixed>> $specs
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function validate( array $input, array $specs, string $step ) {
		$out = [];
		foreach ( $specs as $name => $spec ) {
			$present = array_key_exists( $name, $input ) && $input[ $name ] !== null && $input[ $name ] !== '';
			if ( ! $present ) {
				if ( ! empty( $spec['required'] ) ) {
					return $this->invalid( $name, $step );
				}
				continue;
			}
			$value = $this->clean( $input[ $name ], $spec );
			if ( $value === null ) {
				return $this->invalid( $name, $step );
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $spec
	 * @return mixed|null null = invalid
	 */
	protected function clean( $value, array $spec ) {
		$type = $spec['type'] ?? 'string';
		$max  = (int) ( $spec['max'] ?? 256 );

		switch ( $type ) {
			case 'string':
				if ( ! is_scalar( $value ) ) {
					return null;
				}
				$value = trim( sanitize_text_field( (string) $value ) );
				if ( $value === '' || strlen( $value ) > $max ) {
					return null;
				}
				if ( ! empty( $spec['pattern'] ) && ! preg_match( $spec['pattern'], $value ) ) {
					return null;
				}
				return $value;

			case 'enum':
				return is_string( $value ) && in_array( $value, (array) $spec['values'], true ) ? $value : null;

			case 'list':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$list = [];
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || ! in_array( $item, (array) $spec['values'], true ) ) {
						return null;
					}
					$list[] = $item;
				}
				return array_values( array_unique( $list ) );

			case 'object':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$value = map_deep( $value, static function ( $item ) {
					return is_string( $item ) ? sanitize_text_field( $item ) : ( is_scalar( $item ) || $item === null ? $item : null );
				} );
				$json = wp_json_encode( $value );
				return $json !== false && strlen( $json ) <= self::MAX_NESTED_BYTES ? $value : null;
		}
		return null;
	}

	protected function invalid( string $field, string $step ): \WP_Error {
		return new \WP_Error(
			'invalid_field',
			/* translators: %s: field name */
			sprintf( __( 'Invalid payment data (%s).', 'cellon-payment-collection' ), $field ),
			[ 'status' => 422, 'field' => $field, 'step' => $step ]
		);
	}
}
