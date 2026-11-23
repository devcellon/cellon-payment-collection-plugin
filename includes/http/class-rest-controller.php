<?php
/**
 * The plugin's browser endpoints (namespace cellon-payment/v1). The browser talks to these and to the
 * providers' own SDKs only, never to the Cellon API.
 *
 *   POST /checkouts/{token}/providers/{provider}/intent        create intent / order / session
 *   POST /checkouts/{token}/providers/{provider}/confirm       confirm with the API (PayPal: API captures)
 *   POST /checkouts/{token}/providers/{provider}/charge-token  BridgerPay BLOX token charge
 *   POST /checkouts/{token}/status                             was an unclear payment recorded?
 *   POST /checkouts/{token}/cross-sale                         { "action": "accept" | "skip" }
 *   POST /checkouts/{token}/email                              { "email" } after a paid first sale (dummy-email
 *                                                              checkouts): PUT /customer/update-customer-email
 *
 * PayPal: /providers/paypal/intent is the create-order endpoint, /providers/paypal/confirm the confirm
 * endpoint; the body of the confirm is { paypal_order_id, payment_provider_detail_id } and nothing else is used.
 *
 * Every answer has the API's envelope shape { meta: { success, message, status }, data }.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Http;

use Cellon\PaymentCollection\Checkout\Payment_Orchestrator;
use Cellon\PaymentCollection\Flow_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Controller {

	const NAMESPACE_V1 = 'cellon-payment/v1';

	/** @var Payment_Orchestrator */
	private $orchestrator;

	public function __construct( Payment_Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$token = '(?P<token>[a-f0-9]{64})';

		register_rest_route( self::NAMESPACE_V1, '/checkouts/' . $token . '/providers/(?P<provider>[a-z0-9_-]{2,32})/(?P<operation>intent|confirm|charge-token)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'provider_step' ],
			'permission_callback' => [ $this, 'same_origin' ],
		] );

		register_rest_route( self::NAMESPACE_V1, '/checkouts/' . $token . '/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'same_origin' ],
		] );

		register_rest_route( self::NAMESPACE_V1, '/checkouts/' . $token . '/cross-sale', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cross_sale' ],
			'permission_callback' => [ $this, 'same_origin' ],
		] );

		register_rest_route( self::NAMESPACE_V1, '/checkouts/' . $token . '/email', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'email' ],
			'permission_callback' => [ $this, 'same_origin' ],
		] );
	}

	public function provider_step( \WP_REST_Request $request ): \WP_REST_Response {
		$token     = (string) $request['token'];
		$provider  = strtolower( (string) $request['provider'] );
		$operation = (string) $request['operation'];
		$input     = $this->json( $request );

		Flow_Log::begin( $provider, $operation, $token, $input );
		switch ( $operation ) {
			case 'intent':
				$result = $this->orchestrator->intent( $token, $provider, $input );
				break;
			case 'confirm':
				$result = $this->orchestrator->confirm( $token, $provider, $input );
				break;
			default:
				$result = $this->orchestrator->charge_token( $token, $provider, $input );
		}
		Flow_Log::end( $operation, $result );
		return $this->respond( $result );
	}

	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( $this->orchestrator->status( (string) $request['token'] ) );
	}

	public function cross_sale( \WP_REST_Request $request ): \WP_REST_Response {
		$input  = $this->json( $request );
		$action = isset( $input['action'] ) && $input['action'] === 'skip' ? 'skip' : 'accept';
		return $this->respond( $this->orchestrator->cross_sale( (string) $request['token'], $action ) );
	}

	public function email( \WP_REST_Request $request ): \WP_REST_Response {
		$input  = $this->json( $request );
		$result = $this->orchestrator->collect_email( (string) $request['token'], $input['email'] ?? '' );
		Flow_Log::end( 'email', $result );
		return $this->respond( $result );
	}

	/**
	 * Browser requests from this site only. Modern browsers send Origin on every POST fetch; Referer is
	 * the fallback. The token check happens in the orchestrator.
	 */
	public function same_origin( \WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		if ( $origin === '' ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		$site    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed = $origin !== '' && strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $site;

		return (bool) apply_filters( 'cellon_payment_collection_same_origin', $allowed, $origin );
	}

	/** @return array<string,mixed> */
	private function json( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : [];
	}

	/**
	 * @param array{status:int, body:array<string,mixed>} $result
	 */
	private function respond( array $result ): \WP_REST_Response {
		$response = new \WP_REST_Response( $result['body'], $result['status'] );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && $value !== '' ) {
				$response->header( $name, (string) $value );
			}
		}
		return $response;
	}
}
