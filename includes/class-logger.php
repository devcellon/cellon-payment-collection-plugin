<?php
/**
 * Debug logging. Off unless the setting is on; never writes tokens, card data or full API bodies.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	/** Keys whose values are never written to the log. `card`, `wallet`, `network_token`, `cryptogram`: encrypted card / wallet data (Evervault). */
	const REDACT = [ 'authorization', 'token', 'api_token', 'client_secret', 'secret', 'signature', 'paymentintent', 'cashier_token', 'credit_card_token', 'encrypted_cvv', 'payment_obj', 'card', 'wallet', 'network_token', 'cryptogram', 'email', 'customer_email' ];

	/**
	 * @param string              $event   Short event name, e.g. "intent.failed".
	 * @param array<string,mixed> $context Small context array; sensitive keys are redacted.
	 */
	public static function log( string $event, array $context = [] ): void {
		if ( ! Config::debug() ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[cellon-payment-collection] ' . $event . ' ' . wp_json_encode( self::redact( $context ) ) );
	}

	/**
	 * Always logged (errors the site owner must see), still redacted.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function error( string $event, array $context = [] ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[cellon-payment-collection] ERROR ' . $event . ' ' . wp_json_encode( self::redact( $context ) ) );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function redact( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::REDACT, true ) ) {
				$value[ $key ] = '[redacted]';
			} else {
				$value[ $key ] = self::redact( $item );
			}
		}
		return $value;
	}
}
