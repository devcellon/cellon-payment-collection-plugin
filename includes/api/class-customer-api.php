<?php
/**
 * Customer routes of the Cellon API that belong to the checkout. Today one: the email a dummy-email customer
 * typed on the payment page, saved after the first sale was recorded.
 *
 * Same call as the Core plugin's `submit_email` admin-ajax handler (quiz-funnel-core / Cellon-Nexus-Core
 * Quiz_Service::handle_submit_email()), so the plugin no longer needs that handler:
 *
 *   PUT /customer/update-customer-email
 *   { quiz_result_id, email, variant_type [, ab_testing_price_category] }
 *
 *   variant_type               the ab-testing plugin's cookie wp_ab_visitor_variant, '' without it
 *   ab_testing_price_category  the plan of a multi-plan page (wwiqtest quiz-checkout-v3). Sent like
 *                              wwiqtest's Core: $_SESSION['ab_testing_price_category'] when it is set (the
 *                              plugin writes it on an intent of such a page), else the checkout's own plan.
 *                              Not sent when neither is set, like iqpro's and wwpersonality's Core.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customer_Api {

	const UPDATE_EMAIL_ROUTE = '/customer/update-customer-email';

	/** @var Http_Transport */
	private $http;

	public function __construct( Http_Transport $http ) {
		$this->http = $http;
	}

	/**
	 * @param int|string  $quiz_result_id From the checkout session, never from the browser.
	 * @param string|null $checkout_plan  The checkout's ab_testing_price_category, null when it has none.
	 */
	public function update_email( $quiz_result_id, string $email, ?string $checkout_plan ): Api_Response {
		$body = [
			'quiz_result_id' => $quiz_result_id,
			'email'          => $email,
			'variant_type'   => isset( $_COOKIE['wp_ab_visitor_variant'] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE['wp_ab_visitor_variant'] ) ) : '',
		];

		if ( session_status() === PHP_SESSION_ACTIVE && isset( $_SESSION['ab_testing_price_category'] ) && is_scalar( $_SESSION['ab_testing_price_category'] ) ) {
			$body['ab_testing_price_category'] = (string) $_SESSION['ab_testing_price_category'];
		} elseif ( $checkout_plan !== null && $checkout_plan !== '' ) {
			$body['ab_testing_price_category'] = $checkout_plan;
		}

		/**
		 * Body of PUT /customer/update-customer-email.
		 *
		 * @param array<string,mixed> $body
		 */
		$body = (array) apply_filters( 'cellon_payment_collection_customer_email_body', $body );

		return $this->http->put( (string) apply_filters( 'cellon_payment_collection_customer_email_route', self::UPDATE_EMAIL_ROUTE ), $body );
	}
}
