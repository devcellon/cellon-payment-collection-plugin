<?php
/**
 * Today's Cellon payment routes: one pair of routes per gateway (each provider class declares its own in
 * Provider::api_routes()), the same ones the Core plugin's Payment_Service and the theme handlers call. These routes reject unknown body keys (Joi), so the
 * keys only the common API knows are removed here.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Current_Payment_Api implements Payment_Api {

	/** Keys the per-gateway routes do not accept. The deployed bridgerpay intent route also rejects
	 * `bridgerpay_mode` (422 "bridgerpay_mode is not allowed"); the account's checkout_mode decides. */
	const COMMON_ONLY_KEYS = [ 'payment_provider_slug', 'payment_provider_detail_id', 'customer_email', 'bridgerpay_session', 'bridgerpay_mode' ];

	/** @var Http_Transport */
	protected $http;

	/** @var array<string,array<string,string>> */
	protected $routes;

	/**
	 * @param array<string,array<string,string>> $provider_routes Each provider's own routes
	 *                                                            (Provider_Registry::api_routes()).
	 */
	public function __construct( Http_Transport $http, array $provider_routes = [] ) {
		$this->http   = $http;
		$this->routes = (array) apply_filters( 'cellon_payment_collection_current_routes', array_merge( $provider_routes, self::shared_routes() ) );
	}

	/**
	 * Routes that belong to no provider. Provider routes come from each provider class (api_routes()).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function shared_routes(): array {
		return [
			'_shared' => [
				'cross_sale'  => '/payment/cross-sale/payments/confirm',
				'quiz_result' => '/questions/results',
			],
		];
	}

	public function name(): string {
		return 'current';
	}

	public function supports( string $provider, string $operation ): bool {
		return isset( $this->routes[ $provider ][ $operation ] ) && $this->routes[ $provider ][ $operation ] !== '';
	}

	public function create_intent( string $provider, array $body ): Api_Response {
		return $this->call( $provider, 'intent', $this->strip( $body ) );
	}

	public function confirm( string $provider, array $body ): Api_Response {
		return $this->call( $provider, 'confirm', $this->strip( $body ) );
	}

	public function charge_token( string $provider, array $body ): Api_Response {
		// The BLOX route belongs to the common API generation and accepts the account keys.
		return $this->call( $provider, 'charge_token', $body );
	}

	public function confirm_cross_sale( array $body ): Api_Response {
		return $this->http->post( $this->routes['_shared']['cross_sale'], $this->strip( $body ) );
	}

	public function fetch_quiz_result( array $body ): Api_Response {
		return $this->http->post( $this->routes['_shared']['quiz_result'], $body );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	protected function call( string $provider, string $operation, array $body ): Api_Response {
		if ( ! $this->supports( $provider, $operation ) ) {
			return Api_Response::local_error( 404, __( 'This payment method is not available.', 'cellon-payment-collection' ), 'route_not_available' );
		}
		return $this->http->post( $this->routes[ $provider ][ $operation ], $body );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	protected function strip( array $body ): array {
		return array_diff_key( $body, array_flip( self::COMMON_ONLY_KEYS ) );
	}
}
