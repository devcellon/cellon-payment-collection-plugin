<?php
/**
 * A provider whose payment the API runs in one call (operation "pay", e.g. Evervault). The browser collects the
 * payment data in the provider's own form and sends it once to POST /checkouts/{token}/providers/{provider}/pay. The
 * orchestrator builds the body (build_intent_body(), with the provider's allow-list), makes the one API call
 * (Payment_Api::pay()) and records the outcome. The API runs the whole payment, its processors and their order
 * included, and answers paid, declined or unknown. A 3D Secure step comes before the payment, not in its answer
 * (Three_Ds_Provider).
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface One_Call_Provider {

	/**
	 * Seconds to wait for the API's answer to the one call. 0 = no limit: the API may try several processors, and
	 * cutting it short would only leave the outcome unknown.
	 */
	public function pay_timeout(): int;

	/**
	 * A successful answer that is not a payment yet: the step the browser must run first, after which it pays again
	 * naming that step. null = paid. WP_Error = the API asked for a step this plugin cannot run (nothing was charged).
	 *
	 * @param array<string,mixed> $data API answer data.
	 * @return array<string,string>|null|\WP_Error
	 */
	public function pay_action( array $data );
}
