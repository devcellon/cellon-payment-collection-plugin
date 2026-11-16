<?php
/**
 * The common first-sale payment API: one initiate and one confirm route for every gateway, with
 * `payment_provider_slug` and `payment_provider_detail_id` in the body (the API checks the account
 * belongs to the provider, 422, and is still active, 409).
 *
 * Cross sale and the quiz-result check have no common route yet and use today's routes.
 * Switch to this adapter in Settings → Cellon Payments, or define( 'CELLON_PAYMENT_API_ADAPTER', 'common' ),
 * once the API environment the site talks to serves these routes.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Common_Payment_Api extends Current_Payment_Api {

	/** @var array<string,string> */
	protected $common_routes;

	/**
	 * @param array<string,array<string,string>> $provider_routes
	 */
	public function __construct( Http_Transport $http, array $provider_routes = [] ) {
		parent::__construct( $http, $provider_routes );
		$this->common_routes = (array) apply_filters(
			'cellon_payment_collection_common_routes',
			[
				'initiate' => '/payment/first-sale/payments/initiate',
				'confirm'  => '/payment/first-sale/payments/confirm',
			]
		);
	}

	public function name(): string {
		return 'common';
	}

	public function supports( string $provider, string $operation ): bool {
		if ( $operation === 'intent' || $operation === 'confirm' ) {
			// One route for every provider; the API rejects a provider it does not know.
			return true;
		}
		return parent::supports( $provider, $operation );
	}

	public function create_intent( string $provider, array $body ): Api_Response {
		$body['payment_provider_slug'] = $provider;
		return $this->http->post( $this->common_routes['initiate'], $body );
	}

	public function confirm( string $provider, array $body ): Api_Response {
		$body['payment_provider_slug'] = $provider;
		return $this->http->post( $this->common_routes['confirm'], $body );
	}
}
