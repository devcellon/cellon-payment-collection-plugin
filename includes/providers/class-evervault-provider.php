<?php
/**
 * Evervault: card form, Apple Pay and Google Pay from Evervault's own UI Components; the API runs the payment in one call.
 *
 * The card number and CVC are typed into Evervault's iframe and reach this site only as `ev:` ciphertext; Apple Pay
 * and Google Pay give Evervault's encrypted network token (or, Google Pay PAN_ONLY, the encrypted card). Nothing here
 * names a processor: the Evervault account behind payment_provider_detail_id decides which processors it uses and in
 * which order, so a processor added to that account later needs no change here.
 *
 * The payment is ONE call (Payment_Orchestrator::pay(), Payment_Api::pay()):
 *   POST /payment/first-sale/payments/initiate  { payment_provider_slug: "evervault", payment_provider_detail_id,
 *        email, quiz_result_id, user_on_iqbooster, prc_id, pricing_discount, card | wallet [, three_ds_session_id] }
 *   200 = paid (the confirm's answer: redirect_page, cross_sale …)   402 = declined (data.code, data.decline_code;
 *   PAYMENT_3DS_FAILED: the bank did not confirm)   409 / 422 THREE_DS_*: the 3DS session cannot be used, start again
 *   504 = outcome unknown (PAYMENT_RESULT_UNKNOWN: never pay again before it is checked)
 * The API tries the account's processors in turn, so the call has no time limit by default (pay_timeout()).
 *
 * 3D Secure (Three_Ds_Provider, BACKEND-CONTRACTS.md E3), for the card form's card: before the payment the browser
 * asks for a 3DS session (POST /payment/first-sale/payments/3ds-session, same body). "skipped" = pay without one;
 * "evervault" = a session (tds_…), with the bank's window (evervault.ui.threeDSecure) first when its status is
 * "action-required"; the payment then names it. The API's switch decides whether 3DS runs, so it never needs a release
 * here. Apple Pay / Google Pay pay without this step.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Providers;

use Cellon\PaymentCollection\Checkout\Checkout_Context;
use Cellon\PaymentCollection\Site\Email_Texts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evervault_Provider extends Abstract_Provider implements One_Call_Provider, Three_Ds_Provider {

	/**
	 * Evervault ciphertext as the API validates it: ev:<version>[:<type>]:<iv>:<public key>:<ciphertext>:$. A
	 * digits-only value such as ev:4242…:$ is refused, so a plain card number never passes as encrypted.
	 */
	const CIPHERTEXT = '/^ev:(?![\d:]+\$\z)[A-Za-z0-9]{4}(?::[a-z]+)?(?::[A-Za-z0-9+\/]{12,}={0,2}){3,4}:\$\z/';

	const CIPHERTEXT_MAX = 4000;

	/** A wallet cryptogram Evervault left unencrypted (base64). */
	const CRYPTOGRAM = '/^[A-Za-z0-9+\/]{8,200}={0,2}$/';

	const THREE_DS_SESSION = '/^tds_[A-Za-z0-9_-]{1,120}$/';

	const MERCHANT_ID = '/^merchant_[A-Za-z0-9_-]{1,64}$/';

	/** Currencies whose smallest unit is the currency itself (Apple Pay / Google Pay take amounts in it). */
	const ZERO_DECIMAL = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];

	const THREE_DECIMAL = [ 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ];

	/** Card form labels and placeholders per language (Email_Texts keys); other languages get English. */
	const TEXTS = [
		'en' => [
			'number' => [ 'Card number', '1234 1234 1234 1234' ],
			'expiry' => [ 'Expiry', 'MM / YY' ],
			'cvc'    => [ 'CVC', 'CVC' ],
		],
		'ja' => [
			'number' => [ 'カード番号', '1234 1234 1234 1234' ],
			'expiry' => [ '有効期限', 'MM / YY' ],
			'cvc'    => [ 'セキュリティコード', 'CVC' ],
		],
		'ko' => [
			'number' => [ '카드 번호', '1234 1234 1234 1234' ],
			'expiry' => [ '유효기간', 'MM / YY' ],
			'cvc'    => [ '보안 코드', 'CVC' ],
		],
	];

	public function slug(): string {
		return 'evervault';
	}

	public function label(): string {
		return 'Evervault';
	}

	/** The 3DS session (card form only, nothing charged), then the payment: the API runs it in that one call. */
	public function operations(): array {
		return [ 'three_ds_session', 'pay' ];
	}

	/** What the browser may send with its one payment call (the context fields come from the session). */
	protected function intent_fields(): array {
		return [
			'card'                => [ 'type' => 'evervault_card' ],
			'wallet'              => [ 'type' => 'evervault_wallet' ],
			// The 3DS session this checkout started for this payment (three_ds_session())
			'three_ds_session_id' => [ 'type' => 'string', 'max' => 128, 'pattern' => self::THREE_DS_SESSION ],
		];
	}

	/** The one call's body: exactly one of card (the card form) and wallet (Apple Pay / Google Pay). */
	public function build_intent_body( Checkout_Context $context, array $input, ?int $account_id ) {
		$body = parent::build_intent_body( $context, $input, $account_id );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( isset( $body['card'] ) === isset( $body['wallet'] ) ) {
			return $this->invalid( isset( $body['card'] ) ? 'wallet' : 'card', 'pay' );
		}
		return $body;
	}

	/** No limit unless a site sets one: the API may try several processors before it answers. */
	public function pay_timeout(): int {
		/**
		 * Seconds WordPress waits for the API's answer to an Evervault payment. 0 (default) = until it answers.
		 *
		 * @param int $seconds
		 */
		return max( 0, (int) apply_filters( 'cellon_payment_collection_evervault_timeout', 0 ) );
	}

	/**
	 * 200 = paid: 3DS comes before the payment (three_ds_session()), never in its answer. A next_action is a step this
	 * plugin cannot run (nothing was charged).
	 *
	 * @return null|\WP_Error
	 */
	public function pay_action( array $data ) {
		if ( ! array_key_exists( 'next_action', $data ) || $data['next_action'] === null ) {
			return null;
		}
		return new \WP_Error( 'unsupported_next_action', __( 'Your payment could not be processed.', 'cellon-payment-collection' ), [ 'status' => 502 ] );
	}

	/**
	 * The 3ds-session body: the payment's own body (same price fields, same card), card form only. A wallet's
	 * payment was authenticated in the wallet.
	 */
	public function three_ds_body( Checkout_Context $context, array $input, ?int $account_id ) {
		unset( $input['three_ds_session_id'], $input['wallet'] );
		$body = $this->build_intent_body( $context, $input, $account_id );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return isset( $body['card'] ) ? $body : $this->invalid( 'card', 'three_ds_session' );
	}

	/**
	 * Only a well-formed session (tds_…) is passed on; anything else counts as "skipped", and the payment goes on
	 * without a session (the API decides).
	 */
	public function three_ds_answer( array $data ): array {
		$session = is_string( $data['three_ds_session_id'] ?? null ) ? $data['three_ds_session_id'] : '';
		if ( ( $data['three_ds'] ?? '' ) === 'evervault' && preg_match( self::THREE_DS_SESSION, $session ) ) {
			$status = is_string( $data['status'] ?? null ) && preg_match( '/^[a-z-]{1,32}$/', $data['status'] ) ? $data['status'] : '';
			return [ 'three_ds' => 'evervault', 'three_ds_session_id' => $session, 'status' => $status ];
		}
		$reason = $data['reason'] ?? '';
		return [ 'three_ds' => 'skipped', 'reason' => is_string( $reason ) && preg_match( '/^[A-Z0-9_]{1,64}$/', $reason ) ? $reason : '' ];
	}

	/** The processor's reference of a paid answer, for analytics (first_sale_fronted). */
	public function intent_reference( array $data ): ?string {
		foreach ( [ 'transaction_id', 'payment_intent_id', 'charge_id', 'order_id' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && (string) $data[ $key ] !== '' ) {
				return (string) $data[ $key ];
			}
		}
		return null;
	}

	/**
	 * The card form's look and labels, and Evervault's Apple Pay / Google Pay: the page's wallet container, the amount
	 * in the currency's smallest unit, and the Evervault merchant (the account's merchant_id from the provider list,
	 * or the cellon_payment_collection_evervault_merchant_id filter). Nothing secret: Team ID, App ID and merchant
	 * id are public.
	 */
	public function browser_options( array $env ): array {
		$lang     = (array) ( $env['lang'] ?? [] );
		$language = Email_Texts::resolve( (string) ( $lang['locale'] ?? '' ) );
		$texts    = self::TEXTS[ $language ] ?? self::TEXTS['en'];

		$translations = [];
		foreach ( [ 'number', 'expiry', 'cvc' ] as $field ) {
			$translations[ $field ] = [ 'label' => $texts[ $field ][0], 'placeholder' => $texts[ $field ][1] ];
		}
		$options = [
			'theme'        => 'clean',
			'translations' => $translations,
			// Evervault's 3DS step: a modal over the page (options of evervault.ui.threeDSecure())
			'threeDSecure' => (object) [],
		];

		$ids     = (array) ( $env['ids'] ?? [] );
		$context = $env['context'] ?? null;
		$amount  = $context instanceof Checkout_Context ? self::minor_units( $context->amount, $context->currency ) : null;
		if ( ! empty( $ids['wallets'] ) && $amount !== null ) {
			// As tall as the PayPal button and Stripe's wallet next to it (55 px; iqpro 50 px)
			$size = [ 'width' => '100%', 'height' => ( (string) ( $env['site'] ?? '' ) === 'iqpro' ? 50 : 55 ) . 'px' ];
			/**
			 * Country of the Apple Pay / Google Pay transaction. Default: the checkout's country.
			 *
			 * @param string $country ISO 3166-1 alpha-2.
			 * @param array  $env
			 */
			$country = strtoupper( (string) apply_filters( 'cellon_payment_collection_evervault_wallet_country', (string) ( $env['country'] ?? '' ), $env ) );

			$options['wallets'] = [
				'containerId' => (string) $ids['wallets'],
				'merchantId'  => $this->merchant_id( $env ),
				'transaction' => [
					'amount'   => $amount,
					'currency' => $context->currency,
					'country'  => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'US',
				],
				'locale'      => strlen( $language ) === 2 ? $language : 'en',
				'applePay'    => [ 'type' => 'plain', 'style' => 'black', 'borderRadius' => 4, 'size' => $size ],
				'googlePay'   => [ 'type' => 'plain', 'color' => 'black', 'borderRadius' => 4, 'size' => $size, 'allowedAuthMethods' => [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ] ],
			];
		}
		return $options;
	}

	/** The Evervault merchant of this checkout's Evervault account (the provider list), else the filter's. */
	private function merchant_id( array $env ): string {
		$merchant = '';
		foreach ( (array) ( $env['entries'] ?? [] ) as $entry ) {
			if ( ( $entry['slug'] ?? '' ) === $this->slug() && ! empty( $entry['merchant_id'] ) ) {
				$merchant = (string) $entry['merchant_id'];
				break;
			}
		}
		/**
		 * Evervault merchant id (merchant_…) for Apple Pay / Google Pay. Default: the provider list's merchant_id.
		 * '' = no wallets.
		 *
		 * @param string $merchant
		 * @param array  $env
		 */
		$merchant = (string) apply_filters( 'cellon_payment_collection_evervault_merchant_id', $merchant, $env );
		return preg_match( self::MERCHANT_ID, $merchant ) ? $merchant : '';
	}

	/** @param mixed $amount Major units (e.g. 7.99). */
	private static function minor_units( $amount, string $currency ): ?int {
		if ( ! is_numeric( $amount ) || (float) $amount <= 0 || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return null;
		}
		$factor = in_array( $currency, self::ZERO_DECIMAL, true ) ? 1 : ( in_array( $currency, self::THREE_DECIMAL, true ) ? 1000 : 100 );
		return (int) round( (float) $amount * $factor );
	}

	/* ------------------------------------------------------------ payment data */

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $spec
	 * @return mixed|null
	 */
	protected function clean( $value, array $spec ) {
		switch ( $spec['type'] ?? '' ) {
			case 'evervault_card':
				return self::card( $value );
			case 'evervault_wallet':
				return self::wallet( $value );
		}
		return parent::clean( $value, $spec );
	}

	/**
	 * The card form's card exactly as the API accepts it: encrypted number and CVC, expiry month and year. Anything
	 * else is refused (null), so a plain card number never reaches the API or a log.
	 *
	 * @param mixed $value
	 * @return array{number:string, cvc:string, exp_month:int, exp_year:int}|null
	 */
	private static function card( $value ): ?array {
		if ( ! is_array( $value ) || ! self::encrypted( $value['number'] ?? null ) || ! self::encrypted( $value['cvc'] ?? null ) ) {
			return null;
		}
		$expiry = self::expiry( $value );
		return $expiry === null ? null : [ 'number' => $value['number'], 'cvc' => $value['cvc'] ] + $expiry;
	}

	/**
	 * Apple Pay / Google Pay from Evervault's components:
	 *   { type: apple_pay | google_pay,
	 *     network_token: { number (ev:), exp_month, exp_year, token_service_provider }, cryptogram, eci   (Apple Pay;
	 *       Google Pay CRYPTOGRAM_3DS)
	 *     | card: { number (ev:), exp_month, exp_year }                                                    (Google Pay
	 *       PAN_ONLY: the card itself, no CVC)
	 *     payment_data_type, brand, last_four (optional) }
	 *
	 * @param mixed $value
	 * @return array<string,mixed>|null
	 */
	private static function wallet( $value ): ?array {
		if ( ! is_array( $value ) || ! in_array( $value['type'] ?? '', [ 'apple_pay', 'google_pay' ], true ) ) {
			return null;
		}
		$out = [ 'type' => $value['type'] ];

		if ( isset( $value['network_token'] ) ) {
			$token  = $value['network_token'];
			$expiry = is_array( $token ) ? self::expiry( $token ) : null;
			if ( $expiry === null || ! self::encrypted( $token['number'] ?? null ) ) {
				return null;
			}
			$cryptogram = $value['cryptogram'] ?? null;
			if ( ! self::encrypted( $cryptogram ) && ! ( is_string( $cryptogram ) && preg_match( self::CRYPTOGRAM, $cryptogram ) ) ) {
				return null;
			}
			$out['network_token'] = [ 'number' => $token['number'] ] + $expiry;
			$provider             = self::word( $token['token_service_provider'] ?? null, 20 );
			if ( $provider !== null ) {
				$out['network_token']['token_service_provider'] = $provider;
			}
			$out['cryptogram'] = $cryptogram;
			if ( isset( $value['eci'] ) && is_scalar( $value['eci'] ) && preg_match( '/^\d{1,2}$/', (string) $value['eci'] ) ) {
				$out['eci'] = (string) $value['eci'];
			}
		} elseif ( isset( $value['card'] ) && $value['type'] === 'google_pay' ) {
			$card   = $value['card'];
			$expiry = is_array( $card ) ? self::expiry( $card ) : null;
			if ( $expiry === null || ! self::encrypted( $card['number'] ?? null ) ) {
				return null;
			}
			$out['card'] = [ 'number' => $card['number'] ] + $expiry;
		} else {
			return null;
		}

		foreach ( [ 'payment_data_type' => 40, 'brand' => 30 ] as $key => $max ) {
			$word = self::word( $value[ $key ] ?? null, $max );
			if ( $word !== null ) {
				$out[ $key ] = $word;
			}
		}
		if ( isset( $value['last_four'] ) && is_scalar( $value['last_four'] ) && preg_match( '/^\d{4}$/', (string) $value['last_four'] ) ) {
			$out['last_four'] = (string) $value['last_four'];
		}
		return $out;
	}

	/** @param mixed $value */
	private static function encrypted( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= self::CIPHERTEXT_MAX && preg_match( self::CIPHERTEXT, $value ) === 1;
	}

	/**
	 * @param array<string,mixed> $value exp_month, exp_year (2 or 4 digits).
	 * @return array{exp_month:int, exp_year:int}|null
	 */
	private static function expiry( array $value ): ?array {
		$month = self::digits( $value['exp_month'] ?? null );
		$year  = self::digits( $value['exp_year'] ?? null );
		if ( $month === null || $month < 1 || $month > 12 || $year === null || ( $year > 99 && ( $year < 2000 || $year > 2100 ) ) ) {
			return null;
		}
		return [ 'exp_month' => $month, 'exp_year' => $year ];
	}

	/** @param mixed $value A non-negative whole number, as int or digit string. */
	private static function digits( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		return is_string( $value ) && $value !== '' && strlen( $value ) <= 4 && ctype_digit( $value ) ? (int) $value : null;
	}

	/** @param mixed $value A short label (brand, token service provider …), else null. */
	private static function word( $value, int $max ): ?string {
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9 _.-]{1,' . $max . '}$/', $value ) ? $value : null;
	}
}
