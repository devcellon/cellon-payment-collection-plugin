<?php
/**
 * A one-call provider whose card payment starts with a 3D Secure session (operation "three_ds_session", Evervault;
 * BACKEND-CONTRACTS.md E3). Each Pay press:
 *   1. POST /checkouts/{token}/providers/{provider}/3ds-session with the payment's own fields: the orchestrator sends
 *      the same body the payment will send (three_ds_body()) to the API's 3ds-session route. Nothing is charged.
 *   2. The bank's window in the browser, only when the API answers "action-required".
 *   3. The payment (operation "pay") with three_ds_session_id; the API reads the 3DS result and charges or declines.
 * The API decides whether 3DS runs at all: "skipped" (or an API without the route) means the payment goes on
 * without a session.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Checkout\Checkout_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Three_Ds_Provider {

	/**
	 * The body of the 3ds-session call: the payment's body without three_ds_session_id. Only payments that can be
	 * authenticated this way (the card form's card) are accepted.
	 *
	 * @param array<string,mixed> $input Browser body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function three_ds_body( Checkout_Context $context, array $input, ?int $account_id );

	/**
	 * The API's answer as the browser gets it:
	 *   [ 'three_ds' => 'skipped', 'reason' => '…' ]
	 *   [ 'three_ds' => 'evervault', 'three_ds_session_id' => 'tds_…', 'status' => 'action-required' | 'success' | 'failure' ]
	 *
	 * @param array<string,mixed> $data API answer data.
	 * @return array<string,string>
	 */
	public function three_ds_answer( array $data ): array;
}
