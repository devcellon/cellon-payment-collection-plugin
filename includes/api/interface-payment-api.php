<?php
/**
 * Contract between the payment orchestrator and the Cellon payment API.
 *
 * The orchestrator and the provider handlers build one canonical body per call (the provider's own
 * fields plus `payment_provider_slug` and `payment_provider_detail_id`). An implementation decides
 * which routes to call and how to shape the body for them. Today: Current_Payment_Api (per-gateway
 * routes). Next: Common_Payment_Api (one initiate / confirm route). A later API version is one more
 * class registered through the `cellon_payment_collection_payment_api` filter; the shortcode, the REST
 * endpoints and the provider handlers do not change.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Payment_Api {

	/** Machine name shown in the settings screen ("current", "common", ...). */
	public function name(): string;

	/**
	 * Whether this API can run the operation for the provider.
	 *
	 * @param string $operation One of intent, confirm, charge_token.
	 */
	public function supports( string $provider, string $operation ): bool;

	/**
	 * First-sale intent / order / session. Nothing is charged.
	 *
	 * @param array<string,mixed> $body Canonical body built by the provider handler.
	 */
	public function create_intent( string $provider, array $body ): Api_Response;

	/**
	 * First-sale confirm. The API verifies the payment with the provider (or captures it) and records the sale.
	 *
	 * @param array<string,mixed> $body Canonical body built by the provider handler.
	 */
	public function confirm( string $provider, array $body ): Api_Response;

	/**
	 * Server-to-server charge of a card token (BridgerPay BLOX).
	 *
	 * @param array<string,mixed> $body
	 */
	public function charge_token( string $provider, array $body ): Api_Response;

	/**
	 * A payment the API runs in one call (Evervault): the common first-sale initiate with the provider's payment data
	 * charges it, the API trying its own processors in turn, and answers paid (2xx with the confirm's data), declined
	 * (402) or unknown (504, or no answer). With three_ds_session_id the API reads that 3DS session first.
	 *
	 * @param array<string,mixed> $body    Canonical body built by the provider handler.
	 * @param int                 $timeout Seconds to wait for the answer; 0 = until the API answers.
	 */
	public function pay( string $provider, array $body, int $timeout ): Api_Response;

	/**
	 * Start 3D Secure for a one-call payment (Evervault): the payment's body to the first-sale 3ds-session route.
	 * Nothing is charged. Answers three_ds "skipped" (pay without a session) or "evervault" with three_ds_session_id
	 * and a status ("action-required": the bank's window first).
	 *
	 * @param array<string,mixed> $body The payment's body, without three_ds_session_id.
	 */
	public function three_ds_session( string $provider, array $body ): Api_Response;

	/**
	 * Cross sale on the first sale's saved payment method.
	 *
	 * @param array<string,mixed> $body
	 */
	public function confirm_cross_sale( array $body ): Api_Response;

	/**
	 * Current quiz result, used to check whether a payment with an unknown outcome was recorded.
	 *
	 * @param array<string,mixed> $body
	 */
	public function fetch_quiz_result( array $body ): Api_Response;
}
