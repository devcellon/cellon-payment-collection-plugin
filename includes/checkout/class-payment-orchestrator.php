<?php
/**
 * Server-side payment orchestrator. Every browser payment step goes through here, and here only:
 *
 *   - The checkout context (quiz result, email, pricing fields) comes from the stored session; the
 *     browser cannot change it.
 *   - A provider runs only if the API listed it for this checkout, it has a registered and enabled
 *     handler, and the active Payment_Api supports the step.
 *   - One money-moving step at a time per checkout (lock). A second confirm gets 409.
 *   - A confirm must name an intent this checkout created (Stripe PaymentIntent, PayPal order, BridgerPay order).
 *   - After an unclear outcome (timeout, 5xx, provider still processing, API error after the provider took
 *     the payment), the next attempt first asks the API whether the sale was recorded. Paid → the checkout
 *     ends as paid. Not recorded and the outcome was unknown → the customer waits a grace period before
 *     paying again, so a late webhook or capture can land first.
 *   - Once paid, every later step answers "already paid" with the same redirect (idempotent).
 *
 * The browser core decides *which* provider to show next (its failover policy); this class decides
 * whether the server lets a step run. A provider whose payment the API runs in one call (One_Call_Provider,
 * Evervault) goes through pay(), under the same lock and attempt rules; its 3D Secure session (Three_Ds_Provider)
 * comes first, through three_ds_session(), and only that session's payment may name it.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Checkout;

use Cellon\PaymentCollection\Api\Api_Response;
use Cellon\PaymentCollection\Api\Customer_Api;
use Cellon\PaymentCollection\Api\Payment_Api;
use Cellon\PaymentCollection\Flow_Log;
use Cellon\PaymentCollection\Logger;
use Cellon\PaymentCollection\Providers\Provider;
use Cellon\PaymentCollection\Providers\One_Call_Provider;
use Cellon\PaymentCollection\Providers\Provider_Registry;
use Cellon\PaymentCollection\Providers\Three_Ds_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Orchestrator {

	/** @var Payment_Api */
	private $api;

	/** @var Provider_Registry */
	private $registry;

	/** @var Session_Store */
	private $store;

	/** @var Customer_Api */
	private $customers;

	/** @var string redirect_page of the last quiz-result check. */
	private $last_redirect_page = '';

	public function __construct( Payment_Api $api, Provider_Registry $registry, Session_Store $store, Customer_Api $customers ) {
		$this->api       = $api;
		$this->registry  = $registry;
		$this->store     = $store;
		$this->customers = $customers;
	}

	public function api(): Payment_Api {
		return $this->api;
	}

	/* ------------------------------------------------------------ first sale */

	/**
	 * @param array<string,mixed> $input Browser body.
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function intent( string $token, string $slug, array $input ): array {
		if ( ! $this->store->lock( $token, 3.0 ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ) );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			Flow_Log::quiz( (string) ( $session->context['quiz_result_id'] ?? '' ) );
			if ( $session->is_paid() ) {
				return $this->already_paid( $session );
			}

			$check = $this->check_provider( $session, $slug, 'intent' );
			if ( $check ) {
				return $check;
			}

			$max = (int) apply_filters( 'cellon_payment_collection_max_intents', 25 );
			if ( $session->intent_count >= $max ) {
				return $this->error( 429, 'too_many_attempts', __( 'Too many payment attempts. Please reload the page.', 'cellon-payment-collection' ) );
			}

			$gate = $this->resolve_unclear( $session );
			if ( $gate ) {
				return $gate;
			}

			$account = $this->account( $input );
			if ( ! $session->account_allowed( $slug, $account ) ) {
				return $this->error( 422, 'invalid_payment_account', __( 'This payment method is not available. Please reload the page.', 'cellon-payment-collection' ) );
			}

			$plan = $this->apply_price_category( $session, $input );
			if ( is_wp_error( $plan ) ) {
				return $this->wp_error( $plan );
			}

			$provider = $this->registry->get( $slug );
			$context  = $session->context();
			$body     = $provider->build_intent_body( $context, $input, $account );
			if ( is_wp_error( $body ) ) {
				return $this->wp_error( $body );
			}

			++$session->intent_count;
			$this->store->save( $session );

			$response = $this->api->create_intent( $slug, $body );
			if ( ! $response->is_success() ) {
				Logger::log( 'intent.failed', [ 'slug' => $slug, 'status' => $response->status, 'message' => $response->message ] );
				return $this->pass( $response );
			}

			$data         = $response->data_array();
			$intent_acct  = Provider_Config::account_id( Provider_Config::clean( $data['payment_provider_detail_id'] ?? null ) );
			$reference    = $provider->intent_reference( $data );
			$session->add_attempt( $slug, $intent_acct ?? $account, $reference );
			$this->store->save( $session );
			if ( $reference !== null ) {
				$this->store->remember_reference( $slug, $reference, $token );
			}

			return $this->pass( $response, $provider->browser_intent_data( $data ) );
		} finally {
			$this->store->unlock( $token );
		}
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function confirm( string $token, string $slug, array $input ): array {
		return $this->settle( $token, $slug, $input, 'confirm' );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function charge_token( string $token, string $slug, array $input ): array {
		return $this->settle( $token, $slug, $input, 'charge_token' );
	}

	/**
	 * 3D Secure, step 1 of a card payment the API runs in one call (Three_Ds_Provider, Evervault; BACKEND-CONTRACTS.md
	 * E3). The payment's own body goes to the API's 3ds-session route; nothing is charged. The browser gets:
	 *   { three_ds: "skipped", reason }   pay without a session (the API's 3DS switch is off, nothing to authenticate,
	 *                                     3DS could not start, or this API has no 3ds-session route yet)
	 *   { three_ds: "evervault", three_ds_session_id, status }  the bank's window first when status is
	 *                                     "action-required", then pay naming the session
	 *   an error with data.stage intent   refused (no price, no account, bad body …), nothing charged
	 * The session is kept with this checkout: the next payment may name only it, with the same payment data, once.
	 *
	 * @param array<string,mixed> $input Browser body: the payment's fields.
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function three_ds_session( string $token, string $slug, array $input ): array {
		if ( ! $this->store->lock( $token, 3.0 ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ), [ 'stage' => 'confirm' ] );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			Flow_Log::quiz( (string) ( $session->context['quiz_result_id'] ?? '' ) );
			if ( $session->is_paid() ) {
				return $this->paid_response( $session, null );
			}

			// The payment itself must be allowed; 3DS is optional, so a provider or API without the step pays without it
			$check = $this->check_provider( $session, $slug, 'pay' );
			if ( $check ) {
				$check['body']['data']['stage'] = 'intent';
				return $check;
			}
			$provider = $this->registry->get( $slug );
			if ( ! $provider instanceof Three_Ds_Provider || ! in_array( 'three_ds_session', $provider->operations(), true ) || ! $this->api->supports( $slug, 'three_ds_session' ) ) {
				return $this->ok( [ 'three_ds' => 'skipped', 'reason' => 'NOT_SUPPORTED' ] );
			}

			$max = (int) apply_filters( 'cellon_payment_collection_max_intents', 25 );
			if ( $session->intent_count >= $max ) {
				return $this->error( 429, 'too_many_attempts', __( 'Too many payment attempts. Please reload the page.', 'cellon-payment-collection' ), [ 'stage' => 'intent' ] );
			}

			$gate = $this->resolve_unclear( $session );
			if ( $gate ) {
				$gate['body']['data']['stage'] = 'confirm';
				return $gate;
			}

			$account = $this->account( $input );
			if ( ! $session->account_allowed( $slug, $account ) ) {
				return $this->error( 422, 'invalid_payment_account', __( 'This payment method is not available. Please reload the page.', 'cellon-payment-collection' ), [ 'stage' => 'intent' ] );
			}

			$plan = $this->apply_price_category( $session, $input );
			if ( is_wp_error( $plan ) ) {
				return $this->wp_error( $plan, 'intent' );
			}

			$body = $provider->three_ds_body( $session->context(), $input, $account );
			if ( is_wp_error( $body ) ) {
				return $this->wp_error( $body, 'intent' );
			}

			$session->three_ds = [];
			$this->store->save( $session );

			$response = $this->api->three_ds_session( $slug, $body );
			if ( ! $response->is_success() ) {
				// An API without the route (404 and no code): 3DS is not there yet, the payment goes on as before
				if ( $response->status === 404 && $response->code === null ) {
					Logger::log( 'three_ds.route_missing', [ 'slug' => $slug ] );
					return $this->ok( [ 'three_ds' => 'skipped', 'reason' => 'ROUTE_NOT_AVAILABLE' ] );
				}
				Logger::log( 'three_ds.failed', [ 'slug' => $slug, 'status' => $response->status, 'code' => $response->code ] );
				$out                          = $this->pass( $response );
				$out['body']['data']          = (array) ( $out['body']['data'] ?? [] );
				$out['body']['data']['stage'] = 'intent';
				return $out;
			}

			$answer = $provider->three_ds_answer( $response->data_array() );
			if ( $answer['three_ds'] === 'evervault' ) {
				$session->three_ds = [
					'session_id' => $answer['three_ds_session_id'],
					'body'       => self::fingerprint( $body ),
					'created'    => time(),
				];
				$this->store->save( $session );
			}
			return $this->ok( $answer );
		} finally {
			$this->store->unlock( $token );
		}
	}

	/**
	 * A payment the API runs in one call (One_Call_Provider, e.g. Evervault): the browser sent what the provider's
	 * form gave (the encrypted card, or a wallet's token) once; the API charges it, trying its own processors in
	 * turn, and answers with the outcome. That may take long, so the call has the provider's time limit (none by
	 * default): PHP waits for the answer, keeps going when the browser leaves (the outcome is recorded either way and
	 * the browser can ask /status), and the checkout stays locked meanwhile.
	 *
	 * The browser gets: paid (with the redirect), or an error whose data.stage is intent (nothing charged; also a 3DS
	 * session the API cannot use, THREE_DS_*), payment (declined; data.code / data.decline_code, PAYMENT_3DS_FAILED)
	 * or confirm (unknown: verify first). With three_ds_session_id it must be the session three_ds_session() started
	 * last for this same body.
	 *
	 * @param array<string,mixed> $input
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function pay( string $token, string $slug, array $input ): array {
		if ( ! $this->store->lock( $token ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ), [ 'stage' => 'confirm' ] );
		}
		// A PHP fatal error or time limit skips `finally`: the lock is released at shutdown instead (only if
		// `finally` did not run, so another request's lock is never removed)
		$released = false;
		register_shutdown_function(
			function () use ( &$released, $token ) {
				if ( ! $released ) {
					$this->store->unlock( $token );
				}
			}
		);
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			Flow_Log::quiz( (string) ( $session->context['quiz_result_id'] ?? '' ) );
			if ( $session->is_paid() ) {
				return $this->paid_response( $session, null );
			}

			$check = $this->check_provider( $session, $slug, 'pay' );
			if ( $check ) {
				return $check;
			}
			$provider = $this->registry->get( $slug );
			if ( ! $provider instanceof One_Call_Provider ) {
				return $this->error( 404, 'operation_not_supported', __( 'This payment method is not available.', 'cellon-payment-collection' ), [ 'stage' => 'intent' ] );
			}

			$max = (int) apply_filters( 'cellon_payment_collection_max_intents', 25 );
			if ( $session->intent_count >= $max ) {
				return $this->error( 429, 'too_many_attempts', __( 'Too many payment attempts. Please reload the page.', 'cellon-payment-collection' ), [ 'stage' => 'intent' ] );
			}

			$gate = $this->resolve_unclear( $session );
			if ( $gate ) {
				$gate['body']['data']['stage'] = 'confirm';
				return $gate;
			}

			$account = $this->account( $input );
			if ( ! $session->account_allowed( $slug, $account ) ) {
				return $this->error( 422, 'invalid_payment_account', __( 'This payment method is not available. Please reload the page.', 'cellon-payment-collection' ), [ 'stage' => 'intent' ] );
			}

			$plan = $this->apply_price_category( $session, $input );
			if ( is_wp_error( $plan ) ) {
				return $this->wp_error( $plan, 'intent' );
			}

			$context = $session->context();
			$body    = $provider->build_intent_body( $context, $input, $account );
			if ( is_wp_error( $body ) ) {
				return $this->wp_error( $body, 'intent' );
			}

			// A 3DS session is used by one payment only: the one this checkout started last, for this same body
			$three_ds          = $this->three_ds_check( $session, $body );
			$session->three_ds = [];
			if ( is_wp_error( $three_ds ) ) {
				$this->store->save( $session );
				return $this->wp_error( $three_ds, 'intent' );
			}

			// From here money may move: the attempt is "confirming" until the API answers, so a request that never
			// finishes leaves it unresolved and the next payment of this checkout checks the quiz result first
			++$session->intent_count;
			$session->add_attempt( $slug, $account, null );
			$index = count( $session->attempts ) - 1;
			$session->set_status( $index, 'confirming' );
			$this->store->save( $session );

			ignore_user_abort( true );
			$this->store->hold_lock( $token, $this->pay_lock_seconds() );

			$response = $this->api->pay( $slug, $body, $provider->pay_timeout() );

			if ( $response->is_success() ) {
				$data   = $response->data_array();
				$action = $provider->pay_action( $data );
				if ( is_wp_error( $action ) ) {
					$session->set_status( $index, 'failed' );
					$this->store->save( $session );
					Logger::error( 'pay.unsupported_action', [ 'slug' => $slug, 'next_action' => $data['next_action'] ?? null ] );
					return $this->wp_error( $action, 'intent' );
				}
				if ( $action !== null ) {
					// Nothing charged yet: the customer authenticates (3DS), then pays again naming that step
					$session->set_status( $index, 'charged' );
					$this->store->save( $session );
					return $this->ok( $action );
				}
				$session->attempts[ $index ]['reference'] = $provider->intent_reference( $data );
				$session->set_status( $index, 'succeeded' );
				$data['reference']       = $session->attempts[ $index ]['reference'];
				$data['collected_email'] = '';
				$this->succeed( $session, $context, $slug, $data );
				return $this->paid_response( $session, $data );
			}

			if ( $response->is_outcome_unknown() ) {
				$status = 'unknown';   // 504 PAYMENT_RESULT_UNKNOWN, 5xx or no answer: never pay again before a check
				$stage  = 'confirm';
			} elseif ( $response->status === 402 ) {
				$status = 'failed';    // declined by every processor the API tried: nothing was charged
				$stage  = 'payment';
			} else {
				$status = 'rejected';  // refused before any charge (validation, account …); checked before the next one
				$stage  = 'intent';
			}
			$session->set_status( $index, $status );
			$this->store->save( $session );
			Logger::log( 'pay.failed', [ 'slug' => $slug, 'status' => $response->status, 'code' => $response->code, 'stage' => $stage ] );

			$out                          = $this->pass( $response );
			$out['body']['data']          = (array) ( $out['body']['data'] ?? [] );
			$out['body']['data']['stage'] = $stage;
			$decline                      = $response->data_array()['decline_code'] ?? null;
			if ( is_scalar( $decline ) && preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', (string) $decline ) ) {
				$out['body']['data']['decline_code'] = (string) $decline;
			}
			return $out;
		} finally {
			$this->store->unlock( $token );
			$released = true;
		}
	}

	/**
	 * How long the lock of a one-call payment may look busy before it counts as left over from a killed process.
	 * Not a payment time limit: PHP releases the lock as soon as the API answers, and at shutdown after a fatal error.
	 */
	private function pay_lock_seconds(): int {
		return max( Session_Store::LOCK_STALE, (int) apply_filters( 'cellon_payment_collection_pay_lock_seconds', 30 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Confirm / token charge: the steps where money moves.
	 *
	 * @param array<string,mixed> $input
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function settle( string $token, string $slug, array $input, string $operation ): array {
		if ( ! $this->store->lock( $token ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ) );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			Flow_Log::quiz( (string) ( $session->context['quiz_result_id'] ?? '' ) );
			if ( $session->is_paid() ) {
				return $this->paid_response( $session, null );
			}

			$check = $this->check_provider( $session, $slug, $operation );
			if ( $check ) {
				return $check;
			}

			$provider  = $this->registry->get( $slug );
			$reference = $provider->confirm_reference( $input );
			if ( $reference !== null && $session->has_references( $slug ) ) {
				$index = $session->find_attempt( $slug, $reference );
			} else {
				$index = $session->find_attempt( $slug, null );
			}
			if ( $index === null ) {
				return $this->error( 409, 'unknown_payment', __( 'This payment does not belong to this checkout. Please reload the page.', 'cellon-payment-collection' ) );
			}

			$account = $this->account( $input );
			if ( ! $session->account_allowed( $slug, $account ) ) {
				return $this->error( 422, 'invalid_payment_account', __( 'This payment method is not available. Please reload the page.', 'cellon-payment-collection' ) );
			}

			$context = $session->context();
			$body    = $operation === 'confirm'
				? $provider->build_confirm_body( $context, $input, $account )
				: $provider->build_charge_token_body( $context, $input, $account );
			if ( is_wp_error( $body ) ) {
				return $this->wp_error( $body );
			}

			$session->set_status( $index, 'confirming' );
			$this->store->save( $session );

			$response = $operation === 'confirm' ? $this->api->confirm( $slug, $body ) : $this->api->charge_token( $slug, $body );

			if ( $response->is_success() ) {
				$data = $response->data_array();
				// A token charge can answer 200 with a decline or a 3DS step; only a confirm records the sale.
				if ( $operation === 'charge_token' ) {
					// Approved or waiting for 3DS: the confirm that follows records it. Until then another
					// provider first checks whether this charge was recorded.
					$session->set_status( $index, 'charged' );
					$this->store->save( $session );
					return $this->pass( $response, $data );
				}
				$session->set_status( $index, 'succeeded' );
				$data['reference']       = $session->attempts[ $index ]['reference'];
				// An email the provider's own form collected (dummy-email checkouts only)
				$data['collected_email'] = empty( $context->site['collect_email'] ) ? '' : $provider->collected_email( $input );
				$this->succeed( $session, $context, $slug, $data );
				return $this->paid_response( $session, $data );
			}

			if ( $response->is_outcome_unknown() ) {
				$status = 'unknown';
			} elseif ( $response->status === 409 ) {
				$status = 'pending'; // e.g. BridgerPay still processing: the browser asks again
			} else {
				$status = 'rejected';
			}
			$session->set_status( $index, $status );
			$this->store->save( $session );
			Logger::log( 'confirm.failed', [ 'slug' => $slug, 'operation' => $operation, 'status' => $response->status, 'attempt' => $status, 'message' => $response->message ] );

			return $this->pass( $response );
		} finally {
			$this->store->unlock( $token );
		}
	}

	/**
	 * The browser core's verifyPayment(): true = paid, false = not paid (customer may pay again),
	 * null = still unknown.
	 *
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function status( string $token ): array {
		$session = $this->store->load( $token );
		if ( ! $session ) {
			return $this->expired();
		}
		if ( $session->is_paid() ) {
			return $this->paid_response( $session, null );
		}
		if ( ! $this->store->lock( $token, 3.0 ) ) {
			return $this->ok( [ 'paid' => null ] );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			$paid = $this->check_paid( $session );
			if ( $paid === true ) {
				return $this->record_paid_from_status( $session );
			}

			$index = $session->unresolved_attempt();
			if ( $index === null ) {
				return $this->ok( [ 'paid' => $paid === false ? false : null ] );
			}
			if ( $paid === false && $this->may_release( $session->attempts[ $index ] ) ) {
				$session->set_status( $index, 'failed' );
				$this->store->save( $session );
				return $this->ok( [ 'paid' => false ] );
			}
			return $this->ok( [ 'paid' => null ] );
		} finally {
			$this->store->unlock( $token );
		}
	}

	/* -------------------------------------------------------- customer email */

	/**
	 * Dummy-email checkouts: after the first sale, save the address the customer typed (or the provider's own
	 * form collected) on the customer. Replaces the Core plugin's `submit_email` admin-ajax call with the same
	 * API call (Customer_Api), so it no longer lives in two places. Stricter than that handler: the quiz result
	 * comes from the checkout session (never from the browser), and only a paid first-sale checkout that
	 * collects the email may call it. A repeat with the address already saved answers "saved" without a
	 * second API call.
	 *
	 * @param mixed $email Browser value.
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function collect_email( string $token, $email ): array {
		$email = is_string( $email ) ? sanitize_email( trim( $email ) ) : '';
		if ( $email === '' || ! is_email( $email ) ) {
			return $this->error( 422, 'invalid_email', __( 'A valid email address is required.', 'cellon-payment-collection' ) );
		}
		if ( ! $this->store->lock( $token, 3.0 ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ) );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			$context = $session->context();
			Flow_Log::begin( (string) ( $session->result['gateway'] ?? '' ), 'email', $token, [ 'email' => $email ] );
			Flow_Log::quiz( (string) $context->quiz_result_id );

			if ( $context->flow !== 'first-sale' || empty( $context->site['collect_email'] ) ) {
				return $this->error( 403, 'email_not_collected', __( 'This checkout does not collect an email.', 'cellon-payment-collection' ) );
			}
			if ( ! $session->is_paid() ) {
				return $this->error( 409, 'not_paid', __( 'The email is saved after the payment.', 'cellon-payment-collection' ) );
			}
			if ( $session->email_saved !== '' && strcasecmp( $session->email_saved, $email ) === 0 ) {
				return $this->ok( [ 'saved' => true ] );
			}

			// As the Core plugin's handler, before the API call: the Customer Details page reads it to know the
			// customer is no longer a dummy-email one.
			if ( session_status() === PHP_SESSION_ACTIVE ) {
				$_SESSION['user_submitted_email'] = $email;
			}

			$plan     = $context->extra['ab_testing_price_category'] ?? null;
			$response = $this->customers->update_email( $context->quiz_result_id, $email, is_scalar( $plan ) ? (string) $plan : null );
			if ( ! $response->is_success() ) {
				Logger::log( 'email.failed', [ 'status' => $response->status, 'message' => $response->message ] );
				return $this->pass( $response );
			}

			$session->email_saved = $email;
			$this->store->save( $session );
			return $this->ok( [ 'saved' => true ] );
		} finally {
			$this->store->unlock( $token );
		}
	}

	/* ------------------------------------------------------------ cross sale */

	/**
	 * Offer page: accept charges the cross sale on the first sale's saved method (API decides the gateway);
	 * skip moves on. Mirrors the Core plugin's cross_sale_payment handler, including `skip_offer`.
	 *
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function cross_sale( string $token, string $action ): array {
		if ( ! $this->store->lock( $token ) ) {
			return $this->error( 409, 'payment_in_progress', __( 'A payment is being processed. Please wait.', 'cellon-payment-collection' ) );
		}
		try {
			$session = $this->store->load( $token );
			if ( ! $session ) {
				return $this->expired();
			}
			if ( $session->is_paid() ) {
				return $this->paid_response( $session, null );
			}
			$context = $session->context();
			if ( $context->flow !== 'cross-sale' ) {
				return $this->error( 400, 'wrong_flow', 'Not a cross-sale checkout.' );
			}
			// As the Core plugin's cross-sale handler: a skipped or declined offer goes to customer details.
			$after = $context->redirect_url( 'CUSTOMER_DETAILS_PAGE' );

			if ( $action === 'skip' ) {
				$this->skip_offer();
				return $this->ok( [ 'skipped' => true, 'redirect_url' => $after ] );
			}

			$gate = $this->resolve_unclear( $session );
			if ( $gate ) {
				return $gate;
			}

			$index = count( $session->attempts );
			$session->add_attempt( 'cross_sale', null, null );
			$session->set_status( $index, 'confirming' );
			$this->store->save( $session );

			$response = $this->api->confirm_cross_sale( (array) apply_filters( 'cellon_payment_collection_cross_sale_body', $context->payment_fields( false ), $context ) );

			if ( $response->is_success() ) {
				$data = $response->data_array();
				if ( empty( $data['redirect_page'] ) ) {
					$data['redirect_page'] = 'CUSTOMER_DETAILS_PAGE';
				}
				$session->set_status( $index, 'succeeded' );
				$this->succeed( $session, $context, 'cross_sale', $data );
				return $this->paid_response( $session, $data );
			}

			if ( $response->is_outcome_unknown() ) {
				$session->set_status( $index, 'unknown' );
				$this->store->save( $session );
				return $this->pass( $response );
			}

			// The API declined it: same as today, move on to customer details without the offer.
			$session->set_status( $index, 'failed' );
			$this->store->save( $session );
			$this->skip_offer();
			$out                          = $this->pass( $response );
			$out['body']['data']          = (array) ( $out['body']['data'] ?? [] );
			$out['body']['data']['redirect_url'] = $after;
			return $out;
		} finally {
			$this->store->unlock( $token );
		}
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Before any new attempt: settle an earlier attempt whose outcome is unclear.
	 *
	 * @return array{status:int, body:array<string,mixed>}|null null = go ahead
	 */
	private function resolve_unclear( Checkout_Session $session ): ?array {
		$index = $session->unresolved_attempt();
		if ( $index === null ) {
			return null;
		}
		// The caller holds the checkout lock, so a "confirming" attempt here is one whose PHP request died.
		$attempt = $session->attempts[ $index ];

		$paid = $this->check_paid( $session );
		if ( $paid === true ) {
			return $this->record_paid_from_status( $session );
		}
		if ( $paid === false && $this->may_release( $attempt ) ) {
			$session->set_status( $index, 'failed' );
			$this->store->save( $session );
			return null;
		}

		return $this->error(
			409,
			'verify_pending',
			__( 'We are checking the status of your last payment. Please do not pay again yet.', 'cellon-payment-collection' ),
			[ 'retry_after' => $this->grace() ]
		);
	}

	/**
	 * A not-recorded attempt may be released when the API gave a clear error (rejected) or the grace
	 * period after an unknown outcome has passed.
	 *
	 * @param array<string,mixed> $attempt
	 */
	private function may_release( array $attempt ): bool {
		if ( in_array( $attempt['status'], [ 'rejected', 'charged' ], true ) ) {
			return true;
		}
		return time() - (int) $attempt['updated'] >= $this->grace();
	}

	private function grace(): int {
		return max( 0, (int) apply_filters( 'cellon_payment_collection_unknown_grace_seconds', 90 ) );
	}

	/**
	 * Did the API record the sale? Asks for the quiz result: past the payment page = paid.
	 * true = paid, false = not recorded, null = could not tell.
	 */
	private function check_paid( Checkout_Session $session ): ?bool {
		$context  = $session->context();
		$response = $this->api->fetch_quiz_result( [
			'quiz_result_id'   => $context->quiz_result_id,
			'prc_id'           => $context->extra['prc_id'] ?? '',
			'pricing_discount' => $context->extra['pricing_discount'] ?? '',
		] );
		if ( ! $response->is_success() ) {
			return null;
		}
		$data  = $response->data_array();
		$page  = isset( $data['redirect_page'] ) ? (string) $data['redirect_page'] : '';
		$this->last_redirect_page = $page;
		$still = $context->flow === 'cross-sale' ? [ 'INITIAL_PAYMENT_PAGE', 'CROSS_SELL_OFFER_PAGE' ] : [ 'INITIAL_PAYMENT_PAGE' ];
		$paid  = $page === '' ? null : ! in_array( $page, $still, true );

		/**
		 * Override how a quiz result is read as paid.
		 *
		 * @param bool|null        $paid
		 * @param array            $data    Quiz result data.
		 * @param Checkout_Context $context
		 */
		$paid = apply_filters( 'cellon_payment_collection_is_paid', $paid, $data, $context );
		return $paid === null ? null : (bool) $paid;
	}

	/** @return array{status:int, body:array<string,mixed>} */
	private function record_paid_from_status( Checkout_Session $session ): array {
		$index = $session->unresolved_attempt();
		if ( $index !== null ) {
			$session->set_status( $index, 'succeeded' );
		}
		$context = $session->context();
		$slug    = $index !== null ? (string) $session->attempts[ $index ]['slug'] : '';
		// The analytics event already fired, or will fire, on the request whose confirm recorded the sale.
		$this->succeed( $session, $context, $slug, [ 'redirect_page' => $this->last_redirect_page ], false );
		return $this->paid_response( $session, null );
	}

	/**
	 * @param array<string,mixed> $data API confirm data.
	 */
	private function succeed( Checkout_Session $session, Checkout_Context $context, string $slug, array $data, bool $fire_ab = true ): void {
		$tag = isset( $data['redirect_page'] ) ? (string) $data['redirect_page'] : '';
		$session->mark_paid( [
			'gateway'      => $slug,
			'redirect_tag' => $tag,
			'redirect_url' => $context->redirect_url( $tag ),
		] );
		$this->store->save( $session );

		// Same analytics hook the Core plugin's payment handlers fire.
		$utm = ( session_status() === PHP_SESSION_ACTIVE && isset( $_SESSION['ab_utm_source'] ) ) ? sanitize_text_field( (string) $_SESSION['ab_utm_source'] ) : '';
		$site_fires = array_key_exists( 'fire_ab_event', $context->site ) ? (bool) $context->site['fire_ab_event'] : true;
		if ( $fire_ab && $utm !== 'Emails' && apply_filters( 'cellon_payment_collection_fire_ab_event', $site_fires, $context ) ) {
			do_action( 'ab_testing_main_total', (string) $context->quiz_result_id, (float) ( $data['first_sale_usd_price'] ?? 0 ), (float) ( $data['cross_sale_usd_price'] ?? 0 ) );
		}

		/**
		 * A payment was recorded by the API.
		 *
		 * @param string           $slug     Provider slug, or "cross_sale".
		 * @param array            $data     API confirm data.
		 * @param Checkout_Context $context
		 */
		do_action( 'cellon_payment_collection_payment_succeeded', $slug, $data, $context );
	}

	private function skip_offer(): void {
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			$_SESSION['skip_offer'] = true;
		}
	}

	/**
	 * The plan the buyer picked on a multi-plan page (e.g. wwiqtest quiz-checkout-v3: trial = '', or a
	 * pricing_info.price_list category such as "regular"), sent as ab_testing_price_category with an intent.
	 * Only an intent may change it, and only to '' or a category of this quiz result's price_list (the API
	 * charges that category's prices). It is kept on the checkout, so the confirm / charge-token / cross-sale
	 * bodies name the same plan, and in $_SESSION['ab_testing_price_category'] as the Core plugin's intent
	 * handlers store it, so the Core plugin's cross sale, customer email update and Offers page use it too.
	 * Without the key the plan stays as rendered (the session's category, usually none).
	 *
	 * @param array<string,mixed> $input
	 * @return true|\WP_Error
	 */
	private function apply_price_category( Checkout_Session $session, array $input ) {
		if ( ! array_key_exists( 'ab_testing_price_category', $input ) ) {
			return true;
		}
		$category = is_string( $input['ab_testing_price_category'] ) ? trim( $input['ab_testing_price_category'] ) : null;
		$context  = $session->context();
		if ( $category === null || ! $context->price_category_allowed( $category ) ) {
			Logger::log( 'intent.price_category_rejected', [ 'category' => is_scalar( $input['ab_testing_price_category'] ) ? substr( (string) $input['ab_testing_price_category'], 0, 64 ) : gettype( $input['ab_testing_price_category'] ) ] );
			return new \WP_Error(
				'invalid_field',
				/* translators: %s: field name */
				sprintf( __( 'Invalid payment data (%s).', 'cellon-payment-collection' ), 'ab_testing_price_category' ),
				[ 'status' => 422, 'field' => 'ab_testing_price_category', 'step' => 'intent' ]
			);
		}
		$context->set_price_category( $category );
		$session->context = $context->to_array();
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			$_SESSION['ab_testing_price_category'] = $category;
		}
		return true;
	}

	/** @return array{status:int, body:array<string,mixed>}|null */
	private function check_provider( Checkout_Session $session, string $slug, string $operation ): ?array {
		$provider = $this->registry->get( $slug );
		if ( ! $provider instanceof Provider || ! $this->registry->is_enabled( $slug ) || ! in_array( $slug, $session->allowed_slugs(), true ) ) {
			return $this->error( 422, 'provider_not_allowed', __( 'This payment method is not available.', 'cellon-payment-collection' ) );
		}
		if ( ! in_array( $operation, $provider->operations(), true ) || ! $this->api->supports( $slug, $operation ) ) {
			return $this->error( 404, 'operation_not_supported', __( 'This payment method is not available.', 'cellon-payment-collection' ) );
		}
		return null;
	}

	/**
	 * A payment that names a 3DS session must name the one this checkout started last, for the same body (same card,
	 * price fields and account): one session per Pay press, never reused, the card and price unchanged in between.
	 *
	 * @param array<string,mixed> $body
	 */
	private function three_ds_check( Checkout_Session $session, array $body ): ?\WP_Error {
		$named = (string) ( $body['three_ds_session_id'] ?? '' );
		if ( $named === '' ) {
			return null;
		}
		$started = is_array( $session->three_ds ) ? $session->three_ds : [];
		if ( (string) ( $started['session_id'] ?? '' ) === '' || ! hash_equals( (string) $started['session_id'], $named )
			|| ! hash_equals( (string) ( $started['body'] ?? '' ), self::fingerprint( $body ) ) ) {
			return new \WP_Error( 'three_ds_session_mismatch', __( 'Your payment could not be processed. Please try again.', 'cellon-payment-collection' ), [ 'status' => 422 ] );
		}
		return null;
	}

	/**
	 * The payment body without its 3DS session id: what the session was started for.
	 *
	 * @param array<string,mixed> $body
	 */
	private static function fingerprint( array $body ): string {
		unset( $body['three_ds_session_id'] );
		return hash( 'sha256', (string) wp_json_encode( $body ) );
	}

	/** @param array<string,mixed> $input */
	private function account( array $input ): ?int {
		return Provider_Config::account_id( Provider_Config::clean( $input['payment_provider_detail_id'] ?? null ) );
	}

	/**
	 * @param array<string,mixed>|null $data
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function paid_response( Checkout_Session $session, ?array $data ): array {
		$data                 = is_array( $data ) ? $data : [];
		$data['paid']         = true;
		$data['redirect_url'] = (string) ( $session->result['redirect_url'] ?? '' );
		$data['gateway']      = (string) ( $session->result['gateway'] ?? '' );
		$data['message']      = (string) ( $session->context['site']['success_message'] ?? '' );
		return $this->ok( (array) apply_filters( 'cellon_payment_collection_success_response', $data, $session ) );
	}

	/** @return array{status:int, body:array<string,mixed>} */
	private function already_paid( Checkout_Session $session ): array {
		// The browser core reads "already exists" as "first sale recorded" and verifies → paid.
		$out = $this->error( 409, 'already_paid', __( 'First sale transaction already exists.', 'cellon-payment-collection' ) );
		$out['body']['data']['paid']         = true;
		$out['body']['data']['redirect_url'] = (string) ( $session->result['redirect_url'] ?? '' );
		return $out;
	}

	/**
	 * @param array<string,mixed>|null $data
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function pass( Api_Response $response, ?array $data = null ): array {
		$body = $response->to_browser( $response->is_success() ? $data : null );
		return [ 'status' => (int) $body['meta']['status'], 'body' => $body ];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function ok( array $data ): array {
		return $this->pass( new Api_Response( 200, true, '', $data ), $data );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function error( int $status, string $code, string $message, array $extra = [] ): array {
		$out = $this->pass( Api_Response::local_error( $status, $message, $code ) );
		$out['body']['data'] = array_merge( (array) $out['body']['data'], $extra );
		return $out;
	}

	/**
	 * @param string $stage pay(): the stage the browser reads ('' = none).
	 * @return array{status:int, body:array<string,mixed>}
	 */
	private function wp_error( \WP_Error $error, string $stage = '' ): array {
		$data = (array) $error->get_error_data();
		return $this->error( (int) ( $data['status'] ?? 422 ), (string) $error->get_error_code(), $error->get_error_message(), $stage !== '' ? [ 'stage' => $stage ] : [] );
	}

	/** @return array{status:int, body:array<string,mixed>} */
	private function expired(): array {
		return $this->error( 410, 'checkout_expired', __( 'This checkout has expired. Please reload the page.', 'cellon-payment-collection' ) );
	}
}
